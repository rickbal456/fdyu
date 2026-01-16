/**
 * AIKAFLOW - API Client
 * 
 * Handles all communication with the backend API.
 */

class APIClient {
    constructor() {
        this.baseUrl = window.AIKAFLOW?.apiUrl || '/api';
        this.csrfToken = window.AIKAFLOW?.csrf || this.getCsrfFromMeta();
    }

    /**
     * Get CSRF token from meta tag
     */
    getCsrfFromMeta() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    /**
     * Update CSRF token
     */
    setCsrfToken(token) {
        this.csrfToken = token;
    }

    /**
     * Make an API request
     * @param {string} endpoint - API endpoint
     * @param {Object} options - Fetch options
     * @returns {Promise<Object>}
     */
    async request(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : `${this.baseUrl}${endpoint}`;

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': this.csrfToken,
            ...options.headers
        };

        // Add API key if available
        if (options.apiKey) {
            headers['X-API-Key'] = options.apiKey;
        }

        const config = {
            method: options.method || 'GET',
            headers,
            credentials: 'same-origin',
            ...options
        };

        // Add body for non-GET requests
        if (options.body && config.method !== 'GET') {
            config.body = typeof options.body === 'string'
                ? options.body
                : JSON.stringify(options.body);
        }

        try {
            const response = await fetch(url, config);

            // Handle non-JSON responses
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return { success: true, data: await response.text() };
            }

            const data = await response.json();

            // Handle HTTP errors
            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            // Update CSRF token if provided
            if (data.csrf) {
                this.setCsrfToken(data.csrf);
            }

