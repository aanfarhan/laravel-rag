<?php

namespace Omniglies\LaravelRag\Console\Commands;

use Illuminate\Console\Command;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Models\RagSearchQuery;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\RagException;

class OptimizeCommand extends Command
{
    protected $signature = 'rag:optimize 
                           {--search-indexes : Optimize search indexes}
                           {--vector-sync : Sync unsynced vectors}
                           {--cleanup-old : Clean up old records}
                           {--days=90 : Days to keep for cleanup}
                           {--force : Skip confirmation prompts}';

    protected $description = 'Optimize the RAG knowledge base performance';

    public function __construct(
        protected RagService $ragService,
        protected VectorSearchService $vectorService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting RAG optimization...');
        
        $operations = [
            'search-indexes' => 'Optimize search indexes',
            'vector-sync' => 'Sync unsynced vectors',
            'cleanup-old' => 'Clean up old records',
        ];
        
        $selectedOps = [];
        
        foreach ($operations as $option => $description) {
            if ($this->option($option)) {
                $selectedOps[] = $option;
            }
        }
        
        // If no specific operations selected, ask user
        if (empty($selectedOps)) {
            $selectedOps = $this->choice(
                'Which optimization operations would you like to perform?',
                array_keys($operations),
                null,
                null,
                true
            );
        }
        
        $success = true;
        
        foreach ($selectedOps as $operation) {
            $this->newLine();
            $this->info("Performing: {$operations[$operation]}");
            
            try {
                switch ($operation) {
                    case 'search-indexes':
                        $this->optimizeSearchIndexes();
                        break;
                    case 'vector-sync':
                        $this->syncUnsyncedVectors();
                        break;
                    case 'cleanup-old':
                        $this->cleanupOldRecords();
                        break;
                }
                
                $this->info("✅ {$operations[$operation]} completed");
                
            } catch (\Exception $e) {
                $this->error("❌ {$operations[$operation]} failed: {$e->getMessage()}");
                $success = false;
            }
        }
        
        $this->newLine();
        $this->displayOptimizationStats();
        
        return $success ? self::SUCCESS : self::FAILURE;
    }

    protected function optimizeSearchIndexes(): void
    {
        $this->line('Optimizing database search indexes...');
        
        try {
            $this->ragService->optimizeSearchIndexes();
            
            // Additional database optimizations
            if (config('database.default') === 'pgsql') {
                $this->line('Running PostgreSQL ANALYZE...');
                \DB::statement('ANALYZE');
                
                $this->line('Running PostgreSQL VACUUM...');
                if ($this->option('force') || $this->confirm('Run VACUUM (may take time)?', false)) {
                    \DB::statement('VACUUM');
                }
            }
            
        } catch (\Exception $e) {
            throw new RagException("Search index optimization failed: {$e->getMessage()}");
        }
    }

    protected function syncUnsyncedVectors(): void
    {
        $unsyncedChunks = RagKnowledgeChunk::unsynced()->count();
        
        if ($unsyncedChunks === 0) {
            $this->line('All vectors are already synced.');
            return;
        }
        
        $this->line("Found {$unsyncedChunks} unsynced chunks");
        
        if (!$this->option('force')) {
            if (!$this->confirm("Sync {$unsyncedChunks} vectors with vector database?")) {
                $this->info('Vector sync skipped.');
                return;
            }
        }
        
        $progressBar = $this->output->createProgressBar($unsyncedChunks);
        $progressBar->start();
        
        $synced = 0;
        $failed = 0;
        
        RagKnowledgeChunk::unsynced()->chunk(10, function ($chunks) use ($progressBar, &$synced, &$failed) {
            foreach ($chunks as $chunk) {
                try {
                    $success = $this->vectorService->upsertVector($chunk);
                    if ($success) {
                        $synced++;
                    } else {
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    \Log::error('Failed to sync vector for chunk', [
                        'chunk_id' => $chunk->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                $progressBar->advance();
                
                // Small delay to avoid overwhelming APIs
                usleep(100000); // 0.1 seconds
            }
        });
        
        $progressBar->finish();
        $this->newLine();
        
        $this->line("✅ Synced: {$synced}");
        if ($failed > 0) {
            $this->warn("❌ Failed: {$failed}");
        }
    }

    protected function cleanupOldRecords(): void
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        
        $this->line("Cleaning up records older than {$days} days ({$cutoff->toDateString()})...");
        
        $cleanupStats = [
            'Search Queries' => 0,
            'API Usage Records' => 0,
            'Failed Processing Jobs' => 0,
        ];
        
        // Clean up search queries
        if (config('rag.analytics.enabled', true)) {
            $count = RagSearchQuery::where('created_at', '<', $cutoff)->count();
            
            if ($count > 0) {
                if ($this->option('force') || $this->confirm("Delete {$count} old search queries?")) {
                    $cleanupStats['Search Queries'] = RagSearchQuery::where('created_at', '<', $cutoff)->delete();
                }
            }
        }
        
        // Clean up API usage records
        if (config('rag.analytics.track_usage', true)) {
            $count = RagApiUsage::where('created_at', '<', $cutoff)->count();
            
            if ($count > 0) {
                if ($this->option('force') || $this->confirm("Delete {$count} old API usage records?")) {
                    $cleanupStats['API Usage Records'] = RagApiUsage::where('created_at', '<', $cutoff)->delete();
                }
            }
        }
        
        // Clean up old failed processing jobs
        $count = \DB::table(config('rag.table_prefix', 'rag_') . 'processing_jobs')
                    ->where('status', 'failed')
                    ->where('created_at', '<', $cutoff)
                    ->count();
        
        if ($count > 0) {
            if ($this->option('force') || $this->confirm("Delete {$count} old failed processing jobs?")) {
                $cleanupStats['Failed Processing Jobs'] = \DB::table(config('rag.table_prefix', 'rag_') . 'processing_jobs')
                                                             ->where('status', 'failed')
                                                             ->where('created_at', '<', $cutoff)
                                                             ->delete();
            }
        }
        
        foreach ($cleanupStats as $type => $count) {
            if ($count > 0) {
                $this->line("Deleted {$count} {$type}");
            }
        }
    }

    protected function displayOptimizationStats(): void
    {
        $this->comment('Current knowledge base statistics:');
        
        $stats = [
            'Total Documents' => RagKnowledgeDocument::count(),
            'Processed Documents' => RagKnowledgeDocument::completed()->count(),
            'Total Chunks' => RagKnowledgeChunk::count(),
            'Synced Vectors' => RagKnowledgeChunk::synced()->count(),
            'Pending Jobs' => \DB::table(config('rag.table_prefix', 'rag_') . 'processing_jobs')
                                 ->whereIn('status', ['queued', 'processing'])
                                 ->count(),
        ];
        
        foreach ($stats as $label => $value) {
            $this->line("{$label}: {$value}");
        }
        
        // Vector database stats
        try {
            $vectorStats = $this->vectorService->getIndexStats();
            $this->line("Vector Database Vectors: {$vectorStats['total_vectors']}");
            $this->line("Vector Dimensions: {$vectorStats['dimensions']}");
        } catch (\Exception $e) {
            $this->warn("Could not retrieve vector database stats: {$e->getMessage()}");
        }
    }
}