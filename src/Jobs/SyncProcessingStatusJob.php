<?php

namespace Omniglies\LaravelRag\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagProcessingJob;
use Omniglies\LaravelRag\Services\ExternalProcessingService;

class SyncProcessingStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 10;
    public int $maxExceptions = 5;

    public function __construct(
        public RagKnowledgeDocument $document
    ) {
        $this->onConnection(config('rag.queue.connection', 'default'));
        $this->onQueue(config('rag.queue.queue', 'rag'));
    }

    public function handle(ExternalProcessingService $processingService): void
    {
        try {
            // Skip if document is already completed or failed
            if (in_array($this->document->processing_status, ['completed', 'failed'])) {
                Log::info('Document processing already finished, skipping status sync', [
                    'document_id' => $this->document->id,
                    'status' => $this->document->processing_status,
                ]);
                return;
            }

            $processingJob = $this->document->processingJobs()
                                          ->where('job_type', 'document_processing')
                                          ->where('status', '!=', 'completed')
                                          ->first();

            if (!$processingJob || !$processingJob->external_job_id) {
                Log::warning('No active processing job found for document', [
                    'document_id' => $this->document->id,
                ]);
                return;
            }

            $statusData = $processingService->getJobStatus($processingJob->external_job_id);

            Log::info('Retrieved processing status from external API', [
                'document_id' => $this->document->id,
                'external_job_id' => $processingJob->external_job_id,
                'status' => $statusData['status'],
                'progress' => $statusData['progress'],
            ]);

            switch ($statusData['status']) {
                case 'completed':
                    $this->handleCompletedStatus($processingJob, $statusData);
                    break;

                case 'failed':
                    $this->handleFailedStatus($processingJob, $statusData);
                    break;

                case 'processing':
                case 'in_progress':
                    $this->handleProgressStatus($processingJob, $statusData);
                    break;

                case 'queued':
                case 'pending':
                    // Keep checking
                    $this->scheduleNextCheck();
                    break;

                default:
                    Log::warning('Unknown processing status received', [
                        'document_id' => $this->document->id,
                        'status' => $statusData['status'],
                    ]);
                    $this->scheduleNextCheck();
            }

        } catch (\Exception $e) {
            Log::error('Error syncing processing status', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // Continue checking unless we've exhausted retries
            if ($this->attempts() < $this->tries) {
                $this->scheduleNextCheck(true);
            } else {
                Log::error('Max retry attempts reached for status sync', [
                    'document_id' => $this->document->id,
                ]);
            }
        }
    }

    protected function handleCompletedStatus(RagProcessingJob $processingJob, array $statusData): void
    {
        try {
            $processingJob->markAsCompleted();
            
            $this->document->update([
                'processing_status' => 'completed',
                'processing_completed_at' => now(),
            ]);

            Log::info('Document processing completed', [
                'document_id' => $this->document->id,
                'external_job_id' => $processingJob->external_job_id,
            ]);

            // Trigger embedding generation for all chunks
            $this->triggerEmbeddingGeneration();

        } catch (\Exception $e) {
            Log::error('Error handling completed status', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handleFailedStatus(RagProcessingJob $processingJob, array $statusData): void
    {
        $errorMessage = $statusData['error'] ?? 'Unknown error from external processing API';
        
        $processingJob->markAsFailed($errorMessage);
        
        $this->document->update([
            'processing_status' => 'failed',
            'processing_completed_at' => now(),
        ]);

        Log::error('Document processing failed', [
            'document_id' => $this->document->id,
            'external_job_id' => $processingJob->external_job_id,
            'error' => $errorMessage,
        ]);
    }

    protected function handleProgressStatus(RagProcessingJob $processingJob, array $statusData): void
    {
        $progress = $statusData['progress'] ?? 0;
        
        $processingJob->updateProgress($progress);
        
        Log::debug('Processing progress updated', [
            'document_id' => $this->document->id,
            'progress' => $progress,
        ]);

        // Schedule next check
        $this->scheduleNextCheck();
    }

    protected function scheduleNextCheck(bool $isRetry = false): void
    {
        $delay = $isRetry ? 
            now()->addMinutes(5) : // Longer delay for retries
            now()->addMinutes(2);  // Normal polling interval

        static::dispatch($this->document)->delay($delay);

        Log::debug('Scheduled next status check', [
            'document_id' => $this->document->id,
            'delay_minutes' => $isRetry ? 5 : 2,
            'is_retry' => $isRetry,
        ]);
    }

    protected function triggerEmbeddingGeneration(): void
    {
        $chunks = $this->document->chunks()->whereNull('vector_database_synced_at')->get();
        
        foreach ($chunks as $chunk) {
            GenerateEmbeddingsJob::dispatch($chunk)->delay(now()->addSeconds(rand(1, 30)));
        }

        Log::info('Triggered embedding generation for chunks', [
            'document_id' => $this->document->id,
            'chunk_count' => $chunks->count(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncProcessingStatusJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Don't mark document as failed here - let external processing handle that
        // But mark any pending processing jobs as failed
        $this->document->processingJobs()
                       ->where('job_type', 'document_processing')
                       ->where('status', '!=', 'completed')
                       ->update([
                           'status' => 'failed',
                           'error_message' => 'Status sync job failed: ' . $exception->getMessage(),
                           'completed_at' => now(),
                       ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6); // Keep trying for 6 hours
    }

    public function backoff(): array
    {
        return [60, 300, 600]; // 1 minute, 5 minutes, 10 minutes
    }
}