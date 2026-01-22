/**
 * AIKAFLOW Notification Manager
 * 
 * Handles browser push notifications for workflow status updates.
 * Manages user preferences and browser permission requests.
 */

(function () {
    'use strict';

    const STORAGE_KEY = 'aikaflow_notifications_enabled';

    // State
    let isEnabled = false;
    let isInitialized = false;

    /**
     * Initialize the notification manager
     */
    function init() {
        if (isInitialized) return;
        isInitialized = true;

        // Load user preference from localStorage
        isEnabled = localStorage.getItem(STORAGE_KEY) === 'true';

        // Setup UI handlers
        setupUIHandlers();

        // Update UI to reflect current state
        updateUI();

        // Listen for workflow events
        setupWorkflowListeners();

        console.log('[Notifications] Initialized, enabled:', isEnabled);
    }

    /**
     * Setup UI event handlers
     */
    function setupUIHandlers() {
        // Toggle checkbox change
        const toggle = document.getElementById('setting-notifications-enabled');
        if (toggle) {
            toggle.checked = isEnabled;
            toggle.addEventListener('change', (e) => {
                handleToggleChange(e.target.checked);
            });
        }

        // Permission request button
        const requestBtn = document.getElementById('btn-request-notification');
        if (requestBtn) {
            requestBtn.addEventListener('click', requestPermission);
        }
    }

    /**
     * Handle toggle change
     */
    async function handleToggleChange(enabled) {
        if (enabled) {
            // User wants to enable notifications
            if (!('Notification' in window)) {
                showToast('Your browser does not support notifications', 'error');
                document.getElementById('setting-notifications-enabled').checked = false;
                return;
            }

            const permission = Notification.permission;

            if (permission === 'granted') {
                // Already have permission, just enable
                setEnabled(true);
                showToast('Desktop notifications enabled', 'success');
            } else if (permission === 'denied') {
                // Permission was denied previously
                setEnabled(true); // Store preference, but can't show notifications
                updateUI(); // Show instructions to enable manually
            } else {
                // Need to request permission
                setEnabled(true);
                updateUI();
                // Don't auto-request, let user click the button
            }
        } else {
            // User wants to disable
            setEnabled(false);
            showToast('Desktop notifications disabled', 'info');
        }

        updateUI();
    }

    /**
     * Request notification permission
     */
    async function requestPermission() {
        if (!('Notification' in window)) {
            showToast('Your browser does not support notifications', 'error');
            return;
        }

        try {
            const permission = await Notification.requestPermission();

            if (permission === 'granted') {
                showToast('Notifications enabled!', 'success');
                // Show a test notification
                showTestNotification();
            } else if (permission === 'denied') {
                showToast('Notifications blocked. See instructions below.', 'warning');
            }

            updateUI();
        } catch (error) {
            console.error('[Notifications] Permission request failed:', error);
            showToast('Failed to request permission', 'error');
        }
    }

    /**
     * Show a test notification
     */
    function showTestNotification() {
        if (Notification.permission !== 'granted') return;

        const siteTitle = window.AIKAFLOW?.siteTitle || 'AIKAFLOW';
        const notification = new Notification(siteTitle, {
            body: 'Desktop notifications are now enabled! 🎉',
            icon: getNotificationIcon('success'),
            badge: '/assets/images/logo.png',
            tag: 'aikaflow-test',
            requireInteraction: false
        });

        notification.onclick = () => {
            window.focus();
            notification.close();
        };

        setTimeout(() => notification.close(), 4000);
    }

    /**
     * Set enabled state and save to localStorage
     */
    function setEnabled(enabled) {
        isEnabled = enabled;
        localStorage.setItem(STORAGE_KEY, enabled ? 'true' : 'false');

        // Update checkbox if it exists
        const toggle = document.getElementById('setting-notifications-enabled');
        if (toggle) {
            toggle.checked = enabled;
        }
    }

    /**
     * Update UI to reflect current permission status
     */
    function updateUI() {
        const toggle = document.getElementById('setting-notifications-enabled');
        const statusContainer = document.getElementById('notification-permission-status');
        const statusGranted = document.getElementById('notification-status-granted');
        const statusDenied = document.getElementById('notification-status-denied');
        const statusDefault = document.getElementById('notification-status-default');

        if (!statusContainer) return;

        // Show/hide permission status based on toggle state
        if (toggle?.checked) {
            statusContainer.classList.remove('hidden');

            const permission = 'Notification' in window ? Notification.permission : 'denied';

            // Hide all statuses first
            statusGranted?.classList.add('hidden');
            statusDenied?.classList.add('hidden');
            statusDefault?.classList.add('hidden');

            // Show appropriate status
            if (permission === 'granted') {
                statusGranted?.classList.remove('hidden');
            } else if (permission === 'denied') {
                statusDenied?.classList.remove('hidden');
            } else {
                statusDefault?.classList.remove('hidden');
            }

            // Reinitialize Lucide icons
            if (window.lucide) {
                lucide.createIcons({ nodes: [statusContainer] });
            }
        } else {
            statusContainer.classList.add('hidden');
        }
    }

    /**
     * Setup workflow event listeners
     */
    function setupWorkflowListeners() {
        // Workflow completed
        document.addEventListener('workflow:run:complete', (e) => {
            const workflowName = e.detail?.workflowName || 'Workflow';
            showWorkflowNotification(
                `${workflowName} Complete`,
                `Your workflow "${workflowName}" has finished successfully! 🎉`,
                'success',
                e.detail?.resultUrl
            );
        });

        // Workflow failed
        document.addEventListener('workflow:run:error', (e) => {
            const workflowName = e.detail?.workflowName || 'Workflow';
            const errorMessage = e.detail?.error || 'An error occurred during execution.';
            showWorkflowNotification(
                `${workflowName} Failed`,
                `Workflow "${workflowName}" failed: ${errorMessage}`,
                'error'
            );
        });
    }

    /**
     * Show a workflow notification
     */
    function showWorkflowNotification(title, body, type = 'info', url = null) {
        // Check if notifications are enabled by user
        if (!isEnabled) {
            console.log('[Notifications] Not enabled, skipping notification');
            return;
        }

        // Check if browser supports notifications
        if (!('Notification' in window)) {
            return;
        }

        // Check if permission is granted
        if (Notification.permission !== 'granted') {
            console.log('[Notifications] Permission not granted, skipping');
            return;
        }

        // Only show notification if page is not visible
        // (User is in another tab or minimized)
        if (document.visibilityState === 'visible') {
            console.log('[Notifications] Page visible, skipping desktop notification');
            return;
        }

        try {
            const notification = new Notification(title, {
                body: body,
                icon: getNotificationIcon(type),
                badge: '/assets/images/logo.png',
                tag: 'aikaflow-workflow-' + Date.now(),
                requireInteraction: false,
                silent: false
            });

            // Handle click - focus window and optionally open result
            notification.onclick = () => {
                window.focus();
                if (url) {
                    window.open(url, '_blank');
                }
                notification.close();
            };

            // Auto-close after 8 seconds
            setTimeout(() => notification.close(), 8000);

        } catch (error) {
            console.error('[Notifications] Failed to show notification:', error);
        }
    }

    /**
     * Get notification icon based on type
     */
    function getNotificationIcon(type) {
        // Use a data URL for the icon to avoid 404s
        // You can replace these with actual icon paths if they exist
        const basePath = '/assets/images/';

        switch (type) {
            case 'success':
                return basePath + 'notification-success.png';
            case 'error':
                return basePath + 'notification-error.png';
            default:
                return basePath + 'logo.png';
        }
    }

    /**
     * Show toast message (use existing Toast system or console)
     */
    function showToast(message, type = 'info') {
        if (window.Toast) {
            switch (type) {
                case 'success': Toast.success(message); break;
                case 'error': Toast.error(message); break;
                case 'warning': Toast.warning(message); break;
                default: Toast.info(message);
            }
        } else if (window.showToast) {
            window.showToast(message, type);
        } else {
            console.log(`[Notifications] ${type}: ${message}`);
        }
    }

    /**
     * Check if notifications are currently enabled
     */
    function checkEnabled() {
        return isEnabled &&
            'Notification' in window &&
            Notification.permission === 'granted';
    }

    /**
     * Public API
     */
    window.NotificationManager = {
        init,
        isEnabled: () => isEnabled,
        checkEnabled,
        setEnabled,
        requestPermission,
        showNotification: showWorkflowNotification,
        updateUI
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // Small delay to ensure other components are ready
        setTimeout(init, 100);
    }

})();
