/**
 * AIKAFLOW - Modal Manager
 * 
 * Handles all modal dialogs in the application.
 */

class ModalManager {
    constructor() {
        // Modal elements cache
        this.modals = new Map();
        this.activeModal = null;

        // Initialize
        this.init();
    }

    /**
     * Initialize modal manager
     */
    init() {
        // Cache all modal elements
        document.querySelectorAll('.modal').forEach(modal => {
            this.modals.set(modal.id, modal);
        });

        // Setup global event listeners
        this.setupEventListeners();
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Close modal on backdrop click
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-backdrop')) {
                this.closeActive();
            }
        });

        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.activeModal) {
                this.closeActive();
            }
        });

        // Close buttons
        document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
            btn.addEventListener('click', () => this.closeActive());
        });
    }

    /**
     * Open a modal by ID
     * @param {string} modalId - Modal element ID
     * @param {Object} options - Modal options
     */
    open(modalId, options = {}) {
        const modal = this.modals.get(modalId);
        if (!modal) {
            console.error(`Modal not found: ${modalId}`);
            return;
        }

        // Close any active modal first
        if (this.activeModal) {
            this.close(this.activeModal.id);
        }

        // Show modal
        modal.classList.remove('hidden');
        this.activeModal = modal;

        // Focus first input if available
        setTimeout(() => {
            const firstInput = modal.querySelector('input:not([type="hidden"]), textarea, select');
            if (firstInput) {
                firstInput.focus();
            }
        }, 100);

        // Call onOpen callback if provided
        if (options.onOpen) {
            options.onOpen(modal);
        }

        // Store callbacks
        modal._modalOptions = options;

        return modal;
    }

    /**
     * Close a modal by ID
     * @param {string} modalId - Modal element ID
     */
    close(modalId) {
        const modal = this.modals.get(modalId);
        if (!modal) return;

        // Save callback
        const onClose = modal._modalOptions?.onClose;

        // Hide modal
        modal.classList.add('hidden');

        // Clear options
        delete modal._modalOptions;

        // Clear active modal reference
        if (this.activeModal === modal) {
            this.activeModal = null;
        }

        // Call onClose callback if provided
        // Do this LAST to prevent recursion if the callback opens another modal
        if (onClose) {
            onClose(modal);
        }
    }

    /**
     * Close the currently active modal
     */
    closeActive() {
        if (this.activeModal) {
            this.close(this.activeModal.id);
        }
    }

    /**
     * Check if a modal is open
     * @param {string} modalId - Modal element ID
     */
    isOpen(modalId) {
        const modal = this.modals.get(modalId);
        return modal && !modal.classList.contains('hidden');
    }

    /**
     * Get modal element
     * @param {string} modalId - Modal element ID
     */
    getModal(modalId) {
        return this.modals.get(modalId);
    }
}

// Create global instance
window.Modals = new ModalManager();


/**
 * Toast Notification System
 */
class ToastManager {
    constructor() {
        this.container = document.getElementById('toast-container');
        this.toasts = [];
        this.defaultDuration = 5000;
    }

    /**
     * Show a toast notification
     * @param {Object} options - Toast options
     */
    show(options = {}) {
        const {
            type = 'info',
            title = '',
            message = '',
            duration = this.defaultDuration,
            closable = true
        } = options;

        const id = Utils.generateId('toast');
        const icons = {
            success: 'check-circle',
            error: 'alert-circle',
            warning: 'alert-triangle',
            info: 'info'
        };

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.dataset.toastId = id;

        toast.innerHTML = `
            <div class="toast-icon">
                <i data-lucide="${icons[type] || icons.info}" class="w-5 h-5"></i>
            </div>
            <div class="toast-content">
                ${title ? `<div class="toast-title">${Utils.escapeHtml(title)}</div>` : ''}
                ${message ? `<div class="toast-message">${Utils.escapeHtml(message)}</div>` : ''}
            </div>
            ${closable ? `
                <button class="toast-close" aria-label="Close">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            ` : ''}
        `;

        // Add to container
        this.container.appendChild(toast);
        this.toasts.push({ id, element: toast });

        // Initialize icons
        if (window.lucide) {
            lucide.createIcons({ nodes: [toast] });
        }

        // Close button handler
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide(id));
        }

        // Auto-hide after duration
        if (duration > 0) {
            setTimeout(() => this.hide(id), duration);
        }

        return id;
    }

    /**
     * Hide a toast
     * @param {string} id - Toast ID
     */
    hide(id) {
        const index = this.toasts.findIndex(t => t.id === id);
        if (index === -1) return;

        const { element } = this.toasts[index];

        // Add hiding animation
        element.classList.add('hiding');

        // Remove after animation
        setTimeout(() => {
            element.remove();
            this.toasts.splice(index, 1);
        }, 300);
    }

    /**
     * Hide all toasts
     */
    hideAll() {
        [...this.toasts].forEach(t => this.hide(t.id));
    }

    /**
     * Shorthand methods
     */
    success(title, message, duration) {
        return this.show({ type: 'success', title, message, duration });
    }

    error(title, message, duration) {
        return this.show({ type: 'error', title, message, duration });
    }

    warning(title, message, duration) {
        return this.show({ type: 'warning', title, message, duration });
    }

    info(title, message, duration) {
        return this.show({ type: 'info', title, message, duration });
    }
}

