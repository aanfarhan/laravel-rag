<?php

use Illuminate\Support\Facades\Route;
use Omniglies\LaravelRag\Http\Controllers\RagController;

if (!config('rag.routes.enabled', true)) {
    return;
}

$prefix = config('rag.routes.prefix', 'rag');
$middleware = config('rag.routes.middleware', ['web']);

Route::prefix($prefix)->middleware($middleware)->name('rag.')->group(function () {
    
    // Dashboard and main views
    Route::get('/', [RagController::class, 'dashboard'])->name('dashboard');
    Route::get('/chat', [RagController::class, 'chat'])->name('chat');
    
    // Document management
    Route::get('/documents', [RagController::class, 'documents'])->name('documents.index');
    Route::get('/documents/create', [RagController::class, 'createDocument'])->name('documents.create');
    Route::post('/documents', [RagController::class, 'storeDocument'])->name('documents.store');
    Route::get('/documents/{id}', [RagController::class, 'showDocument'])->name('documents.show');
    Route::delete('/documents/{id}', [RagController::class, 'deleteDocument'])->name('documents.destroy');
    
    // AJAX endpoints for web interface
    Route::post('/ask', [RagController::class, 'askQuestion'])->name('ask');
    Route::get('/search', [RagController::class, 'searchDocuments'])->name('search');
    Route::get('/documents/{id}/status', [RagController::class, 'getDocumentStatus'])->name('documents.status');
    
    // Admin actions
    Route::post('/optimize', [RagController::class, 'optimizeSearch'])->name('optimize');
    Route::post('/clear', [RagController::class, 'clearKnowledgeBase'])->name('clear');
    
});