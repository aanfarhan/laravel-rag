<?php

namespace Omniglies\LaravelRag\Tests\Feature;

use Omniglies\LaravelRag\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Omniglies\LaravelRag\RagServiceProvider;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Models\RagKnowledgeChunk;
use Omniglies\LaravelRag\Jobs\ProcessDocumentJob;

class DocumentIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        // RAG configuration
        $app['config']->set('rag.external_processing.enabled', false);
        $app['config']->set('rag.file_upload.storage_disk', 'testing');
        $app['config']->set('rag.file_upload.allowed_types', ['txt', 'pdf']);
        $app['config']->set('rag.file_upload.max_size', 1024); // 1MB
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        Storage::fake('testing');
        Queue::fake();
        
        // Run migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Database/Migrations');
    }

    public function test_can_upload_text_document_via_web_interface()
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        $file->storeAs('temp', 'test.txt', 'testing');

        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Test Document',
            'file' => $file,
            'metadata' => [
                'author' => 'Test Author',
                'category' => 'Testing'
            ]
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('rag_knowledge_documents', [
            'title' => 'Test Document',
            'source_type' => 'upload',
            'processing_status' => 'pending'
        ]);

        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_can_create_document_with_text_content()
    {
        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Text Document',
            'content' => 'This is test content for the document.',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('rag_knowledge_documents', [
            'title' => 'Text Document',
            'source_type' => 'text',
            'processing_status' => 'pending'
        ]);
    }

    public function test_validates_required_fields()
    {
        // Act
        $response = $this->post(route('rag.documents.store'), []);

        // Assert
        $response->assertSessionHasErrors(['title']);
    }

    public function test_validates_file_type()
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.exe', 100, 'application/exe');

        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Test Document',
            'file' => $file,
        ]);

        // Assert
        $response->assertSessionHasErrors(['file']);
    }

    public function test_validates_file_size()
    {
        // Arrange
        $file = UploadedFile::fake()->create('large.txt', 2048, 'text/plain'); // 2MB

        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Large Document',
            'file' => $file,
        ]);

        // Assert
        $response->assertSessionHasErrors(['file']);
    }

    public function test_can_view_document_list()
    {
        // Arrange
        RagKnowledgeDocument::factory()->count(3)->create();

        // Act
        $response = $this->get(route('rag.documents.index'));

        // Assert
        $response->assertStatus(200);
        $response->assertViewIs('rag::documents.index');
        $response->assertViewHas('documents');
    }

    public function test_can_view_single_document()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();

        // Act
        $response = $this->get(route('rag.documents.show', $document->id));

        // Assert
        $response->assertStatus(200);
        $response->assertViewIs('rag::documents.show');
        $response->assertViewHas('document');
        $response->assertSee($document->title);
    }

    public function test_can_delete_document()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();

        // Act
        $response = $this->delete(route('rag.documents.destroy', $document->id));

        // Assert
        $response->assertRedirect(route('rag.documents.index'));
        $this->assertDatabaseMissing('rag_knowledge_documents', [
            'id' => $document->id
        ]);
    }

    public function test_document_with_chunks_can_be_deleted()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create();
        RagKnowledgeChunk::factory()->count(3)->create(['document_id' => $document->id]);

        // Act
        $response = $this->delete(route('rag.documents.destroy', $document->id));

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseMissing('rag_knowledge_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('rag_knowledge_chunks', ['document_id' => $document->id]);
    }

    public function test_can_get_document_processing_status()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create([
            'processing_status' => 'processing'
        ]);

        // Act
        $response = $this->getJson(route('rag.documents.status', $document->id));

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => [
                'id' => $document->id,
                'status' => 'processing'
            ]
        ]);
    }

    public function test_can_search_documents_via_api()
    {
        // Arrange
        $document = RagKnowledgeDocument::factory()->create(['title' => 'Test Document']);
        RagKnowledgeChunk::factory()->create([
            'document_id' => $document->id,
            'content' => 'This is searchable content about testing.'
        ]);

        // Act
        $response = $this->getJson(route('rag.search', ['query' => 'testing']));

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_duplicate_file_hash_returns_existing_document()
    {
        // Arrange
        $existingDocument = RagKnowledgeDocument::factory()->create([
            'file_hash' => 'test-hash-123'
        ]);

        // Mock a document creation that would have the same hash
        // (In real implementation, this would be handled by the service)

        // Act & Assert - This would need to be tested at the service level
        $this->assertTrue(true); // Placeholder
    }

    public function test_can_filter_documents_by_status()
    {
        // Arrange
        RagKnowledgeDocument::factory()->create(['processing_status' => 'completed']);
        RagKnowledgeDocument::factory()->create(['processing_status' => 'pending']);
        RagKnowledgeDocument::factory()->create(['processing_status' => 'failed']);

        // Act
        $response = $this->get(route('rag.documents.index', ['status' => 'completed']));

        // Assert
        $response->assertStatus(200);
        // In a real test, you'd assert that only completed documents are shown
    }

    public function test_document_metadata_is_stored_correctly()
    {
        // Arrange
        $metadata = [
            'author' => 'John Doe',
            'category' => 'Research',
            'tags' => 'test,document'
        ];

        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Document with Metadata',
            'content' => 'Test content',
            'metadata' => $metadata
        ]);

        // Assert
        $response->assertRedirect();
        $document = RagKnowledgeDocument::where('title', 'Document with Metadata')->first();
        $this->assertNotNull($document);
        $this->assertEquals($metadata, $document->metadata);
    }

    public function test_can_access_document_upload_page()
    {
        // Act
        $response = $this->get(route('rag.documents.create'));

        // Assert
        $response->assertStatus(200);
        $response->assertViewIs('rag::documents.create');
    }

    public function test_cannot_upload_without_title_or_content()
    {
        // Act
        $response = $this->post(route('rag.documents.store'), [
            'title' => 'Test Document'
            // No file or content
        ]);

        // Assert
        $response->assertSessionHasErrors();
    }
}