<?php

namespace Omniglies\LaravelRag\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class InstallCommand extends Command
{
    protected $signature = 'rag:install 
                           {--force : Overwrite existing files}
                           {--skip-migrations : Skip running migrations}
                           {--skip-assets : Skip publishing assets}';

    protected $description = 'Install the Laravel RAG package';

    public function handle(): int
    {
        $this->info('Installing Laravel RAG package...');

        // Publish configuration
        $this->info('Publishing configuration...');
        $this->publishConfig();

        // Publish migrations
        if (!$this->option('skip-migrations')) {
            $this->info('Publishing and running migrations...');
            $this->publishMigrations();
            $this->runMigrations();
        }

        // Publish views
        $this->info('Publishing views...');
        $this->publishViews();

        // Publish assets
        if (!$this->option('skip-assets')) {
            $this->info('Publishing assets...');
            $this->publishAssets();
        }

        $this->info('✅ Laravel RAG package installed successfully!');
        $this->newLine();
        
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $force = $this->option('force');
        
        $params = ['--tag' => 'rag-config'];
        if ($force) {
            $params['--force'] = true;
        }

        Artisan::call('vendor:publish', $params);
        
        $this->line('Configuration published to config/rag.php');
    }

    protected function publishMigrations(): void
    {
        $force = $this->option('force');
        
        $params = ['--tag' => 'rag-migrations'];
        if ($force) {
            $params['--force'] = true;
        }

        Artisan::call('vendor:publish', $params);
        
        $this->line('Migrations published to database/migrations/');
    }

    protected function runMigrations(): void
    {
        if ($this->confirm('Run migrations now?', true)) {
            Artisan::call('migrate', ['--force' => true]);
            $this->line('Migrations completed successfully');
        } else {
            $this->warn('Remember to run migrations later with: php artisan migrate');
        }
    }

    protected function publishViews(): void
    {
        $force = $this->option('force');
        
        $params = ['--tag' => 'rag-views'];
        if ($force) {
            $params['--force'] = true;
        }

        Artisan::call('vendor:publish', $params);
        
        $this->line('Views published to resources/views/vendor/rag/');
    }

    protected function publishAssets(): void
    {
        $force = $this->option('force');
        
        $params = ['--tag' => 'rag-assets'];
        if ($force) {
            $params['--force'] = true;
        }

        Artisan::call('vendor:publish', $params);
        
        $this->line('Assets published to public/vendor/rag/');
    }

    protected function displayNextSteps(): void
    {
        $this->comment('Next steps:');
        $this->line('1. Configure your environment variables in .env:');
        $this->line('   - RAG_AI_PROVIDER (openai or anthropic)');
        $this->line('   - OPENAI_API_KEY or ANTHROPIC_API_KEY');
        $this->line('   - RAG_VECTOR_PROVIDER (pinecone, weaviate, or qdrant)');
        $this->line('   - Vector database credentials');
        $this->line('   - RAG_PROCESSING_API_URL (optional, for external processing)');
        $this->newLine();
        
        $this->line('2. Test your configuration:');
        $this->line('   php artisan rag:test-config');
        $this->newLine();
        
        $this->line('3. Start using RAG:');
        $this->line('   - Visit /rag in your browser for the web interface');
        $this->line('   - Use the API endpoints at /api/rag/*');
        $this->line('   - Ingest documents with: php artisan rag:ingest {file}');
        $this->newLine();
        
        $this->line('4. Queue setup (recommended for production):');
        $this->line('   - Configure a queue driver (redis, database, etc.)');
        $this->line('   - Run queue workers: php artisan queue:work --queue=rag');
        $this->newLine();
        
        $this->info('📖 Documentation: https://github.com/omniglies/laravel-rag');
    }
}