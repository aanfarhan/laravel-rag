@extends('rag::layouts.app')

@section('title', 'Upload Document - RAG Knowledge Base')

@section('content')
<div class="rag-grid rag-grid-cols-1" x-data="ragUpload()">
    <!-- Header -->
    <div class="rag-mb-6">
        <div class="rag-flex rag-items-center rag-gap-2 rag-mb-2">
            <a href="{{ route('rag.documents.index') }}" 
               class="text-blue-600 hover:text-blue-800">
                ← Documents
            </a>
        </div>
        <h1 class="rag-text-2xl rag-font-bold">Upload Document</h1>
        <p class="rag-text-light">Add documents to your knowledge base for AI-powered search and chat.</p>
    </div>

    <div class="rag-grid rag-grid-cols-1 lg:rag-grid-cols-2 rag-gap-6">
        <!-- Upload Form -->
        <div class="rag-card">
            <div class="rag-card-header">
                <h2 class="rag-card-title">Document Details</h2>
            </div>
            <div class="rag-card-body">
                <form action="{{ route('rag.documents.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    
                    <div class="rag-form-group">
                        <label for="title" class="rag-label">Document Title</label>
                        <input type="text" 
                               id="title" 
                               name="title" 
                               class="rag-input" 
                               placeholder="Enter document title"
                               value="{{ old('title') }}"
                               required>
                        @error('title')
                            <div class="text-red-600 text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- File Upload -->
                    <div class="rag-form-group">
                        <label class="rag-label">Upload File</label>
                        <div class="upload-area border-2 border-dashed border-gray-300 rounded-lg p-8 text-center transition-colors"
                             :class="{ 'border-blue-500 bg-blue-50': dragOver }"
                             @drop.prevent="handleDrop($event)"
                             @dragover.prevent="dragOver = true"
                             @dragleave.prevent="dragOver = false">
                            
                            <input type="file" 
                                   name="file" 
                                   x-ref="fileInput" 
                                   @change="handleFileSelect($event)"
                                   accept=".txt,.pdf,.docx,.html,.md"
                                   class="hidden">
                            
                            <div class="space-y-2">
                                <div class="text-4xl">📄</div>
                                <div class="text-lg font-medium">Drop files here or click to browse</div>
                                <div class="text-sm text-gray-500">
                                    Supports: TXT, PDF, DOCX, HTML, MD
                                    <br>
                                    Max size: {{ config('rag.file_upload.max_size', 10240) / 1024 }}MB
                                </div>
                                <button type="button" 
                                        @click="$refs.fileInput.click()"
                                        class="rag-btn rag-btn-outline">
                                    Choose File
                                </button>
                            </div>
                        </div>
                        @error('file')
                            <div class="text-red-600 text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Text Content Alternative -->
                    <div class="rag-form-group">
                        <label class="rag-label">Or Paste Text Content</label>
                        <textarea name="content" 
                                  class="rag-textarea" 
                                  rows="8"
                                  placeholder="Paste your text content here as an alternative to uploading a file">{{ old('content') }}</textarea>
                        @error('content')
                            <div class="text-red-600 text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Metadata -->
                    <div class="rag-form-group">
                        <label class="rag-label">Metadata (Optional)</label>
                        <div x-data="{ metadataFields: [{ key: '', value: '' }] }">
                            <template x-for="(field, index) in metadataFields" :key="index">
                                <div class="rag-flex rag-gap-2 rag-mb-2">
                                    <input type="text" 
                                           :name="`metadata_keys[${index}]`"
                                           x-model="field.key"
                                           placeholder="Key (e.g., author)"
                                           class="rag-input flex-1">
                                    <input type="text" 
                                           :name="`metadata_values[${index}]`"
                                           x-model="field.value"
                                           placeholder="Value"
                                           class="rag-input flex-1">
                                    <button type="button" 
                                            @click="metadataFields.splice(index, 1)"
                                            x-show="metadataFields.length > 1"
                                            class="rag-btn rag-btn-sm rag-btn-error">
                                        ✕
                                    </button>
                                </div>
                            </template>
                            <button type="button" 
                                    @click="metadataFields.push({ key: '', value: '' })"
                                    class="rag-btn rag-btn-sm rag-btn-outline">
                                + Add Metadata
                            </button>
                        </div>
                    </div>

                    <div class="rag-flex rag-gap-4">
                        <button type="submit" class="rag-btn rag-btn-primary flex-1">
                            Upload Document
                        </button>
                        <a href="{{ route('rag.documents.index') }}" 
                           class="rag-btn rag-btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Upload Queue -->
        <div class="rag-card">
            <div class="rag-card-header">
                <div class="rag-flex rag-items-center rag-justify-between">
                    <h2 class="rag-card-title">Upload Queue</h2>
                    <button x-show="files.length > 0" 
                            @click="uploadAll()"
                            class="rag-btn rag-btn-sm rag-btn-primary">
                        Upload All
                    </button>
                </div>
            </div>
            <div class="rag-card-body">
                <div x-show="files.length === 0" class="text-center text-gray-500 py-8">
                    <div class="text-lg mb-2">📋 No files queued</div>
                    <div>Files you select will appear here</div>
                </div>

                <div x-show="files.length > 0" class="space-y-3">
                    <template x-for="file in files" :key="file.id">
                        <div class="border rounded-lg p-4">
                            <div class="rag-flex rag-items-center rag-justify-between rag-mb-2">
                                <div class="flex-1">
                                    <div class="font-medium" x-text="file.name"></div>
                                    <div class="text-sm text-gray-500" x-text="formatFileSize(file.size)"></div>
                                </div>
                                <div class="rag-flex rag-items-center rag-gap-2">
                                    <span class="text-xs px-2 py-1 rounded-full"
                                          :class="{
                                              'bg-yellow-100 text-yellow-800': file.status === 'pending',
                                              'bg-blue-100 text-blue-800': file.status === 'uploading',
                                              'bg-green-100 text-green-800': file.status === 'completed',
                                              'bg-red-100 text-red-800': file.status === 'error'
                                          }"
                                          x-text="file.status">
                                    </span>
                                    <button @click="removeFile(file.id)" 
                                            x-show="file.status !== 'uploading'"
                                            class="text-red-600 hover:text-red-800">
                                        ✕
                                    </button>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div x-show="file.status === 'uploading'" 
                                 class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                     :style="`width: ${file.progress}%`"></div>
                            </div>

                            <!-- Error Message -->
                            <div x-show="file.error" 
                                 class="text-red-600 text-sm mt-2"
                                 x-text="file.error">
                            </div>

                            <!-- Upload Button -->
                            <div x-show="file.status === 'pending'" class="mt-2">
                                <button @click="uploadFile(file)" 
                                        class="rag-btn rag-btn-sm rag-btn-primary">
                                    Upload
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Guidelines -->
    <div class="rag-card rag-mt-6">
        <div class="rag-card-header">
            <h2 class="rag-card-title">Upload Guidelines</h2>
        </div>
        <div class="rag-card-body">
            <div class="rag-grid rag-grid-cols-1 md:rag-grid-cols-2 rag-gap-6">
                <div>
                    <h3 class="rag-font-medium rag-mb-2">Supported File Types</h3>
                    <ul class="list-disc list-inside space-y-1 text-sm text-gray-600">
                        <li><strong>Text files:</strong> .txt, .md</li>
                        <li><strong>Documents:</strong> .pdf, .docx</li>
                        <li><strong>Web content:</strong> .html</li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="rag-font-medium rag-mb-2">Processing Notes</h3>
                    <ul class="list-disc list-inside space-y-1 text-sm text-gray-600">
                        <li>Large documents will be automatically chunked</li>
                        <li>Processing may take a few minutes</li>
                        <li>You'll receive notifications when processing completes</li>
                        <li>Documents are searchable once processing is complete</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Auto-fill title from selected file
document.addEventListener('change', function(e) {
    if (e.target.name === 'file' && e.target.files.length > 0) {
        const titleInput = document.getElementById('title');
        if (!titleInput.value) {
            const filename = e.target.files[0].name;
            const title = filename.replace(/\.[^/.]+$/, ""); // Remove extension
            titleInput.value = title;
        }
    }
});

// Clear content when file is selected and vice versa
document.addEventListener('change', function(e) {
    if (e.target.name === 'file' && e.target.files.length > 0) {
        const contentTextarea = document.querySelector('textarea[name="content"]');
        contentTextarea.value = '';
    }
});

document.addEventListener('input', function(e) {
    if (e.target.name === 'content' && e.target.value.trim()) {
        const fileInput = document.querySelector('input[name="file"]');
        fileInput.value = '';
    }
});
</script>
@endpush
@endsection