<?php

namespace Omniglies\LaravelRag\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Services\ExternalProcessingService;
use Omniglies\LaravelRag\Exceptions\RagException;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries = 3;
    public int $maxExceptions = 3;

    public function __construct(
        public RagKnowledgeDocument $document
    ) {
        $this->timeout = config('rag.queue.processing_timeout', 600);
        $this->onConnection(config('rag.queue.connection', 'default'));
        $this->onQueue(config('rag.queue.queue', 'rag'));
    }

    public function handle(ExternalProcessingService $processingService): void
    {
        try {
            if (!config('rag.external_processing.enabled', true)) {
                Log::info('External processing disabled, skipping document processing', [
                    'document_id' => $this->document->id,
                ]);
                return;
            }

            if (!$this->document->source_path) {
                Log::error('Document has no file path for processing', [
                    'document_id' => $this->document->id,
                ]);
                
                $this->document->update(['processing_status' => 'failed']);
                return;
            }

            $filePath = storage_path('app/' . $this->document->source_path);
            
            if (!file_exists($filePath)) {
                Log::error('Document file not found', [
                    'document_id' => $this->document->id,
                    'file_path' => $filePath,
                ]);
                
                $this->document->update(['processing_status' => 'failed']);
                return;
            }

            // Create a temporary UploadedFile instance for processing
            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $filePath,
                basename($this->document->source_path),
                $this->document->mime_type,
                null,
                true
            );

            $externalJobId = $processingService->submitDocument(
                $this->document,
                $uploadedFile,
                [
                    'chunking_strategy' => config('rag.chunking.strategy', 'semantic'),
                    'chunk_size' => config('rag.chunking.chunk_size', 1000),
                    'chunk_overlap' => config('rag.chunking.chunk_overlap', 200),
                    'extract_metadata' => true,
                ]
            );

            Log::info('Document submitted for external processing', [
                'document_id' => $this->document->id,
                'external_job_id' => $externalJobId,
            ]);

            // Dispatch job to check processing status periodically
            SyncProcessingStatusJob::dispatch($this->document)->delay(now()->addMinutes(1));

        } catch (RagException $e) {
            Log::error('Document processing failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            $this->document->update(['processing_status' => 'failed']);
            throw $e;

        } catch (\Exception $e) {
            Log::error('Unexpected error during document processing', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->document->update(['processing_status' => 'failed']);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDocumentJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->document->update([
            'processing_status' => 'failed',
            'processing_completed_at' => now(),
        ]);

        // Clean up any associated processing jobs
        $this->document->processingJobs()
                      ->where('status', '!=', 'completed')
                      ->update([
                          'status' => 'failed',
                          'error_message' => $exception->getMessage(),
                          'completed_at' => now(),
                      ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    public function backoff(): array
    {
        return [30, 120, 300]; // 30 seconds, 2 minutes, 5 minutes
    }
}