/**
 * AIKAFLOW Panels - Gallery, History
 * 
 * Handles the right-side panels for viewing generated content
 * and workflow run history.
 * 
 * History data is fetched from the database via API.
 * Gallery data is stored in localStorage for now.
 * 
 * NOTE: Share functionality is now provided by the aflow-share plugin.
 */

(function () {
    'use strict';

    class PanelsManager {
        constructor() {
            this.galleryPanel = document.getElementById('gallery-panel');
            this.historyPanel = document.getElementById('history-panel');
            // Share Modal is now handled by aflow-share plugin

            this.currentWorkflowId = null;
            this.historyCache = { current: [], completed: [], aborted: [] };
            this.galleryData = [];
            this.isLoadingHistory = false;

            // Scroll pagination state
            this.historyPage = { current: 1, completed: 1, aborted: 1 };
            this.hasMoreHistory = { current: false, completed: false, aborted: false };
            this.isLoadingMore = false;

            this.init();
        }

        init() {
            this.setupEventListeners();
            this.loadGalleryFromStorage();
        }

        setupEventListeners() {
            // Gallery button
            document.getElementById('btn-gallery')?.addEventListener('click', () => {
                this.openGallery();
            });

            // History button
            document.getElementById('btn-history')?.addEventListener('click', () => {
                this.openHistory();
            });

            // Share button - handled by aflow-share plugin

            // Close buttons
            document.getElementById('btn-close-gallery')?.addEventListener('click', () => {
                this.closeGallery();
            });

            document.getElementById('btn-close-history')?.addEventListener('click', () => {
                this.closeHistory();
            });

            // Refresh history button
            document.getElementById('btn-refresh-history')?.addEventListener('click', () => {
                const currentTab = document.querySelector('.history-tab.active')?.dataset.tab || 'current';
                this.fetchHistory(currentTab, true);
            });

            // Share close handlers - handled by aflow-share plugin

            // History tabs
            document.querySelectorAll('.history-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    this.switchHistoryTab(tab.dataset.tab);
                });
            });

            // Share link generation/copy - handled by aflow-share plugin

            // Listen for workflow run events (to refresh history)
            document.addEventListener('workflow:run:complete', () => {
                // Refresh history when a workflow completes
                this.refreshHistoryIfOpen();
            });

            document.addEventListener('workflow:run:error', () => {
                this.refreshHistoryIfOpen();
            });

            document.addEventListener('workflow:run:aborted', () => {
                this.refreshHistoryIfOpen();
            });

            // Listen for generated content
            document.addEventListener('node:output:generated', (e) => {
                this.addToGallery(e.detail);
            });

            // Cleanup aborted executions button
            document.getElementById('btn-cleanup-aborted')?.addEventListener('click', async () => {
                // Confirm before deleting
                if (window.ConfirmDialog) {
                    const confirmed = await ConfirmDialog.show({
                        title: 'Clear Aborted Runs?',
                        message: 'This will permanently delete all aborted/cancelled workflow executions. This action cannot be undone.',
                        confirmText: 'Clear All',
                        cancelText: 'Cancel',
                        type: 'danger'
                    });
                    if (confirmed) {
                        this.cleanupAbortedExecutions();
                    }
                } else {
                    // Fallback if no ConfirmDialog available
                    if (confirm('Clear all aborted workflow runs? This cannot be undone.')) {
                        this.cleanupAbortedExecutions();
                    }
                }
            });

            // Scroll pagination for history list
            const historyList = document.getElementById('history-list');
            historyList?.addEventListener('scroll', () => {
                if (this.isLoadingMore) return;

                const currentTab = document.querySelector('.history-tab.active')?.dataset.tab || 'current';
                if (!this.hasMoreHistory[currentTab]) return;

                // Check if scrolled near bottom (100px threshold)
                if (historyList.scrollTop + historyList.clientHeight >= historyList.scrollHeight - 100) {
                    this.loadMoreHistory(currentTab);
                }
            });
        }

        // Load more history items (append mode)
        async loadMoreHistory(tab) {
            if (this.isLoadingMore || !this.hasMoreHistory[tab]) return;

            this.isLoadingMore = true;
            this.historyPage[tab]++;

            const list = document.getElementById('history-list');

            // Add loading indicator at bottom
            const loadingIndicator = document.createElement('div');
            loadingIndicator.className = 'history-loading-more text-center py-4';
            loadingIndicator.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 text-primary-500 animate-spin mx-auto"></i>';
            list?.appendChild(loadingIndicator);
            if (window.lucide) lucide.createIcons({ nodes: [loadingIndicator] });

            try {
                const response = await API.get(`/workflows/history.php?status=${tab}&limit=20&page=${this.historyPage[tab]}`);

                if (response.success) {
                    // Append to existing cache
                    this.historyCache[tab] = [...this.historyCache[tab], ...(response.history || [])];
                    this.hasMoreHistory[tab] = response.pagination?.hasMore || false;

                    // Re-render with all items
                    this.renderHistory(tab);
                }
            } catch (error) {
                console.error('Error loading more history:', error);
            } finally {
                this.isLoadingMore = false;
                loadingIndicator?.remove();
            }
        }

        // ========== Gallery ==========

        openGallery() {
            this.closeHistory();
            this.galleryPanel?.classList.add('open');
            this.renderGallery();
        }

        closeGallery() {
            this.galleryPanel?.classList.remove('open');
        }

        async addToGallery(item) {
            try {
                // Skip duplicates (check if this URL was already added)
                const existingItem = this.galleryData.find(g => g.url === item.url);
                if (existingItem) {
                    return; // Already in gallery
                }

                // Save to database
                const response = await API.addToGallery({
                    type: item.type || 'image',
                    url: item.url,
                    nodeId: item.nodeId,
                    nodeType: item.nodeType,
                    workflowId: this.currentWorkflowId
                });

                if (response.success) {
                    // Add to local cache for immediate display
                    const galleryItem = {
                        id: response.id,
                        type: item.type || 'image',
                        url: item.url,
                        nodeId: item.nodeId,
                        nodeType: item.nodeType,
                        created_at: new Date().toISOString(),
                        workflow_id: this.currentWorkflowId
                    };
                    this.galleryData.unshift(galleryItem);

                    // Limit local cache size
                    if (this.galleryData.length > 50) {
                        this.galleryData = this.galleryData.slice(0, 50);
                    }

                    // Update if panel is open
                    if (this.galleryPanel?.classList.contains('open')) {
                        this.renderGallery();
                    }
                }
            } catch (error) {
                console.error('Failed to add to gallery:', error);
            }
        }

        renderGallery() {
            const grid = document.getElementById('gallery-grid');
            const empty = document.getElementById('gallery-empty');

            if (!grid) return;

            // Filter by current workflow (API returns workflow_id, local cache uses workflow_id too)
            const items = this.galleryData.filter(item =>
                !this.currentWorkflowId || item.workflow_id === this.currentWorkflowId || item.workflowId === this.currentWorkflowId
            );

            if (items.length === 0) {
                grid.innerHTML = '';
                empty?.classList.remove('hidden');
                return;
            }

            empty?.classList.add('hidden');

            grid.innerHTML = items.map(item => {
                const isVideo = item.type === 'video' || item.url?.includes('.mp4') || item.url?.includes('.webm');

                return `
                    <div class="gallery-item" data-item-id="${item.id}">
                        ${isVideo
                        ? `<video src="${item.url}" class="gallery-item-media" muted loop></video>`
                        : `<img src="${item.url}" class="gallery-item-media" loading="lazy" alt="Generated content">`
                    }
                        <div class="gallery-item-overlay">
                            <button class="gallery-item-btn" data-action="view" title="View">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </button>
                            <button class="gallery-item-btn" data-action="download" title="Download">
                                <i data-lucide="download" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            // Initialize icons
            if (window.lucide) {
                lucide.createIcons({ root: grid });
            }

            // Add event listeners
            grid.querySelectorAll('.gallery-item-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const itemEl = btn.closest('.gallery-item');
                    const itemId = itemEl?.dataset.itemId;
                    const item = this.galleryData.find(i => String(i.id) === String(itemId));

                    if (btn.dataset.action === 'view' && item) {
                        window.open(item.url, '_blank');
                    } else if (btn.dataset.action === 'download' && item) {
                        this.downloadItem(item);
                    }
                });
            });
        }

        downloadItem(item) {
            const link = document.createElement('a');
            link.href = item.url;
            link.download = `aikaflow_${item.type}_${Date.now()}`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // ========== History (Database-backed) ==========

        openHistory() {
            this.closeGallery();
            this.historyPanel?.classList.add('open');
            this.fetchHistory('current');
        }

        closeHistory() {
            this.historyPanel?.classList.remove('open');
        }

        switchHistoryTab(tab) {
            document.querySelectorAll('.history-tab').forEach(t => {
                t.classList.toggle('active', t.dataset.tab === tab);
            });

            // Show/hide cleanup button based on tab
            const cleanupAction = document.getElementById('history-cleanup-action');
            if (cleanupAction) {
                cleanupAction.classList.toggle('hidden', tab !== 'aborted');
            }

            this.fetchHistory(tab);
        }

        refreshHistoryIfOpen() {
            if (this.historyPanel?.classList.contains('open')) {
                const currentTab = document.querySelector('.history-tab.active')?.dataset.tab || 'current';
                this.fetchHistory(currentTab, true);
            }
        }

        async cleanupAbortedExecutions() {
            try {
                const response = await API.cleanupExecutions('aborted');
                if (response.success) {
                    if (window.Toast) {
                        Toast.success('Cleanup complete', `Deleted ${response.deleted} aborted execution(s)`);
                    }
                    // Refresh history
                    this.fetchHistory('aborted', true);
                } else {
                    if (window.Toast) {
                        Toast.error('Cleanup failed', response.error || 'Unknown error');
                    }
                }
            } catch (error) {
                console.error('Cleanup error:', error);
                if (window.Toast) {
                    Toast.error('Cleanup failed', error.message);
                }
            }
        }

        /**
         * Fetch history from the database API
         * @param {string} tab - 'current', 'completed', or 'aborted'
         * @param {boolean} forceRefresh - Force refresh even if cached
         */
        async fetchHistory(tab = 'current', forceRefresh = false) {
            const list = document.getElementById('history-list');
            const empty = document.getElementById('history-empty');

            if (!list) return;

            // Show loading state
            if (this.isLoadingHistory) return;
            this.isLoadingHistory = true;

            // Reset pagination for this tab
            this.historyPage[tab] = 1;
            this.hasMoreHistory[tab] = false;

            list.innerHTML = `
                <div class="text-center py-8">
                    <i data-lucide="loader-2" class="w-8 h-8 text-primary-500 animate-spin mx-auto mb-2"></i>
                    <p class="text-dark-400 text-sm">Loading history...</p>
                </div>
            `;
            empty?.classList.add('hidden');

            if (window.lucide) {
                lucide.createIcons({ root: list });
            }

            try {
                const response = await API.get(`/workflows/history.php?status=${tab}&limit=20&page=1`);

                if (response.success) {
                    this.historyCache[tab] = response.history || [];
                    this.hasMoreHistory[tab] = response.pagination?.hasMore || false;
                    this.renderHistory(tab);
                } else {
                    throw new Error(response.error || 'Failed to load history');
                }
            } catch (error) {
                console.error('Error fetching history:', error);
                list.innerHTML = `
                    <div class="text-center py-8">
                        <i data-lucide="alert-circle" class="w-8 h-8 text-red-500 mx-auto mb-2"></i>
                        <p class="text-dark-400 text-sm">Failed to load history</p>
                        <button id="btn-retry-history" class="text-primary-400 text-sm mt-2 hover:underline">Retry</button>
                    </div>
                `;
                if (window.lucide) {
                    lucide.createIcons({ root: list });
                }
                document.getElementById('btn-retry-history')?.addEventListener('click', () => {
                    this.fetchHistory(tab, true);
                });
            } finally {
                this.isLoadingHistory = false;
            }
        }

        renderHistory(tab = 'current') {
            const list = document.getElementById('history-list');
            const empty = document.getElementById('history-empty');

            if (!list) return;

            const runs = this.historyCache[tab] || [];

            if (runs.length === 0) {
                list.innerHTML = '';
                empty?.classList.remove('hidden');
                return;
            }

            empty?.classList.add('hidden');

            list.innerHTML = runs.map(run => {
                const statusIcon = run.status === 'running' ? 'loader' :
                    run.status === 'pending' ? 'clock' :
                        run.status === 'queued' ? 'list' :
                            run.status === 'completed' ? 'check-circle' :
                                run.status === 'cancelled' ? 'octagon' : 'x-circle';
                const statusClass = run.status === 'cancelled' ? 'aborted' : run.status;
                const timeAgo = this.formatTimeAgo(run.startedAt);
                const isRunning = run.status === 'running';
                const isPending = run.status === 'pending' || run.status === 'queued';
                const isClickable = run.status === 'running' || run.status === 'completed';

                return `
                    <div class="history-item ${isClickable ? 'cursor-pointer hover:bg-dark-700/50' : 'opacity-70'}" 
                         data-execution-id="${run.id}" 
                         data-status="${run.status}">
                        <div class="history-item-header">
                            <div class="history-item-status ${statusClass}">
                                <i data-lucide="${statusIcon}" class="w-4 h-4 ${isRunning ? 'animate-spin' : ''}"></i>
                                <span>${this.formatStatus(run.status)}</span>
                            </div>
                            <span class="history-item-time">${timeAgo}</span>
                        </div>
                        <p class="text-sm text-dark-200 mb-2">${Utils.escapeHtml(run.workflowName)}</p>
                        ${run.progress !== undefined ? `
                            <div class="mb-2">
                                <div class="h-1 bg-dark-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-primary-500 transition-all" style="width: ${run.progress}%"></div>
                                </div>
                                <span class="text-xs text-dark-500">${run.completedNodes}/${run.totalNodes} nodes</span>
                            </div>
                        ` : ''}
                        <div class="history-item-nodes">
                            ${(run.nodes || []).slice(0, 3).map(node => `
                                <span class="history-item-node">
                                    <i data-lucide="box" class="w-3 h-3"></i>
                                    ${Utils.escapeHtml(node.name || node.type || 'Node')}
                                </span>
                            `).join('')}
                            ${(run.nodes?.length || 0) > 3 ? `<span class="history-item-node">+${run.nodes.length - 3} more</span>` : ''}
                        </div>
                        ${run.status === 'running' ? `
                            <p class="text-xs text-primary-400 mt-2">
                                <i data-lucide="eye" class="w-3 h-3 inline mr-1"></i>Click to view progress
                            </p>
                        ` : ''}
                        ${run.status === 'completed' ? `
                            <p class="text-xs text-primary-400 mt-2">
                                <i data-lucide="image" class="w-3 h-3 inline mr-1"></i>Click to view results
                            </p>
                        ` : ''}
                        ${isPending ? `
                            <p class="text-xs text-dark-500 mt-2">
                                <i data-lucide="hourglass" class="w-3 h-3 inline mr-1"></i>Waiting in queue
                            </p>
                        ` : ''}
                        ${run.error ? `
                            <p class="text-xs text-red-400 mt-2">${Utils.escapeHtml(run.error)}</p>
                        ` : ''}
                    </div>
                `;
            }).join('');

            // Initialize icons
            if (window.lucide) {
                lucide.createIcons({ root: list });
            }

            // Add click handlers for running items only to open execution modal
            list.querySelectorAll('.history-item[data-status="running"]').forEach(item => {
                item.addEventListener('click', () => {
                    // Close history panel
                    this.closeHistory();
                    // Open execution modal
                    if (window.Modals) {
                        window.Modals.open('modal-execution');
                    }
                });
            });

            // Add click handlers for completed items to show results modal
            list.querySelectorAll('.history-item[data-status="completed"]').forEach(item => {
                item.addEventListener('click', async () => {
                    const executionId = item.dataset.executionId;
                    await this.showResultsModal(executionId);
                });
            });
        }

        /**
         * Show results modal for a completed execution
         */
        async showResultsModal(executionId) {
            try {
                // Fetch execution details
                const response = await API.get(`/workflows/status.php?id=${executionId}`);

                if (!response.success) {
                    Toast.error('Failed to load results');
                    return;
                }

                const results = response.allResults || [];
                const nodeStatuses = response.nodeStatuses || [];

                // Get results from nodeStatuses if allResults is empty
                let allResults = results.length > 0 ? results :
                    nodeStatuses.filter(ns => ns.resultUrl).map(ns => ({
                        node_id: ns.nodeId,
                        node_type: ns.nodeType,
                        url: ns.resultUrl
                    }));

                // Filter out input nodes (they are not generated results)
                const inputNodeTypes = ['image-input', 'text-input', 'audio-input', 'video-input', 'file-input', 'manual-trigger'];
                allResults = allResults.filter(result => {
                    const nodeType = result.node_type || '';
                    // Skip input category nodes
                    if (inputNodeTypes.includes(nodeType)) return false;
                    // Also check if it's in input category via NodeManager
                    const nodeDef = window.editorInstance?.nodeManager?.getNodeDefinition(nodeType);
                    if (nodeDef?.category === 'input') return false;
                    return true;
                });

                if (allResults.length === 0) {
                    Toast.info('No results', 'This execution has no generated content');
                    return;
                }

                // Create modal HTML
                const modalHtml = `
                    <div id="results-modal" class="fixed inset-0 bg-black/80 flex items-center justify-center z-[200] backdrop-blur-sm">
                        <div class="bg-dark-900 rounded-2xl border border-dark-700 w-full max-w-4xl mx-4 shadow-2xl max-h-[90vh] flex flex-col">
                            <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-dark-50 flex items-center gap-2">
                                    <i data-lucide="images" class="w-5 h-5 text-primary-500"></i>
                                    Execution Results (${allResults.length})
                                </h3>
                                <button id="btn-close-results" class="p-2 text-dark-400 hover:text-dark-50 rounded-lg hover:bg-dark-800 transition-colors">
                                    <i data-lucide="x" class="w-5 h-5"></i>
                                </button>
                            </div>
                            <div class="p-4 overflow-y-auto custom-scrollbar flex-1">
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                    ${allResults.map(result => {
                    // Detect media type from URL
                    const url = result.url || '';
                    const isVideo = /\.(mp4|webm|mov|avi)($|\?)/i.test(url);
                    const isAudio = /\.(mp3|wav|ogg|m4a)($|\?)/i.test(url);

                    // Get proper node name from definition
                    const nodeType = result.node_type || 'Unknown';
                    const nodeDef = window.editorInstance?.nodeManager?.getNodeDefinition(nodeType);
                    const nodeName = nodeDef?.name || nodeType.replace(/-/g, ' ').replace(/aflow\s*/gi, '').replace(/\b\w/g, l => l.toUpperCase());

                    return `
                                            <div class="relative group rounded-lg overflow-hidden bg-dark-800 border border-dark-700">
                                                ${isVideo ? `
                                                    <video src="${url}" class="w-full aspect-video object-cover" controls></video>
                                                ` : isAudio ? `
                                                    <div class="p-4 flex items-center justify-center aspect-video bg-dark-700">
                                                        <audio src="${url}" controls class="w-full"></audio>
                                                    </div>
                                                ` : `
                                                    <img src="${url}" class="w-full aspect-video object-cover" alt="Result">
                                                `}
                                                <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex items-end p-3 pointer-events-none">
                                                    <div class="flex-1">
                                                        <p class="text-xs text-dark-300">${Utils.escapeHtml(nodeName)}</p>
                                                    </div>
                                                </div>
                                                <div class="absolute bottom-2 right-2 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <a href="${url}" target="_blank" class="p-2 bg-dark-800/80 rounded-lg hover:bg-dark-700 transition-colors">
                                                        <i data-lucide="external-link" class="w-4 h-4 text-white"></i>
                                                    </a>
                                                    <a href="${url}" download class="p-2 bg-dark-800/80 rounded-lg hover:bg-dark-700 transition-colors">
                                                        <i data-lucide="download" class="w-4 h-4 text-white"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        `;
                }).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // Add modal to DOM
                document.body.insertAdjacentHTML('beforeend', modalHtml);

                // Initialize icons
                const modal = document.getElementById('results-modal');
                if (window.lucide) {
                    lucide.createIcons({ nodes: [modal] });
                }

                // Close handlers
                const closeBtn = document.getElementById('btn-close-results');
                closeBtn?.addEventListener('click', () => modal.remove());
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) modal.remove();
                });

                // Close on Escape
                const escHandler = (e) => {
                    if (e.key === 'Escape') {
                        modal.remove();
                        document.removeEventListener('keydown', escHandler);
                    }
                };
                document.addEventListener('keydown', escHandler);

            } catch (error) {
                console.error('Failed to show results:', error);
                Toast.error('Failed to load results', error.message);
            }
        }

        formatStatus(status) {
            // Try to get translated status label first
            if (window.t) {
                const translatedStatus = window.t(`panels.${status}`);
                if (translatedStatus && translatedStatus !== `panels.${status}`) {
                    return translatedStatus;
                }
            }
            // Fallback to hardcoded labels
            const statusLabels = {
                'running': 'Running',
                'pending': 'Pending',
                'queued': 'Queued',
                'completed': 'Completed',
                'failed': 'Failed',
                'cancelled': 'Aborted'
            };
            return statusLabels[status] || status.charAt(0).toUpperCase() + status.slice(1);
        }

        formatTimeAgo(dateString) {
            if (!dateString) return '';

            // Server returns dates in UTC without timezone indicator
            // Add 'Z' to indicate UTC, or replace space with 'T' for ISO format
            let normalizedDate = dateString;
            if (!dateString.includes('T') && !dateString.includes('Z')) {
                // Convert "2026-01-15 15:44:49" to "2026-01-15T15:44:49Z" (UTC)
                normalizedDate = dateString.replace(' ', 'T') + 'Z';
            }

            const date = new Date(normalizedDate);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            // Try to get translated time strings
            const t = window.t;
            const justNow = t ? t('time.just_now') : 'Just now';

            if (seconds < 0) return (justNow !== 'time.just_now') ? justNow : 'Just now'; // Future date (clock skew)
            if (seconds < 60) return (justNow !== 'time.just_now') ? justNow : 'Just now';
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
            if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
            return date.toLocaleDateString();
        }

        // ========== Storage (Gallery - Database-backed) ==========

        setCurrentWorkflow(workflowId) {
            this.currentWorkflowId = workflowId;
        }

        async saveGalleryToStorage() {
            // Gallery is now saved automatically to database via addToGallery
            // This method is kept for backward compatibility
        }

        async loadGalleryFromDatabase() {
            try {
                const response = await API.getGallery({
                    workflowId: this.currentWorkflowId,
                    limit: 50
                });
                if (response.success) {
                    this.galleryData = response.items || [];
                }
            } catch (error) {
                console.error('Failed to load gallery from database:', error);
                this.galleryData = [];
            }
        }

        loadGalleryFromStorage() {
            // Skip in viewer mode (unauthenticated)
            const isViewerMode = document.body.classList.contains('read-only-mode') ||
                window.location.pathname.includes('view');
            if (isViewerMode) {
                this.galleryData = [];
                return;
            }
            // Deprecated - use loadGalleryFromDatabase instead
            this.loadGalleryFromDatabase();
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.PanelsManager = new PanelsManager();
        });
    } else {
        window.PanelsManager = new PanelsManager();
    }
})();
