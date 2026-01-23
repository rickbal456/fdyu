/**
 * AIKAFLOW - Social Media Post Plugin
 * 
 * This file defines the Social Post node for publishing content to social media platforms.
 * 
 * SECURITY NOTE: All API configuration (endpoints, provider details) are stored
 * server-side in plugin.json and resolved by the worker.
 * This client-side file only handles UI and node registration.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-social-post';

    // Platform configurations with icons and colors
    const PLATFORMS = {
        instagram: { name: 'Instagram', icon: 'instagram', color: 'pink' },
        tiktok: { name: 'TikTok', icon: 'music', color: 'cyan' },
        facebook: { name: 'Facebook', icon: 'facebook', color: 'blue' },
        youtube: { name: 'YouTube', icon: 'youtube', color: 'red' },
        x: { name: 'X (Twitter)', icon: 'twitter', color: 'gray' },
        linkedin: { name: 'LinkedIn', icon: 'linkedin', color: 'blue' },
        pinterest: { name: 'Pinterest', icon: 'image', color: 'red' },
        bluesky: { name: 'Bluesky', icon: 'cloud', color: 'blue' },
        threads: { name: 'Threads', icon: 'at-sign', color: 'gray' }
    };

    /**
     * Check if Social API key is configured (either plugin-specific or main)
     * This checks STATUS only, not the actual key value (for security)
     * @returns {boolean}
     */
    function hasSocialApiKey() {
        // First check plugin-specific key status
        if (PluginManager.hasApiKey(`plugin_${PLUGIN_ID}`)) return true;

        // Then check main Social API key status
        return PluginManager.hasApiKey('sapi');
    }

    /**
     * Get user's own API key from settings (if they entered one themselves)
     * This is for users who bring their own key
     */
    function getUserApiKey() {
        // Check user's own keys (not admin-shared)
        const pluginKey = PluginManager.getAiKey(`plugin_${PLUGIN_ID}`);
        if (pluginKey) return pluginKey;

        return PluginManager.getAiKey('sapi');
    }

    /**
     * Fetch connected social accounts from API
     */
    async function fetchSocialAccounts() {
        try {
            const response = await fetch('./api/social/accounts.php');
            const data = await response.json();
            console.log('[Social Post Node] Fetch accounts response:', data);
            if (data.success && data.accounts) {
                return data.accounts;
            } else {
                console.warn('[Social Post Node] Failed to fetch accounts:', data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('[Social Post Node] Failed to fetch social accounts:', error);
        }
        return [];
    }

    // Build fields array - API key field is always included but hidden when configured
    const fields = [
        {
            id: 'apiKey',
            type: 'text',
            label: 'API Key',
            placeholder: 'Your Social API key',
            description: 'Or configure in Settings → Integrations',
            // Hide this field if API key is already configured in admin settings
            showIf: () => !hasSocialApiKey()
        }
    ];

    // Add main fields
    fields.push(
        {
            id: 'accounts',
            type: 'multiselect',
            label: 'Post To',
            placeholder: 'Select social accounts...',
            description: 'Select one or more connected accounts',
            options: [] // Will be populated by onRender callback
        },
        {
            id: 'caption',
            type: 'textarea',
            label: 'Caption',
            placeholder: 'Write your post caption here...\n\nSupports hashtags and mentions.',
            rows: 4,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'scheduleType',
            type: 'select',
            label: 'Publish Time',
            default: 'now',
            options: [
                { value: 'now', label: 'Publish Immediately' },
                { value: 'scheduled', label: 'Schedule for Later' }
            ]
        },
        {
            id: 'scheduledAt',
            type: 'datetime',
            label: 'Scheduled Date/Time',
            showIf: { scheduleType: 'scheduled' }
        }
    );

    /**
     * Load social accounts and update multiselect options
     */
    async function loadAccountsIntoMultiselect(container, currentAccounts) {
        console.log('[Social Post Node] loadAccountsIntoMultiselect called, container:', container);
        // Find the accounts multiselect specifically by data-field-id
        const multiselectContainer = container.querySelector('.multiselect-container[data-field-id="accounts"]');
        console.log('[Social Post Node] multiselectContainer found:', multiselectContainer);
        if (!multiselectContainer) {
            console.warn('[Social Post Node] No multiselect container found for accounts!');
            return;
        }

        // Show loading state
        multiselectContainer.innerHTML = `
            <div class="text-sm text-dark-400 py-2 text-center">
                <i data-lucide="loader" class="w-4 h-4 inline mr-1 animate-spin"></i>
                Loading accounts...
            </div>
        `;
        if (window.lucide) lucide.createIcons({ root: multiselectContainer });

        try {
            const accounts = await fetchSocialAccounts();

            if (accounts.length === 0) {
                multiselectContainer.innerHTML = `
                    <div class="text-sm text-dark-400 py-3 text-center">
                        <i data-lucide="user-x" class="w-5 h-5 mx-auto mb-2 opacity-50"></i>
                        <p>No accounts connected</p>
                        <p class="text-xs mt-1">Go to Settings → Social Accounts</p>
                    </div>
                `;
            } else {
                const selectedValues = Array.isArray(currentAccounts) ? currentAccounts : [];
                let html = '';
                accounts.forEach(acc => {
                    const isChecked = selectedValues.includes(acc.id);
                    const platformIcon = PLATFORMS[acc.platform]?.icon || 'user';
                    html += `
                        <label class="flex items-center gap-2 p-2 rounded-lg hover:bg-dark-700/50 cursor-pointer ${isChecked ? 'bg-dark-700/30' : ''}">
                            <input type="checkbox" 
                                   class="form-checkbox multiselect-option" 
                                   data-field-id="accounts"
                                   data-option-value="${acc.id}"
                                   ${isChecked ? 'checked' : ''}>
                            <i data-lucide="${platformIcon}" class="w-4 h-4 opacity-60"></i>
                            <span class="text-sm text-dark-200">${Utils.escapeHtml(acc.username)} (${PLATFORMS[acc.platform]?.name || acc.platform})</span>
                        </label>
                    `;
                });
                multiselectContainer.innerHTML = html;
            }

            if (window.lucide) lucide.createIcons({ root: multiselectContainer });
        } catch (error) {
            console.error('Failed to load social accounts:', error);
            multiselectContainer.innerHTML = `
                <div class="text-sm text-red-400 py-2 text-center">
                    <i data-lucide="alert-circle" class="w-4 h-4 inline mr-1"></i>
                    Failed to load accounts
                </div>
            `;
            if (window.lucide) lucide.createIcons({ root: multiselectContainer });
        }
    }

    // Register the Social Post node
    PluginManager.registerNode({
        type: 'social-post',
        category: 'output',
        name: 'Social Post',
        description: 'Publish content to social media platforms',
        icon: 'share-2',
        inputs: [
            { id: 'flow', type: 'flow', label: 'Wait For' },
            { id: 'video', type: 'video', label: 'Video (Optional)', optional: true },
            { id: 'image', type: 'image', label: 'Image (Optional)', optional: true },
            { id: 'text', type: 'text', label: 'Caption (Optional)', optional: true }
        ],
        outputs: [
            { id: 'result', type: 'object', label: 'Result' }
        ],
        fields: fields,
        preview: {
            type: 'status',
            source: 'output'
        },
        defaultData: {
            apiKey: '',
            accounts: [],
            caption: '',
            scheduleType: 'now',
            scheduledAt: null
        },

        // Called when properties panel renders this node
        onRender: (container, nodeData) => {
            // Load social accounts into multiselect
            loadAccountsIntoMultiselect(container, nodeData.accounts || []);
        },

        // Custom execution handler
        execute: async function (node, inputs, context) {
            const { apiKey, accounts, caption, scheduleType, scheduledAt } = node.data;

            // Get connected inputs
            const videoUrl = inputs.video || null;
            const imageUrl = inputs.image || null;
            const textInput = inputs.text || '';

            // Use connected text input or fall back to node's caption field
            const finalCaption = textInput.trim() || caption;

            // Determine API key source:
            // 1. User provided key in node field
            // 2. User's own saved key in their settings
            // 3. Admin-configured key (checked on server-side, not exposed here)
            const userProvidedKey = apiKey || getUserApiKey();
            const adminHasKey = hasSocialApiKey();

            if (!userProvidedKey && !adminHasKey) {
                throw new Error('Social API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!accounts || accounts.length === 0) {
                throw new Error('Please select at least one social account to post to.');
            }

            if (!finalCaption && !videoUrl && !imageUrl) {
                throw new Error('Please provide caption text or media (image/video) to post.');
            }

            // Build media array
            const media = [];
            if (videoUrl) {
                media.push({ url: videoUrl, type: 'video' });
            }
            if (imageUrl) {
                media.push({ url: imageUrl, type: 'image' });
            }

            // Build the API payload
            // Note: If userProvidedKey is empty, the server will use the admin-configured key
            const payload = {
                apiKey: userProvidedKey, // Empty string signals server to use admin key
                useAdminKey: !userProvidedKey && adminHasKey, // Flag for server
                caption: finalCaption,
                social_accounts: accounts,
                media: media
            };

            // Add scheduling if configured
            if (scheduleType === 'scheduled' && scheduledAt) {
                payload.scheduled_at = new Date(scheduledAt).toISOString();
            }

            // Return the payload for server-side execution
            // Server will resolve endpoint from plugin.json
            return {
                action: PLUGIN_ID,
                payload: payload
            };
        }
    });

})();