// Create global instance
window.Toast = new ToastManager();


/**
 * Context Menu Manager
 */
class ContextMenuManager {
    constructor() {
        this.menu = document.getElementById('context-menu');
        this.isOpen = false;
        this.context = null;

        // Only initialize if menu element exists
        if (this.menu) {
            this.init();
        }
    }

    /**
     * Initialize context menu
     */
    init() {
        // Hide on click outside
        document.addEventListener('click', (e) => {
            if (this.menu && !this.menu.contains(e.target)) {
                this.hide();
            }
        });

        // Hide on scroll
        document.addEventListener('scroll', () => this.hide(), true);

        // Hide on escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hide();
            }
        });

        // Handle menu item clicks
        if (this.menu) {
            this.menu.querySelectorAll('.context-menu-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    const action = item.dataset.action;
                    if (action) {
                        this.handleAction(action);
                    }
                    this.hide();
                });
            });
        }

        // Listen for canvas context menu events
        document.addEventListener('canvas-contextmenu', (e) => {
            this.show(e.detail.x, e.detail.y, e.detail);
        });
    }

    /**
     * Show context menu
     * @param {number} x - X position
     * @param {number} y - Y position
     * @param {Object} context - Context data
     */
    show(x, y, context = {}) {
        if (!this.menu) return;

        this.context = context;
        this.isOpen = true;

        // Update menu items based on context
        this.updateMenuItems(context);

        // Position menu
        this.menu.style.left = `${x}px`;
        this.menu.style.top = `${y}px`;
        this.menu.classList.remove('hidden');

        // Adjust position if menu goes off screen
        const rect = this.menu.getBoundingClientRect();
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;

        if (rect.right > windowWidth) {
            this.menu.style.left = `${x - rect.width}px`;
        }

        if (rect.bottom > windowHeight) {
            this.menu.style.top = `${y - rect.height}px`;
        }
    }

    /**
     * Hide context menu
     */
    hide() {
        if (!this.menu) return;

        this.menu.classList.add('hidden');
        this.isOpen = false;
        this.context = null;
    }

    /**
     * Update menu items based on context
     */
    updateMenuItems(context) {
        if (!this.menu) return;

        const hasSelection = context.hasSelection;

        // Enable/disable items based on selection
        this.menu.querySelectorAll('.context-menu-item').forEach(item => {
            const action = item.dataset.action;

            switch (action) {
                case 'duplicate':
                case 'delete':
                case 'copy':
                    item.disabled = !hasSelection;
                    item.classList.toggle('opacity-50', !hasSelection);
                    item.classList.toggle('pointer-events-none', !hasSelection);
                    break;
                case 'paste':
                    // Check if clipboard has content
                    const hasClipboard = window.editorInstance?.nodeManager?.clipboard?.length > 0;
                    item.disabled = !hasClipboard;
                    item.classList.toggle('opacity-50', !hasClipboard);
                    item.classList.toggle('pointer-events-none', !hasClipboard);
                    break;
            }
        });
    }

    /**
     * Handle menu action
     */
    handleAction(action) {
        if (!window.editorInstance) return;

        const editor = window.editorInstance;

        switch (action) {
            case 'duplicate':
                editor.canvasManager?.duplicateSelectedNodes();
                break;
            case 'delete':
                editor.canvasManager?.deleteSelectedNodes();
                break;
            case 'copy':
                editor.nodeManager?.copySelectedNodes();
                Toast.info('Copied', 'Nodes copied to clipboard');
                break;
            case 'paste':
                if (this.context?.canvasPoint) {
                    editor.nodeManager?.pasteNodes(this.context.canvasPoint);
                    editor.canvasManager?.renderNodes();
                }
                break;
            case 'select-all':
                editor.canvasManager?.selectAllNodes();
                break;
        }
    }
}

// Create global instance
window.ContextMenu = new ContextMenuManager();


/**
 * Confirmation Dialog
 */
class ConfirmDialog {
    /**
     * Show confirmation dialog
     * @param {Object} options - Dialog options
     * @returns {Promise<boolean>}
     */
    static show(options = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Confirm',
                message = 'Are you sure?',
                confirmText = 'Confirm',
                cancelText = 'Cancel',
                type = 'default' // default, danger, warning
            } = options;

            // Create modal element
            const modalId = 'modal-confirm-' + Date.now();
            const modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal';

