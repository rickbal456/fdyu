/**
 * AIKAFLOW - Main Editor
 * 
 * Initializes and orchestrates all editor components.
 */

class Editor {
    constructor() {
        // Component instances
        this.nodeManager = null;
        this.connectionManager = null;
        this.canvasManager = null;
        this.propertiesPanel = null;
        this.workflowManager = null;

        // State
        this.isInitialized = false;
        this.settings = {
            autoSave: 60,
            confirmDelete: true,
            snapToGrid: true,
            showGrid: true,
            connectionStyle: 'bezier',
            connectionStyle: 'bezier',
            gridSize: 20,
            theme: 'dark'
        };


        // Bind methods
        this.handleNodeSelect = this.handleNodeSelect.bind(this);
        this.handleNodeDeselect = this.handleNodeDeselect.bind(this);
        this.handleNodeMove = this.handleNodeMove.bind(this);
        this.handleNodeUpdate = this.handleNodeUpdate.bind(this);
        this.handleNodeDelete = this.handleNodeDelete.bind(this);
        this.handleSelectionChange = this.handleSelectionChange.bind(this);
        this.handleWorkflowChange = this.handleWorkflowChange.bind(this);
        this.handleZoomChange = this.handleZoomChange.bind(this);
        this.handleExecutionStart = this.handleExecutionStart.bind(this);
        this.handleExecutionProgress = this.handleExecutionProgress.bind(this);
        this.handleExecutionComplete = this.handleExecutionComplete.bind(this);
        this.handleExecutionError = this.handleExecutionError.bind(this);
        this.handleSaveStatusChange = this.handleSaveStatusChange.bind(this);
    }

    /**
     * Initialize the editor
     */
    async init() {
        try {
            // Helper to update loading progress
            const updateLoadingProgress = (percent, status) => {
                const progressBar = document.getElementById('app-loading-progress-bar');
                const statusText = document.getElementById('app-loading-status');
                if (progressBar) progressBar.style.width = `${percent}%`;
                if (statusText) statusText.textContent = status;
            };

            updateLoadingProgress(5, 'Initializing...');

            // Check if we're in viewer mode (read-only, possibly unauthenticated)
            const isViewerMode = document.body.classList.contains('read-only-mode') ||
                window.location.pathname.includes('view');

            // Load site config for nodes (maxRepeatCount, etc.) - skip in viewer mode
            updateLoadingProgress(10, 'Loading configuration...');
            if (!isViewerMode) {
                await this.loadSiteConfig();
            } else {
                // Use defaults in viewer mode
                window.AIKAFLOW_CONFIG = window.AIKAFLOW_CONFIG || { maxRepeatCount: 100 };
            }

            // Load settings from database - skip in viewer mode
            updateLoadingProgress(15, 'Loading settings...');
            if (!isViewerMode) {
                await this.loadSettings();
            }

            // Initialize node manager
            updateLoadingProgress(20, 'Initializing node manager...');
            this.nodeManager = new NodeManager();

            // Initialize connection manager
            updateLoadingProgress(25, 'Initializing connections...');
            this.connectionManager = new ConnectionManager({
                nodeManager: this.nodeManager,
                style: this.settings.connectionStyle,
                onConnectionCreate: (conn) => this.handleConnectionCreate(conn),
                onConnectionDelete: (conn) => this.handleConnectionDelete(conn)
            });

            // Initialize canvas manager
            updateLoadingProgress(30, 'Initializing canvas...');
            this.canvasManager = new CanvasManager({
                nodeManager: this.nodeManager,
                connectionManager: this.connectionManager,
                onNodeSelect: this.handleNodeSelect,
                onNodeDeselect: this.handleNodeDeselect,
                onNodeMove: this.handleNodeMove,
                onNodeDoubleClick: (node) => this.handleNodeDoubleClick(node),
                onCanvasDoubleClick: (point) => this.handleCanvasDoubleClick(point),
                onZoomChange: this.handleZoomChange,
                onSelectionChange: this.handleSelectionChange
            });

            // Link connection manager to canvas
            this.connectionManager.canvasManager = this.canvasManager;

            // Initialize properties panel
            updateLoadingProgress(35, 'Initializing properties panel...');
            this.propertiesPanel = new PropertiesPanel({
                nodeManager: this.nodeManager,
                canvasManager: this.canvasManager,
                onNodeUpdate: this.handleNodeUpdate,
                onNodeDelete: this.handleNodeDelete,
                onClose: () => this.handlePropertiesClose()
            });

            // Initialize workflow manager
            updateLoadingProgress(40, 'Initializing workflow manager...');
            this.workflowManager = new WorkflowManager({
                nodeManager: this.nodeManager,
                connectionManager: this.connectionManager,
                canvasManager: this.canvasManager,
                onWorkflowChange: this.handleWorkflowChange,
                onExecutionStart: this.handleExecutionStart,
                onExecutionProgress: this.handleExecutionProgress,
                onExecutionComplete: this.handleExecutionComplete,
                onExecutionError: this.handleExecutionError,
                onSaveStatusChange: this.handleSaveStatusChange,
                onHistoryChange: () => this.updateUndoRedoButtons()
            });

            // Setup UI event listeners
            updateLoadingProgress(45, 'Setting up UI...');
            this.setupUIListeners();

            // Setup keyboard shortcuts
            this.setupKeyboardShortcuts();

            // Setup sidebar drag and drop
            this.setupSidebarDragDrop();

            // Setup quick add buttons
            this.setupQuickAddButtons();

            // Initialize plugin manager with progress tracking
            updateLoadingProgress(50, 'Loading plugins...');
            if (window.pluginManager) {
                // Hook into plugin loading for progress updates
                const originalLoadPluginNodes = window.pluginManager.loadPluginNodes?.bind(window.pluginManager);
                if (originalLoadPluginNodes) {
                    let loadedCount = 0;
                    const pluginCount = window.pluginManager.plugins?.size || 10;

                    window.pluginManager.loadPluginNodes = async function (plugin) {
                        const result = await originalLoadPluginNodes(plugin);
                        loadedCount++;
                        const pluginProgress = 50 + Math.min(30, (loadedCount / pluginCount) * 30);
                        updateLoadingProgress(pluginProgress, `Loading plugins... (${loadedCount})`);
                        return result;
                    };
                }

                await window.pluginManager.init();
            }

            updateLoadingProgress(85, 'Finalizing...');

            // Check for autosaved workflow - skip in viewer mode
            if (!isViewerMode) {
                await this.checkAutoSave();
            }

            // Apply settings
            updateLoadingProgress(90, 'Applying settings...');
            this.applySettings();

            // Mark as initialized
            this.isInitialized = true;

            // Store global reference
            window.editorInstance = this;

            updateLoadingProgress(100, 'Ready!');

            // Hide loading overlay
            const loadingOverlay = document.getElementById('app-loading-overlay');
            if (loadingOverlay) {
                setTimeout(() => {
                    loadingOverlay.style.opacity = '0';
                    loadingOverlay.style.transition = 'opacity 0.3s ease';
                    setTimeout(() => loadingOverlay.remove(), 300);
                }, 200);
            }

            // Show welcome toast with dynamic site title - skip in viewer mode
            if (!isViewerMode) {
                const siteTitle = window.AIKAFLOW_CONFIG?.siteTitle || 'AIKAFLOW';
                Toast.info(`Welcome to ${siteTitle}`, 'Drag nodes from the sidebar to get started');
            }

            // Check for any running executions and resume polling - skip in viewer mode  
            if (!isViewerMode) {
                this.workflowManager?.resumeRunningExecutions();
            }

        } catch (error) {
            console.error('Editor initialization error:', error);
            Toast.error('Initialization Error', error.message);

            // Still hide loading overlay on error
            const loadingOverlay = document.getElementById('app-loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => loadingOverlay.remove(), 300);
            }
        }
    }

