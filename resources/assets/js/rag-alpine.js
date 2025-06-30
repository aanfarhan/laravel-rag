// Laravel RAG Package - Alpine.js Components

// Global RAG App State
function ragApp() {
    return {
        loading: false,
        loadingMessage: 'Loading...',
        notifications: [],
        showNotifications: false,
        
        init() {
            // Set CSRF token for all AJAX requests
            this.setupCsrfToken();
            
            // Load initial notifications from localStorage
            this.loadNotifications();
            
            // Setup global error handling
            this.setupErrorHandling();
        },
        
        setupCsrfToken() {
            const token = document.querySelector('meta[name="csrf-token"]');
            if (token) {
                window.axios = window.axios || {};
                window.axios.defaults = window.axios.defaults || {};
                window.axios.defaults.headers = window.axios.defaults.headers || {};
                window.axios.defaults.headers.common = window.axios.defaults.headers.common || {};
                window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.getAttribute('content');
            }
        },
        
        setupErrorHandling() {
            window.addEventListener('unhandledrejection', (event) => {
                this.addNotification('error', 'Network Error', 'An unexpected error occurred');
                console.error('Unhandled promise rejection:', event.reason);
            });
        },
        
        // Loading state
        setLoading(state, message = 'Loading...') {
            this.loading = state;
            this.loadingMessage = message;
        },
        
        // Notifications
        addNotification(type, title, message) {
            const notification = {
                id: Date.now() + Math.random(),
                type: type,
                title: title,
                message: message,
                timestamp: new Date()
            };
            
            this.notifications.unshift(notification);
            
            // Limit to 10 notifications
            if (this.notifications.length > 10) {
                this.notifications = this.notifications.slice(0, 10);
            }
            
            this.saveNotifications();
            
            // Auto-remove after 5 seconds for success notifications
            if (type === 'success') {
                setTimeout(() => {
                    this.removeNotification(notification.id);
                }, 5000);
            }
        },
        
        removeNotification(id) {
            this.notifications = this.notifications.filter(n => n.id !== id);
            this.saveNotifications();
        },
        
        clearAllNotifications() {
            this.notifications = [];
            this.saveNotifications();
            this.showNotifications = false;
        },
        
        toggleNotifications() {
            this.showNotifications = !this.showNotifications;
        },
        
        loadNotifications() {
            try {
                const stored = localStorage.getItem('rag_notifications');
                if (stored) {
                    this.notifications = JSON.parse(stored).map(n => ({
                        ...n,
                        timestamp: new Date(n.timestamp)
                    }));
                }
            } catch (e) {
                console.warn('Failed to load notifications from localStorage');
            }
        },
        
        saveNotifications() {
            try {
                localStorage.setItem('rag_notifications', JSON.stringify(this.notifications));
            } catch (e) {
                console.warn('Failed to save notifications to localStorage');
            }
        },
        
        formatTime(timestamp) {
            const now = new Date();
            const diff = now - timestamp;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 86400000);
            
            if (minutes < 1) return 'Just now';
            if (minutes < 60) return `${minutes}m ago`;
            if (hours < 24) return `${hours}h ago`;
            return `${days}d ago`;
        },
        
        // HTTP helpers
        async request(url, options = {}) {
            this.setLoading(true, options.loadingMessage || 'Loading...');
            
            try {
                const response = await fetch(url, {
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest',
                        ...options.headers
                    },
                    ...options
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success === false) {
                    throw new Error(data.error || 'Request failed');
                }
                
                return data;
            } catch (error) {
                this.addNotification('error', 'Request Failed', error.message);
                throw error;
            } finally {
                this.setLoading(false);
            }
        }
    };
}

