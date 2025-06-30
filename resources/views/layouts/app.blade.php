<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('rag.ui.brand_name', 'RAG Knowledge Base'))</title>

    <!-- Alpine.js -->
    <script defer src="{{ config('rag.ui.alpine_js_cdn', 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js') }}"></script>
    
    <!-- RAG Styles -->
    <link href="{{ asset('vendor/rag/css/rag.css') }}" rel="stylesheet">
    
    @stack('styles')
</head>
<body class="rag-body">
    <div class="rag-app" x-data="ragApp()" x-init="init()">
        <!-- Navigation -->
        <nav class="rag-nav">
            <div class="rag-nav-container">
                <div class="rag-nav-brand">
                    <a href="{{ route('rag.dashboard') }}">
                        {{ config('rag.ui.brand_name', 'RAG Knowledge Base') }}
                    </a>
                </div>
                
                <div class="rag-nav-links">
                    <a href="{{ route('rag.dashboard') }}" 
                       class="rag-nav-link {{ request()->routeIs('rag.dashboard') ? 'active' : '' }}">
                        Dashboard
                    </a>
                    <a href="{{ route('rag.chat') }}" 
                       class="rag-nav-link {{ request()->routeIs('rag.chat') ? 'active' : '' }}">
                        Chat
                    </a>
                    <a href="{{ route('rag.documents.index') }}" 
                       class="rag-nav-link {{ request()->routeIs('rag.documents.*') ? 'active' : '' }}">
                        Documents
                    </a>
                </div>

                <div class="rag-nav-actions">
                    <button type="button" 
                            @click="toggleNotifications()" 
                            class="rag-btn-icon"
                            :class="{ 'has-notifications': notifications.length > 0 }">
                        <span class="rag-icon">🔔</span>
                        <span x-show="notifications.length > 0" 
                              x-text="notifications.length" 
                              class="rag-badge"></span>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Notifications -->
        <div x-show="showNotifications" 
             x-transition 
             class="rag-notifications-panel">
            <div class="rag-notifications-header">
                <h3>Notifications</h3>
                <button @click="clearAllNotifications()" class="rag-btn-sm">Clear All</button>
            </div>
            <div class="rag-notifications-list">
                <template x-for="notification in notifications" :key="notification.id">
                    <div class="rag-notification" :class="notification.type">
                        <div class="rag-notification-content">
                            <div class="rag-notification-title" x-text="notification.title"></div>
                            <div class="rag-notification-message" x-text="notification.message"></div>
                            <div class="rag-notification-time" x-text="formatTime(notification.timestamp)"></div>
                        </div>
                        <button @click="removeNotification(notification.id)" class="rag-notification-close">×</button>
                    </div>
                </template>
                <div x-show="notifications.length === 0" class="rag-notifications-empty">
                    No notifications
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <main class="rag-main">
            <!-- Flash Messages -->
            @if(session('success'))
                <div class="rag-alert rag-alert-success" x-data="{ show: true }" x-show="show">
                    <span>{{ session('success') }}</span>
                    <button @click="show = false" class="rag-alert-close">×</button>
                </div>
            @endif

            @if(session('error'))
                <div class="rag-alert rag-alert-error" x-data="{ show: true }" x-show="show">
                    <span>{{ session('error') }}</span>
                    <button @click="show = false" class="rag-alert-close">×</button>
                </div>
            @endif

            @if($errors->any())
                <div class="rag-alert rag-alert-error" x-data="{ show: true }" x-show="show">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button @click="show = false" class="rag-alert-close">×</button>
                </div>
            @endif

            @yield('content')
        </main>

        <!-- Global Loading Overlay -->
        <div x-show="loading" 
             x-transition:enter="rag-transition-enter"
             x-transition:enter-start="rag-transition-enter-start"
             x-transition:enter-end="rag-transition-enter-end"
             x-transition:leave="rag-transition-leave"
             x-transition:leave-start="rag-transition-leave-start"
             x-transition:leave-end="rag-transition-leave-end"
             class="rag-loading-overlay">
            <div class="rag-loading-spinner">
                <div class="rag-spinner"></div>
                <div x-text="loadingMessage" class="rag-loading-text"></div>
            </div>
        </div>
    </div>

    <!-- RAG JavaScript -->
    <script src="{{ asset('vendor/rag/js/rag-alpine.js') }}"></script>
    
    @stack('scripts')
</body>
</html>