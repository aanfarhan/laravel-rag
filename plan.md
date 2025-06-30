# Laravel RAG Package Implementation

I need you to create a complete Laravel package that implements a RAG (Retrieval-Augmented Generation) system. This package should be installable via Composer and easily pluggable into any existing Laravel application. Here are the requirements:

## Package Requirements
- Laravel 9.x/10.x/11.x compatibility
- PostgreSQL database support (with fallback to other databases)
- Installable via Composer
- Publishable configuration and views
- Service provider auto-discovery

## Package Structure

### Package Name: `omniglies/laravel-rag`

### 1. Package Foundation
Create proper package structure with:
- `composer.json` with Laravel package requirements
- Service provider with auto-discovery
- Configuration file publishing
- Migration publishing
- Views publishing

### 2. Database Schema (Publishable Migrations)
Create publishable migrations for:
- `rag_knowledge_documents` table to store document metadata
- `rag_knowledge_chunks` table to store text chunks with basic text search capabilities
- Include proper indexing for text search performance
- Use table prefixes to avoid conflicts

### 3. Models and Relationships
- `RagKnowledgeDocument` model
- `RagKnowledgeChunk` model
- Proper Eloquent relationships between them
- Models should be in package namespace

### 4. Core RAG Service
Create main `RagService` class with methods:
- `ingestDocument($title, $content, $metadata = [])` - Process and store documents
- `searchRelevantChunks($query, $limit = 3)` - Find relevant text chunks
- `askWithContext($userQuestion)` - Combine search + AI response
- Make service bindable to Laravel container

### 5. Document Processing (External API Integration)
- **External Document Processing API** - Integrate with separate Python-based processing service
- **API client** for document ingestion and processing requests
- **Async job handling** - Queue jobs for document processing with status tracking
- **Webhook support** - Receive processing completion notifications
- **Retry logic** - Handle API failures with exponential backoff
- **Processing status tracking** - Real-time status updates for document processing
- **Support for multiple file types** - Text, PDF, DOCX, HTML via external API
- **Chunking configuration** - Send chunking preferences to external service
- **Error handling** - Graceful handling of processing failures

### 6. Search Implementation (Vector Database Integration)
- **Vector Database API Integration** - Support for Pinecone, Weaviate, Chroma, or Qdrant
- **Embedding API calls** - Convert queries to vectors via OpenAI/Cohere embeddings
- **Hybrid search** - Combine vector similarity with keyword matching
- **Search API client** - Configurable REST API client for vector operations
- **Fallback search** - Local PostgreSQL full-text search as backup
- **Search result ranking** - Combine multiple search strategies
- **Configurable similarity thresholds** - Tune relevance scoring
- **Search analytics** - Track search performance and user queries

### 7. AI Integration
- Configurable AI provider support (OpenAI, Anthropic, etc.)
- Service class with driver pattern for different AI providers
- Prompt engineering for RAG context injection
- Error handling and rate limiting
- Facade for easy access

### 8. Web Interface (Publishable Views)
Create publishable Blade views and routes for:
- Document upload/management interface
- AI chat interface with RAG capabilities
- Admin panel for knowledge base management
- All routes should be configurable/disableable

### 9. Console Commands
- `rag:install` - Publish and run migrations
- `rag:ingest {file}` - Ingest documents from file
- `rag:clear` - Clear knowledge base
- `rag:optimize` - Optimize search indexes

### 10. Configuration System
- Comprehensive config file with all options
- Environment variable support
- Multiple AI provider configurations
- Chunk size and search configurations
- Route and middleware configurations

## Technical Specifications

### Package Directory Structure
```
src/
├── RagServiceProvider.php
├── Facades/
│   └── Rag.php
├── Models/
│   ├── RagKnowledgeDocument.php
│   └── RagKnowledgeChunk.php
├── Services/
│   ├── RagService.php
│   ├── ExternalProcessingService.php
│   ├── VectorSearchService.php
│   ├── EmbeddingService.php
│   └── AiProviders/
│       ├── AiProviderInterface.php
│       ├── OpenAiProvider.php
│       └── AnthropicProvider.php
├── Http/
│   ├── Controllers/
│   │   ├── RagController.php
│   │   └── RagApiController.php
│   ├── Middleware/
│   │   └── RagAuth.php
│   └── Requests/
│       ├── IngestDocumentRequest.php
│       └── AskQuestionRequest.php
├── Console/
│   └── Commands/
│       ├── InstallCommand.php
│       ├── IngestCommand.php
│       ├── ClearCommand.php
│       ├── OptimizeCommand.php
│       └── SyncProcessingStatusCommand.php
├── Jobs/
│   ├── ProcessDocumentJob.php
│   ├── GenerateEmbeddingsJob.php
│   └── SyncVectorDatabaseJob.php
├── Database/
│   └── Migrations/
│       ├── 2024_01_01_000001_create_rag_knowledge_documents_table.php
│       └── 2024_01_01_000002_create_rag_knowledge_chunks_table.php
├── Exceptions/
│   ├── RagException.php
│   └── AiProviderException.php
└── config/
    └── rag.php

resources/
├── views/
│   ├── layouts/
│   │   └── app.blade.php
│   ├── documents/
│   │   ├── index.blade.php
│   │   ├── create.blade.php
│   │   └── show.blade.php
│   └── chat/
│       ├── index.blade.php
│       └── components/
│           ├── chat-message.blade.php
│           └── upload-form.blade.php
└── assets/
    ├── css/
    │   └── rag.css
    └── js/
        ├── rag-alpine.js
        └── components/
            ├── chat-component.js
            ├── upload-component.js
            └── search-component.js

routes/
├── web.php
└── api.php

tests/
├── Unit/
│   ├── RagServiceTest.php
│   ├── ExternalProcessingServiceTest.php
│   └── VectorSearchServiceTest.php
└── Feature/
    ├── DocumentIngestionTest.php
    ├── VectorSearchTest.php
    └── AiChatTest.php
```

