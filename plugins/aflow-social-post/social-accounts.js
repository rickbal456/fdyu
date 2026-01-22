/**
 * AIKAFLOW - Social Accounts UI
 * 
 * Injects Social Accounts management UI into the Settings Modal.
 * Handles account connection/disconnection and OAuth flow.
 */

(function () {
    'use strict';

    // Platform configurations
    const PLATFORMS = {
        instagram: { name: 'Instagram', icon: 'instagram', color: 'pink', bgClass: 'bg-pink-500/20', textClass: 'text-pink-400' },
        tiktok: { name: 'TikTok', icon: 'music', color: 'cyan', bgClass: 'bg-cyan-500/20', textClass: 'text-cyan-400' },
        facebook: { name: 'Facebook', icon: 'facebook', color: 'blue', bgClass: 'bg-blue-500/20', textClass: 'text-blue-400' },
        youtube: { name: 'YouTube', icon: 'youtube', color: 'red', bgClass: 'bg-red-500/20', textClass: 'text-red-400' },
        x: { name: 'X (Twitter)', icon: 'twitter', color: 'gray', bgClass: 'bg-gray-500/20', textClass: 'text-gray-400' },
        linkedin: { name: 'LinkedIn', icon: 'linkedin', color: 'blue', bgClass: 'bg-blue-600/20', textClass: 'text-blue-400' },
        pinterest: { name: 'Pinterest', icon: 'bookmark', color: 'red', bgClass: 'bg-red-600/20', textClass: 'text-red-400' },
        bluesky: { name: 'Bluesky', icon: 'cloud', color: 'sky', bgClass: 'bg-sky-500/20', textClass: 'text-sky-400' },
        threads: { name: 'Threads', icon: 'at-sign', color: 'gray', bgClass: 'bg-gray-600/20', textClass: 'text-gray-300' }
    };

    // Wait for DOM ready
    document.addEventListener('DOMContentLoaded', initSocialAccountsUI);

    // Also try to init immediately if DOM is already ready
    if (document.readyState !== 'loading') {
        setTimeout(initSocialAccountsUI, 100);
    }

    function initSocialAccountsUI() {
        // Check if Settings Modal exists and Social tab doesn't exist yet
        const settingsModal = document.getElementById('modal-settings');
        if (!settingsModal) return;

        const existingTab = settingsModal.querySelector('[data-tab="social"]');
        if (existingTab) return; // Already injected

        // Find the tabs container
        const tabsContainer = settingsModal.querySelector('.flex.border-b');
        if (!tabsContainer) return;

        // Add Social Accounts tab button
        const socialTabBtn = document.createElement('button');
        socialTabBtn.className = 'settings-tab';
        socialTabBtn.dataset.tab = 'social';
        socialTabBtn.innerHTML = '<span data-i18n="social.social_accounts">Social Accounts</span>';
        tabsContainer.appendChild(socialTabBtn);

        // Find the settings content container (where other tab contents are)
        const modalBody = settingsModal.querySelector('.modal-body');
        if (!modalBody) return;

        // Create the Social Accounts content section
        const socialContent = document.createElement('div');
        socialContent.id = 'settings-social';
        socialContent.className = 'settings-content hidden';
        socialContent.innerHTML = `
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-sm font-medium text-dark-200" data-i18n="social.connected_accounts">Connected Accounts</h4>
                        <p class="text-xs text-dark-400" data-i18n="social.connect_to_publish">Connect your social media accounts to publish content directly.</p>
                    </div>
                    <button id="btn-connect-social" class="btn-primary text-sm px-3 py-1.5 flex items-center gap-1">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span data-i18n="social.account">Account</span>
                    </button>
                </div>
                <div id="social-accounts-list" class="space-y-2">
                    <div class="text-center py-6 text-dark-400">
                        <i data-lucide="loader" class="w-6 h-6 mx-auto animate-spin"></i>
                        <p class="mt-2 text-sm" data-i18n="social.loading_accounts">Loading accounts...</p>
                    </div>
                </div>
            </div>
        `;
        modalBody.appendChild(socialContent);

        // Initialize Lucide icons for new elements
        if (window.lucide) {
            lucide.createIcons({ root: socialContent });
        }
        // Translate the injected content
        if (window.I18n?.translatePage) window.I18n.translatePage();

        // Setup tab click handler
        socialTabBtn.addEventListener('click', () => {
            // Remove active from all tabs
            settingsModal.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
            settingsModal.querySelectorAll('.settings-content').forEach(c => c.classList.add('hidden'));

            // Activate social tab
            socialTabBtn.classList.add('active');
            socialContent.classList.remove('hidden');

            // Load accounts
            loadSocialAccounts();
        });

        // Setup connect button
        const connectBtn = socialContent.querySelector('#btn-connect-social');
        if (connectBtn) {
            connectBtn.addEventListener('click', showPlatformSelector);
        }

    }

    /**
     * Load and display connected social accounts
     */
    async function loadSocialAccounts() {
        const container = document.getElementById('social-accounts-list');
        if (!container) return;

        // Show loading
        container.innerHTML = `
            <div class="text-center py-6 text-dark-400">
                <i data-lucide="loader" class="w-6 h-6 mx-auto animate-spin"></i>
                <p class="mt-2 text-sm">Loading accounts...</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons({ root: container });

        try {
            const response = await fetch('./api/social/accounts.php');
            const data = await response.json();

            if (data.success && data.accounts && data.accounts.length > 0) {
                renderAccountsList(container, data.accounts);
            } else {
                container.innerHTML = `
                    <div class="text-center py-8 text-dark-400">
                        <i data-lucide="user-x" class="w-10 h-10 mx-auto opacity-50 mb-3"></i>
                        <p class="text-sm font-medium">No accounts connected</p>
                        <p class="text-xs mt-1">Click "Connect Account" to add your first social media account.</p>
                    </div>
                `;
                if (window.lucide) lucide.createIcons({ root: container });
            }
        } catch (error) {
            console.error('Failed to load social accounts:', error);
            container.innerHTML = `
                <div class="text-center py-6 text-red-400">
                    <i data-lucide="alert-circle" class="w-6 h-6 mx-auto mb-2"></i>
                    <p class="text-sm">Failed to load accounts</p>
                    <p class="text-xs mt-1">${error.message || 'Please check your API key configuration.'}</p>
                </div>
            `;
            if (window.lucide) lucide.createIcons({ root: container });
        }
    }

    /**
     * Render the accounts list
     */
    function renderAccountsList(container, accounts) {
        let html = '';

        accounts.forEach(account => {
            const platform = PLATFORMS[account.platform] || {
                name: account.platform,
                icon: 'user',
                bgClass: 'bg-gray-500/20',
                textClass: 'text-gray-400'
            };

            const statusClass = account.status === 'connected'
                ? 'text-green-400'
                : 'text-yellow-400';
            const statusText = account.status === 'connected' ? 'Connected' : 'Needs Reconnect';

            html += `
                <div class="flex items-center justify-between p-3 bg-dark-800/50 rounded-lg border border-dark-700 hover:border-dark-600 transition-colors" data-account-id="${account.id}">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full ${platform.bgClass} flex items-center justify-center">
                            <i data-lucide="${platform.icon}" class="w-5 h-5 ${platform.textClass}"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-dark-100">${Utils.escapeHtml(account.username || 'Unknown')}</span>
                                <span class="text-xs ${statusClass}">${statusText}</span>
                            </div>
                            <span class="text-xs text-dark-400">${platform.name}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        ${account.status !== 'connected' ? `
                            <button class="btn-secondary text-xs px-2 py-1 reconnect-account-btn" data-account-id="${account.id}" data-platform="${account.platform}">
                                <i data-lucide="refresh-cw" class="w-3 h-3 inline mr-1"></i>
                                Reconnect
                            </button>
                        ` : ''}
                        <button class="text-dark-400 hover:text-red-400 p-1 disconnect-account-btn" data-account-id="${account.id}" title="Disconnect account">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;

        // Initialize icons
        if (window.lucide) lucide.createIcons({ root: container });

        // Setup disconnect buttons
        container.querySelectorAll('.disconnect-account-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const accountId = btn.dataset.accountId;
                await disconnectAccount(accountId);
            });
        });

        // Setup reconnect buttons
        container.querySelectorAll('.reconnect-account-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const platform = btn.dataset.platform;
                connectPlatform(platform);
            });
        });
    }

    /**
     * Show platform selector modal/dropdown
     */
    function showPlatformSelector() {
        // Note: Don't check API key here - let the backend validate and return a clear error
        // This prevents UI issues if integrationStatus hasn't loaded yet

        // Create platform selector dropdown/modal
        const existingModal = document.getElementById('platform-selector-modal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'platform-selector-modal';
        modal.className = 'fixed inset-0 bg-black/50 flex items-center justify-center z-[100]';
        modal.innerHTML = `
            <div class="bg-dark-800 rounded-xl border border-dark-700 shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                <div class="px-4 py-3 border-b border-dark-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-dark-100" data-i18n="social.connect_social_account">Connect Social Account</h3>
                    <button id="close-platform-selector" class="text-dark-400 hover:text-dark-200">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-4">
                    <p class="text-sm text-dark-400 mb-4" data-i18n="social.select_platform">Select a platform to connect:</p>
                    <div class="grid grid-cols-3 gap-3">
                        ${Object.entries(PLATFORMS).map(([key, platform]) => `
                            <button class="platform-option flex flex-col items-center gap-2 p-3 rounded-lg border border-dark-600 hover:border-primary-500 hover:bg-dark-700/50 transition-all" data-platform="${key}">
                                <div class="w-10 h-10 rounded-full ${platform.bgClass} flex items-center justify-center">
                                    <i data-lucide="${platform.icon}" class="w-5 h-5 ${platform.textClass}"></i>
                                </div>
                                <span class="text-xs text-dark-200">${platform.name}</span>
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Initialize icons
        if (window.lucide) lucide.createIcons({ root: modal });

        // Close button
        modal.querySelector('#close-platform-selector').addEventListener('click', () => {
            modal.remove();
        });

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        // Platform selection
        modal.querySelectorAll('.platform-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const platform = btn.dataset.platform;
                modal.remove();
                connectPlatform(platform);
            });
        });
    }

    /**
     * Initiate OAuth connection for a platform
     */
    async function connectPlatform(platform) {
        try {
            Toast.info(`Connecting to ${PLATFORMS[platform]?.name || platform}...`);

            const response = await fetch('./api/social/connect.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform })
            });

            const data = await response.json();

            if (data.success && data.authUrl) {
                // Open OAuth popup
                const width = 600;
                const height = 700;
                const left = (window.innerWidth - width) / 2;
                const top = (window.innerHeight - height) / 2;

                const popup = window.open(
                    data.authUrl,
                    'SocialConnect',
                    `width=${width},height=${height},left=${left},top=${top},toolbar=no,menubar=no`
                );

                // Poll for popup close
                const pollTimer = setInterval(() => {
                    if (popup.closed) {
                        clearInterval(pollTimer);
                        // Reload accounts list
                        loadSocialAccounts();
                        Toast.success('Account connection completed', 'Check the list for your new account.');
                    }
                }, 500);

            } else {
                throw new Error(data.error || 'Failed to get authorization URL');
            }
        } catch (error) {
            console.error('Connect platform error:', error);
            Toast.error('Connection Failed', error.message || 'Failed to connect platform');
        }
    }

    /**
     * Disconnect a social account
     */
    async function disconnectAccount(accountId) {
        // Confirm deletion
        let confirmed = false;
        if (window.ConfirmDialog) {
            confirmed = await ConfirmDialog.show({
                title: 'Disconnect Account?',
                message: 'Are you sure you want to disconnect this social account? You will need to reconnect it to post again.',
                confirmText: 'Disconnect',
                type: 'danger'
            });
        } else {
            confirmed = confirm('Disconnect this social account?');
        }

        if (!confirmed) return;

        try {
            const response = await fetch(`./api/social/accounts.php?id=${accountId}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (data.success) {
                Toast.success('Account disconnected');
                loadSocialAccounts();
            } else {
                throw new Error(data.error || 'Failed to disconnect account');
            }
        } catch (error) {
            console.error('Disconnect account error:', error);
            Toast.error('Disconnect Failed', error.message || 'Failed to disconnect account');
        }
    }

    // Expose functions globally for use by other scripts
    window.SocialAccountsUI = {
        loadSocialAccounts,
        connectPlatform,
        disconnectAccount
    };

})();
