# Laravel RAG Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/omniglies/laravel-rag.svg?style=flat-square)](https://packagist.org/packages/omniglies/laravel-rag)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/omniglies/laravel-rag/run-tests?label=tests)](https://github.com/omniglies/laravel-rag/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/workflow/status/omniglies/laravel-rag/Check%20&%20fix%20styling?label=code%20style)](https://github.com/omniglies/laravel-rag/actions?query=workflow%3A"Check+%26+fix+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/omniglies/laravel-rag.svg?style=flat-square)](https://packagist.org/packages/omniglies/laravel-rag)

A complete Laravel package for implementing RAG (Retrieval-Augmented Generation) systems with external API integrations, vector databases, and a beautiful Alpine.js-powered web interface.

## Features

- 🚀 **Plug-and-play installation** - Single command setup
- 🤖 **Multiple AI providers** - OpenAI, Anthropic with extensible driver system
- 📄 **Document processing** - Text, PDF, DOCX support via external APIs
- 🔍 **Hybrid search** - Vector similarity + keyword matching with multiple vector DB providers
- 🎨 **Modern web interface** - Alpine.js components with real-time updates
- 📊 **Analytics & monitoring** - Usage tracking, search analytics, cost monitoring
- ⚡ **Queue-based processing** - Async document processing with status tracking
- 🔧 **Extensive configuration** - Every aspect is configurable
- 🧪 **Comprehensive testing** - Unit and feature tests included

## Quick Start

```bash
# Install the package
composer require omniglies/laravel-rag

# Install and configure
php artisan rag:install

# Configure your environment
# Add to .env:
RAG_AI_PROVIDER=openai
OPENAI_API_KEY=your_openai_key
RAG_VECTOR_PROVIDER=pinecone
PINECONE_API_KEY=your_pinecone_key
```

