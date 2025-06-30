<?php

use Illuminate\Support\Facades\Route;
use Omniglies\LaravelRag\Http\Controllers\RagApiController;

if (!config('rag.routes.enabled', true)) {
    return;
}

$prefix = 'api/' . config('rag.routes.prefix', 'rag');
$middleware = config('rag.routes.api_middleware', ['api']);

Route::prefix($prefix)->middleware($middleware)->name('rag.api.')->group(function () {
    
    // Core RAG API endpoints
    Route::post('/documents', [RagApiController::class, 'ingestDocument'])->name('documents.store');
    Route::get('/documents', [RagApiController::class, 'getDocuments'])->name('documents.index');
    Route::get('/documents/{id}', [RagApiController::class, 'getDocument'])->name('documents.show');
    Route::delete('/documents/{id}', [RagApiController::class, 'deleteDocument'])->name('documents.destroy');
    Route::get('/documents/{id}/status', [RagApiController::class, 'getDocumentStatus'])->name('documents.status');
    
    // Search and Q&A
    Route::post('/ask', [RagApiController::class, 'askQuestion'])->name('ask');
    Route::get('/search', [RagApiController::class, 'searchChunks'])->name('search');
    
    // System endpoints
    Route::get('/stats', [RagApiController::class, 'getStats'])->name('stats');
    Route::get('/health', [RagApiController::class, 'getHealth'])->name('health');
    
});

// Webhook endpoint (no auth required)
Route::post('/webhooks/rag/processing', [RagApiController::class, 'webhookProcessing'])->name('rag.webhook.processing');