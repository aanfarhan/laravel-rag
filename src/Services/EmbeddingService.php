<?php

namespace Omniglies\LaravelRag\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\RagException;

class EmbeddingService
{
    protected Client $client;
    protected string $provider;
    protected array $config;

    public function __construct()
    {
        $this->provider = config('rag.embedding.provider', 'openai');
        $this->config = config("rag.embedding.providers.{$this->provider}", []);
        
        if (empty($this->config)) {
            throw RagException::configurationMissing("embedding.providers.{$this->provider}");
        }

        $this->initializeClient();
    }

    public function generateEmbedding(string $text): array
    {
        if (empty(trim($text))) {
            throw RagException::embeddingFailed('Empty text provided');
        }

        $cacheKey = 'rag:embedding:' . md5($text . $this->provider . $this->getModel());
        
        if (config('rag.cache.enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $embedding = $this->performEmbedding($text);
            
            if (config('rag.cache.enabled', true)) {
                $ttl = config('rag.cache.embeddings_ttl', 86400);
                Cache::put($cacheKey, $embedding, $ttl);
            }

            return $embedding;

        } catch (\Exception $e) {
            Log::error('Embedding generation failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);
            
            throw RagException::embeddingFailed($e->getMessage());
        }
    }

    public function generateBatchEmbeddings(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $embeddings = [];
        $uncachedTexts = [];
        $uncachedIndexes = [];

        if (config('rag.cache.enabled', true)) {
            foreach ($texts as $index => $text) {
                $cacheKey = 'rag:embedding:' . md5($text . $this->provider . $this->getModel());
                $cached = Cache::get($cacheKey);
                
                if ($cached !== null) {
                    $embeddings[$index] = $cached;
                } else {
                    $uncachedTexts[] = $text;
                    $uncachedIndexes[] = $index;
                }
            }
        } else {
            $uncachedTexts = $texts;
            $uncachedIndexes = array_keys($texts);
        }

        if (!empty($uncachedTexts)) {
            try {
                $newEmbeddings = $this->performBatchEmbedding($uncachedTexts);
                
                foreach ($newEmbeddings as $i => $embedding) {
                    $originalIndex = $uncachedIndexes[$i];
                    $embeddings[$originalIndex] = $embedding;
                    
                    if (config('rag.cache.enabled', true)) {
                        $cacheKey = 'rag:embedding:' . md5($uncachedTexts[$i] . $this->provider . $this->getModel());
                        $ttl = config('rag.cache.embeddings_ttl', 86400);
                        Cache::put($cacheKey, $embedding, $ttl);
                    }
                }
                
            } catch (\Exception $e) {
                Log::error('Batch embedding generation failed', [
                    'provider' => $this->provider,
                    'error' => $e->getMessage(),
                    'batch_size' => count($uncachedTexts),
                ]);
                
                throw RagException::embeddingFailed($e->getMessage());
            }
        }

        ksort($embeddings);
        return array_values($embeddings);
    }

    public function getModel(): string
    {
        return match ($this->provider) {
            'openai' => $this->config['model'] ?? 'text-embedding-ada-002',
            'cohere' => $this->config['model'] ?? 'embed-english-v3.0',
            default => 'unknown',
        };
    }

    public function getDimensions(): int
    {
        return match ($this->provider) {
            'openai' => $this->config['dimensions'] ?? 1536,
            'cohere' => $this->config['dimensions'] ?? 1024,
            default => 0,
        };
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getMaxTokens(): int
    {
        return match ($this->provider) {
            'openai' => 8191,
            'cohere' => 2048,
            default => 1000,
        };
    }

    public function validateTextLength(string $text): bool
    {
        $tokenCount = $this->estimateTokenCount($text);
        return $tokenCount <= $this->getMaxTokens();
    }

    public function truncateText(string $text): string
    {
        $maxTokens = $this->getMaxTokens();
        $estimatedTokens = $this->estimateTokenCount($text);
        
        if ($estimatedTokens <= $maxTokens) {
            return $text;
        }

        $ratio = $maxTokens / $estimatedTokens;
        $truncatedLength = (int) (strlen($text) * $ratio * 0.9); // 90% safety margin
        
        return substr($text, 0, $truncatedLength);
    }

    protected function initializeClient(): void
    {
        switch ($this->provider) {
            case 'openai':
                $this->initializeOpenAiClient();
                break;
            case 'cohere':
                $this->initializeCohereClient();
                break;
            default:
                throw RagException::embeddingFailed("Unsupported embedding provider: {$this->provider}");
        }
    }

    protected function initializeOpenAiClient(): void
    {
        if (empty($this->config['api_key'] ?? config('rag.providers.openai.api_key'))) {
            throw RagException::configurationMissing('OpenAI API key');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? config('rag.providers.openai.api_key')),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    protected function initializeCohereClient(): void
    {
        if (empty($this->config['api_key'])) {
            throw RagException::configurationMissing('Cohere API key');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.cohere.ai/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    protected function performEmbedding(string $text): array
    {
        if (!$this->validateTextLength($text)) {
            $text = $this->truncateText($text);
        }

        switch ($this->provider) {
            case 'openai':
                return $this->generateOpenAiEmbedding($text);
            case 'cohere':
                return $this->generateCohereEmbedding($text);
            default:
                throw RagException::embeddingFailed("Embedding not implemented for provider: {$this->provider}");
        }
    }

    protected function performBatchEmbedding(array $texts): array
    {
        $validTexts = [];
        foreach ($texts as $text) {
            if (!$this->validateTextLength($text)) {
                $text = $this->truncateText($text);
            }
            $validTexts[] = $text;
        }

        switch ($this->provider) {
            case 'openai':
                return $this->generateOpenAiBatchEmbedding($validTexts);
            case 'cohere':
                return $this->generateCohereBatchEmbedding($validTexts);
            default:
                throw RagException::embeddingFailed("Batch embedding not implemented for provider: {$this->provider}");
        }
    }

    protected function generateOpenAiEmbedding(string $text): array
    {
        try {
            $response = $this->client->post('embeddings', [
                'json' => [
                    'input' => $text,
                    'model' => $this->getModel(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'][0]['embedding'])) {
                throw new \Exception('Invalid response format from OpenAI');
            }

            RagApiUsage::recordUsage(
                'openai',
                'embedding',
                $data['usage']['total_tokens'] ?? null,
                $this->calculateOpenAiCost($data['usage']['total_tokens'] ?? 0)
            );

            return $data['data'][0]['embedding'];

        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['error']['message'] ?? $errorMessage;
            }
            
            throw new \Exception("OpenAI embedding request failed: {$errorMessage}");
        }
    }

    protected function generateOpenAiBatchEmbedding(array $texts): array
    {
        try {
            $response = $this->client->post('embeddings', [
                'json' => [
                    'input' => $texts,
                    'model' => $this->getModel(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new \Exception('Invalid batch response format from OpenAI');
            }

            RagApiUsage::recordUsage(
                'openai',
                'embedding',
                $data['usage']['total_tokens'] ?? null,
                $this->calculateOpenAiCost($data['usage']['total_tokens'] ?? 0)
            );

            return array_map(function ($item) {
                return $item['embedding'];
            }, $data['data']);

        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['error']['message'] ?? $errorMessage;
            }
            
            throw new \Exception("OpenAI batch embedding request failed: {$errorMessage}");
        }
    }

    protected function generateCohereEmbedding(string $text): array
    {
        try {
            $response = $this->client->post('embed', [
                'json' => [
                    'texts' => [$text],
                    'model' => $this->getModel(),
                    'input_type' => 'search_document',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['embeddings'][0])) {
                throw new \Exception('Invalid response format from Cohere');
            }

            RagApiUsage::recordUsage(
                'cohere',
                'embedding',
                null,
                $this->calculateCohereCost(1)
            );

            return $data['embeddings'][0];

        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['message'] ?? $errorMessage;
            }
            
            throw new \Exception("Cohere embedding request failed: {$errorMessage}");
        }
    }

    protected function generateCohereBatchEmbedding(array $texts): array
    {
        try {
            $response = $this->client->post('embed', [
                'json' => [
                    'texts' => $texts,
                    'model' => $this->getModel(),
                    'input_type' => 'search_document',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
                throw new \Exception('Invalid batch response format from Cohere');
            }

            RagApiUsage::recordUsage(
                'cohere',
                'embedding',
                null,
                $this->calculateCohereCost(count($texts))
            );

            return $data['embeddings'];

        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['message'] ?? $errorMessage;
            }
            
            throw new \Exception("Cohere batch embedding request failed: {$errorMessage}");
        }
    }

    protected function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    protected function calculateOpenAiCost(int $tokens): float
    {
        return $tokens * 0.0001 / 1000;
    }

    protected function calculateCohereCost(int $requests): float
    {
        return $requests * 0.0001;
    }

    public function getUsageStats(int $days = 30): array
    {
        $stats = RagApiUsage::byProvider($this->provider)
                           ->byOperation('embedding')
                           ->recent($days)
                           ->selectRaw('
                               COUNT(*) as total_requests,
                               SUM(tokens_used) as total_tokens,
                               SUM(cost_usd) as total_cost,
                               AVG(cost_usd) as avg_cost_per_request
                           ')
                           ->first();

        return [
            'provider' => $this->provider,
            'model' => $this->getModel(),
            'dimensions' => $this->getDimensions(),
            'total_requests' => $stats->total_requests ?? 0,
            'total_tokens' => $stats->total_tokens ?? 0,
            'total_cost' => $stats->total_cost ?? 0.0,
            'avg_cost_per_request' => $stats->avg_cost_per_request ?? 0.0,
        ];
    }
}