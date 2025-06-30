@extends('rag::layouts.app')

@section('title', 'Documents - RAG Knowledge Base')

@section('content')
<div class="rag-grid rag-grid-cols-1">
    <!-- Header -->
    <div class="rag-flex rag-items-center rag-justify-between rag-mb-6">
        <h1 class="rag-text-2xl rag-font-bold">Documents</h1>
        <div class="rag-flex rag-gap-2">
            <a href="{{ route('rag.documents.create') }}" class="rag-btn rag-btn-primary">
                Upload Document
            </a>
            <button @click="showBulkActions = !showBulkActions" 
                    class="rag-btn rag-btn-outline"
                    x-data="{ showBulkActions: false }">
                Bulk Actions
            </button>
        </div>
    </div>

    <!-- Search and Filters -->
    <div class="rag-card rag-mb-6" x-data="ragSearch()">
        <div class="rag-card-body">
            <div class="rag-grid rag-grid-cols-1 md:rag-grid-cols-3 rag-gap-4">
                <!-- Search -->
                <div class="md:col-span-2">
                    <div class="relative">
                        <input x-model="query" 
                               placeholder="Search documents..."
                               class="rag-input pr-10">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                            <div x-show="isSearching" class="rag-spinner w-4 h-4"></div>
                            <button x-show="!isSearching && query" 
                                    @click="clearSearch()"
                                    class="text-gray-400 hover:text-gray-600">
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Search Results -->
                    <div x-show="results.length > 0" 
                         class="mt-4 bg-white border rounded-lg shadow-sm">
                        <div class="p-3 border-b text-sm font-medium text-gray-600">
                            Search Results (<span x-text="results.length"></span>)
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <template x-for="result in results" :key="result.id">
                                <div class="p-3 border-b last:border-b-0 hover:bg-gray-50">
                                    <div class="font-medium">
                                        <a :href="`/rag/documents/${result.document.id}`" 
                                           class="text-blue-600 hover:text-blue-800"
                                           x-text="result.document.title"></a>
                                    </div>
                                    <div class="text-sm text-gray-600 mt-1" 
                                         x-text="result.content.substring(0, 150) + '...'"></div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        Similarity: <span x-text="Math.round(result.similarity_score * 100)"></span>%
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Status Filter -->
                <div>
                    <select class="rag-select" onchange="filterByStatus(this.value)">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Documents List -->
    <div class="rag-card">
        <div class="rag-card-header">
            <div class="rag-flex rag-items-center rag-justify-between">
                <h2 class="rag-card-title">All Documents ({{ $documents->total() }})</h2>
                
                <!-- Bulk Actions -->
                <div x-data="{ showBulkActions: false }" x-show="showBulkActions" 
                     x-transition class="rag-flex rag-gap-2">
                    <button class="rag-btn rag-btn-sm rag-btn-error" 
                            onclick="confirmBulkDelete()">
                        Delete Selected
                    </button>
                    <button class="rag-btn rag-btn-sm rag-btn-secondary"
                            onclick="selectAll()">
                        Select All
                    </button>
                </div>
            </div>
        </div>

        <div class="rag-card-body">
            @if($documents->count() > 0)
                <div class="space-y-4">
                    @foreach($documents as $document)
                        <div class="rag-flex rag-items-center rag-justify-between rag-p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
                             x-data="ragDocumentStatus({{ $document->id }})">
                            <div class="rag-flex rag-items-center rag-gap-4 flex-1">
                                <!-- Checkbox for bulk actions -->
                                <input type="checkbox" 
                                       value="{{ $document->id }}" 
                                       class="document-checkbox rounded border-gray-300">

                                <!-- Document Info -->
                                <div class="flex-1">
                                    <h3 class="rag-font-medium">
                                        <a href="{{ route('rag.documents.show', $document->id) }}" 
                                           class="text-blue-600 hover:text-blue-800">
                                            {{ $document->title }}
                                        </a>
                                    </h3>
                                    
                                    <div class="rag-text-sm rag-text-light rag-flex rag-items-center rag-gap-4 rag-mt-1">
                                        <span>{{ $document->chunks_count }} chunks</span>
                                        <span>{{ $document->file_size ? number_format($document->file_size / 1024, 1) . ' KB' : 'N/A' }}</span>
                                        <span>{{ $document->created_at->diffForHumans() }}</span>
                                        
                                        @if($document->mime_type)
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-800">
                                                {{ $document->mime_type }}
                                            </span>
                                        @endif
                                    </div>

                                    <!-- Metadata -->
                                    @if($document->metadata && count($document->metadata) > 0)
                                        <div class="rag-flex rag-gap-2 rag-mt-2">
                                            @foreach($document->metadata as $key => $value)
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                                    {{ $key }}: {{ $value }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="rag-flex rag-items-center rag-gap-4">
                                <!-- Status Badge -->
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
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
                                <div x-show="status.status === 'processing'" 
                                     class="w-24 bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                         :style="`width: ${getProgressWidth()}%`"></div>
                                </div>

                                <!-- Actions -->
                                <div class="rag-flex rag-items-center rag-gap-2">
                                    <a href="{{ route('rag.documents.show', $document->id) }}" 
                                       class="rag-btn rag-btn-sm rag-btn-outline">
                                        View
                                    </a>
                                    
                                    <form action="{{ route('rag.documents.destroy', $document->id) }}" 
                                          method="POST" 
                                          class="inline"
                                          onsubmit="return confirm('Are you sure you want to delete this document?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rag-btn rag-btn-sm rag-btn-error">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                @if($documents->hasPages())
                    <div class="mt-6">
                        {{ $documents->links() }}
                    </div>
                @endif
            @else
                <div class="rag-text-center rag-p-8 rag-text-light">
                    <div class="rag-text-lg rag-mb-2">No documents found</div>
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
function filterByStatus(status) {
    const url = new URL(window.location);
    if (status) {
        url.searchParams.set('status', status);
    } else {
        url.searchParams.delete('status');
    }
    window.location = url;
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.document-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });
}

function confirmBulkDelete() {
    const selectedIds = Array.from(document.querySelectorAll('.document-checkbox:checked'))
                             .map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('Please select documents to delete');
        return;
    }
    
    if (!confirm(`Are you sure you want to delete ${selectedIds.length} document(s)?`)) {
        return;
    }
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/rag/documents/bulk-delete';
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    form.innerHTML = `
        <input type="hidden" name="_token" value="${csrfToken}">
        <input type="hidden" name="_method" value="DELETE">
        ${selectedIds.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('')}
    `;
    
    document.body.appendChild(form);
    form.submit();
}
</script>
@endpush
@endsection