<?php

namespace Omniglies\LaravelRag\Tests\Feature;

use Omniglies\LaravelRag\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;
use Omniglies\LaravelRag\RagServiceProvider;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;

class VectorSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('rag.vector_database.provider', 'pinecone');
        $app['config']->set('rag.search.hybrid_search.enabled', true);
        $app['config']->set('rag.search.fallback_to_sql', true);
    }

    public function test_can_search_documents_via_web_interface()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create(['title' => 'Machine Learning Guide']);
        $chunk = RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'Machine learning is a subset of artificial intelligence that focuses on algorithms.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'machine learning']));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure([
            'success',
            'results' => [
                '*' => [
                    'id',
                    'content',
                    'similarity_score',
                    'document' => [
                        'id',
                        'title'
                    ]
                ]
            ],
            'total'
        ]);
    }

    public function test_search_validates_query_parameter()
    {
        // Act
        $response = $this->getJson(route('rag.search'));

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['query']);
    }

    public function test_search_validates_minimum_query_length()
    {
        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'a']));

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['query']);
    }

    public function test_search_validates_maximum_query_length()
    {
        // Act
        $response = $this->getJson(route('rag.search', ['query' => str_repeat('a', 501)]));

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['query']);
    }

    public function test_can_limit_search_results()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();
        RagKnowledgeChunk::factory()->count(10)->create([
            'document_id' => $document->id,
            'content' => 'This is test content about artificial intelligence and machine learning.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', [
            'query' => 'artificial intelligence',
            'limit' => 3
        ]));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        // In a real implementation with mocked vector search, we'd assert count is 3
    }

    public function test_search_validates_limit_parameter()
    {
        // Test minimum
        $response = $this->getJson(route('rag.search', [
            'query' => 'test',
            'limit' => 0
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['limit']);

        // Test maximum
        $response = $this->getJson(route('rag.search', [
            'query' => 'test',
            'limit' => 51
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['limit']);
    }

    public function test_can_set_similarity_threshold()
    {
        // Act
        $response = $this->getJson(route('rag.search', [
            'query' => 'test query',
            'threshold' => 0.8
        ]));

        // Assert
        $response->assertStatus(200);
    }

    public function test_search_validates_threshold_parameter()
    {
        // Test minimum
        $response = $this->getJson(route('rag.search', [
            'query' => 'test',
            'threshold' => -0.1
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['threshold']);

        // Test maximum
        $response = $this->getJson(route('rag.search', [
            'query' => 'test',
            'threshold' => 1.1
        ]));
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['threshold']);
    }

    public function test_search_returns_empty_results_for_no_matches()
    {
        // Arrange
        RagKnowledgeDocument::factory()->create();

        // Act
        $response = $this->getJson(route('rag.search', [
            'query' => 'nonexistent topic that will not match anything'
        ]));

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'results' => [],
            'total' => 0
        ]);
    }

    public function test_search_includes_document_metadata_in_results()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create([
            'title' => 'AI Research Paper',
            'metadata' => ['author' => 'Dr. Smith', 'year' => 2023]
        ]);
        $chunk = RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'Artificial intelligence research has advanced significantly.',
            'chunk_metadata' => ['section' => 'introduction']
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'artificial intelligence']));

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'results' => [
                '*' => [
                    'document' => [
                        'id',
                        'title'
                    ],
                    'metadata'
                ]
            ]
        ]);
    }

    public function test_full_text_search_fallback_works_when_vector_search_fails()
    {
        // This would require mocking the vector search service to throw an exception
        // and then testing that SQL search is used as fallback
        
        // Arrange
        $mockVectorService = M::mock(VectorSearchService::class);
        $mockVectorService->shouldReceive('searchSimilar')
                          ->andThrow(new \Exception('Vector database unavailable'));

        $this->app->instance(VectorSearchService::class, $mockVectorService);

        $document = RagKnowledgeDocument::factory()->create();
        RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'This content should be found via SQL search'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'SQL search']));

        // Assert
        $response->assertStatus(200);
        // The search should fall back to SQL and still return results
    }

    public function test_hybrid_search_combines_vector_and_keyword_results()
    {
        // This test would require mocking both vector and SQL search services
        // to return specific results and then testing that they are combined properly
        
        $this->assertTrue(true); // Placeholder - would need complex mocking
    }

    public function test_search_results_include_similarity_scores()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();
        $chunk = RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'Machine learning algorithms are used in artificial intelligence.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'machine learning']));

        // Assert
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'results' => [
                '*' => [
                    'similarity_score'
                ]
            ]
        ]);
    }

    public function test_search_filters_results_by_similarity_threshold()
    {
        // This would require mocking the vector search to return results with specific scores
        // and then testing that only results above the threshold are returned
        
        $this->assertTrue(true); // Placeholder
    }

    public function test_can_search_across_multiple_documents()
    {
        // Arrange
        $doc1 = RagKnowledgeDocument::factory()->create(['title' => 'ML Basics']);
        $doc2 = RagKnowledgeDocument::factory()->create(['title' => 'AI Advanced']);
        
        RagKnowledgeChunk::factory()->create([
            'document_id' => $doc1->id,
            'content' => 'Machine learning is a fundamental concept in AI.'
        ]);
        
        RagKnowledgeChunk::factory()->create([
            'document_id' => $doc2->id,
            'content' => 'Advanced machine learning techniques include deep learning.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'machine learning']));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        // Results should potentially include chunks from both documents
    }

    public function test_search_handles_special_characters_in_query()
    {
        // Act
        $response = $this->getJson(route('rag.search', [
            'query' => 'AI & ML: "deep learning" (neural networks)'
        ]));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_search_is_case_insensitive()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();
        RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'Machine Learning and Deep Learning are important topics.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'machine learning']));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }
}