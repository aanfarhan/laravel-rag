<?php

namespace Omniglies\LaravelRag\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Models\RagSearchQuery;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface;
use Omniglies\LaravelRag\Exceptions\RagException;
use Omniglies\LaravelRag\Jobs\ProcessDocumentJob;

class RagService
{
    public function __construct(
        protected ExternalProcessingService $processingService,
        protected VectorSearchService $vectorSearchService,
        protected EmbeddingService $embeddingService,
        protected AiProviderInterface $aiProvider
    ) {}

    public function ingestDocument(
        string $title,
        UploadedFile|string $content,
        array $metadata = []
    ): RagKnowledgeDocument {
        if ($content instanceof UploadedFile) {
            $this->validateFile($content);
            $storedPath = $this->storeFile($content);
            $fileHash = hash_file('sha256', $content->getRealPath());
            $fileSize = $content->getSize();
            $mimeType = $content->getMimeType();
            $sourceType = 'upload';
        } else {
            $storedPath = null;
            $fileHash = hash('sha256', $content);
            $fileSize = strlen($content);
            $mimeType = 'text/plain';
            $sourceType = 'text';
        }

        $existingDocument = RagKnowledgeDocument::where('file_hash', $fileHash)->first();
        if ($existingDocument) {
            return $existingDocument;
        }

        $document = RagKnowledgeDocument::create([
            'title' => $title,
            'source_type' => $sourceType,
            'source_path' => $storedPath,
            'file_hash' => $fileHash,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'processing_status' => 'pending',
            'metadata' => $metadata,
        ]);

        if (config('rag.external_processing.enabled', true)) {
            ProcessDocumentJob::dispatch($document);
        } else {
            $this->processDocumentLocally($document, $content);
        }

        return $document;
    }

    public function searchRelevantChunks(string $query, int $limit = null): array
    {
        $limit = $limit ?? config('rag.search.default_limit', 3);
        $startTime = microtime(true);

        try {
            if (config('rag.search.hybrid_search.enabled', true)) {
                $results = $this->performHybridSearch($query, $limit);
            } else {
                $results = $this->performVectorSearch($query, $limit);
            }

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $similarityScores = array_column($results, 'similarity_score');

            RagSearchQuery::recordQuery(
                $query,
                config('rag.search.hybrid_search.enabled', true) ? 'hybrid' : 'vector',
                count($results),
                $responseTime,
                $similarityScores,
                auth()->id()
            );

            return $results;
        } catch (\Exception $e) {
            if (config('rag.search.fallback_to_sql', true)) {
                return $this->performSqlSearch($query, $limit);
            }
            throw RagException::searchFailed($e->getMessage());
        }
    }

    public function askWithContext(string $userQuestion): array
    {
        $relevantChunks = $this->searchRelevantChunks($userQuestion);
        
        if (empty($relevantChunks)) {
            return [
                'answer' => "I don't have relevant information to answer your question.",
                'sources' => [],
                'confidence' => 0.0,
            ];
        }

        $context = $this->buildContext($relevantChunks);
        $prompt = $this->buildPrompt($userQuestion, $context);

        $response = $this->aiProvider->generateResponse($prompt);

        RagApiUsage::recordUsage(
            config('rag.ai_provider'),
            'chat_completion',
            $response['usage']['total_tokens'] ?? null,
            $response['usage']['cost'] ?? null
        );

        return [
            'answer' => $response['content'],
            'sources' => $this->extractSources($relevantChunks),
            'confidence' => $this->calculateConfidence($relevantChunks),
            'usage' => $response['usage'] ?? [],
        ];
    }

    public function getProcessingStatus(int $documentId): array
    {
        $document = RagKnowledgeDocument::findOrFail($documentId);
        
        return [
            'id' => $document->id,
            'title' => $document->title,
            'status' => $document->processing_status,
            'progress' => $document->getProcessingProgressAttribute(),
            'total_chunks' => $document->getTotalChunksAttribute(),
            'synced_chunks' => $document->getSyncedChunksAttribute(),
            'processing_started_at' => $document->processing_started_at,
            'processing_completed_at' => $document->processing_completed_at,
        ];
    }

