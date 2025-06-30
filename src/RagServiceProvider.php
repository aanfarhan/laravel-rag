<?php

namespace Omniglies\LaravelRag;

use Illuminate\Support\ServiceProvider;
use Omniglies\LaravelRag\Console\Commands\InstallCommand;
use Omniglies\LaravelRag\Console\Commands\IngestCommand;
use Omniglies\LaravelRag\Console\Commands\ClearCommand;
use Omniglies\LaravelRag\Console\Commands\OptimizeCommand;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\ExternalProcessingService;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface;
use Omniglies\LaravelRag\Services\AiProviders\OpenAiProvider;
use Omniglies\LaravelRag\Services\AiProviders\AnthropicProvider;

class RagServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rag.php', 'rag');

        $this->app->singleton(RagService::class);
        $this->app->singleton(ExternalProcessingService::class);
        $this->app->singleton(VectorSearchService::class);
        $this->app->singleton(EmbeddingService::class);

        $this->app->bind(AiProviderInterface::class, function ($app) {
            $provider = config('rag.ai_provider', 'openai');
            
            return match ($provider) {
                'openai' => $app->make(OpenAiProvider::class),
                'anthropic' => $app->make(AnthropicProvider::class),
                default => $app->make(OpenAiProvider::class),
            };
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/rag.php' => config_path('rag.php'),
            ], 'rag-config');

            $this->publishes([
                __DIR__ . '/Database/Migrations/' => database_path('migrations'),
            ], 'rag-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/rag'),
            ], 'rag-views');

            $this->publishes([
                __DIR__ . '/../resources/assets' => public_path('vendor/rag'),
            ], 'rag-assets');

            $this->commands([
                InstallCommand::class,
                IngestCommand::class,
                ClearCommand::class,
                OptimizeCommand::class,
                \Omniglies\LaravelRag\Console\Commands\StatusCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'rag');
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
    }
}