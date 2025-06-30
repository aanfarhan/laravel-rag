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
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Exceptions\RagException;

class GenerateEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public int $maxExceptions = 3;

    public function __construct(
        public RagKnowledgeChunk $chunk
    ) {
        $this->onConnection(config('rag.queue.connection', 'default'));
        $this->onQueue(config('rag.queue.queue', 'rag'));
    }

    public function handle(EmbeddingService $embeddingService): void
    {
        try {
            // Create processing job record
            $processingJob = RagProcessingJob::create([
                'document_id' => $this->chunk->document_id,
                'job_type' => 'embedding_generation',
                'status' => 'processing',
                'api_provider' => $embeddingService->getProvider(),
                'started_at' => now(),
            ]);

            Log::info('Generating embedding for chunk', [
                'chunk_id' => $this->chunk->id,
                'document_id' => $this->chunk->document_id,
                'processing_job_id' => $processingJob->id,
            ]);

            // Generate embedding
            $embedding = $embeddingService->generateEmbedding($this->chunk->content);

            // Update chunk with embedding info (but not the embedding itself - that goes to vector DB)
            $this->chunk->update([
                'embedding_model' => $embeddingService->getModel(),
                'embedding_dimensions' => $embeddingService->getDimensions(),
            ]);

            $processingJob->markAsCompleted();

            Log::info('Embedding generated successfully', [
                'chunk_id' => $this->chunk->id,
                'embedding_dimensions' => count($embedding),
                'model' => $embeddingService->getModel(),
            ]);

            // Dispatch job to sync with vector database
            SyncVectorDatabaseJob::dispatch($this->chunk, $embedding)->delay(now()->addSeconds(5));

        } catch (RagException $e) {
            Log::error('Embedding generation failed', [
                'chunk_id' => $this->chunk->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($processingJob)) {
                $processingJob->markAsFailed($e->getMessage());
            }

            throw $e;

        } catch (\Exception $e) {
            Log::error('Unexpected error during embedding generation', [
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

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateEmbeddingsJob failed permanently', [
            'chunk_id' => $this->chunk->id,
            'document_id' => $this->chunk->document_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Mark any associated processing jobs as failed
        RagProcessingJob::where('document_id', $this->chunk->document_id)
                       ->where('job_type', 'embedding_generation')
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