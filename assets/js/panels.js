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
                const isRunning = run.status === 'running' || run.status === 'pending' || run.status === 'queued';

                return `
                    <div class="history-item ${isRunning ? 'cursor-pointer hover:bg-dark-700/50' : ''}" 
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
                        ${run.resultUrl ? `
                            <a href="${run.resultUrl}" target="_blank" class="text-xs text-primary-400 hover:underline mt-2 inline-block">
                                <i data-lucide="external-link" class="w-3 h-3 inline mr-1"></i>View Result
                            </a>
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

            // Add click handlers for running items to open execution modal
            list.querySelectorAll('.history-item[data-status="running"], .history-item[data-status="pending"], .history-item[data-status="queued"]').forEach(item => {
                item.addEventListener('click', () => {
                    // Close history panel
                    this.closeHistory();
                    // Open execution modal
                    if (window.Modals) {
                        window.Modals.open('modal-execution');
                    }
                });
            });
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
            const date = new Date(dateString);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            // Try to get translated time strings
            const t = window.t;
            const justNow = t ? t('time.just_now') : 'Just now';

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
