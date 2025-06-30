@extends('rag::layouts.app')

@section('title', 'Dashboard - RAG Knowledge Base')

@section('content')
<div class="rag-grid rag-grid-cols-1">
    <!-- Header -->
    <div class="rag-flex rag-items-center rag-justify-between rag-mb-6">
        <h1 class="rag-text-2xl rag-font-bold">Dashboard</h1>
        <a href="{{ route('rag.documents.create') }}" class="rag-btn rag-btn-primary">
            Upload Document
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="rag-grid rag-grid-cols-4 rag-mb-6" x-data="ragStats()">
        <div class="rag-card">
            <div class="rag-card-body rag-text-center">
                <div class="rag-text-2xl rag-font-bold rag-text-primary" x-text="stats.documents?.total || {{ $totalDocuments }}">
                    {{ $totalDocuments }}
                </div>
                <div class="rag-text-sm rag-text-light">Total Documents</div>
            </div>
        </div>

        <div class="rag-card">
            <div class="rag-card-body rag-text-center">
                <div class="rag-text-2xl rag-font-bold rag-text-success" x-text="stats.documents?.processed || {{ $processedDocuments }}">
                    {{ $processedDocuments }}
                </div>
                <div class="rag-text-sm rag-text-light">Processed</div>
            </div>
        </div>

        <div class="rag-card">
            <div class="rag-card-body rag-text-center">
                <div class="rag-text-2xl rag-font-bold rag-text-warning" x-text="stats.documents?.pending || {{ $pendingDocuments }}">
                    {{ $pendingDocuments }}
                </div>
                <div class="rag-text-sm rag-text-light">Pending</div>
            </div>
        </div>

        <div class="rag-card">
            <div class="rag-card-body rag-text-center">
                <div class="rag-text-2xl rag-font-bold rag-text-error" x-text="stats.documents?.failed || {{ $failedDocuments }}">
                    {{ $failedDocuments }}
                </div>
                <div class="rag-text-sm rag-text-light">Failed</div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="rag-card rag-mb-6">
        <div class="rag-card-header">
            <h2 class="rag-card-title">Quick Actions</h2>
        </div>
        <div class="rag-card-body">
            <div class="rag-grid rag-grid-cols-3 rag-gap-4">
                <a href="{{ route('rag.chat') }}" class="rag-btn rag-btn-outline">
                    💬 Start Chat
                </a>
                <a href="{{ route('rag.documents.create') }}" class="rag-btn rag-btn-outline">
                    📄 Upload Document
                </a>
                <button @click="$dispatch('rag-optimize')" class="rag-btn rag-btn-outline">
                    ⚡ Optimize Search
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Documents -->
    <div class="rag-card">
        <div class="rag-card-header">
            <div class="rag-flex rag-items-center rag-justify-between">
                <h2 class="rag-card-title">Recent Documents</h2>
                <a href="{{ route('rag.documents.index') }}" class="rag-btn rag-btn-sm rag-btn-outline">
                    View All
                </a>
            </div>
        </div>
        <div class="rag-card-body">
            @if($recentDocuments->count() > 0)
                <div class="space-y-4">
                    @foreach($recentDocuments as $document)
                        <div class="rag-flex rag-items-center rag-justify-between rag-p-4 border border-gray-200 rounded-lg"
                             x-data="ragDocumentStatus({{ $document->id }})">
                            <div class="flex-1">
                                <h3 class="rag-font-medium">
                                    <a href="{{ route('rag.documents.show', $document->id) }}" 
                                       class="text-blue-600 hover:text-blue-800">
                                        {{ $document->title }}
                                    </a>
                                </h3>
                                <div class="rag-text-sm rag-text-light rag-mt-1">
                                    {{ $document->chunks_count }} chunks • 
                                    {{ $document->created_at->diffForHumans() }}
                                </div>
                            </div>
                            
                            <div class="rag-flex rag-items-center rag-gap-4">
                                <!-- Status Badge -->
                                <span class="rag-badge" 
                                      :class="{
                                          'bg-green-100 text-green-800': status.status === 'completed',
                                          'bg-yellow-100 text-yellow-800': status.status === 'processing' || status.status === 'pending',
                                          'bg-red-100 text-red-800': status.status === 'failed',
                                          'bg-gray-100 text-gray-800': !status.status
                                      }"
                                      x-text="status.status || '{{ $document->processing_status }}'">
                                    {{ $document->processing_status }}
                                </span>

                                <!-- Progress Bar -->
                                <div x-show="status.status === 'processing' || '{{ $document->processing_status }}' === 'processing'" 
                                     class="w-24 bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                         :style="`width: ${getProgressWidth()}%`"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rag-text-center rag-p-6 rag-text-light">
                    <div class="rag-text-lg rag-mb-2">No documents yet</div>
                    <div class="rag-mb-4">Upload your first document to get started</div>
                    <a href="{{ route('rag.documents.create') }}" class="rag-btn rag-btn-primary">
                        Upload Document
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('ragOptimize', () => ({
        async optimize() {
            if (!confirm('This will optimize search indexes. Continue?')) return;
            
            try {
                const response = await fetch('/rag/optimize', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Content-Type': 'application/json'
                    }
                });
                
                if (response.ok) {
                    this.$dispatch('notification', {
                        type: 'success',
                        title: 'Optimization Complete',
                        message: 'Search indexes have been optimized'
                    });
                } else {
                    throw new Error('Optimization failed');
                }
            } catch (error) {
                this.$dispatch('notification', {
                    type: 'error',
                    title: 'Optimization Failed',
                    message: error.message
                });
            }
        }
    }));
});

// Listen for optimize event
document.addEventListener('rag-optimize', (event) => {
    Alpine.store('ragOptimize').optimize();
});
</script>
@endpush
@endsection