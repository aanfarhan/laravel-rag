<?php

namespace Omniglies\LaravelRag\Console\Commands;

use Illuminate\Console\Command;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Services\ExternalProcessingService;
use Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;

class StatusCommand extends Command
{
    protected $signature = 'rag:status 
                           {document? : Document ID to check specific document status}
                           {--test-config : Test all service configurations}
                           {--detailed : Show detailed statistics}';

    protected $description = 'Show RAG system status and configuration';

    public function __construct(
        protected RagService $ragService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $documentId = $this->argument('document');
        
        if ($documentId) {
            return $this->showDocumentStatus((int) $documentId);
        }
        
        if ($this->option('test-config')) {
            return $this->testConfiguration();
        }
        
        return $this->showSystemStatus();
    }

    protected function showDocumentStatus(int $documentId): int
    {
        try {
            $status = $this->ragService->getProcessingStatus($documentId);
            
            $this->info("Document Status: {$status['title']}");
            $this->line("ID: {$status['id']}");
            $this->line("Status: {$status['status']}");
            $this->line("Progress: {$status['progress']}%");
            $this->line("Total Chunks: {$status['total_chunks']}");
            $this->line("Synced Chunks: {$status['synced_chunks']}");
            
            if ($status['processing_started_at']) {
                $this->line("Started: {$status['processing_started_at']}");
            }
            
            if ($status['processing_completed_at']) {
                $this->line("Completed: {$status['processing_completed_at']}");
            }
            
            // Show processing jobs
            $document = RagKnowledgeDocument::with('processingJobs')->find($documentId);
            if ($document && $document->processingJobs->count() > 0) {
                $this->newLine();
                $this->comment('Processing Jobs:');
                
                foreach ($document->processingJobs as $job) {
                    $this->line("- {$job->job_type}: {$job->status} ({$job->progress_percentage}%)");
                    if ($job->error_message) {
                        $this->warn("  Error: {$job->error_message}");
                    }
                }
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function testConfiguration(): int
    {
        $this->info('Testing RAG configuration...');
        $this->newLine();
        
        $allPassed = true;
        
        // Test AI Provider
        $allPassed = $this->testAiProvider() && $allPassed;
        
        // Test Embedding Service
        $allPassed = $this->testEmbeddingService() && $allPassed;
        
        // Test Vector Database
        $allPassed = $this->testVectorDatabase() && $allPassed;
        
        // Test External Processing (if enabled)
        if (config('rag.external_processing.enabled', true)) {
            $allPassed = $this->testExternalProcessing() && $allPassed;
        }
        
        // Test Database
        $allPassed = $this->testDatabase() && $allPassed;
        
        $this->newLine();
        if ($allPassed) {
            $this->info('✅ All configuration tests passed!');
            return self::SUCCESS;
        } else {
            $this->error('❌ Some configuration tests failed. Check the errors above.');
            return self::FAILURE;
        }
    }

    protected function testAiProvider(): bool
    {
        $this->line('Testing AI Provider...');
        
        try {
            $provider = app(AiProviderInterface::class);
            $providerName = config('rag.ai_provider');
            
            if ($provider->validateApiKey()) {
                $this->line("✅ {$providerName} API key is valid");
                return true;
            } else {
                $this->error("❌ {$providerName} API key validation failed");
                return false;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ AI Provider error: {$e->getMessage()}");
            return false;
        }
    }

    protected function testEmbeddingService(): bool
    {
        $this->line('Testing Embedding Service...');
        
        try {
            $embeddingService = app(EmbeddingService::class);
            $provider = $embeddingService->getProvider();
            $model = $embeddingService->getModel();
            
            // Test with a simple text
            $embedding = $embeddingService->generateEmbedding('test');
            
            if (is_array($embedding) && count($embedding) > 0) {
                $this->line("✅ {$provider} embeddings working (model: {$model}, dimensions: " . count($embedding) . ")");
                return true;
            } else {
                $this->error("❌ Invalid embedding response");
                return false;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Embedding Service error: {$e->getMessage()}");
            return false;
        }
    }

    protected function testVectorDatabase(): bool
    {
        $this->line('Testing Vector Database...');
        
        try {
            $vectorService = app(VectorSearchService::class);
            $provider = config('rag.vector_database.provider');
            
            $stats = $vectorService->getIndexStats();
            
            $this->line("✅ {$provider} connection successful");
            $this->line("   Vectors: {$stats['total_vectors']}, Dimensions: {$stats['dimensions']}");
            return true;
            
        } catch (\Exception $e) {
            $this->error("❌ Vector Database error: {$e->getMessage()}");
            return false;
        }
    }

    protected function testExternalProcessing(): bool
    {
        $this->line('Testing External Processing API...');
        
        try {
            $processingService = app(ExternalProcessingService::class);
            $status = $processingService->getApiStatus();
            
            if ($status['status'] === 'online') {
                $this->line("✅ External Processing API is online");
                if (isset($status['version'])) {
                    $this->line("   Version: {$status['version']}");
                }
                return true;
            } else {
                $this->error("❌ External Processing API is offline");
                return false;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ External Processing API error: {$e->getMessage()}");
            return false;
        }
    }

    protected function testDatabase(): bool
    {
        $this->line('Testing Database...');
        
        try {
            // Test connection
            \DB::connection()->getPdo();
            
            // Check if tables exist
            $prefix = config('rag.table_prefix', 'rag_');
            $tables = [
                $prefix . 'knowledge_documents',
                $prefix . 'knowledge_chunks',
                $prefix . 'processing_jobs',
                $prefix . 'search_queries',
                $prefix . 'api_usage',
            ];
            
            foreach ($tables as $table) {
                if (!\Schema::hasTable($table)) {
                    $this->error("❌ Table {$table} does not exist. Run migrations.");
                    return false;
                }
            }
            
            $this->line('✅ Database connection and tables OK');
            return true;
            
        } catch (\Exception $e) {
            $this->error("❌ Database error: {$e->getMessage()}");
            return false;
        }
    }

    protected function showSystemStatus(): int
    {
        $this->info('RAG System Status');
        $this->line('==================');
        $this->newLine();
        
        // Configuration info
        $this->comment('Configuration:');
        $this->line('AI Provider: ' . config('rag.ai_provider'));
        $this->line('Vector Provider: ' . config('rag.vector_database.provider'));
        $this->line('Embedding Provider: ' . config('rag.embedding.provider'));
        $this->line('External Processing: ' . (config('rag.external_processing.enabled') ? 'Enabled' : 'Disabled'));
        $this->line('Routes: ' . (config('rag.routes.enabled') ? 'Enabled' : 'Disabled'));
        $this->newLine();
        
        // Statistics
        $this->comment('Knowledge Base Statistics:');
        $this->displayBasicStats();
        
        if ($this->option('detailed')) {
            $this->newLine();
            $this->displayDetailedStats();
        }
        
        return self::SUCCESS;
    }

    protected function displayBasicStats(): void
    {
        $stats = [
            'Total Documents' => RagKnowledgeDocument::count(),
            'Processed Documents' => RagKnowledgeDocument::completed()->count(),
            'Pending Documents' => RagKnowledgeDocument::pending()->count(),
            'Failed Documents' => RagKnowledgeDocument::failed()->count(),
            'Total Chunks' => RagKnowledgeChunk::count(),
            'Synced Vectors' => RagKnowledgeChunk::synced()->count(),
        ];
        
        foreach ($stats as $label => $value) {
            $this->line("{$label}: {$value}");
        }
    }

    protected function displayDetailedStats(): void
    {
        $this->comment('Detailed Statistics:');
        
        // Processing jobs
        $jobStats = \DB::table(config('rag.table_prefix', 'rag_') . 'processing_jobs')
                       ->select('status', \DB::raw('count(*) as count'))
                       ->groupBy('status')
                       ->pluck('count', 'status')
                       ->toArray();
        
        $this->line('Processing Jobs:');
        foreach ($jobStats as $status => $count) {
            $this->line("  {$status}: {$count}");
        }
        
        // Recent activity
        if (config('rag.analytics.enabled', true)) {
            $searchesToday = \DB::table(config('rag.table_prefix', 'rag_') . 'search_queries')
                               ->whereDate('created_at', today())
                               ->count();
            
            $this->line("Searches Today: {$searchesToday}");
            
            $costThisMonth = \DB::table(config('rag.table_prefix', 'rag_') . 'api_usage')
                               ->whereYear('created_at', now()->year)
                               ->whereMonth('created_at', now()->month)
                               ->sum('cost_usd');
            
            $this->line("API Cost This Month: $" . number_format($costThisMonth, 4));
        }
        
        // Vector database stats
        try {
            $vectorService = app(VectorSearchService::class);
            $vectorStats = $vectorService->getIndexStats();
            
            $this->line('Vector Database:');
            $this->line("  Total Vectors: {$vectorStats['total_vectors']}");
            $this->line("  Dimensions: {$vectorStats['dimensions']}");
            
        } catch (\Exception $e) {
            $this->warn("Could not retrieve vector database stats: {$e->getMessage()}");
        }
    }
}