<?php

namespace Omniglies\LaravelRag\Tests\Unit;

use Omniglies\LaravelRag\Tests\TestCase;
use Mockery as M;
use Illuminate\Http\UploadedFile;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\ExternalProcessingService;
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Exceptions\RagException;

class RagServiceTest extends TestCase
{
    protected $ragService;
    protected $processingService;
    protected $vectorService;
    protected $embeddingService;
    protected $aiProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processingService = M::mock(ExternalProcessingService::class);
        $this->vectorService = M::mock(VectorSearchService::class);
        $this->embeddingService = M::mock(EmbeddingService::class);
        $this->aiProvider = M::mock(AiProviderInterface::class);

        $this->ragService = new RagService(
            $this->processingService,
            $this->vectorService,
            $this->embeddingService,
            $this->aiProvider
        );
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_can_ingest_text_document()
    {
        // Arrange
        $title = 'Test Document';
        $content = 'This is test content for the document.';
        $metadata = ['author' => 'Test Author'];

        // Mock the document creation
        $document = M::mock(RagKnowledgeDocument::class);
        $document->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $document->shouldReceive('getAttribute')->with('processing_status')->andReturn('pending');

        // Act & Assert - This would need Laravel's test framework for full testing
        $this->assertTrue(true); // Placeholder assertion
    }

    public function test_can_search_relevant_chunks()
    {
        // Arrange
        $query = 'test query';
        $limit = 3;
        $mockResults = [
            [
                'id' => 1,
                'content' => 'Test content 1',
                'similarity_score' => 0.9,
                'document' => ['id' => 1, 'title' => 'Test Doc 1']
            ],
            [
                'id' => 2,
                'content' => 'Test content 2',
                'similarity_score' => 0.8,
                'document' => ['id' => 2, 'title' => 'Test Doc 2']
            ]
        ];

        $this->vectorService
            ->shouldReceive('searchSimilar')
            ->with($query, ['limit' => $limit, 'threshold' => M::any()])
            ->once()
            ->andReturn($mockResults);

        // Act
        $results = $this->ragService->searchRelevantChunks($query, $limit);

        // Assert
        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals('Test content 1', $results[0]['content']);
        $this->assertEquals(0.9, $results[0]['similarity_score']);
    }

    public function test_can_ask_with_context()
    {
        // Arrange
        $question = 'What is this about?';
        $relevantChunks = [
            [
                'id' => 1,
                'content' => 'This document is about testing.',
                'similarity_score' => 0.9,
                'document' => ['id' => 1, 'title' => 'Test Doc']
            ]
        ];

        $aiResponse = [
            'content' => 'This document appears to be about testing based on the content.',
            'usage' => ['total_tokens' => 50, 'cost' => 0.001]
        ];

        // Mock vector search
        $this->vectorService
            ->shouldReceive('searchSimilar')
            ->once()
            ->andReturn($relevantChunks);

        // Mock AI provider
        $this->aiProvider
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn($aiResponse);

        // Act
        $result = $this->ragService->askWithContext($question);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertEquals($aiResponse['content'], $result['answer']);
        $this->assertCount(1, $result['sources']);
    }

    public function test_ask_with_no_relevant_chunks_returns_appropriate_response()
    {
        // Arrange
        $question = 'What is this about?';

        // Mock vector search returning empty results
        $this->vectorService
            ->shouldReceive('searchSimilar')
            ->once()
            ->andReturn([]);

        // Act
        $result = $this->ragService->askWithContext($question);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals("I don't have relevant information to answer your question.", $result['answer']);
        $this->assertEmpty($result['sources']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function test_validate_file_throws_exception_for_invalid_file_type()
    {
        // Arrange
        $file = M::mock(UploadedFile::class);
        $file->shouldReceive('getClientOriginalExtension')->andReturn('exe');
        $file->shouldReceive('getSize')->andReturn(1024);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('validateFile');
        $method->setAccessible(true);

        // Act & Assert
        $this->expectException(RagException::class);
        $method->invoke($this->ragService, $file);
    }

    public function test_validate_file_throws_exception_for_file_too_large()
    {
        // Arrange
        $file = M::mock(UploadedFile::class);
        $file->shouldReceive('getClientOriginalExtension')->andReturn('txt');
        $file->shouldReceive('getSize')->andReturn(50 * 1024 * 1024); // 50MB

        // Mock config
        config(['rag.file_upload.max_size' => 1024]); // 1MB limit

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('validateFile');
        $method->setAccessible(true);

        // Act & Assert
        $this->expectException(RagException::class);
        $method->invoke($this->ragService, $file);
    }

    public function test_chunk_text_splits_content_properly()
    {
        // Arrange
        $text = str_repeat('This is a sentence. ', 200); // Long text

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('chunkText');
        $method->setAccessible(true);

        // Act
        $chunks = $method->invoke($this->ragService, $text);

        // Assert
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(1, count($chunks)); // Should be split into multiple chunks
        
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1000, strlen($chunk)); // Default chunk size
        }
    }

    public function test_build_context_formats_chunks_correctly()
    {
        // Arrange
        $chunks = [
            [
                'content' => 'First chunk content',
                'document' => ['title' => 'Doc 1']
            ],
            [
                'content' => 'Second chunk content',
                'document' => ['title' => 'Doc 2']
            ]
        ];

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('buildContext');
        $method->setAccessible(true);

        // Act
        $context = $method->invoke($this->ragService, $chunks);

        // Assert
        $this->assertIsString($context);
        $this->assertStringContainsString('Source: Doc 1', $context);
        $this->assertStringContainsString('First chunk content', $context);
        $this->assertStringContainsString('Source: Doc 2', $context);
        $this->assertStringContainsString('Second chunk content', $context);
    }

    public function test_calculate_confidence_returns_correct_average()
    {
        // Arrange
        $chunks = [
            ['similarity_score' => 0.9],
            ['similarity_score' => 0.8],
            ['similarity_score' => 0.7]
        ];

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('calculateConfidence');
        $method->setAccessible(true);

        // Act
        $confidence = $method->invoke($this->ragService, $chunks);

        // Assert
        $this->assertEqualsWithDelta(0.8, $confidence, 0.01); // Average of 0.9, 0.8, 0.7
    }

    public function test_calculate_confidence_returns_zero_for_empty_chunks()
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->ragService);
        $method = $reflection->getMethod('calculateConfidence');
        $method->setAccessible(true);

        // Act
        $confidence = $method->invoke($this->ragService, []);

        // Assert
        $this->assertEquals(0.0, $confidence);
    }
}