Visit `/rag` in your browser and start uploading documents!

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Web Interface](#web-interface)
  - [API Usage](#api-usage)
  - [Programmatic Usage](#programmatic-usage)
- [External Integrations](#external-integrations)
- [Console Commands](#console-commands)
- [Testing](#testing)
- [Architecture](#architecture)
- [Contributing](#contributing)

## Installation

### Requirements

- PHP 8.1+
- Laravel 9.x, 10.x, or 11.x
- Database (PostgreSQL recommended for full-text search, MySQL/SQLite supported)
- Queue system (Redis/Database recommended for production)

### Step 1: Install via Composer

```bash
composer require omniglies/laravel-rag
```

### Step 2: Install the Package

```bash
php artisan rag:install
```

This command will:
- Publish configuration files
- Publish and run migrations
- Publish views and assets
- Display setup instructions

### Step 3: Configure Environment

Add these environment variables to your `.env`:

```env
# AI Provider (required)
RAG_AI_PROVIDER=openai
OPENAI_API_KEY=your_openai_api_key

# Vector Database (required)
RAG_VECTOR_PROVIDER=pinecone
PINECONE_API_KEY=your_pinecone_api_key
PINECONE_ENVIRONMENT=your_pinecone_environment
PINECONE_INDEX_NAME=rag-knowledge

# External Processing API (optional)
RAG_PROCESSING_API_URL=https://your-processing-api.com
RAG_PROCESSING_API_KEY=your_processing_api_key

# Queue Configuration (recommended)
QUEUE_CONNECTION=redis
RAG_QUEUE_CONNECTION=redis
```

### Step 4: Set Up Queue Workers (Production)

```bash
# Start queue workers for document processing
php artisan queue:work --queue=rag
```

## Configuration

The package configuration is published to `config/rag.php`. Key configuration sections:

### AI Providers

```php
'ai_provider' => env('RAG_AI_PROVIDER', 'openai'),

'providers' => [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('RAG_OPENAI_MODEL', 'gpt-3.5-turbo'),
        'max_tokens' => env('RAG_OPENAI_MAX_TOKENS', 1000),
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('RAG_ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
    ],
],
```

### Vector Databases

```php
'vector_database' => [
    'provider' => env('RAG_VECTOR_PROVIDER', 'pinecone'),
    'providers' => [
        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'environment' => env('PINECONE_ENVIRONMENT'),
            'index_name' => env('PINECONE_INDEX_NAME', 'rag-knowledge'),
        ],
        'weaviate' => [
            'url' => env('WEAVIATE_URL'),
            'api_key' => env('WEAVIATE_API_KEY'),
        ],
        'qdrant' => [
            'url' => env('QDRANT_URL'),
            'api_key' => env('QDRANT_API_KEY'),
        ],
    ],
],
```

### Search Configuration

```php
'search' => [
    'default_limit' => env('RAG_SEARCH_LIMIT', 3),
    'similarity_threshold' => env('RAG_SIMILARITY_THRESHOLD', 0.7),
    'hybrid_search' => [
        'enabled' => env('RAG_HYBRID_SEARCH_ENABLED', true),
        'vector_weight' => env('RAG_VECTOR_WEIGHT', 0.8),
        'keyword_weight' => env('RAG_KEYWORD_WEIGHT', 0.2),
    ],
],
```

## Usage

### Web Interface

The package provides a complete web interface accessible at `/rag`:

- **Dashboard**: Overview of documents and processing status
- **Documents**: Upload, manage, and view document processing status
- **Chat**: AI-powered chat interface with RAG capabilities

#### Features

- Drag-and-drop file uploads
- Real-time processing status updates
- Vector search with similarity scores
- Source attribution in AI responses
- Responsive Alpine.js components

### API Usage

The package exposes RESTful API endpoints at `/api/rag/`:

#### Upload Document

```bash
curl -X POST /api/rag/documents \
  -H "Content-Type: multipart/form-data" \
  -F "title=My Document" \
  -F "file=@document.pdf" \
  -F "metadata[author]=John Doe"
```

#### Ask Question

```bash
curl -X POST /api/rag/ask \
  -H "Content-Type: application/json" \
  -d '{
    "question": "What is the main topic of the documents?",
    "context_limit": 5,
    "temperature": 0.7
  }'
```

#### Search Documents

```bash
curl -X GET "/api/rag/search?query=machine learning&limit=10&threshold=0.8"
```

#### Get System Status

```bash
curl -X GET /api/rag/health
```

### Programmatic Usage

#### Using the Facade

```php
use Omniglies\LaravelRag\Facades\Rag;

// Ingest a document
$document = Rag::ingestDocument('Document Title', $uploadedFile, [
    'author' => 'John Doe',
    'category' => 'Research'
]);

// Ask a question
$response = Rag::askWithContext('What is machine learning?');
echo $response['answer'];

// Search for similar content
$chunks = Rag::searchRelevantChunks('artificial intelligence', 5);
```

#### Using the Service

```php
use Omniglies\LaravelRag\Services\RagService;

class MyController extends Controller
{
    public function __construct(private RagService $ragService) {}

    public function search(Request $request)
    {
        $results = $this->ragService->searchRelevantChunks(
            $request->query,
            $request->limit ?? 10
        );
        
        return response()->json($results);
    }
}
```

#### Advanced Usage

```php
use Omniglies\LaravelRag\Services\VectorSearchService;
use Omniglies\LaravelRag\Services\EmbeddingService;

// Direct vector operations
$vectorService = app(VectorSearchService::class);
$embeddingService = app(EmbeddingService::class);

// Generate embeddings
$embedding = $embeddingService->generateEmbedding('sample text');

// Perform vector search
$results = $vectorService->searchSimilar('query text', [
    'limit' => 10,
    'threshold' => 0.8,
    'namespace' => 'documents'
]);
```

## External Integrations

### Document Processing API

The package supports external document processing services for advanced file format support:

```php
// Configure external processing
'external_processing' => [
    'enabled' => env('RAG_EXTERNAL_PROCESSING_ENABLED', true),
    'api_url' => env('RAG_PROCESSING_API_URL'),
    'api_key' => env('RAG_PROCESSING_API_KEY'),
    'webhook_secret' => env('RAG_PROCESSING_WEBHOOK_SECRET'),
],
```

Expected API interface:
- `POST /documents/process` - Submit document for processing
- `GET /jobs/{id}/status` - Check processing status
- `GET /jobs/{id}/result` - Get processed chunks
- Webhook support for completion notifications

### Vector Database Providers

#### Pinecone

```env
RAG_VECTOR_PROVIDER=pinecone
PINECONE_API_KEY=your_api_key
PINECONE_ENVIRONMENT=us-west1-gcp
PINECONE_INDEX_NAME=rag-knowledge
```

#### Weaviate

```env
RAG_VECTOR_PROVIDER=weaviate
WEAVIATE_URL=http://localhost:8080
WEAVIATE_API_KEY=your_api_key
```

#### Qdrant

```env
RAG_VECTOR_PROVIDER=qdrant
QDRANT_URL=http://localhost:6333
QDRANT_API_KEY=your_api_key
```

### Embedding Providers

#### OpenAI

```env
RAG_EMBEDDING_PROVIDER=openai
RAG_OPENAI_EMBEDDING_MODEL=text-embedding-ada-002
```

#### Cohere

```env
RAG_EMBEDDING_PROVIDER=cohere
COHERE_API_KEY=your_api_key
RAG_COHERE_EMBEDDING_MODEL=embed-english-v3.0
```

## Console Commands

### Installation and Setup

```bash
# Install the package (run after composer install)
php artisan rag:install

# Test configuration
php artisan rag:status --test-config
```

### Document Management

```bash
# Ingest a document
php artisan rag:ingest path/to/document.pdf --title="Research Paper"

# Check document status
php artisan rag:status 123

# Clear all documents
php artisan rag:clear --force
```

### Maintenance

```bash
# Optimize search indexes
php artisan rag:optimize --search-indexes

# Sync unsynced vectors
php artisan rag:optimize --vector-sync

# Clean up old records
php artisan rag:optimize --cleanup-old --days=30
```

### System Status

```bash
# Check overall system status
php artisan rag:status

# Check specific document
php artisan rag:status 123

# Detailed statistics
php artisan rag:status --detailed
```

## Testing

The package includes comprehensive tests:

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Test Structure

- **Unit Tests**: Test individual services and components
- **Feature Tests**: Test HTTP endpoints and integration scenarios
- **Mocking**: External APIs are mocked for reliable testing

## Architecture

### Core Components

```
src/
├── Services/           # Core business logic
│   ├── RagService.php             # Main service orchestrator
│   ├── ExternalProcessingService.php  # Document processing API client
│   ├── VectorSearchService.php    # Vector database operations
│   ├── EmbeddingService.php       # Embedding generation
│   └── AiProviders/               # AI provider implementations
├── Models/             # Eloquent models
├── Http/               # Controllers and middleware
├── Jobs/               # Queue jobs for async processing
├── Console/            # Artisan commands
└── Database/           # Migrations
```

### Data Flow

1. **Document Upload** → **Queue Processing** → **External API** → **Chunking** → **Embeddings** → **Vector Storage**
2. **User Query** → **Vector Search** + **Keyword Search** → **Context Building** → **AI Generation** → **Response**

### Design Patterns

- **Service Layer**: Business logic separation
- **Strategy Pattern**: Pluggable AI and vector providers
- **Observer Pattern**: Event-driven processing updates
- **Factory Pattern**: Provider instantiation
- **Repository Pattern**: Data access abstraction

## Alpine.js Components

The web interface uses Alpine.js for reactive components:

### Chat Component

```html
<div x-data="ragChat()" x-init="init()">
    <!-- Real-time chat interface with typing indicators -->
</div>
```

### Upload Component

```html
<div x-data="ragUpload()" x-init="init()">
    <!-- Drag-and-drop upload with progress tracking -->
</div>
```

### Search Component

```html
<div x-data="ragSearch()" x-init="init()">
    <!-- Live search with debouncing and results -->
</div>
```

## Performance Considerations

### Production Optimization

1. **Queue Workers**: Use dedicated queue workers for document processing
2. **Caching**: Enable Redis/Memcached for embeddings and search results
3. **Database**: Use PostgreSQL for optimal full-text search performance
4. **Vector Database**: Choose appropriate vector DB based on scale
5. **Rate Limiting**: Configure API rate limits for external services

### Scaling

- **Horizontal Scaling**: Multiple queue workers for processing
- **Vector Database Sharding**: Distribute vectors across multiple indexes
- **CDN**: Serve static assets via CDN
- **Load Balancing**: Distribute web requests across multiple instances

## Security

### API Keys

- Store all API keys in environment variables
- Use Laravel's encryption for sensitive configuration
- Rotate keys regularly

### Authentication

- Configure authentication middleware for web routes
- Use API tokens for programmatic access
- Implement rate limiting for API endpoints

### Data Privacy

- Documents are processed according to your AI provider's terms
- Vector embeddings do not contain original text
- Implement data retention policies

## Troubleshooting

### Common Issues

#### Document Processing Stuck

```bash
# Check queue workers
php artisan queue:work --queue=rag

# Check external API status
php artisan rag:status --test-config

# Retry failed jobs
php artisan queue:retry all
```

#### Vector Search Not Working

```bash
# Test vector database connection
php artisan rag:status --test-config

# Check vector sync status
php artisan rag:optimize --vector-sync
```

#### High API Costs

```bash
# Check usage statistics
php artisan rag:status --detailed

# Optimize chunk sizes
# Edit config/rag.php chunking settings
```

### Debug Mode

Enable debug logging in `config/logging.php`:

```php
'channels' => [
    'rag' => [
        'driver' => 'daily',
        'path' => storage_path('logs/rag.log'),
        'level' => 'debug',
    ],
],
```

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

### Development Setup

```bash
git clone https://github.com/omniglies/laravel-rag.git
cd laravel-rag
composer install
npm install
npm run dev
```

### Running Tests

```bash
composer test
composer test:coverage
composer test:types
```

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Omniglies Team](https://github.com/omniglies)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Roadmap

- [ ] Support for more file formats (PPT, XLS, etc.)
- [ ] Built-in document OCR capabilities
- [ ] GraphQL API support
- [ ] Advanced analytics dashboard
- [ ] Multi-tenant support
- [ ] Document versioning
- [ ] Collaborative features
- [ ] Export/import capabilities

---

**Need help?** Check out our [documentation](https://docs.laravel-rag.com) or [open an issue](https://github.com/omniglies/laravel-rag/issues).