            return data;
        } catch (error) {
            console.error('API request error:', error);

            // Handle network errors
            if (error.name === 'TypeError' && error.message === 'Failed to fetch') {
                throw new Error('Network error. Please check your connection.');
            }

            throw error;
        }
    }

    /**
     * GET request
     */
    get(endpoint, options = {}) {
        return this.request(endpoint, { ...options, method: 'GET' });
    }

    /**
     * POST request
     */
    post(endpoint, body, options = {}) {
        return this.request(endpoint, { ...options, method: 'POST', body });
    }

    /**
     * PUT request
     */
    put(endpoint, body, options = {}) {
        return this.request(endpoint, { ...options, method: 'PUT', body });
    }

    /**
     * DELETE request
     */
    delete(endpoint, options = {}) {
        return this.request(endpoint, { ...options, method: 'DELETE' });
    }

    // ============================================
    // Authentication Endpoints
    // ============================================

    /**
     * Check authentication status
     */
    async checkAuth() {
        return this.get('/auth/me.php');
    }

    /**
     * Login
     */
    async login(email, password) {
        return this.post('/auth/login.php', { email, password });
    }

    /**
     * Logout
     */
    async logout() {
        return this.post('/auth/logout.php');
    }

    /**
     * Register
     */
    async register(email, username, password) {
        return this.post('/auth/register.php', { email, username, password });
    }

    // ============================================
    // Workflow Endpoints
    // ============================================

    /**
     * Get list of user's workflows
     */
    async getWorkflows(options = {}) {
        const params = new URLSearchParams();
        if (options.page) params.append('page', options.page);
        if (options.limit) params.append('limit', options.limit);
        if (options.search) params.append('search', options.search);

        const query = params.toString();
        return this.get(`/workflows/list.php${query ? '?' + query : ''}`);
    }

    /**
     * Get a single workflow
     */
    async loadWorkflow(workflowId) {
        return this.get(`/workflows/get.php?id=${workflowId}`);
    }

    /**
     * Save workflow
     */
    async saveWorkflow(workflowData) {
        return this.post('/workflows/save.php', workflowData);
    }

    /**
     * Delete workflow
     */
    async deleteWorkflow(workflowId) {
        return this.delete(`/workflows/delete.php?id=${workflowId}`);
    }

    /**
     * Duplicate workflow
     */
    async duplicateWorkflow(workflowId) {
        return this.post('/workflows/duplicate.php', { id: workflowId });
    }

    // ============================================
    // Execution Endpoints
    // ============================================

    /**
     * Execute a workflow
     */
    async executeWorkflow(data) {
        return this.post('/workflows/execute.php', data);
    }

    /**
     * Get execution status
     */
    async getExecutionStatus(executionId) {
        return this.get(`/workflows/status.php?id=${executionId}`);
    }

    /**
     * Cancel execution
     */
    async cancelExecution(executionId) {
        return this.post('/workflows/cancel.php', { id: executionId });
    }

    /**
     * Get execution history
     */
    async getExecutionHistory(workflowId, limit = 10) {
        return this.get(`/workflows/history.php?workflow_id=${workflowId}&limit=${limit}`);
    }

    /**
     * Cleanup executions
     * @param {string} type - 'aborted', 'failed', 'completed', 'old', 'all'
     * @param {number} days - Number of days for 'old' and 'completed' types
     */
    async cleanupExecutions(type = 'aborted', days = 30) {
        return this.post('/workflows/cleanup.php', { type, days });
    }

    /**
     * Delete a specific execution
     */
    async deleteExecution(executionId) {
        return this.request('/workflows/cleanup.php', {
            method: 'DELETE',
            body: { id: executionId }
        });
    }

    // ============================================
    // Media/File Endpoints
    // ============================================

    /**
     * Upload a file
     */
    async uploadFile(file, options = {}) {
        const formData = new FormData();
        formData.append('file', file);

        if (options.folder) {
            formData.append('folder', options.folder);
        }

        return this.request('/media/upload.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': this.csrfToken
                // Don't set Content-Type - browser will set it with boundary
            }
        });
    }

    /**
     * Get media list
     */
    async getMedia(options = {}) {
        const params = new URLSearchParams();
        if (options.type) params.append('type', options.type);
        if (options.page) params.append('page', options.page);
        if (options.limit) params.append('limit', options.limit);

        const query = params.toString();
        return this.get(`/media/list.php${query ? '?' + query : ''}`);
    }

    /**
     * Delete media
     */
    async deleteMedia(mediaId) {
        return this.delete(`/media/delete.php?id=${mediaId}`);
    }

    // ============================================
    // External API Proxy Endpoints
    // ============================================

    /**
     * Proxy request to RunningHub.ai
     */
    async runningHubRequest(action, data) {
        return this.post('/proxy/runninghub.php', { action, ...data });
    }

    /**
     * Proxy request to Kie.ai (Suno)
     */
    async kieRequest(action, data) {
        return this.post('/proxy/kie.php', { action, ...data });
    }

    /**
     * Proxy request to JsonCut.com
     */
    async jsonCutRequest(action, data) {
        return this.post('/proxy/jsoncut.php', { action, ...data });
    }

    /**
     * Check task status (for async operations)
     */
    async checkTaskStatus(taskId, provider = 'runninghub') {
        return this.get(`/proxy/status.php?task_id=${taskId}&provider=${provider}`);
    }

    // ============================================
    // User Settings Endpoints
    // ============================================

    /**
     * Get user settings
     */
    async getSettings() {
        const result = await this.get('/user/settings.php');
        // Populate global config with site settings for nodes to use
        if (result.success && result.siteConfig) {
            window.AIKAFLOW_CONFIG = window.AIKAFLOW_CONFIG || {};
            Object.assign(window.AIKAFLOW_CONFIG, result.siteConfig);
        }
        return result;
    }

    /**
     * Update user settings
     */
    async updateSettings(settings) {
        return this.post('/user/settings.php', settings);
    }

    /**
     * Regenerate API key
     */
    async regenerateApiKey() {
        return this.post('/user/regenerate-api-key.php');
    }

    /**
     * Change password
     */
    async changePassword(currentPassword, newPassword) {
        return this.post('/user/change-password.php', {
            current_password: currentPassword,
            new_password: newPassword
        });
    }

    // ============================================
    // User Preferences Endpoints (Database-backed)
    // ============================================

    /**
     * Get all user preferences
     */
    async getPreferences() {
        return this.get('/user/preferences.php');
    }

    /**
     * Get a specific preference
     */
    async getPreference(key) {
        return this.get(`/user/preferences.php?key=${encodeURIComponent(key)}`);
    }

    /**
     * Save a single preference
     */
    async savePreference(key, value) {
        return this.post('/user/preferences.php', { key, value });
    }

    /**
     * Save multiple preferences
     */
    async savePreferences(preferences) {
        return this.post('/user/preferences.php', { preferences });
    }

    /**
     * Delete a preference
     */
    async deletePreference(key) {
        return this.request('/user/preferences.php', {
            method: 'DELETE',
            body: { key }
        });
    }

    // ============================================
    // Workflow Autosave Endpoints (Database-backed)
    // ============================================

    /**
     * Get autosave data for a workflow
     */
    async getAutosave(workflowId = null) {
        const params = workflowId ? `?workflow_id=${workflowId}` : '';
        return this.get(`/workflows/autosave.php${params}`);
    }

    /**
     * Save autosave data
     */
    async saveAutosave(data, workflowId = null) {
        return this.post('/workflows/autosave.php', {
            data,
            workflowId
        });
    }

    /**
     * Clear autosave data
     */
    async clearAutosave(workflowId = null) {
        return this.request('/workflows/autosave.php', {
            method: 'DELETE',
            body: { workflowId }
        });
    }

    // ============================================
    // User Gallery Endpoints (Database-backed)
    // ============================================

    /**
     * Get gallery items
     */
    async getGallery(options = {}) {
        const params = new URLSearchParams();
        if (options.workflowId) params.append('workflow_id', options.workflowId);
        if (options.source) params.append('source', options.source);
        if (options.limit) params.append('limit', options.limit);
        if (options.offset) params.append('offset', options.offset);

        const query = params.toString();
        return this.get(`/user/gallery.php${query ? '?' + query : ''}`);
    }

    /**
     * Add item to gallery
     */
    async addToGallery(item) {
        return this.post('/user/gallery.php', item);
    }

    /**
     * Remove item from gallery
     */
    async removeFromGallery(itemId) {
        return this.request('/user/gallery.php', {
            method: 'DELETE',
            body: { id: itemId }
        });
    }

    // ============================================
    // Utility Methods
    // ============================================

    /**
     * Ping server to check connectivity
     */
    async ping() {
        try {
            const start = Date.now();
            await this.get('/ping.php');
            return { success: true, latency: Date.now() - start };
        } catch (error) {
            return { success: false, error: error.message };
        }
    }

    /**
     * Get server status
     */
    async getServerStatus() {
        return this.get('/status.php');
    }
}

