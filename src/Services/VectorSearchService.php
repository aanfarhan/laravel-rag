<?php

namespace Omniglies\LaravelRag\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Models\RagApiUsage;
use Omniglies\LaravelRag\Exceptions\RagException;

class VectorSearchService
{
    protected Client $client;
    protected string $provider;
    protected array $config;
    protected EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
        $this->provider = config('rag.vector_database.provider', 'pinecone');
        $this->config = config("rag.vector_database.providers.{$this->provider}", []);
        
        if (empty($this->config)) {
            throw RagException::configurationMissing("vector_database.providers.{$this->provider}");
        }

        $this->initializeClient();
    }

    public function searchSimilar(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? config('rag.search.default_limit', 3);
        $threshold = $options['threshold'] ?? config('rag.search.similarity_threshold', 0.7);
        $namespace = $options['namespace'] ?? null;

        $cacheKey = 'rag:vector_search:' . md5($query . serialize($options));
        
        if (config('rag.cache.enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $embedding = $this->embeddingService->generateEmbedding($query);
            $results = $this->performVectorSearch($embedding, $limit, $threshold, $namespace);
            
            if (config('rag.cache.enabled', true)) {
                Cache::put($cacheKey, $results, config('rag.cache.ttl', 3600));
            }

            RagApiUsage::recordUsage(
                $this->provider,
                'vector_search',
                null,
                $this->calculateSearchCost($limit)
            );

            return $results;

        } catch (\Exception $e) {
            Log::error('Vector search failed', [
                'provider' => $this->provider,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            
            throw RagException::vectorDatabaseError($e->getMessage());
        }
    }

    public function upsertVector(RagKnowledgeChunk $chunk): bool
    {
        try {
            if (!$chunk->content) {
                return false;
            }

            $embedding = $this->embeddingService->generateEmbedding($chunk->content);
            $vectorId = $this->generateVectorId($chunk);
            
            $success = $this->performUpsert($vectorId, $embedding, [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'content' => substr($chunk->content, 0, 1000), // Limit metadata size
                'document_title' => $chunk->document->title ?? '',
            ]);

            if ($success) {
                $chunk->update([
                    'vector_id' => $vectorId,
                    'vector_database_synced_at' => now(),
                    'embedding_model' => $this->embeddingService->getModel(),
                    'embedding_dimensions' => $this->embeddingService->getDimensions(),
                ]);

                RagApiUsage::recordUsage(
                    $this->provider,
                    'vector_upsert',
                    null,
                    $this->calculateUpsertCost(),
                    $chunk->document_id
                );
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Vector upsert failed', [
                'provider' => $this->provider,
                'chunk_id' => $chunk->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function deleteVector(string $vectorId): bool
    {
        try {
            return $this->performDelete($vectorId);
            
        } catch (\Exception $e) {
            Log::error('Vector delete failed', [
                'provider' => $this->provider,
                'vector_id' => $vectorId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function deleteVectors(array $vectorIds): bool
    {
        if (empty($vectorIds)) {
            return true;
        }

        try {
            return $this->performBatchDelete($vectorIds);
            
        } catch (\Exception $e) {
            Log::error('Batch vector delete failed', [
                'provider' => $this->provider,
                'vector_count' => count($vectorIds),
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function deleteAllVectors(): bool
    {
        try {
            return $this->performDeleteAll();
            
        } catch (\Exception $e) {
            Log::error('Delete all vectors failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function getIndexStats(): array
    {
        try {
            return $this->performGetStats();
            
        } catch (\Exception $e) {
            Log::error('Get index stats failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'total_vectors' => 0,
                'dimensions' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function initializeClient(): void
    {
        switch ($this->provider) {
            case 'pinecone':
                $this->initializePineconeClient();
                break;
            case 'weaviate':
                $this->initializeWeaviateClient();
                break;
            case 'qdrant':
                $this->initializeQdrantClient();
                break;
            default:
                throw RagException::vectorDatabaseError("Unsupported vector database provider: {$this->provider}");
        }
    }

    protected function initializePineconeClient(): void
    {
        $this->client = new Client([
            'base_uri' => "https://{$this->config['index_name']}-{$this->config['project_id']}.svc.{$this->config['environment']}.pinecone.io",
            'headers' => [
                'Api-Key' => $this->config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    protected function initializeWeaviateClient(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['api_key'];
        }

        $this->client = new Client([
            'base_uri' => rtrim($this->config['url'], '/'),
            'headers' => $headers,
            'timeout' => 30,
        ]);
    }

    protected function initializeQdrantClient(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->config['api_key'])) {
            $headers['api-key'] = $this->config['api_key'];
        }

        $this->client = new Client([
            'base_uri' => rtrim($this->config['url'], '/'),
            'headers' => $headers,
            'timeout' => 30,
        ]);
    }

    protected function performVectorSearch(array $embedding, int $limit, float $threshold, ?string $namespace): array
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->searchPinecone($embedding, $limit, $threshold, $namespace);
            case 'weaviate':
                return $this->searchWeaviate($embedding, $limit, $threshold);
            case 'qdrant':
                return $this->searchQdrant($embedding, $limit, $threshold);
            default:
                throw RagException::vectorDatabaseError("Search not implemented for provider: {$this->provider}");
        }
    }

    protected function searchPinecone(array $embedding, int $limit, float $threshold, ?string $namespace): array
    {
        $payload = [
            'vector' => $embedding,
            'topK' => $limit,
            'includeMetadata' => true,
            'includeValues' => false,
        ];

        if ($namespace) {
            $payload['namespace'] = $namespace;
        }

        $response = $this->client->post('/query', ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        $results = [];
        foreach ($data['matches'] ?? [] as $match) {
            if ($match['score'] >= $threshold) {
                $results[] = $this->formatSearchResult($match);
            }
        }

        return $results;
    }

    protected function searchWeaviate(array $embedding, int $limit, float $threshold): array
    {
        $className = $this->config['class_name'];
        
        $query = [
            'query' => [
                'Get' => [
                    $className => [
                        'nearVector' => [
                            'vector' => $embedding,
                            'certainty' => $threshold,
                        ],
                        'limit' => $limit,
                        '_additional' => ['certainty'],
                        'properties' => ['chunk_id', 'document_id', 'content', 'document_title'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/v1/graphql', ['json' => $query]);
        $data = json_decode($response->getBody()->getContents(), true);

        $results = [];
        $objects = $data['data']['Get'][$className] ?? [];
        
        foreach ($objects as $object) {
            $results[] = [
                'id' => $object['chunk_id'],
                'similarity_score' => $object['_additional']['certainty'],
                'content' => $object['content'],
                'document' => [
                    'id' => $object['document_id'],
                    'title' => $object['document_title'],
                ],
                'metadata' => $object,
            ];
        }

        return $results;
    }

    protected function searchQdrant(array $embedding, int $limit, float $threshold): array
    {
        $collection = $this->config['collection'];
        
        $payload = [
            'vector' => $embedding,
            'limit' => $limit,
            'score_threshold' => $threshold,
            'with_payload' => true,
        ];

        $response = $this->client->post("/collections/{$collection}/points/search", ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        $results = [];
        foreach ($data['result'] ?? [] as $point) {
            $results[] = [
                'id' => $point['payload']['chunk_id'],
                'similarity_score' => $point['score'],
                'content' => $point['payload']['content'],
                'document' => [
                    'id' => $point['payload']['document_id'],
                    'title' => $point['payload']['document_title'],
                ],
                'metadata' => $point['payload'],
            ];
        }

        return $results;
    }

    public function performUpsert(string $vectorId, array $embedding, array $metadata): bool
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->upsertPinecone($vectorId, $embedding, $metadata);
            case 'weaviate':
                return $this->upsertWeaviate($vectorId, $embedding, $metadata);
            case 'qdrant':
                return $this->upsertQdrant($vectorId, $embedding, $metadata);
            default:
                throw RagException::vectorDatabaseError("Upsert not implemented for provider: {$this->provider}");
        }
    }

    protected function upsertPinecone(string $vectorId, array $embedding, array $metadata): bool
    {
        $payload = [
            'vectors' => [
                [
                    'id' => $vectorId,
                    'values' => $embedding,
                    'metadata' => $metadata,
                ],
            ],
        ];

        $response = $this->client->post('/vectors/upsert', ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        return isset($data['upsertedCount']) && $data['upsertedCount'] > 0;
    }

    protected function upsertWeaviate(string $vectorId, array $embedding, array $metadata): bool
    {
        $className = $this->config['class_name'];
        
        $payload = [
            'class' => $className,
            'id' => $vectorId,
            'vector' => $embedding,
            'properties' => $metadata,
        ];

        $response = $this->client->post('/v1/objects', ['json' => $payload]);
        
        return $response->getStatusCode() === 200;
    }

    protected function upsertQdrant(string $vectorId, array $embedding, array $metadata): bool
    {
        $collection = $this->config['collection'];
        
        $payload = [
            'points' => [
                [
                    'id' => $vectorId,
                    'vector' => $embedding,
                    'payload' => $metadata,
                ],
            ],
        ];

        $response = $this->client->put("/collections/{$collection}/points", ['json' => $payload]);
        $data = json_decode($response->getBody()->getContents(), true);

        return isset($data['status']) && $data['status'] === 'ok';
    }

    protected function performDelete(string $vectorId): bool
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->deletePinecone([$vectorId]);
            case 'weaviate':
                return $this->deleteWeaviate($vectorId);
            case 'qdrant':
                return $this->deleteQdrant([$vectorId]);
            default:
                return false;
        }
    }

    protected function performBatchDelete(array $vectorIds): bool
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->deletePinecone($vectorIds);
            case 'weaviate':
                return $this->batchDeleteWeaviate($vectorIds);
            case 'qdrant':
                return $this->deleteQdrant($vectorIds);
            default:
                return false;
        }
    }

    protected function deletePinecone(array $vectorIds): bool
    {
        $response = $this->client->post('/vectors/delete', [
            'json' => ['ids' => $vectorIds],
        ]);
        
        return $response->getStatusCode() === 200;
    }

    protected function deleteWeaviate(string $vectorId): bool
    {
        $response = $this->client->delete("/v1/objects/{$vectorId}");
        return $response->getStatusCode() === 204;
    }

    protected function batchDeleteWeaviate(array $vectorIds): bool
    {
        $success = true;
        foreach ($vectorIds as $vectorId) {
            if (!$this->deleteWeaviate($vectorId)) {
                $success = false;
            }
        }
        return $success;
    }

    protected function deleteQdrant(array $vectorIds): bool
    {
        $collection = $this->config['collection'];
        
        $response = $this->client->post("/collections/{$collection}/points/delete", [
            'json' => ['points' => $vectorIds],
        ]);
        
        $data = json_decode($response->getBody()->getContents(), true);
        return isset($data['status']) && $data['status'] === 'ok';
    }

    protected function performDeleteAll(): bool
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->deleteAllPinecone();
            case 'weaviate':
                return $this->deleteAllWeaviate();
            case 'qdrant':
                return $this->deleteAllQdrant();
            default:
                return false;
        }
    }

    protected function deleteAllPinecone(): bool
    {
        $response = $this->client->post('/vectors/delete', [
            'json' => ['deleteAll' => true],
        ]);
        
        return $response->getStatusCode() === 200;
    }

    protected function deleteAllWeaviate(): bool
    {
        $className = $this->config['class_name'];
        $response = $this->client->delete("/v1/schema/{$className}");
        return $response->getStatusCode() === 200;
    }

    protected function deleteAllQdrant(): bool
    {
        $collection = $this->config['collection'];
        $response = $this->client->delete("/collections/{$collection}");
        $data = json_decode($response->getBody()->getContents(), true);
        return isset($data['status']) && $data['status'] === 'ok';
    }

    protected function performGetStats(): array
    {
        switch ($this->provider) {
            case 'pinecone':
                return $this->getStatsPinecone();
            case 'weaviate':
                return $this->getStatsWeaviate();
            case 'qdrant':
                return $this->getStatsQdrant();
            default:
                return ['total_vectors' => 0, 'dimensions' => 0];
        }
    }

    protected function getStatsPinecone(): array
    {
        $response = $this->client->post('/describe_index_stats');
        $data = json_decode($response->getBody()->getContents(), true);
        
        return [
            'total_vectors' => $data['totalVectorCount'] ?? 0,
            'dimensions' => $data['dimension'] ?? 0,
            'index_fullness' => $data['indexFullness'] ?? 0,
        ];
    }

    protected function getStatsWeaviate(): array
    {
        $className = $this->config['class_name'];
        $response = $this->client->get("/v1/objects?class={$className}&limit=1");
        $data = json_decode($response->getBody()->getContents(), true);
        
        return [
            'total_vectors' => $data['totalResults'] ?? 0,
            'dimensions' => $this->embeddingService->getDimensions(),
        ];
    }

    protected function getStatsQdrant(): array
    {
        $collection = $this->config['collection'];
        $response = $this->client->get("/collections/{$collection}");
        $data = json_decode($response->getBody()->getContents(), true);
        
        return [
            'total_vectors' => $data['result']['points_count'] ?? 0,
            'dimensions' => $data['result']['config']['params']['vectors']['size'] ?? 0,
        ];
    }

    protected function formatSearchResult(array $match): array
    {
        $metadata = $match['metadata'] ?? [];
        
        return [
            'id' => $metadata['chunk_id'] ?? $match['id'],
            'similarity_score' => $match['score'] ?? $match['certainty'] ?? 0,
            'content' => $metadata['content'] ?? '',
            'document' => [
                'id' => $metadata['document_id'] ?? null,
                'title' => $metadata['document_title'] ?? '',
            ],
            'metadata' => $metadata,
        ];
    }

    protected function generateVectorId(RagKnowledgeChunk $chunk): string
    {
        return "chunk_{$chunk->id}_{$chunk->chunk_hash}";
    }

    protected function calculateSearchCost(int $limit): ?float
    {
        return match ($this->provider) {
            'pinecone' => $limit * 0.0001,
            'weaviate' => null, // Often self-hosted, no direct cost
            'qdrant' => null, // Often self-hosted, no direct cost
            default => null,
        };
    }

    protected function calculateUpsertCost(): ?float
    {
        return match ($this->provider) {
            'pinecone' => 0.0001,
            'weaviate' => null,
            'qdrant' => null,
            default => null,
        };
    }
}