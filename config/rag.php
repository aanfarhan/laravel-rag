<?php

return [
    'table_prefix' => env('RAG_TABLE_PREFIX', 'rag_'),

    'ai_provider' => env('RAG_AI_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'model' => env('RAG_OPENAI_MODEL', 'gpt-3.5-turbo'),
            'embedding_model' => env('RAG_OPENAI_EMBEDDING_MODEL', 'text-embedding-ada-002'),
            'max_tokens' => env('RAG_OPENAI_MAX_TOKENS', 1000),
            'temperature' => env('RAG_OPENAI_TEMPERATURE', 0.7),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('RAG_ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
            'max_tokens' => env('RAG_ANTHROPIC_MAX_TOKENS', 1000),
            'temperature' => env('RAG_ANTHROPIC_TEMPERATURE', 0.7),
        ],
    ],

    'external_processing' => [
        'enabled' => env('RAG_EXTERNAL_PROCESSING_ENABLED', true),
        'api_url' => env('RAG_PROCESSING_API_URL'),
        'api_key' => env('RAG_PROCESSING_API_KEY'),
        'timeout' => env('RAG_PROCESSING_TIMEOUT', 300),
        'retry_attempts' => env('RAG_PROCESSING_RETRY_ATTEMPTS', 3),
        'webhook_secret' => env('RAG_PROCESSING_WEBHOOK_SECRET'),
    ],

    'vector_database' => [
        'provider' => env('RAG_VECTOR_PROVIDER', 'pinecone'),
        'providers' => [
            'pinecone' => [
                'api_key' => env('PINECONE_API_KEY'),
                'environment' => env('PINECONE_ENVIRONMENT'),
                'index_name' => env('PINECONE_INDEX_NAME', 'rag-knowledge'),
                'dimensions' => env('PINECONE_DIMENSIONS', 1536),
            ],
            'weaviate' => [
                'url' => env('WEAVIATE_URL'),
                'api_key' => env('WEAVIATE_API_KEY'),
                'class_name' => env('WEAVIATE_CLASS_NAME', 'RagDocument'),
            ],
            'qdrant' => [
                'url' => env('QDRANT_URL'),
                'api_key' => env('QDRANT_API_KEY'),
                'collection' => env('QDRANT_COLLECTION', 'rag_documents'),
            ],
        ],
    ],

    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'openai'),
        'providers' => [
            'openai' => [
                'model' => env('RAG_OPENAI_EMBEDDING_MODEL', 'text-embedding-ada-002'),
                'dimensions' => 1536,
            ],
            'cohere' => [
                'api_key' => env('COHERE_API_KEY'),
                'model' => env('RAG_COHERE_EMBEDDING_MODEL', 'embed-english-v3.0'),
                'dimensions' => 1024,
            ],
        ],
    ],

    'chunking' => [
        'strategy' => env('RAG_CHUNKING_STRATEGY', 'semantic'),
        'chunk_size' => env('RAG_CHUNK_SIZE', 1000),
        'chunk_overlap' => env('RAG_CHUNK_OVERLAP', 200),
        'min_chunk_size' => env('RAG_MIN_CHUNK_SIZE', 100),
        'max_chunk_size' => env('RAG_MAX_CHUNK_SIZE', 2000),
        'separators' => ['\n\n', '\n', '. ', '! ', '? '],
    ],

    'search' => [
        'default_limit' => env('RAG_SEARCH_LIMIT', 3),
        'similarity_threshold' => env('RAG_SIMILARITY_THRESHOLD', 0.7),
        'hybrid_search' => [
            'enabled' => env('RAG_HYBRID_SEARCH_ENABLED', true),
            'vector_weight' => env('RAG_VECTOR_WEIGHT', 0.8),
            'keyword_weight' => env('RAG_KEYWORD_WEIGHT', 0.2),
        ],
        'fallback_to_sql' => env('RAG_FALLBACK_TO_SQL', true),
    ],

    'routes' => [
        'enabled' => env('RAG_ROUTES_ENABLED', true),
        'prefix' => env('RAG_ROUTES_PREFIX', 'rag'),
        'middleware' => ['web'],
        'api_middleware' => ['api'],
    ],

    'middleware' => [
        'auth' => env('RAG_AUTH_MIDDLEWARE', 'auth'),
        'rate_limiting' => env('RAG_RATE_LIMITING', true),
        'rate_limit' => env('RAG_RATE_LIMIT', '60,1'),
    ],

    'ui' => [
        'enabled' => env('RAG_UI_ENABLED', true),
        'alpine_js_cdn' => env('RAG_ALPINE_CDN', 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js'),
        'theme' => env('RAG_UI_THEME', 'default'),
        'brand_name' => env('RAG_BRAND_NAME', 'RAG Knowledge Base'),
    ],

    'file_upload' => [
        'max_size' => env('RAG_MAX_FILE_SIZE', 10240), // KB
        'allowed_types' => ['txt', 'pdf', 'docx', 'html', 'md'],
        'storage_disk' => env('RAG_STORAGE_DISK', 'local'),
        'storage_path' => env('RAG_STORAGE_PATH', 'rag/documents'),
    ],

    'queue' => [
        'connection' => env('RAG_QUEUE_CONNECTION', 'default'),
        'queue' => env('RAG_QUEUE_NAME', 'rag'),
        'processing_timeout' => env('RAG_PROCESSING_TIMEOUT', 600),
    ],

    'analytics' => [
        'enabled' => env('RAG_ANALYTICS_ENABLED', true),
        'track_searches' => env('RAG_TRACK_SEARCHES', true),
        'track_usage' => env('RAG_TRACK_USAGE', true),
        'retention_days' => env('RAG_ANALYTICS_RETENTION', 90),
    ],

    'cache' => [
        'enabled' => env('RAG_CACHE_ENABLED', true),
        'ttl' => env('RAG_CACHE_TTL', 3600),
        'embeddings_ttl' => env('RAG_EMBEDDINGS_CACHE_TTL', 86400),
    ],
];