// Create global instance
window.API = new APIClient();


/**
 * API Request Queue
 * For managing concurrent requests and rate limiting
 */
class APIRequestQueue {
    constructor(options = {}) {
        this.maxConcurrent = options.maxConcurrent || 5;
        this.retryAttempts = options.retryAttempts || 3;
        this.retryDelay = options.retryDelay || 1000;

        this.queue = [];
        this.running = 0;
        this.paused = false;
    }

    /**
     * Add request to queue
     */
    enqueue(requestFn, priority = 0) {
        return new Promise((resolve, reject) => {
            this.queue.push({
                fn: requestFn,
                priority,
                resolve,
                reject,
                attempts: 0
            });

            // Sort by priority (higher first)
            this.queue.sort((a, b) => b.priority - a.priority);

            this.processQueue();
        });
    }

    /**
     * Process queued requests
     */
    async processQueue() {
        if (this.paused || this.running >= this.maxConcurrent || this.queue.length === 0) {
            return;
        }

        const request = this.queue.shift();
        this.running++;

        try {
            const result = await request.fn();
            request.resolve(result);
        } catch (error) {
            request.attempts++;

            if (request.attempts < this.retryAttempts) {
                // Retry with exponential backoff
                setTimeout(() => {
                    this.queue.unshift(request);
                    this.processQueue();
                }, this.retryDelay * Math.pow(2, request.attempts - 1));
            } else {
                request.reject(error);
            }
        } finally {
            this.running--;
            this.processQueue();
        }
    }

    /**
     * Pause queue processing
     */
    pause() {
        this.paused = true;
    }

    /**
     * Resume queue processing
     */
    resume() {
        this.paused = false;
        this.processQueue();
    }

    /**
     * Clear queue
     */
    clear() {
        const pending = this.queue.splice(0);
        pending.forEach(request => {
            request.reject(new Error('Request cancelled'));
        });
    }

    /**
     * Get queue status
     */
    getStatus() {
        return {
            pending: this.queue.length,
            running: this.running,
            paused: this.paused
        };
    }
}

// Create global instance
window.APIQueue = new APIRequestQueue();


/**
 * Polling Manager
 * For managing long-running async operations
 */
