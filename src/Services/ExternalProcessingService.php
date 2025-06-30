<?php

namespace Omniglies\LaravelRag\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagProcessingJob;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\RagException;

class ExternalProcessingService
{
    protected Client $client;
    protected string $apiUrl;
    protected string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->apiUrl = config('rag.external_processing.api_url');
        $this->apiKey = config('rag.external_processing.api_key');
        $this->timeout = config('rag.external_processing.timeout', 300);

        if (empty($this->apiUrl) || empty($this->apiKey)) {
            throw RagException::configurationMissing('external_processing API credentials');
        }

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function submitDocument(
        RagKnowledgeDocument $document,
        UploadedFile $file,
        array $options = []
    ): string {
        $processingJob = RagProcessingJob::create([
            'document_id' => $document->id,
            'job_type' => 'document_processing',
            'status' => 'queued',
            'api_provider' => 'external_processing_api',
            'max_retries' => config('rag.external_processing.retry_attempts', 3),
        ]);

        try {
            $response = $this->client->post('/documents/process', [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($file->getRealPath(), 'r'),
                        'filename' => $file->getClientOriginalName(),
                    ],
                    [
                        'name' => 'options',
                        'contents' => json_encode(array_merge([
                            'chunking_strategy' => config('rag.chunking.strategy', 'semantic'),
                            'chunk_size' => config('rag.chunking.chunk_size', 1000),
                            'chunk_overlap' => config('rag.chunking.chunk_overlap', 200),
                            'extract_metadata' => true,
                            'webhook_url' => $this->getWebhookUrl(),
                            'webhook_secret' => config('rag.external_processing.webhook_secret'),
                        ], $options)),
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $externalJobId = $data['job_id'] ?? null;

            if (!$externalJobId) {
                throw new \Exception('No job ID returned from processing API');
            }

            $processingJob->update([
                'external_job_id' => $externalJobId,
                'status' => 'processing',
                'started_at' => now(),
            ]);

            $document->update([
                'processing_job_id' => $processingJob->id,
                'external_document_id' => $data['document_id'] ?? null,
                'processing_status' => 'processing',
                'processing_started_at' => now(),
            ]);

            RagApiUsage::recordUsage(
                'external_processing_api',
                'document_processing',
                null,
                $data['cost'] ?? null,
                $document->id
            );

            return $externalJobId;

        } catch (RequestException $e) {
            $processingJob->markAsFailed($e->getMessage());
            $document->update(['processing_status' => 'failed']);
            
            Log::error('External processing API request failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            
            throw RagException::documentProcessingFailed($e->getMessage());
        }
    }

    public function getJobStatus(string $externalJobId): array
    {
        try {
            $response = $this->client->get("/jobs/{$externalJobId}/status");
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'status' => $data['status'] ?? 'unknown',
                'progress' => $data['progress'] ?? 0,
                'result' => $data['result'] ?? null,
                'error' => $data['error'] ?? null,
                'estimated_completion' => $data['estimated_completion'] ?? null,
            ];

        } catch (RequestException $e) {
            Log::error('Failed to get job status from external API', [
                'job_id' => $externalJobId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'status' => 'error',
                'progress' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getProcessedDocument(string $externalJobId): array
    {
        try {
            $response = $this->client->get("/jobs/{$externalJobId}/result");
            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['chunks']) || !is_array($data['chunks'])) {
                throw new \Exception('Invalid response format: missing chunks array');
            }

            return [
                'chunks' => $data['chunks'],
                'metadata' => $data['metadata'] ?? [],
                'processing_stats' => $data['processing_stats'] ?? [],
            ];

        } catch (RequestException $e) {
            Log::error('Failed to get processed document from external API', [
                'job_id' => $externalJobId,
                'error' => $e->getMessage(),
            ]);
            
            throw RagException::documentProcessingFailed($e->getMessage());
        }
    }

    public function retryJob(RagProcessingJob $job): bool
    {
        if (!$job->canRetry()) {
            return false;
        }

        try {
            $response = $this->client->post("/jobs/{$job->external_job_id}/retry");
            
            $job->markAsRetrying();
            
            Log::info('Job retry initiated', [
                'job_id' => $job->id,
                'external_job_id' => $job->external_job_id,
                'retry_count' => $job->retry_count,
            ]);
            
            return true;

        } catch (RequestException $e) {
            Log::error('Failed to retry job', [
                'job_id' => $job->id,
                'external_job_id' => $job->external_job_id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function cancelJob(string $externalJobId): bool
    {
        try {
            $this->client->delete("/jobs/{$externalJobId}");
            
            Log::info('Job cancelled', ['external_job_id' => $externalJobId]);
            
            return true;

        } catch (RequestException $e) {
            Log::error('Failed to cancel job', [
                'external_job_id' => $externalJobId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function handleWebhook(array $payload): bool
    {
        $webhookSecret = config('rag.external_processing.webhook_secret');
        $signature = request()->header('X-Webhook-Signature');
        
        if (!$this->verifyWebhookSignature($payload, $signature, $webhookSecret)) {
            Log::warning('Invalid webhook signature');
            return false;
        }

        $externalJobId = $payload['job_id'] ?? null;
        $status = $payload['status'] ?? null;
        
        if (!$externalJobId || !$status) {
            Log::warning('Invalid webhook payload', $payload);
            return false;
        }

        $processingJob = RagProcessingJob::where('external_job_id', $externalJobId)->first();
        
        if (!$processingJob) {
            Log::warning('Processing job not found for webhook', ['external_job_id' => $externalJobId]);
            return false;
        }

        switch ($status) {
            case 'completed':
                return $this->handleCompletedJob($processingJob, $payload);
            
            case 'failed':
                return $this->handleFailedJob($processingJob, $payload);
            
            case 'progress':
                return $this->handleProgressUpdate($processingJob, $payload);
            
            default:
                Log::warning('Unknown webhook status', ['status' => $status, 'payload' => $payload]);
                return false;
        }
    }

    protected function handleCompletedJob(RagProcessingJob $job, array $payload): bool
    {
        try {
            $result = $this->getProcessedDocument($job->external_job_id);
            
            $document = $job->document;
            $document->update([
                'processing_status' => 'completed',
                'processing_completed_at' => now(),
            ]);

            foreach ($result['chunks'] as $index => $chunkData) {
                $document->chunks()->create([
                    'content' => $chunkData['content'],
                    'chunk_index' => $index,
                    'chunk_hash' => hash('sha256', $chunkData['content']),
                    'chunk_metadata' => $chunkData['metadata'] ?? [],
                    'keywords' => $chunkData['keywords'] ?? [],
                ]);
            }

            $job->markAsCompleted();
            
            Log::info('Document processing completed', [
                'document_id' => $document->id,
                'chunks_created' => count($result['chunks']),
            ]);
            
            return true;

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            $job->document->update(['processing_status' => 'failed']);
            
            Log::error('Failed to handle completed job', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    protected function handleFailedJob(RagProcessingJob $job, array $payload): bool
    {
        $errorMessage = $payload['error'] ?? 'Unknown error';
        
        $job->markAsFailed($errorMessage);
        $job->document->update(['processing_status' => 'failed']);
        
        Log::error('Document processing failed', [
            'document_id' => $job->document_id,
            'job_id' => $job->id,
            'error' => $errorMessage,
        ]);
        
        return true;
    }

    protected function handleProgressUpdate(RagProcessingJob $job, array $payload): bool
    {
        $progress = $payload['progress'] ?? 0;
        
        $job->updateProgress($progress);
        
        Log::debug('Job progress updated', [
            'job_id' => $job->id,
            'progress' => $progress,
        ]);
        
        return true;
    }

    protected function verifyWebhookSignature(array $payload, ?string $signature, string $secret): bool
    {
        if (!$signature || !$secret) {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    protected function getWebhookUrl(): string
    {
        return route('rag.webhook.processing');
    }

    public function getApiStatus(): array
    {
        try {
            $response = $this->client->get('/status');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'status' => 'online',
                'version' => $data['version'] ?? 'unknown',
                'queue_size' => $data['queue_size'] ?? 0,
                'processing_capacity' => $data['processing_capacity'] ?? 0,
            ];
            
        } catch (RequestException $e) {
            return [
                'status' => 'offline',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getSupportedFileTypes(): array
    {
        try {
            $response = $this->client->get('/supported-formats');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['supported_formats'] ?? [];
            
        } catch (RequestException $e) {
            Log::error('Failed to get supported file types', ['error' => $e->getMessage()]);
            return config('rag.file_upload.allowed_types', []);
        }
    }
}