### Database Structure
```sql
-- rag_knowledge_documents
id, title, source_type, source_path, file_hash, file_size, mime_type,
processing_status (pending, processing, completed, failed), 
processing_job_id, external_document_id, processing_started_at, processing_completed_at,
metadata (json), created_at, updated_at

-- rag_knowledge_chunks  
id, document_id, content, chunk_index, chunk_hash, 
vector_id, vector_database_synced_at, embedding_model, embedding_dimensions,
chunk_metadata (json), keywords (json), 
search_vector (tsvector), -- PostgreSQL fallback search
created_at, updated_at

-- rag_processing_jobs
id, document_id, job_type (document_processing, embedding_generation, vector_sync), 
status (queued, processing, completed, failed, retrying), 
external_job_id, api_provider, progress_percentage, 
error_message, retry_count, max_retries,
started_at, completed_at, created_at, updated_at

-- rag_search_queries (analytics)
id, user_id, query_text, search_type (vector, keyword, hybrid),
results_count, response_time_ms, vector_similarity_scores (json),
created_at

-- rag_api_usage (cost tracking)
id, provider (processing_api, openai, pinecone, etc), 
operation_type (document_processing, embedding, vector_search),
tokens_used, cost_usd, document_id, created_at
```

### Frontend Technology Stack
- **Alpine.js** for UI interactivity and reactive components
- **CSS Grid/Flexbox** for responsive layouts
- **Fetch API** for AJAX requests
- **Publishable assets** (CSS/JS) with CDN fallbacks

### Alpine.js Components Required
- **Chat Interface** - Real-time messaging with typing indicators
- **Document Upload** - Drag-and-drop file upload with progress
- **Search Interface** - Live search with debouncing
- **Document Management** - CRUD operations with modals
- **Settings Panel** - Configuration management
- **Notification System** - Toast notifications for user feedback
```php
// config/rag.php
return [
    'table_prefix' => 'rag_',
    'ai_provider' => env('RAG_AI_PROVIDER', 'openai'),
    'providers' => [
        'openai' => [...],
        'anthropic' => [...],
    ],
    'chunking' => [...],
    'search' => [...],
    'routes' => [...],
    'middleware' => [...],
];
```

## Package Features
```html
<!-- Chat Interface Component -->
<div x-data="ragChat()" x-init="init()">
    <div class="chat-messages" x-ref="messages">
        <template x-for="message in messages" :key="message.id">
            <div class="message" :class="message.role">
                <span x-text="message.content"></span>
                <small x-text="message.timestamp"></small>
            </div>
        </template>
        <div x-show="isTyping" class="typing-indicator">AI is typing...</div>
    </div>
    
    <form @submit.prevent="sendMessage()" class="chat-input">
        <input x-model="newMessage" :disabled="isLoading" placeholder="Ask a question...">
        <button type="submit" :disabled="isLoading || !newMessage.trim()">
            <span x-show="!isLoading">Send</span>
            <span x-show="isLoading">Sending...</span>
        </button>
    </form>
</div>

<!-- Document Upload Component -->
<div x-data="ragUpload()" x-init="init()">
    <div class="upload-area" 
         @drop.prevent="handleDrop($event)" 
         @dragover.prevent="dragOver = true"
         @dragleave.prevent="dragOver = false"
         :class="{ 'drag-over': dragOver }">
        <input type="file" x-ref="fileInput" @change="handleFileSelect($event)" multiple accept=".txt,.pdf,.docx">
        <p>Drop files here or click to browse</p>
    </div>
    
    <div x-show="files.length > 0" class="upload-queue">
        <template x-for="file in files" :key="file.id">
            <div class="file-item">
                <span x-text="file.name"></span>
                <div class="progress-bar">
                    <div class="progress" :style="`width: ${file.progress}%`"></div>
                </div>
                <button @click="removeFile(file.id)" x-show="file.status !== 'uploading'">Remove</button>
            </div>
        </template>
    </div>
</div>
```

