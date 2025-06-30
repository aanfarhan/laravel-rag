@extends('rag::layouts.app')

@section('title', 'Chat - RAG Knowledge Base')

@section('content')
<div class="rag-grid rag-grid-cols-1" x-data="ragChat()">
    <!-- Header -->
    <div class="rag-flex rag-items-center rag-justify-between rag-mb-6">
        <h1 class="rag-text-2xl rag-font-bold">AI Chat</h1>
        <button @click="clearMessages()" 
                class="rag-btn rag-btn-outline"
                :disabled="messages.length === 0">
            Clear Chat
        </button>
    </div>

    <!-- Chat Container -->
    <div class="rag-card" style="height: calc(100vh - 200px);">
        <!-- Messages Area -->
        <div class="rag-card-body rag-flex rag-flex-col" style="height: 100%;">
            <div x-ref="messages" 
                 class="flex-1 overflow-y-auto space-y-4 mb-4 p-4 bg-gray-50 rounded-lg">
                
                <!-- Welcome Message -->
                <div x-show="messages.length === 0" class="text-center text-gray-500 py-8">
                    <div class="text-lg mb-2">👋 Welcome to RAG Chat!</div>
                    <div>Ask me anything about your uploaded documents.</div>
                </div>

                <!-- Messages -->
                <template x-for="message in messages" :key="message.id">
                    <div class="flex" :class="{ 'justify-end': message.role === 'user' }">
                        <div class="max-w-3xl">
                            <!-- User Message -->
                            <div x-show="message.role === 'user'" 
                                 class="bg-blue-600 text-white rounded-lg px-4 py-2 ml-auto">
                                <div x-text="message.content"></div>
                                <div class="text-xs opacity-75 mt-1" x-text="formatTime(message.timestamp)"></div>
                            </div>

                            <!-- AI Message -->
                            <div x-show="message.role === 'assistant'" 
                                 class="bg-white border rounded-lg px-4 py-2"
                                 :class="{ 'border-red-200 bg-red-50': message.isError }">
                                <div class="flex items-start gap-2 mb-2">
                                    <span class="text-lg">🤖</span>
                                    <div class="flex-1">
                                        <div x-text="message.content" class="whitespace-pre-wrap"></div>
                                        
                                        <!-- Sources -->
                                        <div x-show="message.sources && message.sources.length > 0" class="mt-3">
                                            <div class="text-xs font-medium text-gray-600 mb-2">Sources:</div>
                                            <div class="space-y-1">
                                                <template x-for="source in message.sources" :key="source.document_id">
                                                    <div class="text-xs bg-gray-100 rounded px-2 py-1">
                                                        <a :href="`/rag/documents/${source.document_id}`" 
                                                           class="text-blue-600 hover:text-blue-800"
                                                           x-text="source.document_title"></a>
                                                        <span class="text-gray-500 ml-2" 
                                                              x-text="`(${Math.round(source.similarity_score * 100)}% match)`"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        <!-- Confidence -->
                                        <div x-show="message.confidence > 0" class="mt-2">
                                            <div class="text-xs text-gray-500">
                                                Confidence: <span x-text="Math.round(message.confidence * 100)"></span>%
                                            </div>
                                        </div>

                                        <div class="text-xs text-gray-400 mt-2" x-text="formatTime(message.timestamp)"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Typing Indicator -->
                <div x-show="isTyping" class="flex">
                    <div class="bg-white border rounded-lg px-4 py-2">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">🤖</span>
                            <div class="flex space-x-1">
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s;"></div>
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input Area -->
            <form @submit.prevent="sendMessage()" class="flex gap-2">
                <input x-model="newMessage" 
                       :disabled="isLoading"
                       placeholder="Ask a question about your documents..."
                       class="rag-input flex-1"
                       @keydown.enter.prevent="sendMessage()">
                <button type="submit" 
                        :disabled="isLoading || !newMessage.trim()"
                        class="rag-btn rag-btn-primary px-6">
                    <span x-show="!isLoading">Send</span>
                    <span x-show="isLoading">
                        <div class="rag-spinner w-4 h-4"></div>
                    </span>
                </button>
            </form>

            <!-- Quick Actions -->
            <div class="mt-3 flex gap-2 text-xs">
                <button @click="newMessage = 'What documents do I have uploaded?'" 
                        class="text-blue-600 hover:text-blue-800">
                    📚 My Documents
                </button>
                <button @click="newMessage = 'Give me a summary of the main topics covered.'" 
                        class="text-blue-600 hover:text-blue-800">
                    📝 Summarize Content
                </button>
                <button @click="newMessage = 'What are the key points I should know?'" 
                        class="text-blue-600 hover:text-blue-800">
                    🎯 Key Points
                </button>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
.animate-bounce {
    animation: bounce 1s infinite;
}

@keyframes bounce {
    0%, 20%, 53%, 80%, 100% {
        transform: translate3d(0,0,0);
    }
    40%, 43% {
        transform: translate3d(0,-8px,0);
    }
    70% {
        transform: translate3d(0,-4px,0);
    }
    90% {
        transform: translate3d(0,-1px,0);
    }
}
</style>
@endpush
@endsection