<?php

namespace Omniglies\LaravelRag\Console\Commands;

use Illuminate\Console\Command;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Exceptions\RagException;

class ClearCommand extends Command
{
    protected $signature = 'rag:clear 
                           {--force : Skip confirmation prompt}
                           {--keep-files : Keep uploaded files on disk}';

    protected $description = 'Clear the RAG knowledge base';

    public function __construct(
        protected RagService $ragService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->option('force')) {
            $documentCount = RagKnowledgeDocument::count();
            
            if ($documentCount === 0) {
                $this->info('Knowledge base is already empty.');
                return self::SUCCESS;
            }
            
            $this->warn("This will delete {$documentCount} documents and all associated data.");
            $this->warn('This action cannot be undone!');
            
            if (!$this->confirm('Are you sure you want to clear the knowledge base?')) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        try {
            $this->info('Clearing knowledge base...');
            
            // Show progress for large datasets
            $documentCount = RagKnowledgeDocument::count();
            if ($documentCount > 100) {
                $progressBar = $this->output->createProgressBar($documentCount);
                $progressBar->start();
                
                RagKnowledgeDocument::chunk(50, function ($documents) use ($progressBar) {
                    foreach ($documents as $document) {
                        $this->deleteDocument($document);
                        $progressBar->advance();
                    }
                });
                
                $progressBar->finish();
                $this->newLine(2);
            } else {
                // Use the service method for smaller datasets
                $success = $this->ragService->clearKnowledgeBase();
                
                if (!$success) {
                    $this->error('Failed to clear knowledge base');
                    return self::FAILURE;
                }
            }
            
            $this->info('✅ Knowledge base cleared successfully!');
            
            $this->displayStats();

            return self::SUCCESS;

        } catch (RagException $e) {
            $this->error("RAG Error: {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function deleteDocument(RagKnowledgeDocument $document): void
    {
        try {
            // Delete vector database entries
            $chunkVectorIds = $document->chunks()->pluck('vector_id')
                                               ->filter()
                                               ->toArray();
            
            if (!empty($chunkVectorIds)) {
                $vectorService = app(\Omniglies\LaravelRag\Services\VectorSearchService::class);
                $vectorService->deleteVectors($chunkVectorIds);
            }
            
            // Delete files unless --keep-files is specified
            if (!$this->option('keep-files') && $document->source_path) {
                \Storage::disk(config('rag.file_upload.storage_disk', 'local'))
                        ->delete($document->source_path);
            }
            
            // Delete database records (cascades to chunks and jobs)
            $document->delete();
            
        } catch (\Exception $e) {
            $this->warn("Failed to delete document {$document->id}: {$e->getMessage()}");
        }
    }

    protected function displayStats(): void
    {
        $this->newLine();
        $this->comment('Final statistics:');
        
        $remaining = [
            'Documents' => RagKnowledgeDocument::count(),
            'Chunks' => \DB::table(config('rag.table_prefix', 'rag_') . 'knowledge_chunks')->count(),
            'Processing Jobs' => \DB::table(config('rag.table_prefix', 'rag_') . 'processing_jobs')->count(),
            'Search Queries' => \DB::table(config('rag.table_prefix', 'rag_') . 'search_queries')->count(),
            'API Usage Records' => \DB::table(config('rag.table_prefix', 'rag_') . 'api_usage')->count(),
        ];
        
        foreach ($remaining as $type => $count) {
            $status = $count === 0 ? '✅' : '⚠️ ';
            $this->line("{$status} {$type}: {$count}");
        }
        
        if (array_sum($remaining) > 0) {
            $this->warn('Some records remain. This may be expected if you used --keep-files or if there were errors.');
        }
    }
}