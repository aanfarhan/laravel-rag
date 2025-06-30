<?php

namespace Omniglies\LaravelRag\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Omniglies\LaravelRag\RagServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('migrate', ['--database' => 'testing']);
    }

    protected function getPackageProviders($app)
    {
        return [
            RagServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Setup RAG package configuration
        $app['config']->set('rag.ai_provider', 'openai');
        $app['config']->set('rag.providers.openai.api_key', 'test-key');
        $app['config']->set('rag.providers.openai.model', 'gpt-3.5-turbo');
        $app['config']->set('rag.embedding.provider', 'openai');
        $app['config']->set('rag.embedding.providers.openai.api_key', 'test-key');
        $app['config']->set('rag.vector_database.provider', 'pinecone');
        $app['config']->set('rag.vector_database.providers.pinecone.api_key', 'test-key');
        $app['config']->set('rag.vector_database.providers.pinecone.environment', 'test');
        $app['config']->set('rag.vector_database.providers.pinecone.index_name', 'test-index');
        $app['config']->set('rag.vector_database.providers.qdrant.url', 'http://localhost:6333');
        $app['config']->set('rag.vector_database.providers.qdrant.api_key', 'test-key');
        $app['config']->set('rag.vector_database.providers.qdrant.collection_name', 'test-collection');
        $app['config']->set('rag.vector_database.providers.qdrant.project_id', 'test-project');
        $app['config']->set('rag.external_processing.enabled', false);
        $app['config']->set('rag.external_processing.api_url', 'https://test-api.com');
        $app['config']->set('rag.external_processing.api_key', 'test-key');
        $app['config']->set('rag.external_processing.webhook_secret', 'test-secret');
        $app['config']->set('rag.routes.enabled', true);
        $app['config']->set('rag.queue.connection', 'sync');
        
        // Setup queue configuration
        $app['config']->set('queue.default', 'sync');
    }
}