### Core Features
- **Plug-and-play installation** - Single command setup
- **Multiple AI provider support** - OpenAI, Anthropic, configurable
- **Document ingestion** - Text, PDF, DOCX support
- **Smart text chunking** - Configurable chunk sizes with overlap
- **Full-text search** - PostgreSQL optimized with fallbacks
- **Web interface** - Complete admin panel and chat interface
- **API endpoints** - RESTful API for custom integrations
- **Middleware support** - Configurable authentication and rate limiting

### Installation Experience
```bash
composer require omniglies/laravel-rag
php artisan rag:install
```

### Usage Examples
```php
// Via Facade - Document Ingestion
Rag::ingestDocument('Company Policy', $uploadedFile);
$status = Rag::getProcessingStatus($documentId);

// Via Facade - Vector Search
$answer = Rag::ask('What is our vacation policy?');
$chunks = Rag::searchSimilar('vacation policy', ['limit' => 5]);

// Via Service - Advanced Usage
$ragService = app(RagService::class);
$vectorService = app(VectorSearchService::class);

// Hybrid search with custom weights
$results = $vectorService->hybridSearch('vacation policy', [
    'vector_weight' => 0.8,
    'keyword_weight' => 0.2,
    'threshold' => 0.75
]);
```

### API Integration Examples
```php
// External Processing API Integration
$processingService = app(ExternalProcessingService::class);
$jobId = $processingService->submitDocument($file, [
    'chunking_strategy' => 'semantic',
    'chunk_size' => 1000,
    'extract_metadata' => true
]);

// Vector Database Integration  
$vectorService = app(VectorSearchService::class);
$embedding = $vectorService->createEmbedding('user query text');
$similar = $vectorService->searchSimilar($embedding, [
    'namespace' => 'company_docs',
    'top_k' => 10
]);
```

## Implementation Notes
- **Package isolation** - All components should be self-contained
- **Namespace everything** - Use `AI\LaravelRag` namespace
- **Configuration driven** - Everything should be configurable
- **Extensible design** - Use interfaces and drivers pattern
- **Database agnostic** - Work with MySQL, PostgreSQL, SQLite
- **Laravel conventions** - Follow Laravel package development standards
- **Alpine.js integration** - No build process, CDN-based, component architecture
- **Progressive enhancement** - Works without JavaScript, enhanced with Alpine.js
- **Accessibility first** - WCAG 2.1 AA compliance with proper ARIA attributes
- **Mobile responsive** - Touch-friendly interface with adaptive layouts
- **Comprehensive testing** - Unit and feature tests including Alpine.js components
- **Documentation** - Include detailed README and API docs with Alpine.js examples

### Alpine.js Specific Requirements
- **Component isolation** - Each Alpine component should be self-contained
- **Data stores** - Use Alpine stores for global state management
- **Event system** - Proper event handling and custom events
- **CSRF protection** - Include Laravel CSRF tokens in AJAX requests
- **Error handling** - Graceful error handling with user-friendly messages
- **Performance** - Debounced inputs, lazy loading, efficient DOM updates
- **CDN integration** - Configurable CDN usage with local fallbacks
- **IE11 support** - Optional polyfills for older browser support

## Expected Deliverables
1. **Complete Laravel package** with proper composer.json
2. **Service provider** with auto-discovery
3. **Publishable configuration** file with external API settings
4. **Database migrations** (publishable) including processing status tracking
5. **All model classes** with relationships for documents, chunks, and jobs
6. **External Processing API integration** with retry logic and webhooks
7. **Vector Database integration** with multiple provider support
8. **Embedding service** with multiple provider support (OpenAI, Cohere, etc.)
9. **Hybrid search implementation** combining vector and keyword search
10. **Queue jobs** for async document processing and vector syncing
11. **AI provider drivers** (OpenAI, Anthropic) with context injection
12. **Web interface** (publishable views) with Alpine.js components
13. **API endpoints** with proper validation for external integrations
14. **Console commands** for management and monitoring
15. **Comprehensive tests** (PHPUnit) including external API mocking
16. **Installation guide** and documentation with API setup instructions
17. **GitHub Actions** workflow for CI/CD
18. **Webhook handlers** for processing completion notifications

## Package Publishing Requirements
- Proper `composer.json` with Laravel package discovery
- Service provider registration
- Configuration publishing
- Migration publishing  
- View publishing
- Asset publishing
- Route registration (optional/configurable)
- Middleware registration
- Command registration

Please implement this as a complete, production-ready Laravel package that can be distributed via Packagist. Start with the package foundation (composer.json, service provider), then implement core functionality, and finally add the web interface and API endpoints.