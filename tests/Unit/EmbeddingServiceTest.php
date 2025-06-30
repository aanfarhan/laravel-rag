<?php

namespace Omniglies\LaravelRag\Tests\Unit;

use Omniglies\LaravelRag\Tests\TestCase;
use Mockery as M;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Omniglies\LaravelRag\Services\EmbeddingService;
use Omniglies\LaravelRag\Exceptions\RagException;

class EmbeddingServiceTest extends TestCase
{
    protected $embeddingService;
    protected $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the configuration
        config([
            'rag.embedding.provider' => 'openai',
            'rag.embedding.providers.openai.api_key' => 'test-key',
            'rag.embedding.providers.openai.model' => 'text-embedding-ada-002',
            'rag.embedding.providers.openai.dimensions' => 1536,
        ]);

        $this->mockClient = M::mock(Client::class);
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_can_generate_single_embedding()
    {
        // Arrange
        $text = 'This is test text';
        $mockEmbedding = array_fill(0, 1536, 0.1); // Mock 1536-dimensional embedding
        
        $mockResponse = new Response(200, [], json_encode([
            'data' => [
                ['embedding' => $mockEmbedding]
            ],
            'usage' => ['total_tokens' => 10]
        ]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('embeddings', M::any())
            ->once()
            ->andReturn($mockResponse);

        // Create service with mocked client
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act
        $result = $service->generateEmbedding($text);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1536, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    public function test_can_generate_batch_embeddings()
    {
        // Arrange
        $texts = ['Text 1', 'Text 2'];
        $mockEmbeddings = [
            array_fill(0, 1536, 0.1),
            array_fill(0, 1536, 0.2)
        ];
        
        $mockResponse = new Response(200, [], json_encode([
            'data' => [
                ['embedding' => $mockEmbeddings[0]],
                ['embedding' => $mockEmbeddings[1]]
            ],
            'usage' => ['total_tokens' => 20]
        ]));

        $this->mockClient
            ->shouldReceive('post')
            ->with('embeddings', M::any())
            ->once()
            ->andReturn($mockResponse);

        // Create service with mocked client
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act
        $results = $service->generateBatchEmbeddings($texts);

        // Assert
        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals($mockEmbeddings[0], $results[0]);
        $this->assertEquals($mockEmbeddings[1], $results[1]);
    }

    public function test_throws_exception_for_empty_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act & Assert
        $this->expectException(RagException::class);
        $this->expectExceptionMessage('Empty text provided');
        $service->generateEmbedding('');
    }

    public function test_throws_exception_for_whitespace_only_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act & Assert
        $this->expectException(RagException::class);
        $this->expectExceptionMessage('Empty text provided');
        $service->generateEmbedding('   ');
    }

    public function test_get_model_returns_configured_model()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act
        $model = $service->getModel();

        // Assert
        $this->assertEquals('text-embedding-ada-002', $model);
    }

    public function test_get_dimensions_returns_configured_dimensions()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act
        $dimensions = $service->getDimensions();

        // Assert
        $this->assertEquals(1536, $dimensions);
    }

    public function test_get_provider_returns_configured_provider()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();

        // Act
        $provider = $service->getProvider();

        // Assert
        $this->assertEquals('openai', $provider);
    }

    public function test_validate_text_length_returns_true_for_short_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();
        $shortText = 'This is a short text';

        // Act
        $isValid = $service->validateTextLength($shortText);

        // Assert
        $this->assertTrue($isValid);
    }

    public function test_validate_text_length_returns_false_for_very_long_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();
        $longText = str_repeat('word ', 10000); // Very long text

        // Act
        $isValid = $service->validateTextLength($longText);

        // Assert
        $this->assertFalse($isValid);
    }

    public function test_truncate_text_shortens_long_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();
        $longText = str_repeat('word ', 10000);
        $originalLength = strlen($longText);

        // Act
        $truncatedText = $service->truncateText($longText);

        // Assert
        $this->assertLessThan($originalLength, strlen($truncatedText));
        $this->assertTrue($service->validateTextLength($truncatedText));
    }

    public function test_truncate_text_returns_unchanged_for_short_text()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();
        $shortText = 'This is a short text';

        // Act
        $result = $service->truncateText($shortText);

        // Assert
        $this->assertEquals($shortText, $result);
    }

    public function test_estimate_token_count_returns_reasonable_estimate()
    {
        // Arrange
        $service = $this->createEmbeddingServiceWithMockClient();
        $text = 'This is a test text with multiple words';

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('estimateTokenCount');
        $method->setAccessible(true);

        // Act
        $tokenCount = $method->invoke($service, $text);

        // Assert
        $this->assertIsInt($tokenCount);
        $this->assertGreaterThan(0, $tokenCount);
        $this->assertLessThan(strlen($text), $tokenCount); // Should be less than character count
    }

    protected function createEmbeddingServiceWithMockClient(): EmbeddingService
    {
        $service = new EmbeddingService();
        
        // Use reflection to replace the client
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $this->mockClient);
        
        return $service;
    }
}