// RAG Chat Component
function ragChat() {
    return {
        messages: [],
        newMessage: '',
        isLoading: false,
        isTyping: false,
        streamingResponse: '',
        
        init() {
            this.loadMessages();
            this.$refs.messages?.scrollTo(0, this.$refs.messages.scrollHeight);
        },
        
        async sendMessage() {
            if (!this.newMessage.trim() || this.isLoading) return;
            
            const userMessage = {
                id: Date.now(),
                role: 'user',
                content: this.newMessage,
                timestamp: new Date()
            };
            
            this.messages.push(userMessage);
            this.newMessage = '';
            this.isLoading = true;
            this.isTyping = true;
            this.streamingResponse = '';
            
            this.scrollToBottom();
            this.saveMessages();
            
            try {
                const response = await fetch('/rag/ask', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        question: userMessage.content,
                        stream: false
                    })
                });
                
                const data = await response.json();
                
                if (data.success === false) {
                    throw new Error(data.error);
                }
                
                const aiMessage = {
                    id: Date.now() + 1,
                    role: 'assistant',
                    content: data.answer,
                    sources: data.sources || [],
                    confidence: data.confidence || 0,
                    timestamp: new Date()
                };
                
                this.messages.push(aiMessage);
                this.saveMessages();
                
            } catch (error) {
                const errorMessage = {
                    id: Date.now() + 1,
                    role: 'assistant',
                    content: 'Sorry, I encountered an error: ' + error.message,
                    timestamp: new Date(),
                    isError: true
                };
                
                this.messages.push(errorMessage);
                this.saveMessages();
            } finally {
                this.isLoading = false;
                this.isTyping = false;
                this.scrollToBottom();
            }
        },
        
        scrollToBottom() {
            this.$nextTick(() => {
                const messagesEl = this.$refs.messages;
                if (messagesEl) {
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                }
            });
        },
        
        clearMessages() {
            this.messages = [];
            this.saveMessages();
        },
        
        loadMessages() {
            try {
                const stored = localStorage.getItem('rag_chat_messages');
                if (stored) {
                    this.messages = JSON.parse(stored).map(m => ({
                        ...m,
                        timestamp: new Date(m.timestamp)
                    }));
                }
            } catch (e) {
                console.warn('Failed to load chat messages');
            }
        },
        
        saveMessages() {
            try {
                // Keep only last 50 messages
                const messagesToSave = this.messages.slice(-50);
                localStorage.setItem('rag_chat_messages', JSON.stringify(messagesToSave));
            } catch (e) {
                console.warn('Failed to save chat messages');
            }
        },
        
        formatTime(timestamp) {
            return timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    };
}

// RAG Upload Component
function ragUpload() {
    return {
        files: [],
        dragOver: false,
        uploadProgress: {},
        
        init() {
            // Setup drag and drop
            document.addEventListener('dragover', (e) => e.preventDefault());
            document.addEventListener('drop', (e) => e.preventDefault());
        },
        
        handleDrop(event) {
            this.dragOver = false;
            const files = Array.from(event.dataTransfer.files);
            this.addFiles(files);
        },
        
        handleFileSelect(event) {
            const files = Array.from(event.target.files);
            this.addFiles(files);
            event.target.value = ''; // Reset input
        },
        
        addFiles(newFiles) {
            newFiles.forEach(file => {
                const fileObj = {
                    id: Date.now() + Math.random(),
                    file: file,
                    name: file.name,
                    size: file.size,
                    type: file.type,
                    status: 'pending',
                    progress: 0,
                    error: null
                };
                
                this.files.push(fileObj);
            });
        },
        
        removeFile(fileId) {
            this.files = this.files.filter(f => f.id !== fileId);
        },
        
        async uploadFile(fileObj) {
            if (fileObj.status === 'uploading') return;
            
            fileObj.status = 'uploading';
            fileObj.progress = 0;
            
            const formData = new FormData();
            formData.append('file', fileObj.file);
            formData.append('title', fileObj.name);
            
            try {
                const response = await fetch('/rag/documents', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    },
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`Upload failed: ${response.statusText}`);
                }
                
                fileObj.status = 'completed';
                fileObj.progress = 100;
                
                // Add success notification
                this.$dispatch('notification', {
                    type: 'success',
                    title: 'Upload Complete',
                    message: `${fileObj.name} uploaded successfully`
                });
                
                // Remove from list after 3 seconds
                setTimeout(() => {
                    this.removeFile(fileObj.id);
                }, 3000);
                
            } catch (error) {
                fileObj.status = 'error';
                fileObj.error = error.message;
                
                this.$dispatch('notification', {
                    type: 'error',
                    title: 'Upload Failed',
                    message: `Failed to upload ${fileObj.name}: ${error.message}`
                });
            }
        },
        
        async uploadAll() {
            const pendingFiles = this.files.filter(f => f.status === 'pending');
            
            for (const file of pendingFiles) {
                await this.uploadFile(file);
                // Small delay between uploads
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        },
        
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    };
}