class PollingManager {
    constructor() {
        this.pollers = new Map();
    }

    /**
     * Start polling for a task
     * @param {string} taskId - Task identifier
     * @param {Function} checkFn - Function to check status
     * @param {Object} options - Polling options
     */
    start(taskId, checkFn, options = {}) {
        const {
            interval = 2000,
            maxAttempts = 300,
            onProgress = () => { },
            onComplete = () => { },
            onError = () => { }
        } = options;

        // Stop existing poller for this task
        this.stop(taskId);

        let attempts = 0;

        const poll = async () => {
            try {
                attempts++;
                const result = await checkFn();

                if (result.completed) {
                    this.stop(taskId);
                    onComplete(result);
                    return;
                }

                if (result.failed) {
                    this.stop(taskId);
                    onError(new Error(result.error || 'Task failed'));
                    return;
                }

                onProgress(result);

                if (attempts >= maxAttempts) {
                    this.stop(taskId);
                    onError(new Error('Polling timeout'));
                    return;
                }

            } catch (error) {
                console.error('Polling error:', error);
                // Continue polling on transient errors
            }
        };

        // Initial poll
        poll();

        // Setup interval
        const pollerId = setInterval(poll, interval);

        this.pollers.set(taskId, {
            id: pollerId,
            startTime: Date.now(),
            attempts
        });

        return taskId;
    }

    /**
     * Stop polling for a task
     */
    stop(taskId) {
        const poller = this.pollers.get(taskId);
        if (poller) {
            clearInterval(poller.id);
            this.pollers.delete(taskId);
        }
    }

    /**
     * Stop all pollers
     */
    stopAll() {
        this.pollers.forEach((_, taskId) => this.stop(taskId));
    }

    /**
     * Check if task is being polled
     */
    isPolling(taskId) {
        return this.pollers.has(taskId);
    }

    /**
     * Get poller status
     */
    getStatus(taskId) {
        return this.pollers.get(taskId) || null;
    }
}

// Create global instance
window.Poller = new PollingManager();


/**
 * WebSocket Manager (for real-time updates)
 */
class WebSocketManager {
    constructor() {
        this.socket = null;
        this.url = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000;
        this.handlers = new Map();
        this.isConnected = false;
    }

    /**
     * Connect to WebSocket server
     */
    connect(url) {
        if (this.socket) {
            this.disconnect();
        }

        this.url = url;

        try {
            this.socket = new WebSocket(url);

            this.socket.onopen = () => {
                this.isConnected = true;
                this.reconnectAttempts = 0;
                this.emit('connected');
            };

            this.socket.onclose = () => {
                this.isConnected = false;
                this.emit('disconnected');
                this.tryReconnect();
            };

            this.socket.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.emit('error', error);
            };

            this.socket.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.emit(data.type || 'message', data);
                } catch (e) {
                    this.emit('message', event.data);
                }
            };

        } catch (error) {
            console.error('WebSocket connection error:', error);
            this.tryReconnect();
        }
    }

    /**
     * Disconnect from WebSocket server
     */
    disconnect() {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
        this.isConnected = false;
    }

    /**
     * Try to reconnect
     */
    tryReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            return;
        }

        this.reconnectAttempts++;
        const delay = this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1);

        setTimeout(() => {
            if (this.url) {
                this.connect(this.url);
            }
        }, delay);
    }

    /**
     * Send message
     */
    send(data) {
        if (!this.isConnected || !this.socket) {
            console.warn('WebSocket not connected');
            return false;
        }

        const message = typeof data === 'string' ? data : JSON.stringify(data);
        this.socket.send(message);
        return true;
    }

    /**
     * Register event handler
     */
    on(event, handler) {
        if (!this.handlers.has(event)) {
            this.handlers.set(event, []);
        }
        this.handlers.get(event).push(handler);
    }

    /**
     * Remove event handler
     */
    off(event, handler) {
        const handlers = this.handlers.get(event);
        if (handlers) {
            const index = handlers.indexOf(handler);
            if (index > -1) {
                handlers.splice(index, 1);
            }
        }
    }

    /**
     * Emit event
     */
    emit(event, data) {
        const handlers = this.handlers.get(event);
        if (handlers) {
            handlers.forEach(handler => handler(data));
        }
    }
}

// Create global instance
window.WS = new WebSocketManager();