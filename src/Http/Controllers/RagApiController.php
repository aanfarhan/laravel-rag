<?php

namespace Omniglies\LaravelRag\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Services\ExternalProcessingService;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Http\Requests\IngestDocumentRequest;
use Omniglies\LaravelRag\Http\Requests\AskQuestionRequest;
use Omniglies\LaravelRag\Exceptions\RagException;

class RagApiController extends Controller
{
    public function __construct(
        protected RagService $ragService,
        protected ExternalProcessingService $processingService
    ) {
        if (config('rag.middleware.rate_limiting', true)) {
            $rateLimit = config('rag.middleware.rate_limit', '60,1');
            $this->middleware("throttle:{$rateLimit}");
        }
    }

    public function ingestDocument(IngestDocumentRequest $request): JsonResponse
    {
        try {
            $title = $request->input('title');
            $metadata = $request->input('metadata', []);
            
            if ($request->hasFile('file')) {
                $document = $this->ragService->ingestDocument(
                    $title,
                    $request->file('file'),
                    $metadata
                );
            } else {
                $document = $this->ragService->ingestDocument(
                    $title,
                    $request->input('content'),
                    $metadata
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'status' => $document->processing_status,
                    'created_at' => $document->created_at,
                ],
            ], 201);

        } catch (RagException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function askQuestion(AskQuestionRequest $request): JsonResponse
    {
        try {
            $question = $request->input('question');
            $contextLimit = $request->input('context_limit', config('rag.search.default_limit', 3));
            
            $response = $this->ragService->askWithContext($question);
            $response['question'] = $question;
            $response['success'] = true;

            return response()->json($response);

        } catch (RagException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function searchChunks(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2|max:500',
            'limit' => 'sometimes|integer|min:1|max:50',
            'threshold' => 'sometimes|numeric|min:0|max:1',
        ]);

        try {
            $query = $request->input('query');
            $limit = $request->input('limit', 10);

            $chunks = $this->ragService->searchRelevantChunks($query, $limit);

            return response()->json([
                'success' => true,
                'query' => $query,
                'results' => $chunks,
                'total' => count($chunks),
            ]);

        } catch (RagException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getDocuments(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 20), 100);
        $status = $request->input('status');

        $query = RagKnowledgeDocument::with('chunks:id,document_id')
                                    ->withCount('chunks');

        if ($status) {
            $query->where('processing_status', $status);
        }

        $documents = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'documents' => $documents,
        ]);
    }

    public function getDocument(int $id): JsonResponse
    {
        try {
            $document = RagKnowledgeDocument::with(['chunks', 'processingJobs'])
                                           ->findOrFail($id);

            $status = $this->ragService->getProcessingStatus($id);

            return response()->json([
                'success' => true,
                'document' => $document,
                'processing_status' => $status,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Document not found',
            ], 404);
        }
    }

    public function deleteDocument(int $id): JsonResponse
    {
        try {
            $success = $this->ragService->deleteDocument($id);
            
            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Document deleted successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete document',
            ], 500);

        } catch (RagException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function getDocumentStatus(int $id): JsonResponse
    {
        try {
            $status = $this->ragService->getProcessingStatus($id);
            
            return response()->json([
                'success' => true,
                'status' => $status,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Document not found',
            ], 404);
        }
    }

    public function webhookProcessing(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $success = $this->processingService->handleWebhook($payload);
            
            if ($success) {
                return response()->json(['success' => true]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to process webhook',
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStats(): JsonResponse
    {
        try {
            $stats = [
                'documents' => [
                    'total' => RagKnowledgeDocument::count(),
                    'processed' => RagKnowledgeDocument::completed()->count(),
                    'pending' => RagKnowledgeDocument::pending()->count(),
                    'failed' => RagKnowledgeDocument::failed()->count(),
                ],
                'chunks' => [
                    'total' => \DB::table(config('rag.table_prefix', 'rag_') . 'knowledge_chunks')->count(),
                    'synced' => \DB::table(config('rag.table_prefix', 'rag_') . 'knowledge_chunks')
                               ->whereNotNull('vector_database_synced_at')
                               ->count(),
                ],
            ];

            // Add API usage stats if analytics are enabled
            if (config('rag.analytics.enabled', true)) {
                $stats['usage'] = [
                    'searches_today' => \DB::table(config('rag.table_prefix', 'rag_') . 'search_queries')
                                          ->whereDate('created_at', today())
                                          ->count(),
                    'total_cost_this_month' => \DB::table(config('rag.table_prefix', 'rag_') . 'api_usage')
                                                 ->whereYear('created_at', now()->year)
                                                 ->whereMonth('created_at', now()->month)
                                                 ->sum('cost_usd'),
                ];
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getHealth(): JsonResponse
    {
        try {
            $health = [
                'status' => 'healthy',
                'version' => '1.0.0',
                'timestamp' => now()->toISOString(),
                'services' => [
                    'database' => $this->checkDatabaseHealth(),
                    'ai_provider' => $this->checkAiProviderHealth(),
                    'vector_database' => $this->checkVectorDatabaseHealth(),
                ],
            ];

            $overallHealthy = collect($health['services'])->every(fn($service) => $service['status'] === 'healthy');
            
            if (!$overallHealthy) {
                $health['status'] = 'degraded';
            }

            $statusCode = $overallHealthy ? 200 : 503;

            return response()->json($health, $statusCode);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ], 503);
        }
    }

    protected function checkDatabaseHealth(): array
    {
        try {
            \DB::connection()->getPdo();
            return ['status' => 'healthy'];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    protected function checkAiProviderHealth(): array
    {
        try {
            $aiProvider = app(\Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface::class);
            $isHealthy = $aiProvider->validateApiKey();
            
            return [
                'status' => $isHealthy ? 'healthy' : 'unhealthy',
                'provider' => config('rag.ai_provider'),
            ];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    protected function checkVectorDatabaseHealth(): array
    {
        try {
            $vectorService = app(\Omniglies\LaravelRag\Services\VectorSearchService::class);
            $stats = $vectorService->getIndexStats();
            
            return [
                'status' => 'healthy',
                'provider' => config('rag.vector_database.provider'),
                'stats' => $stats,
            ];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
}