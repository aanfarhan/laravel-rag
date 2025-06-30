<?php

namespace Omniglies\LaravelRag\Tests\Feature;

use Omniglies\LaravelRag\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;
use Omniglies\LaravelRag\RagServiceProvider;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('rag.routes.enabled', true);
        $app['config']->set('rag.ai_provider', 'openai');
    }

    public function test_can_access_chat_interface()
    {
        // Act
        $response = $this->get(route('rag.chat'));

        // Assert
        $response->assertStatus(200);
        $response->assertViewIs('rag::chat.index');
    }

    public function test_can_ask_question_via_api()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create(['title' => 'Test Document']);
        $chunk = RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'This is test content about artificial intelligence.'
        ]);

        // Mock the RAG service
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->with('What is AI?')
                       ->once()
                       ->andReturn([
                           'answer' => 'AI stands for artificial intelligence, which is mentioned in the uploaded documents.',
                           'sources' => [
                               [
                                   'document_id' => $document->id,
                                   'document_title' => $document->title,
                                   'chunk_id' => $chunk->id,
                                   'similarity_score' => 0.9
                               ]
                           ],
                           'confidence' => 0.9,
                           'usage' => ['total_tokens' => 150, 'cost' => 0.003]
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'What is AI?'
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'answer' => 'AI stands for artificial intelligence, which is mentioned in the uploaded documents.',
            'question' => 'What is AI?'
        ]);
        $response->assertJsonStructure([
            'answer',
            'sources',
            'confidence',
            'usage',
            'question',
            'success'
        ]);
    }

    public function test_validates_question_input()
    {
        // Act
        $response = $this->postJson(route('rag.ask'), []);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    public function test_validates_question_length()
    {
        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'Hi' // Too short
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    public function test_validates_question_max_length()
    {
        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => str_repeat('a', 1001) // Too long
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    public function test_can_ask_question_via_web_interface()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => 'This is a test answer.',
                           'sources' => [],
                           'confidence' => 0.8,
                           'usage' => ['total_tokens' => 100]
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->post(route('rag.ask'), [
            'question' => 'What is this about?'
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertViewIs('rag::chat.response');
        $response->assertViewHas('response');
    }

    public function test_handles_no_relevant_chunks_gracefully()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => "I don't have relevant information to answer your question.",
                           'sources' => [],
                           'confidence' => 0.0,
                           'usage' => []
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'What is quantum computing?'
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'answer' => "I don't have relevant information to answer your question.",
            'confidence' => 0.0
        ]);
    }

    public function test_can_handle_service_errors_gracefully()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andThrow(new \Exception('Service temporarily unavailable'));

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'What is this about?'
        ]);

        // Assert
        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
            'error' => 'Service temporarily unavailable'
        ]);
    }

    public function test_can_specify_context_limit()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('searchRelevantChunks')
                       ->with('test question', 5)
                       ->once()
                       ->andReturn([]);
        
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => "I don't have relevant information to answer your question.",
                           'sources' => [],
                           'confidence' => 0.0
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'test question',
            'context_limit' => 5
        ]);

        // Assert
        $response->assertStatus(200);
    }

    public function test_can_specify_temperature_parameter()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => 'Test answer',
                           'sources' => [],
                           'confidence' => 0.8
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'test question',
            'temperature' => 0.3
        ]);

        // Assert
        $response->assertStatus(200);
    }

    public function test_validates_temperature_range()
    {
        // Test minimum
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'valid question',
            'temperature' => -0.1
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['temperature']);

        // Test maximum
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'valid question',
            'temperature' => 2.1
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['temperature']);
    }

    public function test_can_specify_model_parameter()
    {
        // Arrange
        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => 'Test answer',
                           'sources' => [],
                           'confidence' => 0.8
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'test question',
            'model' => 'gpt-4'
        ]);

        // Assert
        $response->assertStatus(200);
    }

    public function test_response_includes_sources_with_similarity_scores()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();
        $chunk = RagKnowledgeChunk::factory()->create(['document_id' => $document->id]);

        $mockRagService = M::mock(RagService::class);
        $mockRagService->shouldReceive('askWithContext')
                       ->once()
                       ->andReturn([
                           'answer' => 'Test answer',
                           'sources' => [
                               [
                                   'document_id' => $document->id,
                                   'document_title' => $document->title,
                                   'chunk_id' => $chunk->id,
                                   'similarity_score' => 0.95
                               ]
                           ],
                           'confidence' => 0.95
                       ]);

        $this->app->instance(RagService::class, $mockRagService);

        // Act
        $response = $this->postJson(route('rag.ask'), [
            'question' => 'test question'
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'sources' => [
                '*' => [
                    'document_id',
                    'document_title',
                    'chunk_id',
                    'similarity_score'
                ]
            ]
        ]);
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }
}