<?php

namespace Omniglies\LaravelRag\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Models\RagProcessingJob;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Exceptions\RagException;

class SyncVectorDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $maxExceptions = 3;

    public function __construct(
        public RagKnowledgeChunk $chunk,
        public ?array $embedding = null
    ) {
        $this->onConnection(config('rag.queue.connection', 'default'));
        $this->onQueue(config('rag.queue.queue', 'rag'));
    }

    public function handle(VectorSearchService $vectorService): void
    {
        try {
            // Create processing job record
            $processingJob = RagProcessingJob::create([
                'document_id' => $this->chunk->document_id,
                'job_type' => 'vector_sync',
                'status' => 'processing',
                'api_provider' => config('rag.vector_database.provider'),
                'started_at' => now(),
            ]);

            Log::info('Syncing chunk with vector database', [
                'chunk_id' => $this->chunk->id,
                'document_id' => $this->chunk->document_id,
                'processing_job_id' => $processingJob->id,
                'has_embedding' => !is_null($this->embedding),
            ]);

            // Use provided embedding or generate via vector service
            if ($this->embedding) {
                $success = $this->syncWithEmbedding($vectorService);
            } else {
                $success = $vectorService->upsertVector($this->chunk);
            }

            if ($success) {
                $processingJob->markAsCompleted();
                
                Log::info('Chunk synced with vector database successfully', [
                    'chunk_id' => $this->chunk->id,
                    'vector_id' => $this->chunk->vector_id,
                ]);
            } else {
                throw new RagException('Failed to sync chunk with vector database');
            }

        } catch (RagException $e) {
            Log::error('Vector database sync failed', [
                'chunk_id' => $this->chunk->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($processingJob)) {
                $processingJob->markAsFailed($e->getMessage());
            }

            throw $e;

        } catch (\Exception $e) {
            Log::error('Unexpected error during vector database sync', [
                'chunk_id' => $this->chunk->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($processingJob)) {
                $processingJob->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    protected function syncWithEmbedding(VectorSearchService $vectorService): bool
    {
        try {
            $vectorId = $this->generateVectorId();
            $metadata = [
                'chunk_id' => $this->chunk->id,
                'document_id' => $this->chunk->document_id,
                'content' => substr($this->chunk->content, 0, 1000), // Limit metadata size
                'document_title' => $this->chunk->document->title ?? '',
                'chunk_index' => $this->chunk->chunk_index,
            ];

            $success = $vectorService->performUpsert($vectorId, $this->embedding, $metadata);

            if ($success) {
                $this->chunk->update([
                    'vector_id' => $vectorId,
                    'vector_database_synced_at' => now(),
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to sync embedding with vector database', [
                'chunk_id' => $this->chunk->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    protected function generateVectorId(): string
    {
        return "chunk_{$this->chunk->id}_{$this->chunk->chunk_hash}";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncVectorDatabaseJob failed permanently', [
            'chunk_id' => $this->chunk->id,
            'document_id' => $this->chunk->document_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Mark any associated processing jobs as failed
        RagProcessingJob::where('document_id', $this->chunk->document_id)
                       ->where('job_type', 'vector_sync')
                       ->where('status', '!=', 'completed')
                       ->update([
                           'status' => 'failed',
                           'error_message' => $exception->getMessage(),
                           'completed_at' => now(),
                       ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function backoff(): array
    {
        return [30, 120, 300]; // 30 seconds, 2 minutes, 5 minutes
    }
}