// RAG Search Component
function ragSearch() {
    return {
        query: '',
        results: [],
        isSearching: false,
        searchTimeout: null,
        
        init() {
            this.$watch('query', () => {
                this.debouncedSearch();
            });
        },
        
        debouncedSearch() {
            clearTimeout(this.searchTimeout);
            
            if (this.query.length < 2) {
                this.results = [];
                return;
            }
            
            this.searchTimeout = setTimeout(() => {
                this.performSearch();
            }, 300);
        },
        
        async performSearch() {
            if (!this.query.trim()) return;
            
            this.isSearching = true;
            
            try {
                const response = await fetch(`/rag/search?query=${encodeURIComponent(this.query)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.results = data.results;
                } else {
                    throw new Error(data.error);
                }
                
            } catch (error) {
                console.error('Search failed:', error);
                this.results = [];
            } finally {
                this.isSearching = false;
            }
        },
        
        clearSearch() {
            this.query = '';
            this.results = [];
        }
    };
}

// Document Status Component
function ragDocumentStatus(documentId) {
    return {
        status: {},
        polling: false,
        pollInterval: null,
        
        init() {
            this.loadStatus();
            this.startPolling();
        },
        
        destroy() {
            this.stopPolling();
        },
        
        async loadStatus() {
            try {
                const response = await fetch(`/rag/documents/${documentId}/status`);
                const data = await response.json();
                
                if (data.success) {
                    this.status = data.status;
                    
                    // Stop polling if completed or failed
                    if (['completed', 'failed'].includes(this.status.status)) {
                        this.stopPolling();
                    }
                }
            } catch (error) {
                console.error('Failed to load document status:', error);
            }
        },
        
        startPolling() {
            if (this.pollInterval) return;
            
            this.polling = true;
            this.pollInterval = setInterval(() => {
                this.loadStatus();
            }, 3000); // Poll every 3 seconds
        },
        
        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
            this.polling = false;
        },
        
        getStatusColor() {
            switch (this.status.status) {
                case 'completed': return 'text-green-600';
                case 'failed': return 'text-red-600';
                case 'processing': return 'text-blue-600';
                default: return 'text-gray-600';
            }
        },
        
        getProgressWidth() {
            return Math.max(0, Math.min(100, this.status.progress || 0));
        }
    };
}

// Statistics Component
function ragStats() {
    return {
        stats: {},
        loading: true,
        
        init() {
            this.loadStats();
            
            // Refresh stats every 30 seconds
            setInterval(() => {
                this.loadStats();
            }, 30000);
        },
        
        async loadStats() {
            try {
                const response = await fetch('/api/rag/stats');
                const data = await response.json();
                
                if (data.success) {
                    this.stats = data.stats;
                }
            } catch (error) {
                console.error('Failed to load stats:', error);
            } finally {
                this.loading = false;
            }
        }
    };
}

// Register global functions
window.ragApp = ragApp;
window.ragChat = ragChat;
window.ragUpload = ragUpload;
window.ragSearch = ragSearch;
window.ragDocumentStatus = ragDocumentStatus;
window.ragStats = ragStats;