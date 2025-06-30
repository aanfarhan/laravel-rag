@extends('rag::layouts.app')

@section('title', $document->title . ' - RAG Knowledge Base')

@section('content')
<div class="rag-grid rag-grid-cols-1" x-data="ragDocumentStatus({{ $document->id }})">
    <!-- Header -->
    <div class="rag-mb-6">
        <div class="rag-flex rag-items-center rag-gap-2 rag-mb-2">
            <a href="{{ route('rag.documents.index') }}" 
               class="text-blue-600 hover:text-blue-800">
                ← Documents
            </a>
        </div>
        <div class="rag-flex rag-items-center rag-justify-between">
            <div>
                <h1 class="rag-text-2xl rag-font-bold">{{ $document->title }}</h1>
                <div class="rag-text-light rag-flex rag-items-center rag-gap-4 rag-mt-1">
                    <span>{{ $document->created_at->format('M j, Y g:i A') }}</span>
                    @if($document->file_size)
                        <span>{{ number_format($document->file_size / 1024, 1) }} KB</span>
                    @endif
                    @if($document->mime_type)
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-800">
                            {{ $document->mime_type }}
                        </span>
                    @endif
                </div>
            </div>
            
            <div class="rag-flex rag-items-center rag-gap-2">
                <!-- Status Badge -->
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
                      :class="{
                          'bg-green-100 text-green-800': status.status === 'completed',
                          'bg-yellow-100 text-yellow-800': status.status === 'processing' || status.status === 'pending',
                          'bg-red-100 text-red-800': status.status === 'failed',
                          'bg-gray-100 text-gray-800': !status.status
                      }"
                      x-text="status.status || '{{ $document->processing_status }}'">
                    {{ $document->processing_status }}
                </span>

                <!-- Actions -->
                <form action="{{ route('rag.documents.destroy', $document->id) }}" 
                      method="POST" 
                      class="inline"
                      onsubmit="return confirm('Are you sure you want to delete this document?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rag-btn rag-btn-error">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="rag-grid rag-grid-cols-1 lg:rag-grid-cols-3 rag-gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Processing Status -->
            <div class="rag-card">
                <div class="rag-card-header">
                    <h2 class="rag-card-title">Processing Status</h2>
                </div>
                <div class="rag-card-body">
                    <div class="space-y-4">
                        <!-- Progress Bar -->
                        <div>
                            <div class="rag-flex rag-items-center rag-justify-between rag-mb-2">
                                <span class="text-sm font-medium">Overall Progress</span>
                                <span class="text-sm text-gray-600" 
                                      x-text="`${Math.round(status.progress || {{ $processingStatus['progress'] }})}%`">
                                    {{ round($processingStatus['progress']) }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="bg-blue-600 h-3 rounded-full transition-all duration-500" 
                                     :style="`width: ${status.progress || {{ $processingStatus['progress'] }}}%`"></div>
                            </div>
                        </div>

                        <!-- Status Details -->
                        <div class="rag-grid rag-grid-cols-2 rag-gap-4">
                            <div>
                                <div class="text-sm text-gray-600">Total Chunks</div>
                                <div class="text-lg font-semibold" 
                                     x-text="status.total_chunks || {{ $processingStatus['total_chunks'] }}">
                                    {{ $processingStatus['total_chunks'] }}
                                </div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-600">Synced to Vector DB</div>
                                <div class="text-lg font-semibold" 
                                     x-text="status.synced_chunks || {{ $processingStatus['synced_chunks'] }}">
                                    {{ $processingStatus['synced_chunks'] }}
                                </div>
                            </div>
                        </div>

                        <!-- Timestamps -->
                        @if($processingStatus['processing_started_at'])
                            <div class="text-sm text-gray-600">
                                <strong>Started:</strong> {{ \Carbon\Carbon::parse($processingStatus['processing_started_at'])->format('M j, Y g:i A') }}
                            </div>
                        @endif
                        
                        @if($processingStatus['processing_completed_at'])
                            <div class="text-sm text-gray-600">
                                <strong>Completed:</strong> {{ \Carbon\Carbon::parse($processingStatus['processing_completed_at'])->format('M j, Y g:i A') }}
                            </div>
                        @endif

                        <!-- Auto-refresh indicator -->
                        <div x-show="polling" class="text-xs text-gray-500 flex items-center gap-1">
                            <div class="rag-spinner w-3 h-3"></div>
                            Auto-refreshing status...
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document Chunks -->
            <div class="rag-card">
                <div class="rag-card-header">
                    <div class="rag-flex rag-items-center rag-justify-between">
                        <h2 class="rag-card-title">Document Chunks ({{ $document->chunks->count() }})</h2>
                        
                        @if($document->chunks->count() > 0)
                            <div class="rag-flex rag-items-center rag-gap-2 text-sm">
                                <span class="text-green-600">
                                    {{ $document->chunks->where('vector_database_synced_at', '!=', null)->count() }} synced
                                </span>
                                <span class="text-gray-400">|</span>
                                <span class="text-yellow-600">
                                    {{ $document->chunks->where('vector_database_synced_at', null)->count() }} pending
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="rag-card-body">
                    @if($document->chunks->count() > 0)
                        <div class="space-y-4 max-h-96 overflow-y-auto">
                            @foreach($document->chunks as $chunk)
                                <div class="border rounded-lg p-4 hover:bg-gray-50">
                                    <div class="rag-flex rag-items-start rag-justify-between rag-mb-2">
                                        <div class="text-sm font-medium text-gray-600">
                                            Chunk #{{ $chunk->chunk_index + 1 }}
                                        </div>
                                        <div class="rag-flex rag-items-center rag-gap-2">
                                            @if($chunk->vector_database_synced_at)
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                                    ✓ Synced
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                                    ⏳ Pending
                                                </span>
                                            @endif
                                            
                                            <span class="text-xs text-gray-500">
                                                {{ str_word_count($chunk->content) }} words
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="text-sm text-gray-700 leading-relaxed">
                                        {{ Str::limit($chunk->content, 300) }}
                                    </div>
                                    
                                    @if($chunk->keywords && count($chunk->keywords) > 0)
                                        <div class="rag-flex rag-gap-1 rag-mt-2">
                                            @foreach(array_slice($chunk->keywords, 0, 5) as $keyword)
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                                    {{ $keyword }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center text-gray-500 py-8">
                            <div class="text-lg mb-2">📄 No chunks yet</div>
                            <div>Chunks will appear here once processing is complete</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Document Metadata -->
            <div class="rag-card">
                <div class="rag-card-header">
                    <h2 class="rag-card-title">Document Info</h2>
                </div>
                <div class="rag-card-body space-y-3">
                    <div>
                        <div class="text-sm font-medium text-gray-600">Source Type</div>
                        <div class="text-sm">{{ ucfirst($document->source_type) }}</div>
                    </div>
                    
                    @if($document->file_hash)
                        <div>
                            <div class="text-sm font-medium text-gray-600">File Hash</div>
                            <div class="text-xs font-mono text-gray-500">{{ substr($document->file_hash, 0, 16) }}...</div>
                        </div>
                    @endif
                    
                    @if($document->metadata && count($document->metadata) > 0)
                        <div>
                            <div class="text-sm font-medium text-gray-600 mb-2">Custom Metadata</div>
                            @foreach($document->metadata as $key => $value)
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-sm text-gray-600">{{ $key }}:</span>
                                    <span class="text-sm">{{ $value }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Processing Jobs -->
            @if($document->processingJobs->count() > 0)
                <div class="rag-card">
                    <div class="rag-card-header">
                        <h2 class="rag-card-title">Processing Jobs</h2>
                    </div>
                    <div class="rag-card-body">
                        <div class="space-y-3">
                            @foreach($document->processingJobs as $job)
                                <div class="border rounded-lg p-3">
                                    <div class="rag-flex rag-items-center rag-justify-between rag-mb-1">
                                        <div class="text-sm font-medium">{{ ucwords(str_replace('_', ' ', $job->job_type)) }}</div>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs"
                                              @class([
                                                  'bg-green-100 text-green-800' => $job->status === 'completed',
                                                  'bg-blue-100 text-blue-800' => $job->status === 'processing',
                                                  'bg-yellow-100 text-yellow-800' => $job->status === 'queued' || $job->status === 'retrying',
                                                  'bg-red-100 text-red-800' => $job->status === 'failed',
                                              ])>
                                            {{ ucfirst($job->status) }}
                                        </span>
                                    </div>
                                    
                                    @if($job->progress_percentage > 0)
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 mb-2">
                                            <div class="bg-blue-600 h-1.5 rounded-full" 
                                                 style="width: {{ $job->progress_percentage }}%"></div>
                                        </div>
                                    @endif
                                    
                                    @if($job->error_message)
                                        <div class="text-xs text-red-600 mt-1">{{ $job->error_message }}</div>
                                    @endif
                                    
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $job->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <!-- Quick Actions -->
            <div class="rag-card">
                <div class="rag-card-header">
                    <h2 class="rag-card-title">Quick Actions</h2>
                </div>
                <div class="rag-card-body space-y-2">
                    <button onclick="testSearch()" class="rag-btn rag-btn-outline w-full">
                        🔍 Test Search
                    </button>
                    <a href="{{ route('rag.chat') }}?ask=Tell me about {{ urlencode($document->title) }}" 
                       class="rag-btn rag-btn-outline w-full">
                        💬 Ask AI About This
                    </a>
                    <button onclick="reprocessDocument()" 
                            class="rag-btn rag-btn-outline w-full"
                            @disabled="status.status === 'processing'">
                        🔄 Reprocess
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function testSearch() {
    const query = prompt('Enter search query to test:', '{{ Str::limit($document->title, 50) }}');
    if (query) {
        window.open(`/rag/documents?search=${encodeURIComponent(query)}`, '_blank');
    }
}

function reprocessDocument() {
    if (!confirm('Are you sure you want to reprocess this document? This will recreate all chunks.')) {
        return;
    }
    
    fetch(`/rag/documents/{{ $document->id }}/reprocess`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Document reprocessing started');
            location.reload();
        } else {
            alert('Failed to start reprocessing: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

// Auto-scroll to hash target
if (window.location.hash) {
    setTimeout(() => {
        const target = document.querySelector(window.location.hash);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth' });
        }
    }, 100);
}
</script>
@endpush
@endsection