    public function deleteDocument(int $documentId): bool
    {
        $document = RagKnowledgeDocument::findOrFail($documentId);
        
        if ($document->source_path) {
            Storage::disk(config('rag.file_upload.storage_disk', 'local'))
                   ->delete($document->source_path);
        }

        $chunkVectorIds = $document->chunks()->pluck('vector_id')
                                           ->filter()
                                           ->toArray();

        if (!empty($chunkVectorIds)) {
            $this->vectorSearchService->deleteVectors($chunkVectorIds);
        }

        return $document->delete();
    }

    public function optimizeSearchIndexes(): void
    {
        if (config('database.default') === 'pgsql') {
            $prefix = config('rag.table_prefix', 'rag_');
            \DB::statement("REINDEX INDEX {$prefix}knowledge_chunks_search_vector_idx");
        }
    }

    public function clearKnowledgeBase(): bool
    {
        try {
            $this->vectorSearchService->deleteAllVectors();
            
            RagKnowledgeDocument::chunk(100, function ($documents) {
                foreach ($documents as $document) {
                    if ($document->source_path) {
                        Storage::disk(config('rag.file_upload.storage_disk', 'local'))
                               ->delete($document->source_path);
                    }
                }
            });

            RagKnowledgeDocument::truncate();
            RagKnowledgeChunk::truncate();
            
            Cache::tags(['rag'])->flush();
            
            return true;
        } catch (\Exception $e) {
            throw RagException::searchFailed("Failed to clear knowledge base: " . $e->getMessage());
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $maxSize = config('rag.file_upload.max_size', 10240);
        $allowedTypes = config('rag.file_upload.allowed_types', []);

        if ($file->getSize() > $maxSize * 1024) {
            throw RagException::fileTooLarge($file->getSize() / 1024, $maxSize);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedTypes)) {
            throw RagException::invalidFileType($extension);
        }
    }

    protected function storeFile(UploadedFile $file): string
    {
        $disk = config('rag.file_upload.storage_disk', 'local');
        $path = config('rag.file_upload.storage_path', 'rag/documents');
        
        return $file->store($path, $disk);
    }