            const buttonClass = type === 'danger'
                ? 'bg-red-600 hover:bg-red-700'
                : 'bg-primary-600 hover:bg-primary-700';

            modal.innerHTML = `
                <div class="modal-backdrop"></div>
                <div class="modal-content w-full max-w-md">
                    <div class="modal-header">
                        <h3 class="text-lg font-semibold text-dark-50">${Utils.escapeHtml(title)}</h3>

                        <button class="modal-close">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-dark-300">${Utils.escapeHtml(message)}</p>
                    </div>

                    <div class="modal-footer">
                        <button class="btn-secondary" id="${modalId}-cancel">${Utils.escapeHtml(cancelText)}</button>
                        <button class="btn-primary ${buttonClass}" id="${modalId}-confirm">${Utils.escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Initialize icons
            if (window.lucide) {
                lucide.createIcons({ nodes: [modal] });
            }

            // Handle buttons
            const cleanup = (result) => {
                modal.remove();
                resolve(result);
            };

            modal.querySelector('.modal-close').addEventListener('click', () => cleanup(false));
            modal.querySelector('.modal-backdrop').addEventListener('click', () => cleanup(false));
            modal.querySelector(`#${modalId}-cancel`).addEventListener('click', () => cleanup(false));
            modal.querySelector(`#${modalId}-confirm`).addEventListener('click', () => cleanup(true));

            // ESC key
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    document.removeEventListener('keydown', escHandler);
                    cleanup(false);
                }
            };
            document.addEventListener('keydown', escHandler);

            // Focus confirm button
            setTimeout(() => {
                modal.querySelector(`#${modalId}-confirm`).focus();
            }, 100);
        });
    }

    /**
     * Show delete confirmation
     */
    static delete(itemName = 'this item') {
        return this.show({
            title: 'Delete Confirmation',
            message: `Are you sure you want to delete ${itemName}? This action cannot be undone.`,
            confirmText: 'Delete',
            cancelText: 'Cancel',
            type: 'danger'
        });
    }

    /**
     * Show unsaved changes confirmation
     */
    static unsavedChanges() {
        return this.show({
            title: 'Unsaved Changes',
            message: 'You have unsaved changes. Do you want to continue without saving?',
            confirmText: 'Continue',
            cancelText: 'Cancel',
            type: 'warning'
        });
    }
}

// Make available globally
window.ConfirmDialog = ConfirmDialog;


/**
 * Prompt Dialog
 */
class PromptDialog {
    /**
     * Show prompt dialog
     * @param {Object} options - Dialog options
     * @returns {Promise<string|null>}
     */
    static show(options = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Enter Value',
                message = '',
                placeholder = '',
                defaultValue = '',
                confirmText = 'OK',
                cancelText = 'Cancel',
                inputType = 'text'
            } = options;

            const modalId = 'modal-prompt-' + Date.now();
            const modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal';

            modal.innerHTML = `
                <div class="modal-backdrop"></div>
                <div class="modal-content w-full max-w-md">
                    <div class="modal-header">
                        <h3 class="text-lg font-semibold text-dark-50">${Utils.escapeHtml(title)}</h3>

                        <button class="modal-close">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${message ? `<p class="text-dark-300 mb-4">${Utils.escapeHtml(message)}</p>` : ''}
                        <input 

                            type="${inputType}" 
                            id="${modalId}-input" 
                            class="form-input w-full"
                            placeholder="${Utils.escapeHtml(placeholder)}"
                            value="${Utils.escapeHtml(defaultValue)}"
                        >
                    </div>
                    <div class="modal-footer">
                        <button class="btn-secondary" id="${modalId}-cancel">${Utils.escapeHtml(cancelText)}</button>
                        <button class="btn-primary" id="${modalId}-confirm">${Utils.escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            if (window.lucide) {
                lucide.createIcons({ nodes: [modal] });
            }

            const input = modal.querySelector(`#${modalId}-input`);

            const cleanup = (result) => {
                modal.remove();
                resolve(result);
            };

            const confirm = () => {
                const value = input.value.trim();
                cleanup(value || null);
            };

            modal.querySelector('.modal-close').addEventListener('click', () => cleanup(null));
            modal.querySelector('.modal-backdrop').addEventListener('click', () => cleanup(null));
            modal.querySelector(`#${modalId}-cancel`).addEventListener('click', () => cleanup(null));
            modal.querySelector(`#${modalId}-confirm`).addEventListener('click', confirm);

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    confirm();
                }
            });

            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    document.removeEventListener('keydown', escHandler);
                    cleanup(null);
                }
            };
            document.addEventListener('keydown', escHandler);

            // Focus input
            setTimeout(() => {
                input.focus();
                input.select();
            }, 100);
        });
    }
}

// Make available globally
window.PromptDialog = PromptDialog;