/**
 * AIKAFLOW Share Plugin
 * 
 * Adds workflow sharing functionality via public links.
 * This plugin injects UI elements and handles share link generation.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-share';

    /**
     * SharePlugin class - handles all sharing functionality
     */
    class SharePlugin {
        constructor() {
            this.modal = null;
            this.button = null;
            this.isInitialized = false;
        }

        /**
         * Initialize the share plugin
         */
        init() {
            if (this.isInitialized) return;

            // Inject UI elements
            this.injectButton();
            this.injectModal();

            // Setup event listeners
            this.setupEventListeners();

            this.isInitialized = true;
        }

        /**
         * Inject share button into canvas controls
         */
        injectButton() {
            const controlsContainer = document.querySelector('.canvas-controls');
            if (!controlsContainer) {
                console.warn('Share Plugin: Canvas controls container not found');
                return;
            }

            // Check if button already exists
            if (document.getElementById('btn-share')) return;

            // Create button
            this.button = document.createElement('button');
            this.button.id = 'btn-share';
            this.button.className = 'canvas-control-btn';
            this.button.title = 'Share Workflow';
            this.button.innerHTML = '<i data-lucide="share-2" class="w-4 h-4"></i>';

            // Insert before the last element or at the end
            controlsContainer.appendChild(this.button);

            // Initialize Lucide icon
            if (window.lucide) {
                lucide.createIcons({ nodes: [this.button] });
            }
        }

        /**
         * Inject share modal into the DOM
         */
        injectModal() {
            // Check if modal already exists
            if (document.getElementById('share-modal')) return;

            const modalHtml = `
                <div id="share-modal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
                    <div class="bg-dark-800 rounded-2xl border border-dark-700 w-full max-w-md mx-4 overflow-hidden">
                        <!-- Share Header -->
                        <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i data-lucide="share-2" class="w-5 h-5 text-green-400"></i>
                                <h3 class="font-semibold text-dark-50" data-i18n="share.title">Share Workflow</h3>
                            </div>
                            <button id="btn-close-share"
                                class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">
                                <i data-lucide="x" class="w-5 h-5"></i>
                            </button>
                        </div>

                        <!-- Share Content -->
                        <div class="p-4 space-y-4">
                            <p class="text-dark-400 text-sm" data-i18n="share.description">Share this workflow as read-only. Others can view but not run or edit.</p>

                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2" data-i18n="share.share_link">Share Link</label>
                                <div class="flex gap-2">
                                    <input type="text" id="share-link" readonly class="form-input flex-1 font-mono text-sm"
                                        placeholder="Generate share link...">
                                    <button id="btn-copy-share" class="btn-secondary px-3" title="Copy Link">
                                        <i data-lucide="copy" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center justify-between py-3 px-4 bg-dark-700/50 rounded-lg">
                                <div>
                                    <p class="text-sm font-medium text-dark-200" data-i18n="share.enable_share">Enable Share Link</p>
                                    <p class="text-xs text-dark-400" data-i18n="share.disable_access">Turn off to disable access</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="share-public" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <button id="btn-generate-share" class="btn-primary w-full">
                                <i data-lucide="link" class="w-4 h-4"></i>
                                <span data-i18n="share.generate_link">Generate Share Link</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // Append modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            this.modal = document.getElementById('share-modal');

            // Initialize Lucide icons in modal
            if (window.lucide) {
                lucide.createIcons({ root: this.modal });
            }
            // Translate the injected modal
            if (window.I18n?.translatePage) window.I18n.translatePage();
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Share button click
            document.getElementById('btn-share')?.addEventListener('click', () => {
                this.openShare();
            });

            // Close button
            document.getElementById('btn-close-share')?.addEventListener('click', () => {
                this.closeShare();
            });

            // Modal backdrop click
            this.modal?.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.closeShare();
                }
            });

            // Generate share link
            document.getElementById('btn-generate-share')?.addEventListener('click', () => {
                this.generateShareLink();
            });

            // Copy share link
            document.getElementById('btn-copy-share')?.addEventListener('click', () => {
                this.copyShareLink();
            });
        }

        /**
         * Open share modal
         */
        openShare() {
            this.modal?.classList.remove('hidden');
            const linkInput = document.getElementById('share-link');
            const btn = document.getElementById('btn-generate-share');
            if (linkInput) linkInput.value = '';

            // Check if share exists
            const editor = window.editorInstance || window.editor;
            const shareId = editor?.workflowManager?.currentWorkflow?.shareId;
            const shareIsPublic = editor?.workflowManager?.currentWorkflow?.shareIsPublic;

            // Set public toggle
            const publicToggle = document.getElementById('share-public');
            if (publicToggle) {
                // Default to true if not set (new share) or use existing state
                publicToggle.checked = (shareIsPublic !== undefined) ? shareIsPublic : true;
            }

            if (shareId && linkInput && btn) {
                // Construct link
                const url = new URL(window.location.href);
                const basePath = url.pathname.substring(0, url.pathname.lastIndexOf('/') + 1);
                linkInput.value = `${url.origin}${basePath}view?share=${shareId}`;

                // Update button
                btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Update Share Link';
                if (window.lucide) lucide.createIcons({ nodes: [btn] });
            } else if (btn) {
                // Reset button
                btn.innerHTML = '<i data-lucide="link" class="w-4 h-4"></i> Generate Share Link';
                if (window.lucide) lucide.createIcons({ nodes: [btn] });
            }
        }

        /**
         * Close share modal
         */
        closeShare() {
            this.modal?.classList.add('hidden');
        }

        /**
         * Generate share link
         */
        async generateShareLink() {
            const btn = document.getElementById('btn-generate-share');
            const linkInput = document.getElementById('share-link');
            const isPublic = document.getElementById('share-public')?.checked;

            if (!btn || !linkInput) return;

            const editor = window.editorInstance || window.editor;

            // Check if workflow is saved first
            const currentId = editor?.workflowManager?.currentWorkflow?.id;

            if (!currentId) {
                if (window.Toast) Toast.info('Please save the workflow to the database before sharing');
                editor?.saveWorkflow?.();
                return;
            }

            const workflowData = editor?.workflowManager?.serialize();

            if (!workflowData) {
                if (window.Toast) Toast.error('No workflow to share');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Generating...';
            if (window.lucide) lucide.createIcons({ nodes: [btn] });

            try {
                // Get base path dynamically
                const pathParts = window.location.pathname.split('/');
                const appPath = pathParts.length > 1 && pathParts[1] ? '/' + pathParts[1] : '';

                const response = await fetch(`${appPath}/api/share`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        workflow: workflowData,
                        isPublic: isPublic,
                        workflowId: currentId
                    })
                });

                const data = await response.json();

                if (data.success && data.shareId) {
                    const url = new URL(window.location.href);
                    const basePath = url.pathname.substring(0, url.pathname.lastIndexOf('/') + 1);
                    const shareUrl = `${url.origin}${basePath}view?share=${data.shareId}`;
                    linkInput.value = shareUrl;

                    // Update currentWorkflow shareId and isPublic
                    if (editor?.workflowManager?.currentWorkflow) {
                        editor.workflowManager.currentWorkflow.shareId = data.shareId;
                        editor.workflowManager.currentWorkflow.shareIsPublic = isPublic;
                    }

                    Toast?.success('Share link generated!');

                    // Update button text
                    btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Update Share Link';
                    if (window.lucide) lucide.createIcons({ nodes: [btn] });
                } else {
                    throw new Error(data.error || 'Failed to generate share link');
                }
            } catch (error) {
                Toast?.error(error.message);
                btn.innerHTML = '<i data-lucide="link" class="w-4 h-4"></i> Generate Share Link';
                if (window.lucide) lucide.createIcons({ nodes: [btn] });
            } finally {
                btn.disabled = false;
            }
        }

        /**
         * Copy share link to clipboard
         */
        copyShareLink() {
            const linkInput = document.getElementById('share-link');
            if (!linkInput || !linkInput.value) {
                Toast?.warning('Generate a share link first');
                return;
            }

            navigator.clipboard.writeText(linkInput.value).then(() => {
                Toast?.success('Link copied to clipboard!');
            }).catch(() => {
                linkInput.select();
                document.execCommand('copy');
                Toast?.success('Link copied!');
            });
        }
    }

    // Initialize plugin when DOM is ready
    function initPlugin() {
        // Wait for editor to be ready
        const checkInterval = setInterval(() => {
            if (window.editorInstance || document.querySelector('.canvas-controls')) {
                clearInterval(checkInterval);
                const sharePlugin = new SharePlugin();
                sharePlugin.init();
                window.SharePlugin = sharePlugin;
            }
        }, 100);

        // Timeout after 10 seconds
        setTimeout(() => clearInterval(checkInterval), 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlugin);
    } else {
        initPlugin();
    }

})();