    protected function processDocumentLocally(RagKnowledgeDocument $document, $content): void
    {
        $document->update(['processing_status' => 'processing', 'processing_started_at' => now()]);

        try {
            $textContent = $content instanceof UploadedFile 
                ? $this->extractTextFromFile($content)
                : $content;

            $chunks = $this->chunkText($textContent);
            
            foreach ($chunks as $index => $chunkContent) {
                RagKnowledgeChunk::create([
                    'document_id' => $document->id,
                    'content' => $chunkContent,
                    'chunk_index' => $index,
                    'chunk_hash' => hash('sha256', $chunkContent),
                ]);
            }

            $document->update([
                'processing_status' => 'completed',
                'processing_completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            $document->update(['processing_status' => 'failed']);
            throw RagException::documentProcessingFailed($e->getMessage());
        }
    }

    protected function extractTextFromFile(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        return match ($extension) {
            'txt', 'md' => file_get_contents($file->getRealPath()),
            'pdf' => $this->extractTextFromPdf($file),
            'docx' => $this->extractTextFromDocx($file),
            'html' => strip_tags(file_get_contents($file->getRealPath())),
            default => throw RagException::invalidFileType($extension),
        };
    }

    protected function extractTextFromPdf(UploadedFile $file): string
    {
        return "PDF text extraction not implemented - use external processing service";
    }

    protected function extractTextFromDocx(UploadedFile $file): string
    {
        return "DOCX text extraction not implemented - use external processing service";
    }

    protected function chunkText(string $text): array
    {
        $chunkSize = config('rag.chunking.chunk_size', 1000);
        $overlap = config('rag.chunking.chunk_overlap', 200);
        $separators = config('rag.chunking.separators', ['\n\n', '\n', '. ']);

        $chunks = [];
        $currentChunk = '';
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk . $sentence) > $chunkSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = substr($currentChunk, -$overlap) . $sentence;
            } else {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return array_filter($chunks);
    }

    protected function performHybridSearch(string $query, int $limit): array
    {
        $vectorResults = $this->performVectorSearch($query, $limit * 2);
        $sqlResults = $this->performSqlSearch($query, $limit * 2);

        $vectorWeight = config('rag.search.hybrid_search.vector_weight', 0.8);
        $keywordWeight = config('rag.search.hybrid_search.keyword_weight', 0.2);

        $combinedResults = [];
        $seenChunks = [];

        foreach ($vectorResults as $result) {
            $id = $result['id'];
            if (!isset($seenChunks[$id])) {
                $combinedResults[$id] = $result;
                $combinedResults[$id]['hybrid_score'] = $result['similarity_score'] * $vectorWeight;
                $seenChunks[$id] = true;
            }
        }

        foreach ($sqlResults as $result) {
            $id = $result['id'];
            $sqlScore = 0.5; // Default SQL relevance score
            
            if (isset($combinedResults[$id])) {
                $combinedResults[$id]['hybrid_score'] += $sqlScore * $keywordWeight;
            } else {
                $result['similarity_score'] = $sqlScore;
                $result['hybrid_score'] = $sqlScore * $keywordWeight;
                $combinedResults[$id] = $result;
            }
        }

        usort($combinedResults, function ($a, $b) {
            return $b['hybrid_score'] <=> $a['hybrid_score'];
        });

        return array_slice(array_values($combinedResults), 0, $limit);
    }

    protected function performVectorSearch(string $query, int $limit): array
    {
        return $this->vectorSearchService->searchSimilar($query, [
            'limit' => $limit,
            'threshold' => config('rag.search.similarity_threshold', 0.7),
        ]);
    }

    protected function performSqlSearch(string $query, int $limit): array
    {
        $chunks = RagKnowledgeChunk::with('document')
                                   ->fullTextSearch($query)
                                   ->limit($limit)
                                   ->get();

        return $chunks->map(function ($chunk) {
            return [
                'id' => $chunk->id,
                'content' => $chunk->content,
                'similarity_score' => 0.5, // Default score for SQL search
                'document' => [
                    'id' => $chunk->document->id,
                    'title' => $chunk->document->title,
                ],
                'metadata' => $chunk->chunk_metadata,
            ];
        })->toArray();
    }

    protected function buildContext(array $chunks): string
    {
        $context = '';
        foreach ($chunks as $chunk) {
            $context .= "Source: {$chunk['document']['title']}\n";
            $context .= $chunk['content'] . "\n\n";
        }
        return trim($context);
    }

    protected function buildPrompt(string $question, string $context): string
    {
        return "Based on the following context, please answer the question. If the context doesn't contain relevant information, say so.\n\n" .
               "Context:\n{$context}\n\n" .
               "Question: {$question}\n\n" .
               "Answer:";
    }

    protected function extractSources(array $chunks): array
    {
        $sources = [];
        foreach ($chunks as $chunk) {
            $sources[] = [
                'document_id' => $chunk['document']['id'],
                'document_title' => $chunk['document']['title'],
                'chunk_id' => $chunk['id'],
                'similarity_score' => $chunk['similarity_score'],
            ];
        }
        return $sources;
    }

    protected function calculateConfidence(array $chunks): float
    {
        if (empty($chunks)) {
            return 0.0;
        }

        $scores = array_column($chunks, 'similarity_score');
        return array_sum($scores) / count($scores);
    }
}