    /**
     * Setup UI event listeners
     */
    setupUIListeners() {
        // ============================================
        // Toolbar Buttons
        // ============================================

        // New workflow
        document.getElementById('btn-new')?.addEventListener('click', () => {
            this.newWorkflow();
        });

        // Open workflow
        document.getElementById('btn-open')?.addEventListener('click', () => {
            this.openWorkflowModal();
        });

        // Save workflow
        document.getElementById('btn-save')?.addEventListener('click', () => {
            this.saveWorkflow();
        });

        // Export workflow
        document.getElementById('btn-export')?.addEventListener('click', () => {
            this.exportWorkflow();
        });

        // Undo
        document.getElementById('btn-undo')?.addEventListener('click', () => {
            this.undo();
        });

        // Redo
        document.getElementById('btn-redo')?.addEventListener('click', () => {
            this.redo();
        });

        // Zoom controls
        document.getElementById('btn-zoom-in')?.addEventListener('click', () => {
            this.canvasManager?.zoomIn();
        });

        document.getElementById('btn-zoom-out')?.addEventListener('click', () => {
            this.canvasManager?.zoomOut();
        });

        document.getElementById('btn-zoom-fit')?.addEventListener('click', () => {
            this.canvasManager?.fitToView();
        });

        // Run workflow
        document.getElementById('btn-run')?.addEventListener('click', () => {
            this.runWorkflow();
        });

        // Execution modal buttons
        document.getElementById('btn-start-execution')?.addEventListener('click', () => {
            // Check if there's a pending flow execution (from Start Flow node)
            if (this.pendingFlowExecution) {
                this.startFlowExecution();
            } else {
                this.startExecution();
            }
        });

        document.getElementById('btn-abort-execution')?.addEventListener('click', async () => {
            const confirmed = await ConfirmDialog.show({
                title: 'Abort Workflow?',
                message: 'Are you sure you want to abort the running workflow? This action cannot be undone.',
                confirmText: 'Abort',
                cancelText: 'Continue Running',
                type: 'danger'
            });
            if (confirmed) {
                this.abortExecution();
            }
        });

        document.getElementById('btn-close-execution-modal')?.addEventListener('click', () => {
            Modals.closeActive();
        });

        // Theme Toggle
        document.getElementById('btn-theme-toggle')?.addEventListener('click', () => {
            this.toggleTheme();
        });

        // ============================================
        // Mobile Responsive Handlers
        // ============================================

        // Mobile Sidebar Toggle
        const btnToggleSidebar = document.getElementById('btn-toggle-sidebar');
        const sidebarLeft = document.getElementById('sidebar-left');
        const sidebarOverlay = document.getElementById('sidebar-overlay');

        btnToggleSidebar?.addEventListener('click', () => {
            sidebarLeft?.classList.toggle('open');
            sidebarOverlay?.classList.toggle('active');
        });

        sidebarOverlay?.addEventListener('click', () => {
            sidebarLeft?.classList.remove('open');
            sidebarOverlay?.classList.remove('active');
        });

        // Close sidebar button (mobile)
        document.getElementById('btn-close-sidebar')?.addEventListener('click', () => {
            sidebarLeft?.classList.remove('open');
            sidebarOverlay?.classList.remove('active');
        });

        // Mobile Toolbar More Button
        const btnToolbarMore = document.getElementById('btn-toolbar-more');
        const toolbarDropdown = document.getElementById('toolbar-dropdown');

        btnToolbarMore?.addEventListener('click', (e) => {
            e.stopPropagation();
            toolbarDropdown?.classList.toggle('hidden');
        });

        // Close toolbar dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!toolbarDropdown?.contains(e.target) && e.target !== btnToolbarMore) {
                toolbarDropdown?.classList.add('hidden');
            }
        });

        // Handle toolbar dropdown actions
        toolbarDropdown?.querySelectorAll('button[data-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                toolbarDropdown?.classList.add('hidden');

                switch (action) {
                    case 'theme': this.toggleTheme(); break;
                    case 'settings': this.openSettingsModal(); break;
                    case 'new': this.newWorkflow(); break;
                    case 'open': this.openWorkflowModal(); break;
                    case 'save': this.saveWorkflow(); break;
                    case 'export': this.exportWorkflow(); break;
                    case 'undo': this.undo(); break;
                    case 'redo': this.redo(); break;
                    case 'zoom-in': this.canvasManager?.zoomIn(); break;
                    case 'zoom-out': this.canvasManager?.zoomOut(); break;
                    case 'zoom-fit': this.canvasManager?.fitToView(); break;
                }
            });
        });

        // Settings
        document.getElementById('btn-settings')?.addEventListener('click', () => {
            this.openSettingsModal();
        });

        // Plugins
        document.getElementById('btn-plugins')?.addEventListener('click', () => {
            Modals.open('modal-plugins');
        });

        // ============================================
        // Canvas Controls
        // ============================================

        // Toggle minimap
        document.getElementById('btn-toggle-minimap')?.addEventListener('click', (e) => {
            const minimap = document.getElementById('minimap');
            minimap?.classList.toggle('hidden');
            e.currentTarget.classList.toggle('active');
        });

        // Toggle grid
        document.getElementById('btn-toggle-grid')?.addEventListener('click', (e) => {
            const isVisible = this.canvasManager?.toggleGrid();
            e.currentTarget.classList.toggle('active', isVisible);
            this.settings.showGrid = isVisible;
            this.saveSettings();
        });

        // Toggle snap
        document.getElementById('btn-toggle-snap')?.addEventListener('click', (e) => {
            const isEnabled = this.canvasManager?.toggleSnapToGrid();
            e.currentTarget.classList.toggle('active', isEnabled);
            this.settings.snapToGrid = isEnabled;
            this.saveSettings();
        });

        // ============================================
        // User Menu
        // ============================================

        const userMenuBtn = document.getElementById('btn-user-menu');
        const userDropdown = document.getElementById('user-dropdown');

        userMenuBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown?.classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#user-menu-container')) {
                userDropdown?.classList.add('hidden');
            }
            // Close language dropdowns when clicking outside
            if (!e.target.closest('#lang-switcher-desktop')) {
                document.getElementById('lang-dropdown-desktop')?.classList.add('hidden');
            }
            if (!e.target.closest('#lang-submenu-container')) {
                document.getElementById('lang-submenu-mobile')?.classList.add('hidden');
            }
        });

        // ============================================
        // Language Switcher
        // ============================================

        // Desktop language toggle
        document.getElementById('btn-lang-toggle-desktop')?.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('lang-dropdown-desktop')?.classList.toggle('hidden');
        });

        // Mobile language toggle
        document.getElementById('btn-lang-mobile')?.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('lang-submenu-mobile')?.classList.toggle('hidden');
        });

        // Language option click handlers
        document.querySelectorAll('.lang-option[data-lang]').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const lang = btn.getAttribute('data-lang');
                if (window.I18n) {
                    await window.I18n.setLanguage(lang);
                    Toast.success('Language changed', window.I18n.translations[lang]?.meta?.name || lang);
                }
                // Close dropdowns
                document.getElementById('lang-dropdown-desktop')?.classList.add('hidden');
                document.getElementById('lang-submenu-mobile')?.classList.add('hidden');
                document.getElementById('user-dropdown')?.classList.add('hidden');
                // Refresh icons
                lucide?.createIcons();
            });
        });

        // ============================================
        // User Menu Items
        // ============================================

        // My Workflows
        document.getElementById('menu-my-workflows')?.addEventListener('click', (e) => {
            e.preventDefault();
            userDropdown?.classList.add('hidden');
            this.openWorkflowModal();
        });

        // API Keys
        document.getElementById('menu-api-keys')?.addEventListener('click', (e) => {
            e.preventDefault();
            userDropdown?.classList.add('hidden');

            // Use API plugin modal if available
            if (window.AflowApiPlugin) {
                window.AflowApiPlugin.openModal();
                return;
            }

            // Fallback to settings modal with API tab
            Modals.open('modal-settings', {
                onOpen: (modal) => {
                    // Switch to API tab
                    document.querySelectorAll('.settings-tab').forEach(t => {
                        t.classList.toggle('active', t.dataset.tab === 'api');
                    });
                    document.querySelectorAll('.settings-content').forEach(content => {
                        content.classList.toggle('hidden', content.id !== 'settings-api');
                    });

                    // Populate settings values
                    document.getElementById('setting-autosave').value = this.settings.autoSave;
                    document.getElementById('setting-confirm-delete').checked = this.settings.confirmDelete;
                    document.getElementById('setting-snap-grid').checked = this.settings.snapToGrid;
                    document.getElementById('setting-grid-size').value = this.settings.gridSize;
                    document.getElementById('setting-connection-style').value = this.settings.connectionStyle;
                }
            });
        });

        // Keyboard Shortcuts
        document.getElementById('menu-shortcuts')?.addEventListener('click', (e) => {
            e.preventDefault();
            userDropdown?.classList.add('hidden');
            Modals.open('modal-shortcuts');
        });

        // Settings
        document.getElementById('menu-settings')?.addEventListener('click', (e) => {
            e.preventDefault();
            userDropdown?.classList.add('hidden');
            this.openSettingsModal();
        });

        // ============================================
        // Workflow Name
        // ============================================

        const workflowNameInput = document.getElementById('workflow-name');
        const workflowNameMobile = document.getElementById('workflow-name-mobile');

        // Sync desktop workflow name
        workflowNameInput?.addEventListener('change', (e) => {
            this.workflowManager?.updateWorkflowInfo({ name: e.target.value });
            // Sync to mobile input
            if (workflowNameMobile) workflowNameMobile.value = e.target.value;
        });

        workflowNameInput?.addEventListener('focus', () => {
            workflowNameInput.select();
        });

        // Mobile workflow name - sync to desktop and manager
        workflowNameMobile?.addEventListener('change', (e) => {
            this.workflowManager?.updateWorkflowInfo({ name: e.target.value });
            // Sync to desktop input
            if (workflowNameInput) workflowNameInput.value = e.target.value;
        });

        workflowNameMobile?.addEventListener('focus', () => {
            workflowNameMobile.select();
        });

        // Mobile run button - delegate to main run button
        document.getElementById('btn-run-mobile')?.addEventListener('click', () => {
            document.getElementById('btn-run')?.click();
        });

        // ============================================
        // Sidebar Search
        // ============================================

        const nodeSearch = document.getElementById('node-search');
        nodeSearch?.addEventListener('input', Utils.debounce((e) => {
            this.filterNodes(e.target.value);
        }, 200));

        // ============================================
        // Category Toggles
        // ============================================

        document.querySelectorAll('.category-header').forEach(header => {
            header.addEventListener('click', () => {
                const category = header.closest('.node-category');
                category?.classList.toggle('collapsed');
            });
        });

        // ============================================
        // Modal Buttons
        // ============================================

        // New workflow modal
        document.getElementById('btn-confirm-new')?.addEventListener('click', () => {
            const name = document.getElementById('new-workflow-name')?.value || 'Untitled Workflow';
            const template = document.getElementById('new-workflow-template')?.value || 'blank';
            this.workflowManager?.newWorkflow(name, template);
            Modals.closeActive();
            this.updateWorkflowNameInput();
        });

        // Save workflow modal
        document.getElementById('btn-confirm-save')?.addEventListener('click', () => {
            this.confirmSaveWorkflow();
        });

        // Import file button
        document.getElementById('btn-import-file')?.addEventListener('click', () => {
            document.getElementById('file-import')?.click();
        });

        // File import handler
        document.getElementById('file-import')?.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.importWorkflow(e.target.files[0]);
                e.target.value = ''; // Reset input
            }
        });

        // Save settings button
        document.getElementById('btn-save-settings')?.addEventListener('click', async () => {
            await this.saveSettingsFromModal();
            Modals.closeActive();
            Toast.success('Settings saved');
        });

        // Settings tabs
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;

                // Update tab buttons
                document.querySelectorAll('.settings-tab').forEach(t => {
                    t.classList.toggle('active', t.dataset.tab === tabName);
                });

                // Update tab content
                document.querySelectorAll('.settings-content').forEach(content => {
                    content.classList.toggle('hidden', content.id !== `settings-${tabName}`);
                });
            });
        });

        // API key buttons
        document.getElementById('btn-copy-api')?.addEventListener('click', () => {
            const apiKeyInput = document.getElementById('user-api-key');
            if (apiKeyInput) {
                Utils.copyToClipboard(apiKeyInput.value);
                Toast.success('Copied', 'API key copied to clipboard');
            }
        });

        document.getElementById('btn-toggle-api')?.addEventListener('click', () => {
            const apiKeyInput = document.getElementById('user-api-key');
            if (apiKeyInput) {
                apiKeyInput.type = apiKeyInput.type === 'password' ? 'text' : 'password';
            }
        });

        document.getElementById('btn-regenerate-api')?.addEventListener('click', async () => {
            const confirmed = await ConfirmDialog.show({
                title: 'Regenerate API Key',
                message: 'This will invalidate your current API key. Any integrations using it will stop working.',
                confirmText: 'Regenerate',
                type: 'warning'
            });

            if (confirmed) {
                try {
                    const response = await API.regenerateApiKey();
                    if (response.success) {
                        document.getElementById('user-api-key').value = response.apiKey;
                        Toast.success('API key regenerated');
                    }
                } catch (error) {
                    Toast.error('Failed', error.message);
                }
            }
        });

        // Execution modal buttons
        // Legacy: Keep for backward compatibility if needed
        // Cancel execution button now renamed to abort-execution

        document.getElementById('btn-toggle-log')?.addEventListener('click', (e) => {
            const log = document.getElementById('execution-log');
            log?.classList.toggle('hidden');

            const icon = e.currentTarget.querySelector('i');
            if (icon) {
                icon.style.transform = log?.classList.contains('hidden') ? '' : 'rotate(180deg)';
            }
        });
    }

    /**
     * Setup keyboard shortcuts
     */
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Check for read-only mode (restore default browser shortcuts)
            if (document.body.classList.contains('read-only-mode')) {
                return;
            }

            // Ignore if typing in input
            if (e.target.matches('input, textarea, select')) {
                return;
            }

            const ctrl = e.ctrlKey || e.metaKey;
            const shift = e.shiftKey;
            const key = e.key.toLowerCase();

            // Ctrl+N - New workflow
            if (ctrl && key === 'n') {
                e.preventDefault();
                this.newWorkflow();
            }

            // Ctrl+O - Open workflow
            if (ctrl && key === 'o') {
                e.preventDefault();
                this.openWorkflowModal();
            }

            // Ctrl+S - Save workflow
            if (ctrl && key === 's') {
                e.preventDefault();
                this.saveWorkflow();
            }

            // Ctrl+Shift+S - Save as
            if (ctrl && shift && key === 's') {
                e.preventDefault();
                this.saveWorkflowAs();
            }

            // Ctrl+E - Export
            if (ctrl && key === 'e') {
                e.preventDefault();
                this.exportWorkflow();
            }

            // Ctrl+Z - Undo
            if (ctrl && key === 'z' && !shift) {
                e.preventDefault();
                this.undo();
            }

            // Ctrl+Y or Ctrl+Shift+Z - Redo
            if ((ctrl && key === 'y') || (ctrl && shift && key === 'z')) {
                e.preventDefault();
                this.redo();
            }

            // F5 or Ctrl+Enter - Run workflow
            if (key === 'f5' || (ctrl && key === 'enter')) {
                e.preventDefault();
                this.runWorkflow();
            }

            // Space - Toggle properties panel
            if (key === ' ' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const selectedNodes = this.nodeManager?.getSelectedNodes();
                if (selectedNodes?.length === 1) {
                    this.propertiesPanel?.toggle(selectedNodes[0]);
                }
            }

            // Tab - Cycle through nodes
            if (key === 'tab' && !ctrl) {
                e.preventDefault();
                this.cycleNodeSelection(shift ? -1 : 1);
            }
        });
    }

    /**
     * Setup sidebar drag and drop
     */
    setupSidebarDragDrop() {
        document.querySelectorAll('.node-item[draggable="true"]').forEach(item => {
            this.setupNodeItemDrag(item);
        });
    }

    /**
     * Setup drag and drop for a single node item
     * @param {HTMLElement} item - The node item element
     */
    setupNodeItemDrag(item) {
        item.addEventListener('dragstart', (e) => {
            const nodeType = item.dataset.nodeType;
            e.dataTransfer.setData('node-type', nodeType);
            e.dataTransfer.effectAllowed = 'copy';
            item.classList.add('dragging');

            // Create drag image
            const dragImage = item.cloneNode(true);
            dragImage.style.position = 'absolute';
            dragImage.style.top = '-1000px';
            dragImage.style.opacity = '0.8';
            document.body.appendChild(dragImage);
            e.dataTransfer.setDragImage(dragImage, 20, 20);

            setTimeout(() => dragImage.remove(), 0);
        });

        item.addEventListener('dragend', () => {
            item.classList.remove('dragging');
        });

        // Double-click to add node at center
        item.addEventListener('dblclick', () => {
            const nodeType = item.dataset.nodeType;
            const center = this.canvasManager?.getViewportCenter() || { x: 400, y: 300 };
            this.addNode(nodeType, center);
        });
    }


    /**
     * Setup quick add buttons
     */
    setupQuickAddButtons() {
        document.querySelectorAll('.quick-add-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const nodeType = btn.dataset.nodeType;
                const center = this.canvasManager?.getViewportCenter() || { x: 400, y: 300 };
                this.addNode(nodeType, center);
            });
        });
    }

    /**
     * Filter nodes in sidebar
     */
    filterNodes(query) {
        const lowerQuery = query.toLowerCase().trim();

        document.querySelectorAll('.node-category').forEach(category => {
            let visibleCount = 0;

            category.querySelectorAll('.node-item').forEach(item => {
                const nodeName = item.querySelector('.node-name')?.textContent.toLowerCase() || '';
                const nodeDesc = item.querySelector('.node-desc')?.textContent.toLowerCase() || '';
                const nodeType = item.dataset.nodeType?.toLowerCase() || '';

                const matches = !lowerQuery ||
                    nodeName.includes(lowerQuery) ||
                    nodeDesc.includes(lowerQuery) ||
                    nodeType.includes(lowerQuery);

                item.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            // Hide empty categories
            category.style.display = visibleCount > 0 ? '' : 'none';

            // Auto-expand categories with matches
            if (lowerQuery && visibleCount > 0) {
                category.classList.remove('collapsed');
            }
        });
    }

    /**
     * Add a node to the canvas
     */
    addNode(nodeType, position) {
        const node = this.nodeManager?.createNode(nodeType, position);
        if (node) {
            this.canvasManager?.renderNode(node);
            this.canvasManager?.hideEmptyState();
            this.nodeManager?.selectNode(node.id);
            this.canvasManager?.updateNodeSelectionVisuals();
            this.propertiesPanel?.open(node);
            this.workflowManager?.markChanged();
            this.updateStatusBar();

            Toast.success('Node added', this.nodeManager?.getNodeDefinition(nodeType)?.name);
        }
    }

    /**
     * Cycle through node selection
     */
    cycleNodeSelection(direction) {
        const nodes = this.nodeManager?.getAllNodes() || [];
        if (nodes.length === 0) return;

        const selectedNodes = this.nodeManager?.getSelectedNodes() || [];
        let currentIndex = -1;

        if (selectedNodes.length > 0) {
            currentIndex = nodes.findIndex(n => n.id === selectedNodes[0].id);
        }

        let nextIndex = currentIndex + direction;
        if (nextIndex < 0) nextIndex = nodes.length - 1;
        if (nextIndex >= nodes.length) nextIndex = 0;

        const nextNode = nodes[nextIndex];
        this.nodeManager?.selectNode(nextNode.id);
        this.canvasManager?.updateNodeSelectionVisuals();
        this.handleSelectionChange([nextNode]);
    }

    // ============================================
    // Workflow Operations
    // ============================================

    /**
     * Create new workflow
     */
    async newWorkflow() {
        if (this.workflowManager?.hasUnsavedChanges) {
            const confirmed = await ConfirmDialog.unsavedChanges();
            if (!confirmed) return;
        }

        Modals.open('modal-new', {
            onOpen: (modal) => {
                modal.querySelector('#new-workflow-name').value = 'Untitled Workflow';
                modal.querySelector('#new-workflow-template').value = 'blank';
            }
        });
    }

    /**
     * Open workflow modal
     */
    async openWorkflowModal() {
        if (this.workflowManager?.hasUnsavedChanges) {
            const confirmed = await ConfirmDialog.unsavedChanges();
            if (!confirmed) return;
        }

        Modals.open('modal-open', {
            onOpen: async () => {
                await this.loadWorkflowList();
            }
        });
    }

    /**
     * Load workflow list
     */
    async loadWorkflowList() {
        const listContainer = document.getElementById('workflows-list');
        if (!listContainer) return;

        listContainer.innerHTML = `
            <div class="text-gray-500 text-center py-8">
                <i data-lucide="loader-2" class="w-6 h-6 inline animate-spin"></i>
                <p class="mt-2">Loading workflows...</p>
            </div>
        `;

        if (window.lucide) {
            lucide.createIcons({ nodes: [listContainer] });
        }

        try {
            const workflows = await this.workflowManager?.getWorkflowList() || [];

            if (workflows.length === 0) {
                listContainer.innerHTML = `
                    <div class="text-gray-500 text-center py-8">
                        <i data-lucide="folder-open" class="w-8 h-8 inline mb-2"></i>
                        <p>No workflows yet</p>
                        <p class="text-sm mt-1">Create your first workflow!</p>
                    </div>
                `;
            } else {
                listContainer.innerHTML = workflows.map(workflow => `
                    <div class="workflow-list-item" data-workflow-id="${workflow.id}">
                        <div class="workflow-list-thumb">
                            ${workflow.thumbnail
                        ? `<img src="${workflow.thumbnail}" alt="${Utils.escapeHtml(workflow.name)}">`
                        : `<div class="w-full h-full flex items-center justify-center bg-dark-700">
                                    <i data-lucide="workflow" class="w-5 h-5 text-gray-500"></i>
                                   </div>`
                    }
                        </div>
                        <div class="workflow-list-info">
                            <div class="workflow-list-name">${Utils.escapeHtml(workflow.name)}</div>
                            <div class="workflow-list-meta">
                                <span>${Utils.formatRelativeTime(workflow.updatedAt || workflow.createdAt)}</span>
                                <span>•</span>
                                <span>${workflow.nodeCount || 0} nodes</span>
                            </div>
                        </div>
                        <div class="workflow-list-actions">
                            <button class="btn-icon btn-open-workflow" title="Open">
                                <i data-lucide="folder-open" class="w-4 h-4"></i>
                            </button>
                            <button class="btn-icon btn-delete-workflow" title="Delete">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                `).join('');

                // Add click handlers
                listContainer.querySelectorAll('.workflow-list-item').forEach(item => {
                    const workflowId = item.dataset.workflowId;

                    item.addEventListener('click', (e) => {
                        if (!e.target.closest('.workflow-list-actions')) {
                            this.loadWorkflow(workflowId);
                            Modals.closeActive();
                        }
                    });

                    item.querySelector('.btn-open-workflow')?.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.loadWorkflow(workflowId);
                        Modals.closeActive();
                    });

                    item.querySelector('.btn-delete-workflow')?.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        const confirmed = await ConfirmDialog.delete('this workflow');
                        if (confirmed) {
                            await this.workflowManager?.deleteWorkflow(workflowId);
                            this.loadWorkflowList();
                        }
                    });
                });
            }

            if (window.lucide) {
                lucide.createIcons({ nodes: [listContainer] });
            }

        } catch (error) {
            listContainer.innerHTML = `
                <div class="text-red-400 text-center py-8">
                    <i data-lucide="alert-circle" class="w-8 h-8 inline mb-2"></i>
                    <p>Failed to load workflows</p>
                    <p class="text-sm mt-1">${Utils.escapeHtml(error.message)}</p>
                </div>
            `;

            if (window.lucide) {
                lucide.createIcons({ nodes: [listContainer] });
            }
        }
    }

    /**
     * Load a workflow
     */
    async loadWorkflow(workflowId) {
        try {
            await this.workflowManager?.loadWorkflow(workflowId);
            this.updateWorkflowNameInput();
            this.updateUndoRedoButtons();
            this.updateStatusBar();
        } catch (error) {
            Toast.error('Load failed', error.message);
        }
    }

    /**
     * Save workflow
     */
    async saveWorkflow() {
        if (!this.workflowManager?.currentWorkflow.id) {
            // New workflow - show save dialog
            this.saveWorkflowAs();
        } else {
            // Existing workflow - save directly
            await this.workflowManager?.saveWorkflow();
        }
    }

    /**
     * Save workflow as (show dialog)
     */
    saveWorkflowAs() {
        const workflow = this.workflowManager?.getWorkflowInfo();

        Modals.open('modal-save', {
            onOpen: (modal) => {
                modal.querySelector('#save-workflow-name').value = workflow?.name || 'Untitled Workflow';
                modal.querySelector('#save-workflow-desc').value = workflow?.description || '';
                modal.querySelector('#save-workflow-public').checked = workflow?.isPublic || false;
            }
        });
    }

    /**
     * Confirm save workflow (from modal)
     */
    async confirmSaveWorkflow() {
        const name = document.getElementById('save-workflow-name')?.value.trim();
        const description = document.getElementById('save-workflow-desc')?.value.trim();
        const isPublic = document.getElementById('save-workflow-public')?.checked;

        if (!name) {
            Toast.warning('Name required', 'Please enter a workflow name');
            return;
        }

        this.workflowManager?.updateWorkflowInfo({ name, description, isPublic });

        try {
            await this.workflowManager?.saveWorkflow();
            Modals.closeActive();
            this.updateWorkflowNameInput();
        } catch (error) {
            // Error already shown by workflow manager
        }
    }

    /**
     * Export workflow
     */
    exportWorkflow() {
        this.workflowManager?.exportWorkflow();
    }

    /**
     * Import workflow
     */
    async importWorkflow(file) {
        try {
            await this.workflowManager?.importWorkflow(file);
            this.updateWorkflowNameInput();
            Modals.closeActive();
        } catch (error) {
            // Error already shown
        }
    }

    /**
     * Undo
     */
    undo() {
        if (!this.workflowManager?.canUndo()) return;

        // Set flag to prevent markChanged during render
        this.workflowManager.isUndoRedoAction = true;

        this.workflowManager.historyIndex--;
        this.workflowManager.deserialize(this.workflowManager.history[this.workflowManager.historyIndex]);
        this.workflowManager.hasUnsavedChanges = true;

        // Re-render canvas
        this.canvasManager?.renderNodes();
        this.propertiesPanel?.update();

        // Reset flag after all operations
        this.workflowManager.isUndoRedoAction = false;

        // Update UI
        this.updateUndoRedoButtons();
        this.workflowManager.onHistoryChange();
    }

    /**
     * Redo
     */
    redo() {
        if (!this.workflowManager?.canRedo()) return;

        // Set flag to prevent markChanged during render
        this.workflowManager.isUndoRedoAction = true;

        this.workflowManager.historyIndex++;
        this.workflowManager.deserialize(this.workflowManager.history[this.workflowManager.historyIndex]);
        this.workflowManager.hasUnsavedChanges = true;

        // Re-render canvas
        this.canvasManager?.renderNodes();
        this.propertiesPanel?.update();

        // Reset flag after all operations
        this.workflowManager.isUndoRedoAction = false;

        // Update UI
        this.updateUndoRedoButtons();
        this.workflowManager.onHistoryChange();
    }

    /**
     * Open execution modal (clicking Run button in topbar)
     */
    async runWorkflow() {
        // If already running, just open the execution modal to show progress
        if (this.workflowManager?.isRunning()) {
            Modals.open('modal-execution');
            return;
        }

        // Validate workflow
        const nodes = this.nodeManager?.getAllNodes() || [];
        if (nodes.length === 0) {
            Toast.warning('Empty workflow', 'Add some nodes first');
            return;
        }

        // Open execution modal in pre-run state
        this.openExecutionModalPreRun();
    }

    /**
     * Open execution modal in pre-run state (before execution starts)
     */
    openExecutionModalPreRun() {
        // Get execution order to display nodes
        const executionOrder = this.connectionManager?.getExecutionOrder() || [];

        Modals.open('modal-execution', {
            onOpen: (modal) => {
                // Reset progress
                modal.querySelector('#execution-progress-bar').style.width = '0%';
                modal.querySelector('#execution-progress-text').textContent = '0%';

                // Show nodes list in pending state
                const nodesList = modal.querySelector('#execution-nodes');
                if (executionOrder.length > 0) {
                    nodesList.innerHTML = executionOrder
                        .map(node => this.renderExecutionNodeItem(node))
                        .join('');
                } else {
                    nodesList.innerHTML = `
                        <div class="text-center py-8 text-dark-400">
                            <i data-lucide="alert-circle" class="w-12 h-12 mx-auto mb-4 text-dark-500"></i>
                            <p class="text-sm">No nodes to execute</p>
                        </div>
                    `;
                }

                // Clear log
                const logEl = modal.querySelector('#execution-log');
                if (logEl) logEl.textContent = '';

                // Show pre-run buttons
                modal.querySelector('#btn-close-execution-modal')?.classList.remove('hidden');
                modal.querySelector('#btn-start-execution')?.classList.remove('hidden');
                modal.querySelector('#btn-abort-execution')?.classList.add('hidden');
                modal.querySelector('#btn-view-result')?.classList.add('hidden');

                if (window.lucide) {
                    lucide.createIcons({ nodes: [nodesList] });
                }
            }
        });
    }

    /**
     * Actually start the workflow execution (called from modal button)
     */
    async startExecution() {
        const result = await this.workflowManager?.executeWorkflow();

        if (result?.success) {
            // Close the execution modal
            Modals.closeActive();

            // Open the run history panel
            if (window.PanelsManager) {
                window.PanelsManager.openHistory();
            }
        }
    }

    /**
     * Abort execution (with confirmation already shown)
     */
    async abortExecution() {
        const executionId = this.workflowManager?.executionState?.executionId;
        await this.workflowManager?.cancelExecution();

        // Dispatch abort event for history tracking
        document.dispatchEvent(new CustomEvent('workflow:run:aborted', {
            detail: { executionId }
        }));

        this.resetRunButton();
        Modals.closeActive();
        Toast.warning('Workflow Aborted', 'The workflow execution has been stopped');
    }

    /**
     * Run a single flow starting from a specific trigger node
     * @param {string} triggerNodeId - The ID of the trigger node to start from
     */
    async runSingleFlow(triggerNodeId) {
        const triggerNode = this.nodeManager?.getNode(triggerNodeId);
        if (!triggerNode) {
            Toast.error('Node not found', 'The trigger node could not be found');
            return;
        }

        // Get nodes in this flow (connected to the trigger via flow connections)
        const downstreamNodes = this.connectionManager?.getConnectedNodes(triggerNodeId, 'downstream') || [];

        // Collect all nodes including upstream data providers
        const flowNodeIds = new Set([triggerNodeId]);
        const flowNodes = [triggerNode];

        // Add downstream nodes
        downstreamNodes.forEach(node => {
            if (!flowNodeIds.has(node.id)) {
                flowNodeIds.add(node.id);
                flowNodes.push(node);
            }
        });

        // For each downstream node, also get their upstream data providers (input nodes)
        // This ensures Text Input, Image Input, etc. are included
        downstreamNodes.forEach(node => {
            const upstreamNodes = this.connectionManager?.getConnectedNodes(node.id, 'upstream') || [];
            upstreamNodes.forEach(upNode => {
                // Only add if not already in the flow and not a trigger node
                // (triggers are handled separately)
                if (!flowNodeIds.has(upNode.id)) {
                    const nodeDef = this.nodeManager?.getNodeDefinition(upNode.type);
                    // Include input/utility nodes that provide data
                    if (nodeDef?.category === 'input' || nodeDef?.category === 'utility') {
                        flowNodeIds.add(upNode.id);
                        flowNodes.push(upNode);
                    }
                }
            });
        });

        if (flowNodes.length === 0) {
            Toast.warning('Empty flow', 'This trigger has no connected nodes');
            return;
        }

        const flowName = triggerNode.data?.flowName || 'Flow';

        // Store pending flow execution data
        this.pendingFlowExecution = {
            triggerNodeId,
            flowNodes,
            flowName
        };

        // Open execution modal with the flow nodes
        this.openExecutionModalForFlow(flowNodes, flowName);
    }

    /**
     * Open execution modal for a specific flow
     */
    openExecutionModalForFlow(flowNodes, flowName) {
        Modals.open('modal-execution', {
            onOpen: (modal) => {
                // Reset progress
                modal.querySelector('#execution-progress-bar').style.width = '0%';
                modal.querySelector('#execution-progress-text').textContent = '0%';

                // Show nodes list in pending state
                const nodesList = modal.querySelector('#execution-nodes');
                if (flowNodes.length > 0) {
                    nodesList.innerHTML = flowNodes
                        .map(node => this.renderExecutionNodeItem(node))
                        .join('');
                } else {
                    nodesList.innerHTML = `
                        <div class="text-center py-8 text-dark-400">
                            <i data-lucide="alert-circle" class="w-12 h-12 mx-auto mb-4 text-dark-500"></i>
                            <p class="text-sm">No nodes to execute</p>
                        </div>
                    `;
                }

                // Clear log
                const logEl = modal.querySelector('#execution-log');
                if (logEl) logEl.textContent = '';

                // Show pre-run buttons
                modal.querySelector('#btn-close-execution-modal')?.classList.remove('hidden');
                modal.querySelector('#btn-start-execution')?.classList.remove('hidden');
                modal.querySelector('#btn-abort-execution')?.classList.add('hidden');
                modal.querySelector('#btn-view-result')?.classList.add('hidden');

                if (window.lucide) {
                    lucide.createIcons({ nodes: [nodesList] });
                }
            }
        });
    }

    /**
     * Start execution for pending flow (called from modal button when flow is pending)
     */
    async startFlowExecution() {
        if (this.pendingFlowExecution) {
            const { triggerNodeId, flowNodes, flowName } = this.pendingFlowExecution;
            this.pendingFlowExecution = null;

            Toast.info(`Running ${flowName}`, `Starting execution of ${flowNodes.length} node(s)`);

            const result = await this.workflowManager?.executeSingleFlow(triggerNodeId, flowNodes);

            if (result?.success) {
                // Close the execution modal
                Modals.closeActive();

                // Open the run history panel
                if (window.PanelsManager) {
                    window.PanelsManager.openHistory();
                }
            }
        }
    }


    // ============================================
    // Event Handlers
    // ============================================

    /**
     * Handle node selection
     */
    handleNodeSelect(node) {
        this.propertiesPanel?.open(node);
    }

    /**
     * Handle node deselection
     */
    handleNodeDeselect() {
        // Keep properties panel open if only one node was selected
    }

    /**
     * Handle node movement
     */
    handleNodeMove(nodes) {
        this.workflowManager?.markChanged();
    }

    /**
     * Handle node update from properties panel
     */
    handleNodeUpdate(nodeId, fieldId, value) {
        // Update node in canvas
        const node = this.nodeManager?.getNode(nodeId);
        if (node) {
            this.canvasManager?.renderNode(node);
        }

        this.workflowManager?.markChanged();
    }

    /**
     * Handle node deletion
     */
    async handleNodeDelete(nodeId) {
        if (this.settings.confirmDelete) {
            const node = this.nodeManager?.getNode(nodeId);
            const confirmed = await ConfirmDialog.delete(node?.name || 'this node');
            if (!confirmed) return;
        }

        this.canvasManager?.deleteNode(nodeId);
        this.workflowManager?.markChanged();
    }

    /**
     * Handle node double click
     */
    handleNodeDoubleClick(node) {
        this.propertiesPanel?.open(node);
    }

    /**
     * Handle canvas double click
     */
    handleCanvasDoubleClick(point) {
        // Could show node picker menu here
    }

    /**
     * Handle selection change
     */
    handleSelectionChange(selectedNodes) {
        this.updateStatusBar();

        if (selectedNodes.length === 1) {
            this.propertiesPanel?.open(selectedNodes[0]);
        } else if (selectedNodes.length === 0) {
            this.propertiesPanel?.close();
        }
    }

    /**
     * Handle connection creation
     */
    handleConnectionCreate(connection) {
        this.workflowManager?.markChanged();
        this.updateStatusBar();
    }

    /**
     * Handle connection deletion
     */
    handleConnectionDelete(connection) {
        this.workflowManager?.markChanged();
        this.updateStatusBar();
    }

    /**
     * Handle properties panel close
     */
    handlePropertiesClose() {
        // Optional: could clear selection
    }

    /**
     * Handle workflow change
     */
    handleWorkflowChange(workflow) {
        this.updateWorkflowNameInput();
    }

    /**
     * Handle zoom change
     */
    handleZoomChange(zoom) {
        // Already handled by canvas manager
    }

    /**
     * Handle execution start
     */
    handleExecutionStart(data) {
        // Dispatch start event
        document.dispatchEvent(new CustomEvent('workflow:run:start', { detail: data }));

        // Initialize node timer tracking
        this.nodeStartTimes = new Map();
        this.nodeTimerInterval = null;

        // Reset execution tracking for repeat workflows
        this.currentTrackingExecutionId = data.executionId;

        // Show execution modal
        Modals.open('modal-execution', {
            onOpen: (modal) => {
                // Reset progress
                modal.querySelector('#execution-progress-bar').style.width = '0%';
                modal.querySelector('#execution-progress-text').textContent = '0%';

                // Populate node list - group by flow if multiple flows exist
                const nodesList = modal.querySelector('#execution-nodes');
                const hasMultipleFlows = data.flows && data.flows.length > 1;

                let html = '';

                if (hasMultipleFlows) {
                    // Group nodes by flowId
                    const flowGroups = new Map();
                    data.nodes.forEach(node => {
                        const flowId = node.flowId || 'ungrouped';
                        if (!flowGroups.has(flowId)) {
                            flowGroups.set(flowId, {
                                name: node.flowName || 'Workflow',
                                nodes: []
                            });
                        }
                        flowGroups.get(flowId).nodes.push(node);
                    });

                    // Render each flow group
                    let flowIndex = 0;
                    flowGroups.forEach((flow, flowId) => {
                        const flowColor = ['#8b5cf6', '#3b82f6', '#10b981', '#f59e0b', '#ef4444'][flowIndex % 5];
                        html += `
                            <div class="execution-flow-group mb-4">
                                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-dark-700">
                                    <div class="w-3 h-3 rounded-full" style="background-color: ${flowColor}"></div>
                                    <span class="text-sm font-semibold text-dark-200">${Utils.escapeHtml(flow.name)}</span>
                                    <span class="text-xs text-dark-500">(${flow.nodes.length} nodes)</span>
                                </div>
                                <div class="execution-flow-nodes pl-2 border-l-2" style="border-color: ${flowColor}30">
                        `;

                        flow.nodes.forEach(node => {
                            html += this.renderExecutionNodeItem(node);
                        });

                        html += `
                                </div>
                            </div>
                        `;
                        flowIndex++;
                    });
                } else {
                    // Single flow - render flat list
                    html = data.nodes.map(node => this.renderExecutionNodeItem(node)).join('');
                }

                nodesList.innerHTML = html;

                // Clear log
                modal.querySelector('#execution-log').textContent = '';

                // Update buttons for running state
                modal.querySelector('#btn-start-execution')?.classList.add('hidden');
                modal.querySelector('#btn-abort-execution')?.classList.remove('hidden');
                modal.querySelector('#btn-view-result')?.classList.add('hidden');
                modal.querySelector('#btn-close-execution-modal')?.classList.add('hidden');

                if (window.lucide) {
                    lucide.createIcons({ nodes: [nodesList] });
                }
            }
        });

        // Update run button (keep it clickable to allow opening execution modal)
        const runBtn = document.getElementById('btn-run');
        if (runBtn) {
            runBtn.classList.add('is-running');
            runBtn.innerHTML = `
                <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
                <span class="hidden sm:inline">Running...</span>
            `;
            if (window.lucide) {
                lucide.createIcons({ nodes: [runBtn] });
            }
        }

        // Update status bar
        const statusExecution = document.getElementById('status-execution');
        if (statusExecution) {
            statusExecution.classList.remove('hidden');
        }
    }

    /**
     * Render a single execution node item HTML
     */
    renderExecutionNodeItem(node) {
        const def = this.nodeManager?.getNodeDefinition(node.type);
        const colors = Utils.getCategoryColor(def?.category);
        const icon = Utils.getNodeIcon(node.type);
        const displayName = (node.name && node.name !== node.type) ? node.name : (def?.name || node.type);

        return `
            <div class="execution-node-item pending" data-node-id="${node.id}">
                <div class="execution-node-icon" style="background-color: ${colors.bg}; color: ${colors.text}">
                    <i data-lucide="${icon}" class="w-4 h-4"></i>
                </div>
                <div class="execution-node-info">
                    <div class="execution-node-name">${Utils.escapeHtml(displayName)}</div>
                    <div class="execution-node-status"><span class="status-text">Pending</span><span class="status-timer"></span></div>
                </div>
                <div class="execution-node-progress">
                    <div class="execution-node-progress-bar" style="width: 0%"></div>
                </div>
            </div>
        `;
    }

    /**
     * Handle execution progress
     */
    handleExecutionProgress(data) {
        const modal = Modals.getModal('modal-execution');
        if (!modal) return;

        // Check if this is a new execution (execution ID changed) - happens with repeat workflows
        if (this.currentTrackingExecutionId && this.currentTrackingExecutionId !== data.executionId) {
            // Reset the modal for new execution
            this.cleanupNodeTimers();
            this.nodeStartTimes = new Map();

            // Reset progress bar
            modal.querySelector('#execution-progress-bar').style.width = '0%';
            modal.querySelector('#execution-progress-text').textContent = '0%';

            // Reset all node items to pending state
            modal.querySelectorAll('.execution-node-item').forEach(item => {
                item.className = 'execution-node-item pending';
                const statusText = item.querySelector('.status-text');
                const statusTimer = item.querySelector('.status-timer');
                const progressBar = item.querySelector('.execution-node-progress-bar');
                if (statusText) statusText.textContent = 'Pending';
                if (statusTimer) statusTimer.textContent = '';
                if (progressBar) progressBar.style.width = '0%';
            });

            // Add log entry for new execution
            this.addExecutionLog(`--- Starting Execution ${data.executionId} ---`);
        }

        // Track current execution ID
        this.currentTrackingExecutionId = data.executionId;

        // Update progress bar
        modal.querySelector('#execution-progress-bar').style.width = `${data.progress}%`;
        modal.querySelector('#execution-progress-text').textContent = `${data.progress}%`;

        // Update node statuses
        if (data.nodeStatuses) {
            data.nodeStatuses.forEach(ns => {
                const nodeItem = modal.querySelector(`.execution-node-item[data-node-id="${ns.nodeId}"]`);
                if (nodeItem) {
                    nodeItem.className = `execution-node-item ${ns.status}`;
                    const statusText = nodeItem.querySelector('.status-text');
                    const statusTimer = nodeItem.querySelector('.status-timer');

                    // Track start time when node starts processing
                    if (ns.status === 'processing' && !this.nodeStartTimes.has(ns.nodeId)) {
                        this.nodeStartTimes.set(ns.nodeId, Date.now());
                        // Start timer interval if not already running
                        this.startNodeTimers(modal);
                    }

                    // Update status text with timer
                    if (ns.status === 'processing') {
                        statusText.textContent = 'Processing';
                        // Timer will be updated by interval
                    } else if (ns.status === 'completed' || ns.status === 'failed') {
                        // Show final elapsed time
                        const startTime = this.nodeStartTimes.get(ns.nodeId);
                        if (startTime) {
                            const elapsed = this.formatElapsedTime(Date.now() - startTime);
                            statusText.textContent = ns.status.charAt(0).toUpperCase() + ns.status.slice(1);
                            statusTimer.textContent = ` (${elapsed})`;
                            this.nodeStartTimes.delete(ns.nodeId);
                        } else {
                            statusText.textContent = ns.status.charAt(0).toUpperCase() + ns.status.slice(1);
                            statusTimer.textContent = '';
                        }
                    } else {
                        statusText.textContent = ns.status.charAt(0).toUpperCase() + ns.status.slice(1);
                        statusTimer.textContent = '';
                    }

                    if (ns.status === 'completed') {
                        nodeItem.querySelector('.execution-node-progress-bar').style.width = '100%';
                    } else if (ns.status === 'processing') {
                        nodeItem.querySelector('.execution-node-progress-bar').style.width = '50%';
                    }
                }
            });
        }

        // Add log entry
        this.addExecutionLog(`Progress: ${data.progress}%`);
    }

    /**
     * Start node timers interval to update elapsed time display
     */
    startNodeTimers(modal) {
        if (this.nodeTimerInterval) return; // Already running

        this.nodeTimerInterval = setInterval(() => {
            if (this.nodeStartTimes.size === 0) {
                clearInterval(this.nodeTimerInterval);
                this.nodeTimerInterval = null;
                return;
            }

            this.nodeStartTimes.forEach((startTime, nodeId) => {
                const nodeItem = modal.querySelector(`.execution-node-item[data-node-id="${nodeId}"]`);
                if (nodeItem) {
                    const statusTimer = nodeItem.querySelector('.status-timer');
                    if (statusTimer) {
                        const elapsed = this.formatElapsedTime(Date.now() - startTime);
                        statusTimer.textContent = ` (${elapsed})`;
                    }
                }
            });
        }, 1000);
    }

    /**
     * Format elapsed time as mm:ss
     */
    formatElapsedTime(ms) {
        const totalSeconds = Math.floor(ms / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }

    /**
     * Cleanup node timers
     */
    cleanupNodeTimers() {
        if (this.nodeTimerInterval) {
            clearInterval(this.nodeTimerInterval);
            this.nodeTimerInterval = null;
        }
        if (this.nodeStartTimes) {
            this.nodeStartTimes.clear();
        }
    }

    /**
     * Handle execution complete
     */
    handleExecutionComplete(data) {
        // Dispatch complete event
        document.dispatchEvent(new CustomEvent('workflow:run:complete', { detail: data }));

        // Cleanup timers
        this.cleanupNodeTimers();

        const modal = Modals.getModal('modal-execution');
        if (modal) {
            modal.querySelector('#execution-progress-bar').style.width = '100%';
            modal.querySelector('#execution-progress-text').textContent = '100%';
            modal.querySelector('#btn-abort-execution')?.classList.add('hidden');
            modal.querySelector('#btn-close-execution-modal')?.classList.remove('hidden');

            const viewBtn = modal.querySelector('#btn-view-result');
            if (data.resultUrl) {
                viewBtn.classList.remove('hidden');
                viewBtn.onclick = () => window.open(data.resultUrl, '_blank');
            }

            // Check if result is temporary (no CDN storage)
            if (data.metadata?.is_temporary || data.isTemporary) {
                // Show warning about temporary file
                const warningEl = document.createElement('div');
                warningEl.className = 'bg-yellow-500/20 border border-yellow-500/50 rounded-lg p-3 mt-3';
                warningEl.innerHTML = `
                    <div class="flex items-start gap-2">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-sm text-yellow-300 font-medium">Temporary File</p>
                            <p class="text-xs text-yellow-400/80 mt-1">
                                This result is stored on the API provider's temporary storage and may be deleted soon. 
                                Please download immediately to keep a copy.
                            </p>
                        </div>
                    </div>
                `;

                // Insert after progress section
                const progressSection = modal.querySelector('.modal-body > div:first-child');
                if (progressSection) {
                    progressSection.after(warningEl);
                } else {
                    modal.querySelector('.modal-body')?.prepend(warningEl);
                }

                // Initialize the Lucide icon
                if (window.lucide) {
                    lucide.createIcons({ nodes: [warningEl] });
                }

                // Show toast warning
                Toast.warning(
                    'Temporary Result',
                    'Download the result immediately - it may be deleted from provider storage.',
                    8000
                );
            }
        }

        this.addExecutionLog('Execution completed successfully!');
        this.resetRunButton();

        // Render nodes to show output previews
        this.canvasManager?.renderNodes();
    }


    /**
     * Handle execution error
     */
    handleExecutionError(data) {
        // Dispatch error event
        document.dispatchEvent(new CustomEvent('workflow:run:error', { detail: data }));

        // Cleanup timers
        this.cleanupNodeTimers();

        const modal = Modals.getModal('modal-execution');
        if (modal) {
            modal.querySelector('#btn-abort-execution')?.classList.add('hidden');
            modal.querySelector('#btn-close-execution-modal')?.classList.remove('hidden');
        }

        this.addExecutionLog(`Error: ${data.error}`);
        this.resetRunButton();
    }

    /**
     * Add execution log entry
     */
    addExecutionLog(message) {
        const log = document.getElementById('execution-log');
        if (log) {
            const timestamp = new Date().toLocaleTimeString();
            log.textContent += `[${timestamp}] ${message}\n`;
            log.scrollTop = log.scrollHeight;
        }
    }

    /**
     * Reset run button
     */
    resetRunButton() {
        const runBtn = document.getElementById('btn-run');
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.classList.remove('is-running');
            runBtn.innerHTML = `
                <i data-lucide="play" class="w-4 h-4"></i>
                <span class="hidden sm:inline">Run</span>
            `;
            if (window.lucide) {
                lucide.createIcons({ nodes: [runBtn] });
            }
        }

        const statusExecution = document.getElementById('status-execution');
        if (statusExecution) {
            statusExecution.classList.add('hidden');
        }
    }

    /**
     * Handle save status change
     */
    handleSaveStatusChange(status) {
        const saveStatus = document.getElementById('save-status');
        if (!saveStatus) return;

        switch (status) {
            case 'saving':
                saveStatus.innerHTML = `
                    <i data-lucide="loader-2" class="w-3 h-3 inline animate-spin"></i>
                    <span>Saving...</span>
                `;
                break;
            case 'saved':
                saveStatus.innerHTML = `
                    <i data-lucide="cloud" class="w-3 h-3 inline"></i>
                    <span>All changes saved</span>
                `;
                break;
            case 'unsaved':
                saveStatus.innerHTML = `
                    <i data-lucide="cloud-off" class="w-3 h-3 inline"></i>
                    <span>Unsaved changes</span>
                `;
                break;
            case 'error':
                saveStatus.innerHTML = `
                    <i data-lucide="alert-circle" class="w-3 h-3 inline text-red-400"></i>
                    <span class="text-red-400">Save failed</span>
                `;
                break;
        }

        if (window.lucide) {
            lucide.createIcons({ nodes: [saveStatus] });
        }
    }

    // ============================================
    // Settings
    // ============================================

    /**
     * Open settings modal
     */
    openSettingsModal() {
        Modals.open('modal-settings', {
            onOpen: () => {
                // Populate settings values
                document.getElementById('setting-autosave').value = this.settings.autoSave;
                document.getElementById('setting-confirm-delete').checked = this.settings.confirmDelete;
                document.getElementById('setting-snap-grid').checked = this.settings.snapToGrid;
                document.getElementById('setting-grid-size').value = this.settings.gridSize;
                document.getElementById('setting-connection-style').value = this.settings.connectionStyle;
            }
        });
    }

    /**
     * Save settings from modal
     */
    async saveSettingsFromModal() {
        // Save general editor settings
        this.settings.autoSave = parseInt(document.getElementById('setting-autosave').value) || 0;
        this.settings.confirmDelete = document.getElementById('setting-confirm-delete').checked;
        this.settings.snapToGrid = document.getElementById('setting-snap-grid').checked;
        this.settings.gridSize = parseInt(document.getElementById('setting-grid-size').value) || 20;
        this.settings.connectionStyle = document.getElementById('setting-connection-style').value;

        this.saveSettings();
        this.applySettings();

        // Save profile data if changed
        const username = document.getElementById('profile-username')?.value?.trim();
        const email = document.getElementById('profile-email')?.value?.trim();
        const currentPassword = document.getElementById('profile-current-password')?.value;
        const newPassword = document.getElementById('profile-new-password')?.value;

        try {
            // Update profile (username/email) if provided
            if (username || email) {
                const profileData = {};
                if (username) profileData.username = username;
                if (email) profileData.email = email;

                const response = await fetch(`${window.AIKAFLOW.apiUrl}/user/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(profileData)
                });

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || 'Failed to update profile');
                }
            }

            // Change password if provided
            if (currentPassword && newPassword) {
                const pwResponse = await fetch(`${window.AIKAFLOW.apiUrl}/user/change-password.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        current_password: currentPassword,
                        new_password: newPassword
                    })
                });

                const pwResult = await pwResponse.json();
                if (!pwResult.success) {
                    throw new Error(pwResult.error || 'Failed to change password');
                }

                // Clear password fields
                document.getElementById('profile-current-password').value = '';
                document.getElementById('profile-new-password').value = '';
            }
        } catch (error) {
            console.error('Profile update error:', error);
            Toast.error('Error', error.message || 'Failed to update profile');
        }
    }

    /**
     * Save settings to database
     */
    async saveSettings() {
        try {
            await API.savePreference('editor_settings', this.settings);
        } catch (error) {
            console.error('Failed to save settings to database:', error);
        }
    }

    /**
     * Load site config for nodes (maxRepeatCount, etc.)
     */
    async loadSiteConfig() {
        try {
            // API.getSettings also populates AIKAFLOW_CONFIG
            await API.getSettings();
        } catch (error) {
            console.error('Failed to load site config:', error);
            // Use defaults if fetch fails
            window.AIKAFLOW_CONFIG = window.AIKAFLOW_CONFIG || { maxRepeatCount: 100 };
        }
    }

    /**
     * Load settings from database
     */
    async loadSettings() {
        try {
            const response = await API.getPreference('editor_settings');
            if (response.success && response.value) {
                Object.assign(this.settings, response.value);
            }
        } catch (error) {
            console.error('Failed to load settings from database:', error);
        }
    }

    /**
     * Apply settings to components
     */
    applySettings() {
        // Auto-save interval
        this.workflowManager?.setAutoSaveInterval(this.settings.autoSave * 1000);

        // Snap to grid
        if (this.canvasManager) {
            this.canvasManager.state.snapToGrid = this.settings.snapToGrid;
            this.canvasManager.state.gridSize = this.settings.gridSize;
            this.canvasManager.state.showGrid = this.settings.showGrid;
            this.canvasManager.updateGrid();
        }

        // Connection style
        if (this.connectionManager) {
            this.connectionManager.setStyle(this.settings.connectionStyle);
        }

        // Update UI
        document.getElementById('btn-toggle-grid')?.classList.toggle('active', this.settings.showGrid);
        document.getElementById('btn-toggle-grid')?.classList.toggle('active', this.settings.showGrid);
        document.getElementById('btn-toggle-snap')?.classList.toggle('active', this.settings.snapToGrid);

        // Apply theme
        const html = document.documentElement;
        if (this.settings.theme === 'light') {
            html.classList.remove('dark');
            html.classList.add('light-mode');
        } else {
            html.classList.add('dark');
            html.classList.remove('light-mode');
        }

        // Update toggle icon
        const btn = document.getElementById('btn-theme-toggle');
        if (btn) {
            const icon = this.settings.theme === 'light' ? 'sun' : 'moon';
            // Simple replace
            btn.innerHTML = `<i data-lucide="${icon}" class="w-4 h-4"></i>`;
            if (window.lucide) {
                lucide.createIcons({ nodes: [btn] });
            }
        }
    }

    /**
     * Toggle theme
     */
    toggleTheme() {
        this.settings.theme = this.settings.theme === 'dark' ? 'light' : 'dark';
        this.saveSettings();
        this.applySettings();
    }


    // ============================================
    // UI Updates
    // ============================================

    /**
     * Update workflow name input
     */
    updateWorkflowNameInput() {
        const name = this.workflowManager?.currentWorkflow.name || 'Untitled Workflow';

        // Update desktop input
        const input = document.getElementById('workflow-name');
        if (input) {
            input.value = name;
        }

        // Update mobile input
        const mobileInput = document.getElementById('workflow-name-mobile');
        if (mobileInput) {
            mobileInput.value = name;
        }
    }

    /**
     * Update undo/redo buttons
     */
    updateUndoRedoButtons() {
        const undoBtn = document.getElementById('btn-undo');
        const redoBtn = document.getElementById('btn-redo');

        if (undoBtn) {
            undoBtn.disabled = !this.workflowManager?.canUndo();
        }
        if (redoBtn) {
            redoBtn.disabled = !this.workflowManager?.canRedo();
        }
    }

    /**
     * Update status bar
     */
    updateStatusBar() {
        this.canvasManager?.updateStatusBar();
    }

    /**
     * Check for autosaved workflow
     */
    async checkAutoSave() {
        const autosave = this.workflowManager?.loadFromLocalStorage();
        if (autosave?.data) {
            const confirmed = await ConfirmDialog.show({
                title: 'Restore Autosave?',
                message: `Found an autosaved workflow from ${Utils.formatRelativeTime(autosave.savedAt)}. Would you like to restore it?`,
                confirmText: 'Restore',
                cancelText: 'Discard'
            });

            if (confirmed) {
                this.workflowManager?.deserialize(autosave.data);
                this.updateWorkflowNameInput();
                Toast.success('Workflow restored', 'Autosaved workflow has been restored');
            } else {
                this.workflowManager?.clearLocalStorage();
            }
        }
    }
}

// ============================================
// Initialize Editor on DOM Ready
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    window.editorInstance = new Editor();
    window.editorInstance.init();

    // Also expose as window.editor for backward compatibility/simplicity
    window.editor = window.editorInstance;
});