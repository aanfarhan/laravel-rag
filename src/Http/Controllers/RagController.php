<?php

namespace Omniglies\LaravelRag\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Routing\Controller;
use Omniglies\LaravelRag\Services\RagService;
use Omniglies\LaravelRag\Models\RagKnowledgeDocument;
use Omniglies\LaravelRag\Http\Requests\IngestDocumentRequest;
use Omniglies\LaravelRag\Http\Requests\AskQuestionRequest;
use Omniglies\LaravelRag\Exceptions\RagException;

class RagController extends Controller
{
    public function __construct(
        protected RagService $ragService
    ) {
        $this->middleware(config('rag.middleware.auth', 'auth'));
    }

    public function dashboard(): View
    {
        $totalDocuments = RagKnowledgeDocument::count();
        $processedDocuments = RagKnowledgeDocument::completed()->count();
        $pendingDocuments = RagKnowledgeDocument::pending()->count();
        $failedDocuments = RagKnowledgeDocument::failed()->count();
        
        $recentDocuments = RagKnowledgeDocument::with('chunks')
                                             ->latest()
                                             ->limit(10)
                                             ->get();

        return view('rag::dashboard', compact(
            'totalDocuments',
            'processedDocuments',
            'pendingDocuments',
            'failedDocuments',
            'recentDocuments'
        ));
    }

    public function chat(): View
    {
        return view('rag::chat.index');
    }

    public function documents(): View
    {
        $documents = RagKnowledgeDocument::with('chunks')
                                        ->withCount('chunks')
                                        ->latest()
                                        ->paginate(20);

        return view('rag::documents.index', compact('documents'));
    }

    public function createDocument(): View
    {
        return view('rag::documents.create');
    }

    public function storeDocument(IngestDocumentRequest $request): RedirectResponse
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

            return redirect()
                ->route('rag.documents.show', $document->id)
                ->with('success', 'Document uploaded successfully and is being processed.');

        } catch (RagException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function showDocument(int $id): View
    {
        $document = RagKnowledgeDocument::with(['chunks', 'processingJobs'])
                                       ->findOrFail($id);

        $processingStatus = $this->ragService->getProcessingStatus($id);

        return view('rag::documents.show', compact('document', 'processingStatus'));
    }

    public function deleteDocument(int $id): RedirectResponse
    {
        try {
            $this->ragService->deleteDocument($id);
            
            return redirect()
                ->route('rag.documents.index')
                ->with('success', 'Document deleted successfully.');

        } catch (RagException $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function askQuestion(AskQuestionRequest $request)
    {
        try {
            $question = $request->input('question');
            $stream = $request->input('stream', false);
            $contextLimit = $request->input('context_limit', config('rag.search.default_limit', 3));
            
            if ($stream) {
                return $this->streamResponse($question, $request->all());
            }

            $response = $this->ragService->askWithContext($question);
            $response['question'] = $question;

            if ($request->expectsJson()) {
                return response()->json($response);
            }

            return view('rag::chat.response', compact('response'));

        } catch (RagException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'success' => false,
                ], 500);
            }

            return redirect()
                ->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function searchDocuments(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2|max:500',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        try {
            $query = $request->input('query');
            $limit = $request->input('limit', 10);

            $chunks = $this->ragService->searchRelevantChunks($query, $limit);

            return response()->json([
                'success' => true,
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

    public function getDocumentStatus(int $id): JsonResponse
    {
        try {
            $status = $this->ragService->getProcessingStatus($id);
            
            return response()->json([
                'success' => true,
                'status' => $status,
            ]);

        } catch (RagException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function clearKnowledgeBase(Request $request): RedirectResponse
    {
        if (!$request->input('confirm')) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Please confirm the action.']);
        }

        try {
            $this->ragService->clearKnowledgeBase();
            
            return redirect()
                ->route('rag.dashboard')
                ->with('success', 'Knowledge base cleared successfully.');

        } catch (RagException $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function optimizeSearch(): RedirectResponse
    {
        try {
            $this->ragService->optimizeSearchIndexes();
            
            return redirect()
                ->back()
                ->with('success', 'Search indexes optimized successfully.');

        } catch (RagException $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    protected function streamResponse(string $question, array $options = [])
    {
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($question, $options) {
            try {
                $relevantChunks = $this->ragService->searchRelevantChunks($question);
                
                if (empty($relevantChunks)) {
                    $this->sendSseData([
                        'type' => 'error',
                        'content' => "I don't have relevant information to answer your question.",
                    ]);
                    return;
                }

                $context = $this->buildContext($relevantChunks);
                $prompt = $this->buildPrompt($question, $context);

                $aiProvider = app(\Omniglies\LaravelRag\Services\AiProviders\AiProviderInterface::class);
                
                foreach ($aiProvider->streamResponse($prompt, $options) as $chunk) {
                    if ($chunk['is_complete']) {
                        $this->sendSseData([
                            'type' => 'complete',
                            'sources' => $this->extractSources($relevantChunks),
                            'usage' => $chunk['usage'] ?? [],
                        ]);
                    } else {
                        $this->sendSseData([
                            'type' => 'content',
                            'content' => $chunk['content'],
                        ]);
                    }
                }

            } catch (\Exception $e) {
                $this->sendSseData([
                    'type' => 'error',
                    'content' => $e->getMessage(),
                ]);
            }
        }, 200, $headers);
    }

    protected function sendSseData(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    protected function buildContext(array $chunks): string
    {
        $context = '';
        foreach ($chunks as $chunk) {
            $context .= "Source: {$chunk['document']['title']}\n";
            $context .= $chunk['content'] . "\n\n";
        }
        return trim($context);
    }

    protected function buildPrompt(string $question, string $context): string
    {
        return "Based on the following context, please answer the question. If the context doesn't contain relevant information, say so.\n\n" .
               "Context:\n{$context}\n\n" .
               "Question: {$question}\n\n" .
               "Answer:";
    }

    protected function extractSources(array $chunks): array
    {
        $sources = [];
        foreach ($chunks as $chunk) {
            $sources[] = [
                'document_id' => $chunk['document']['id'],
                'document_title' => $chunk['document']['title'],
                'chunk_id' => $chunk['id'],
                'similarity_score' => $chunk['similarity_score'],
            ];
        }
        return $sources;
    }
}