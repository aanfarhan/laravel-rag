<?php

namespace Omniglies\LaravelRag\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Exceptions\RagException;

class IngestCommand extends Command
{
    protected $signature = 'rag:ingest 
                           {file : Path to the file to ingest}
                           {--title= : Custom title for the document}
                           {--wait : Wait for processing to complete}
                           {--timeout=300 : Timeout in seconds when waiting}';

    protected $description = 'Ingest a document into the RAG knowledge base';

    public function __construct(
        protected RagService $ragService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->argument('file');
        
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        if (!is_readable($filePath)) {
            $this->error("File is not readable: {$filePath}");
            return self::FAILURE;
        }

        try {
            $title = $this->option('title') ?: basename($filePath);
            
            $this->info("Ingesting document: {$title}");
            $this->line("File: {$filePath}");
            
            // Create UploadedFile instance
            $file = new UploadedFile(
                $filePath,
                basename($filePath),
                mime_content_type($filePath),
                null,
                true
            );

            $document = $this->ragService->ingestDocument($title, $file);
            
            $this->info("✅ Document ingested successfully!");
            $this->line("Document ID: {$document->id}");
            $this->line("Status: {$document->processing_status}");
            
            if ($this->option('wait')) {
                $this->waitForProcessing($document->id);
            } else {
                $this->line("\nUse 'rag:status {$document->id}' to check processing status");
            }

            return self::SUCCESS;

        } catch (RagException $e) {
            $this->error("RAG Error: {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function waitForProcessing(int $documentId): void
    {
        $timeout = (int) $this->option('timeout');
        $startTime = time();
        
        $this->info("\nWaiting for document processing to complete...");
        
        $progressBar = $this->output->createProgressBar(100);
        $progressBar->start();
        
        while (time() - $startTime < $timeout) {
            try {
                $status = $this->ragService->getProcessingStatus($documentId);
                
                $progressBar->setProgress((int) $status['progress']);
                
                if ($status['status'] === 'completed') {
                    $progressBar->finish();
                    $this->newLine(2);
                    $this->info("✅ Document processing completed!");
                    $this->line("Total chunks: {$status['total_chunks']}");
                    $this->line("Synced chunks: {$status['synced_chunks']}");
                    return;
                }
                
                if ($status['status'] === 'failed') {
                    $progressBar->finish();
                    $this->newLine(2);
                    $this->error("❌ Document processing failed");
                    return;
                }
                
                sleep(2);
                
            } catch (\Exception $e) {
                $progressBar->finish();
                $this->newLine(2);
                $this->error("Error checking status: {$e->getMessage()}");
                return;
            }
        }
        
        $progressBar->finish();
        $this->newLine(2);
        $this->warn("⏰ Timeout reached. Processing may still be in progress.");
        $this->line("Use 'rag:status {$documentId}' to check current status");
    }
}