<?php

namespace Omniglies\LaravelRag\Tests\Unit;

use Omniglies\LaravelRag\Tests\TestCase;
use Mockery as M;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Exceptions\RagException;

class VectorSearchServiceTest extends TestCase
{
    protected $vectorService;
    protected $mockClient;
    protected $mockEmbeddingService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the configuration
        config([
            'rag.vector_database.provider' => 'pinecone',
            'rag.vector_database.providers.pinecone' => [
                'api_key' => 'test-key',
                'environment' => 'test-env',
                'index_name' => 'test-index',
                'project_id' => 'test-project',
                'dimensions' => 1536,
            ],
            'rag.search.default_limit' => 3,
            'rag.search.similarity_threshold' => 0.7,
        ]);

        $this->mockClient = M::mock(Client::class);
        $this->mockEmbeddingService = M::mock(EmbeddingService::class);
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_can_search_similar_vectors()
    {
        // Arrange
        $query = 'test query';
        $mockEmbedding = array_fill(0, 1536, 0.1);
        $mockSearchResults = [
            [
                'id' => 'chunk_1',
                'score' => 0.9,
                'metadata' => [
                    'chunk_id' => 1,
                    'document_id' => 1,
                    'content' => 'Test content 1',
                    'document_title' => 'Test Doc 1'
                ]
            ],
            [
                'id' => 'chunk_2',
                'score' => 0.8,
                'metadata' => [
                    'chunk_id' => 2,
                    'document_id' => 2,
                    'content' => 'Test content 2',
                    'document_title' => 'Test Doc 2'
                ]
            ]
        ];

        $this->mockEmbeddingService
            ->shouldReceive('generateEmbedding')
            ->with($query)
            ->once()
            ->andReturn($mockEmbedding);

        $mockResponse = new Response(200, [], json_encode([
            'matches' => $mockSearchResults
        ]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('/query', M::any())
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $results = $service->searchSimilar($query);

        // Assert
        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['id']);
        $this->assertEquals(0.9, $results[0]['similarity_score']);
        $this->assertEquals('Test content 1', $results[0]['content']);
    }

    public function test_can_upsert_vector_for_chunk()
    {
        // Arrange
        $mockDocument = M::mock(RagKnowledgeDocument::class);
        $mockDocument->shouldReceive('getAttribute')->with('title')->andReturn('Test Document');

        $mockChunk = M::mock(RagKnowledgeChunk::class);
        $mockChunk->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $mockChunk->shouldReceive('getAttribute')->with('content')->andReturn('Test content');
        $mockChunk->shouldReceive('getAttribute')->with('document_id')->andReturn(1);
        $mockChunk->shouldReceive('getAttribute')->with('chunk_hash')->andReturn('test-hash');
        $mockChunk->shouldReceive('getAttribute')->with('document')->andReturn($mockDocument);
        $mockChunk->document = $mockDocument;

        $mockEmbedding = array_fill(0, 1536, 0.1);

        $this->mockEmbeddingService
            ->shouldReceive('generateEmbedding')
            ->with('Test content')
            ->once()
            ->andReturn($mockEmbedding);

        $this->mockEmbeddingService
            ->shouldReceive('getModel')
            ->andReturn('text-embedding-ada-002');

        $this->mockEmbeddingService
            ->shouldReceive('getDimensions')
            ->andReturn(1536);

        $mockChunk
            ->shouldReceive('update')
            ->once()
            ->with(M::type('array'));

        $mockResponse = new Response(200, [], json_encode([
            'upsertedCount' => 1
        ]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('/vectors/upsert', M::any())
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $result = $service->upsertVector($mockChunk);

        // Assert
        $this->assertTrue($result);
    }

    public function test_can_delete_vector()
    {
        // Arrange
        $vectorId = 'test-vector-id';

        $mockResponse = new Response(200, [], json_encode(['success' => true]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('/vectors/delete', M::any())
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $result = $service->deleteVector($vectorId);

        // Assert
        $this->assertTrue($result);
    }

    public function test_can_delete_multiple_vectors()
    {
        // Arrange
        $vectorIds = ['vector-1', 'vector-2', 'vector-3'];

        $mockResponse = new Response(200, [], json_encode(['success' => true]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('/vectors/delete', M::any())
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $result = $service->deleteVectors($vectorIds);

        // Assert
        $this->assertTrue($result);
    }

    public function test_can_get_index_stats()
    {
        // Arrange
        $mockStats = [
            'totalVectorCount' => 1000,
            'dimension' => 1536,
            'indexFullness' => 0.1
        ];

        $mockResponse = new Response(200, [], json_encode($mockStats));

        $this->mockClient
            ->shouldReceive('post')
            ->with('/describe_index_stats')
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $stats = $service->getIndexStats();

        // Assert
        $this->assertIsArray($stats);
        $this->assertEquals(1000, $stats['total_vectors']);
        $this->assertEquals(1536, $stats['dimensions']);
    }

    public function test_search_filters_by_similarity_threshold()
    {
        // Arrange
        $query = 'test query';
        $mockEmbedding = array_fill(0, 1536, 0.1);
        $mockSearchResults = [
            [
                'id' => 'chunk_1',
                'score' => 0.9, // Above threshold
                'metadata' => [
                    'chunk_id' => 1,
                    'content' => 'Test content 1'
                ]
            ],
            [
                'id' => 'chunk_2',
                'score' => 0.5, // Below threshold (0.7)
                'metadata' => [
                    'chunk_id' => 2,
                    'content' => 'Test content 2'
                ]
            ]
        ];

        $this->mockEmbeddingService
            ->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn($mockEmbedding);

        $mockResponse = new Response(200, [], json_encode([
            'matches' => $mockSearchResults
        ]));

        $this->mockClient
            ->shouldReceive('post')
            ->once()
            ->andReturn($mockResponse);

        $service = $this->createVectorServiceWithMocks();

        // Act
        $results = $service->searchSimilar($query);

        // Assert
        $this->assertCount(1, $results); // Only one result above threshold
        $this->assertEquals(0.9, $results[0]['similarity_score']);
    }

    public function test_upsert_vector_returns_false_for_empty_content()
    {
        // Arrange
        $mockChunk = M::mock(RagKnowledgeChunk::class);
        $mockChunk->shouldReceive('getAttribute')->with('content')->andReturn('');

        $service = $this->createVectorServiceWithMocks();

        // Act
        $result = $service->upsertVector($mockChunk);

        // Assert
        $this->assertFalse($result);
    }

    public function test_throws_exception_for_missing_configuration()
    {
        // Arrange
        config(['rag.vector_database.providers.pinecone' => []]);

        // Act & Assert
        $this->expectException(RagException::class);
        $this->expectExceptionMessage('Missing required configuration');
        new VectorSearchService($this->mockEmbeddingService);
    }

    public function test_format_search_result_handles_missing_metadata()
    {
        // Arrange
        $match = [
            'id' => 'test-id',
            'score' => 0.8,
            'metadata' => null
        ];

        $service = $this->createVectorServiceWithMocks();

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatSearchResult');
        $method->setAccessible(true);

        // Act
        $result = $method->invoke($service, $match);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('test-id', $result['id']);
        $this->assertEquals(0.8, $result['similarity_score']);
        $this->assertEquals('', $result['content']);
    }

    protected function createVectorServiceWithMocks(): VectorSearchService
    {
        $service = new VectorSearchService($this->mockEmbeddingService);
        
        // Use reflection to replace the client
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $this->mockClient);
        
        return $service;
    }
}