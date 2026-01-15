/**
 * AIKAFLOW - Plugin Manager
 * 
 * Handles plugin loading, installation, and management.
 * Works similar to WordPress plugin system.
 */

class PluginManager {
    constructor() {
        this.plugins = new Map();
        this.loadedNodes = new Map();
        this.isInitialized = false;
        this.baseUrl = ''; // Will be set during init
    }

    /**
     * Initialize plugin manager
     */
    async init() {
        // Get base URL - use relative path based on known AIKAFLOW config or derive from pathname
        // For root-level deployment (like app.fidyu.com), use relative './api'
        // For subdirectory deployment (like localhost/aikaflow), use '/subdirectory/api'

        if (window.AIKAFLOW && window.AIKAFLOW.apiUrl) {
            // Use the configured API URL from PHP (most reliable)
            this.baseUrl = window.AIKAFLOW.apiUrl;
            // Derive app base from apiUrl (remove /api suffix)
            this.appBase = this.baseUrl.replace(/\/api\/?$/, '');
        } else {
            // Fallback: derive from pathname, but be smarter about it
            const pathname = window.location.pathname;

            // Check if we're at root level (e.g., /view, /index, /login etc)
            // or in a subdirectory (e.g., /aikaflow/view, /aikaflow/index)
            const knownRootPages = ['view', 'view.php', 'index', 'index.php', 'login', 'login.php', 'register', 'register.php'];
            const pathParts = pathname.split('/').filter(p => p);

            if (pathParts.length <= 1 || knownRootPages.includes(pathParts[0])) {
                // Root level deployment
                this.baseUrl = './api';
                this.appBase = '.';
            } else {
                // Subdirectory deployment (e.g., /aikaflow)
                this.appBase = '/' + pathParts[0];
                this.baseUrl = this.appBase + '/api';
            }
        }

        // Check if we're in viewer mode (read-only, possibly unauthenticated)
        const isViewerMode = document.body.classList.contains('read-only-mode') ||
            window.location.pathname.includes('view');

        try {
            // Skip auth-requiring API calls in viewer mode to avoid 401 errors
            if (!isViewerMode) {
                // Load integration status from database (NOT actual keys - keys stay server-side for security)
                this.integrationStatus = await this.loadIntegrationStatus();

                // Load actual keys for admin editing (only values the user owns, not shared admin keys)
                this.integrationKeys = await this.loadUserIntegrationKeys();
            } else {
                // In viewer mode, use empty defaults
                this.integrationStatus = {};
                this.integrationKeys = {};
            }

            // Load installed plugins (this should work even in viewer mode as plugins list is public)
            await this.loadPlugins();

            // Setup UI event listeners (only if relevant UI exists)
            this.setupEventListeners();

            // Render integration keys in settings (only if admin UI exists)
            if (!isViewerMode) {
                await this.renderIntegrationKeys();
            }

            this.isInitialized = true;

            // Expose global function for admin.js to call when integrations tab opens
            window.loadIntegrationKeys = async () => {
                await this.renderIntegrationKeys();
            };

        } catch (error) {
            console.error('Plugin Manager initialization error:', error);
            // Even if init fails, mark as initialized so dependent code can proceed
            this.isInitialized = true;
        }
    }

    /**
     * Load integration STATUS (true/false for each provider) - does NOT expose actual keys
     * This is safe to call from the browser
     */
    async loadIntegrationStatus() {
        try {
            const response = await fetch('./api/user/integration-status.php');
            const data = await response.json();
            if (data.success && data.configured) {
                return data.configured;
            }
        } catch (error) {
            console.error('Failed to load integration status:', error);
        }
        return {};
    }

    /**
     * Load SITE-LEVEL integration keys for editing in admin settings
     * Admin gets full keys, regular users get nothing (avoids 403 error)
     */
    async loadUserIntegrationKeys() {
        try {
            // First check if user is admin via public settings (avoids 403 network error)
            const publicRes = await fetch('./api/user/public-settings.php');
            const publicData = await publicRes.json();

            if (!publicData.isAdmin) {
                // User is not admin, don't even try to hit admin endpoint
                return {};
            }

            // User is admin, safe to call admin endpoint
            const response = await fetch('./api/admin/settings.php');
            const data = await response.json();

            if (data.success && data.settings && data.settings.integration_keys) {
                // integration_keys is stored as JSON string in site_settings
                const keys = typeof data.settings.integration_keys === 'string'
                    ? JSON.parse(data.settings.integration_keys)
                    : data.settings.integration_keys;
                return keys || {};
            }
        } catch (error) {
            console.log('Could not load integration keys:', error);
        }
        return {};
    }

    /**
     * Save integration key to database (site-level)
     */
    async saveIntegrationKey(provider, value) {
        try {
            const keys = await this.loadUserIntegrationKeys();
            keys[provider] = value;

            // Save to admin settings (site_settings table)
            await fetch('./api/admin/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ integration_keys: JSON.stringify(keys) })
            });

            // Also update integration status cache
            this.integrationStatus = await this.loadIntegrationStatus();
        } catch (error) {
            console.error('Failed to save integration key:', error);
        }
    }

    /**
     * Check if an integration has an API key configured
     * Returns true/false only - does NOT expose the actual key value (for security)
     * The actual key is used server-side during workflow execution
     */
    static hasApiKey(provider) {
        // Check integration status (loaded from secure endpoint)
        if (window.pluginManager?.integrationStatus) {
            return window.pluginManager.integrationStatus[provider] === true;
        }
        return false;
    }

    /**
     * Get a specific integration key (sync version - uses cached USER keys only)
     * This returns keys the USER has entered themselves, not admin-shared keys
     * For checking if admin has configured a key, use hasApiKey() instead
     */
    static getAiKey(provider) {
        // Use cached keys from the instance if available
        // Note: instance is window.pluginManager (lowercase 'p')
        if (window.pluginManager?.integrationKeys) {
            return window.pluginManager.integrationKeys[provider] || '';
        }
        return '';
    }

    /**
     * Render integration keys in settings
     */
    async renderIntegrationKeys() {
        const container = document.getElementById('integration-keys-container');
        if (!container) return;

        // Collect all providers and which plugins use them
        const providers = new Map();

        // Known provider metadata
        // Note: BunnyCDN is configured as a storage plugin, not here
        const providerMeta = {
            'runninghub': { name: 'RunningHub', icon: 'cpu', color: 'purple', placeholder: 'Enter your RunningHub API key' },
            'jsoncut': { name: 'JsonCut', icon: 'scissors', color: 'blue', placeholder: 'Enter your JsonCut API key' },
            'openrouter': { name: 'OpenRouter (LLM)', icon: 'bot', color: 'pink', placeholder: 'Enter your OpenRouter API key' },
            'postforme': { name: 'Postforme (Social Media)', icon: 'share-2', color: 'pink', placeholder: 'Enter your Postforme API key' }
        };

        // Scan plugins for providers
        this.plugins.forEach((plugin, id) => {
            if (!plugin.enabled) return;

            // Check apiConfig.provider for node plugins
            const provider = plugin.apiConfig?.provider;
            if (provider) {
                if (!providers.has(provider)) {
                    providers.set(provider, { plugins: [], type: 'api' });
                }
                providers.get(provider).plugins.push(plugin.name);
            }

            // Check for storage plugins
            if (plugin.type === 'storage') {
                const storageProvider = plugin.id.replace('aflow-storage-', '');
                if (!providers.has(storageProvider)) {
                    providers.set(storageProvider, { plugins: [], type: 'storage', configFields: plugin.configFields || [] });
                }
                providers.get(storageProvider).plugins.push(plugin.name);
            }

            // Check for optional providers (like openrouter for text enhancement)
            if (plugin.optionalProviders && Array.isArray(plugin.optionalProviders)) {
                plugin.optionalProviders.forEach(p => {
                    if (!providers.has(p)) {
                        providers.set(p, { plugins: [], type: 'api' });
                    }
                    providers.get(p).plugins.push(plugin.name + ' (optional)');
                });
            }
        });

        // Always include OpenRouter if text-input plugin is available (for enhancement feature)
        if (!providers.has('openrouter')) {
            const hasTextInput = Array.from(this.plugins.values()).some(p => p.enabled && p.type === 'input-text');
            if (hasTextInput) {
                providers.set('openrouter', { plugins: ['Text Input Enhancement'], type: 'api' });
            }
        }

        // Build HTML
        let html = '';

        if (providers.size === 0) {
            html = `
                <div class="text-center py-6 text-gray-500">
                    <i data-lucide="plug" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                    <p class="text-sm">No integrations found</p>
                    <p class="text-xs">Install plugins to configure API keys</p>
                </div>
            `;
        } else {
            const savedKeys = await this.loadUserIntegrationKeys();

            // Pre-load OpenRouter settings if needed
            let openRouterSettings = { model: 'openai/gpt-4o-mini', systemPrompts: [] };
            if (providers.has('openrouter')) {
                openRouterSettings = await this.loadOpenRouterSettings();
            }

            for (const [provider, data] of providers) {
                const meta = providerMeta[provider] || {
                    name: provider.charAt(0).toUpperCase() + provider.slice(1),
                    icon: 'key',
                    color: 'gray',
                    placeholder: `Enter your ${provider} API key`
                };

                const usedByText = data.plugins.length > 0
                    ? `Used by: ${data.plugins.join(', ')}`
                    : '';

                if (data.type === 'storage' && data.configFields.length > 0) {
                    // Render storage plugin config fields
                    html += `
                        <div class="bg-dark-800/50 rounded-lg p-4 border border-dark-700">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-lg bg-${meta.color}-500/20 flex items-center justify-center">
                                    <i data-lucide="${meta.icon}" class="w-4 h-4 text-${meta.color}-400"></i>
                                </div>
                                <div>
                                    <h5 class="font-medium text-dark-100">${meta.name}</h5>
                                    <p class="text-xs text-gray-500">${usedByText}</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                    `;

                    data.configFields.forEach(field => {
                        const fieldId = `integration-${provider}-${field.id}`;
                        const savedValue = savedKeys[`${provider}_${field.id}`] || '';
                        html += `
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-1">${field.label}</label>
                                <input type="${field.type === 'password' ? 'password' : 'text'}" 
                                       id="${fieldId}" 
                                       class="form-input font-mono text-sm integration-key-input"
                                       data-provider="${provider}"
                                       data-field="${field.id}"
                                       placeholder="${field.placeholder || ''}"
                                       value="${savedValue}">
                            </div>
                        `;
                    });

                    html += `
                            </div>
                        </div>
                    `;
                } else if (provider === 'openrouter') {
                    // Special rendering for OpenRouter with model selection and system prompts
                    const savedValue = savedKeys[provider] || '';

                    html += `
                        <div class="bg-dark-800/50 rounded-lg p-4 border border-dark-700">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-lg bg-${meta.color}-500/20 flex items-center justify-center">
                                    <i data-lucide="${meta.icon}" class="w-4 h-4 text-${meta.color}-400"></i>
                                </div>
                                <div>
                                    <h5 class="font-medium text-dark-100">${meta.name}</h5>
                                    <p class="text-xs text-gray-500">${usedByText || 'Text enhancement for prompts'}</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-1">API Key</label>
                                    <input type="password" 
                                           id="integration-${provider}" 
                                           class="form-input font-mono text-sm integration-key-input"
                                           data-provider="${provider}"
                                           placeholder="${meta.placeholder}"
                                           value="${savedValue}">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-1">Model</label>
                                    <select id="openrouter-model" class="form-select openrouter-setting" data-setting="model">
                                        <option value="openai/gpt-4o-mini" ${openRouterSettings.model === 'openai/gpt-4o-mini' ? 'selected' : ''}>GPT-4o Mini (Fast)</option>
                                        <option value="openai/gpt-4o" ${openRouterSettings.model === 'openai/gpt-4o' ? 'selected' : ''}>GPT-4o</option>
                                        <option value="anthropic/claude-3.5-sonnet" ${openRouterSettings.model === 'anthropic/claude-3.5-sonnet' ? 'selected' : ''}>Claude 3.5 Sonnet</option>
                                        <option value="anthropic/claude-3-haiku" ${openRouterSettings.model === 'anthropic/claude-3-haiku' ? 'selected' : ''}>Claude 3 Haiku (Fast)</option>
                                        <option value="google/gemini-pro-1.5" ${openRouterSettings.model === 'google/gemini-pro-1.5' ? 'selected' : ''}>Gemini Pro 1.5</option>
                                        <option value="meta-llama/llama-3.1-70b-instruct" ${openRouterSettings.model === 'meta-llama/llama-3.1-70b-instruct' ? 'selected' : ''}>Llama 3.1 70B</option>
                                    </select>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-sm font-medium text-dark-300">System Prompts</label>
                                        <button type="button" id="add-system-prompt" class="text-xs text-primary-400 hover:text-primary-300 flex items-center gap-1">
                                            <i data-lucide="plus" class="w-3 h-3"></i> Add Prompt
                                        </button>
                                    </div>
                                    <div id="system-prompts-list" class="space-y-2">
                                        ${this.renderSystemPromptsList(openRouterSettings.systemPrompts || [])}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    // Render simple API key field with concurrency limit
                    const savedValue = savedKeys[provider] || '';
                    const savedConcurrency = savedKeys[`${provider}_concurrency`] ?? '';
                    const isUnlimited = savedConcurrency === '0' || savedConcurrency === 0;

                    html += `
                        <div class="bg-dark-800/50 rounded-lg p-4 border border-dark-700">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-lg bg-${meta.color}-500/20 flex items-center justify-center">
                                    <i data-lucide="${meta.icon}" class="w-4 h-4 text-${meta.color}-400"></i>
                                </div>
                                <div>
                                    <h5 class="font-medium text-dark-100">${meta.name}</h5>
                                    <p class="text-xs text-gray-500">${usedByText}</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-xs text-dark-400 mb-1 block">API Key</label>
                                    <input type="password" 
                                           id="integration-${provider}" 
                                           class="form-input font-mono text-sm integration-key-input"
                                           data-provider="${provider}"
                                           placeholder="${meta.placeholder}"
                                           value="${savedValue}">
                                </div>
                                <div>
                                    <label class="text-xs text-dark-400 mb-1 block">Concurrent Request Limit</label>
                                    <div class="flex items-center gap-2">
                                        <select id="integration-${provider}-concurrency-type"
                                                class="form-select text-sm flex-1 concurrency-type-select"
                                                data-provider="${provider}">
                                            <option value="default" ${!savedConcurrency ? 'selected' : ''}>Default (50)</option>
                                            <option value="unlimited" ${isUnlimited ? 'selected' : ''}>Unlimited</option>
                                            <option value="custom" ${savedConcurrency && !isUnlimited ? 'selected' : ''}>Custom</option>
                                        </select>
                                        <input type="number" 
                                               id="integration-${provider}-concurrency" 
                                               class="form-input text-sm w-24 integration-key-input concurrency-input ${(!savedConcurrency || isUnlimited) ? 'hidden' : ''}"
                                               data-provider="${provider}"
                                               data-field="concurrency"
                                               placeholder="50"
                                               min="1"
                                               max="1000"
                                               value="${savedConcurrency && !isUnlimited ? savedConcurrency : ''}">
                                    </div>
                                    <p class="text-xs text-dark-500 mt-1">Max simultaneous API calls. Use "Unlimited" for no rate limiting.</p>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
        }

        container.innerHTML = html;

        // Reinitialize Lucide icons
        if (window.lucide) {
            lucide.createIcons({ root: container });
        }

        container.querySelectorAll('.integration-key-input').forEach(input => {
            input.addEventListener('blur', () => {
                const provider = input.dataset.provider;
                const field = input.dataset.field;
                const key = field ? `${provider}_${field}` : provider;
                this.saveIntegrationKey(key, input.value.trim());
            });
            input.addEventListener('change', () => {
                const provider = input.dataset.provider;
                const field = input.dataset.field;
                const key = field ? `${provider}_${field}` : provider;
                this.saveIntegrationKey(key, input.value.trim());
            });
        });

        // Setup concurrency type dropdown listeners
        container.querySelectorAll('.concurrency-type-select').forEach(select => {
            select.addEventListener('change', (e) => {
                const provider = select.dataset.provider;
                const concurrencyInput = container.querySelector(`#integration-${provider}-concurrency`);
                const selectedValue = e.target.value;

                if (selectedValue === 'custom') {
                    // Show custom input
                    concurrencyInput?.classList.remove('hidden');
                    concurrencyInput?.focus();
                } else {
                    // Hide custom input
                    concurrencyInput?.classList.add('hidden');

                    // Save the value
                    if (selectedValue === 'unlimited') {
                        // Save 0 for unlimited
                        this.saveIntegrationKey(`${provider}_concurrency`, '0');
                    } else {
                        // Default - remove the setting (empty string)
                        this.saveIntegrationKey(`${provider}_concurrency`, '');
                    }
                }
            });
        });

        // Setup OpenRouter specific listeners
        this.setupOpenRouterListeners(container);
    }

    /**
     * Load OpenRouter settings (model and system prompts)
     * Uses public endpoint which is safe for all users
     */
    async loadOpenRouterSettings() {
        try {
            const response = await fetch('./api/user/public-settings.php');
            const data = await response.json();

            if (data.success && data.settings && data.settings.openrouter_settings) {
                return data.settings.openrouter_settings;
            }
        } catch (e) {
            console.log('Could not load OpenRouter settings:', e);
        }
        return { model: 'openai/gpt-4o-mini', systemPrompts: [] };
    }

    /**
     * Save OpenRouter settings to site_settings (global, admin only)
     */
    async saveOpenRouterSettings(settings) {
        try {
            await fetch('./api/admin/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ openrouter_settings: JSON.stringify(settings) })
            });
        } catch (e) {
            console.error('Failed to save OpenRouter settings:', e);
        }
    }

    /**
     * Render system prompts list HTML as accordion
     */
    renderSystemPromptsList(prompts) {
        if (!prompts || prompts.length === 0) {
            return '<p class="text-xs text-gray-500 italic">No custom prompts. Click "Add Prompt" to create one.</p>';
        }

        return prompts.map((prompt, index) => `
            <div class="system-prompt-accordion border border-dark-600 rounded-lg overflow-hidden" data-prompt-id="${prompt.id}">
                <div class="system-prompt-header flex items-center justify-between p-3 bg-dark-700 cursor-pointer hover:bg-dark-650 transition-colors">
                    <div class="flex items-center gap-2 flex-1">
                        <i data-lucide="chevron-right" class="w-4 h-4 text-dark-400 accordion-icon transition-transform"></i>
                        <span class="text-sm font-medium text-dark-100 prompt-title">${Utils.escapeHtml(prompt.name || 'Untitled Prompt')}</span>
                    </div>
                    <button type="button" class="text-red-400 hover:text-red-300 delete-prompt-btn p-1" data-prompt-id="${prompt.id}" title="Delete prompt">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="system-prompt-content hidden p-3 bg-dark-800 space-y-3">
                    <div>
                        <label class="block text-xs text-dark-400 mb-1">Prompt Name</label>
                        <input type="text" class="form-input text-sm prompt-name-input"
                               value="${Utils.escapeHtml(prompt.name || '')}" placeholder="Enter prompt name" data-prompt-id="${prompt.id}">
                    </div>
                    <div>
                        <label class="block text-xs text-dark-400 mb-1">System Prompt Content</label>
                        <textarea class="form-textarea text-xs h-24 prompt-content-input" placeholder="Enter system prompt content..."
                                  data-prompt-id="${prompt.id}">${Utils.escapeHtml(prompt.content || '')}</textarea>
                    </div>
                </div>
            </div>
        `).join('');
    }

    /**
     * Setup OpenRouter specific event listeners
     */
    setupOpenRouterListeners(container) {
        // Model selection change
        const modelSelect = container.querySelector('#openrouter-model');
        if (modelSelect) {
            modelSelect.addEventListener('change', () => {
                const settings = this.loadOpenRouterSettings();
                settings.model = modelSelect.value;
                this.saveOpenRouterSettings(settings);
            });
        }

        // Add system prompt button
        const addBtn = container.querySelector('#add-system-prompt');
        if (addBtn) {
            addBtn.addEventListener('click', async () => {
                // Create new prompt with default values
                const settings = await this.loadOpenRouterSettings();
                const newPrompt = {
                    id: 'prompt_' + Date.now(),
                    name: 'New Prompt',
                    content: ''
                };
                settings.systemPrompts = settings.systemPrompts || [];
                settings.systemPrompts.push(newPrompt);
                await this.saveOpenRouterSettings(settings);

                // Re-render prompts list
                const listContainer = container.querySelector('#system-prompts-list');
                if (listContainer) {
                    listContainer.innerHTML = this.renderSystemPromptsList(settings.systemPrompts);
                    if (window.lucide) lucide.createIcons({ root: listContainer });
                    this.setupPromptItemListeners(container);

                    // Auto-expand the newly added prompt
                    const newAccordion = listContainer.querySelector(`[data-prompt-id="${newPrompt.id}"]`);
                    if (newAccordion) {
                        this.togglePromptAccordion(newAccordion, true);
                        // Focus on the name input
                        const nameInput = newAccordion.querySelector('.prompt-name-input');
                        if (nameInput) {
                            nameInput.focus();
                            nameInput.select();
                        }
                    }
                }
            });
        }

        // Setup listeners for existing prompt items
        this.setupPromptItemListeners(container);
    }

    /**
     * Toggle accordion open/close
     */
    togglePromptAccordion(accordion, forceOpen = null) {
        const content = accordion.querySelector('.system-prompt-content');
        const icon = accordion.querySelector('.accordion-icon');

        const shouldOpen = forceOpen !== null ? forceOpen : content.classList.contains('hidden');

        if (shouldOpen) {
            content.classList.remove('hidden');
            icon?.classList.add('rotate-90');
        } else {
            content.classList.add('hidden');
            icon?.classList.remove('rotate-90');
        }
    }

    /**
     * Setup listeners for system prompt items
     */
    setupPromptItemListeners(container) {
        // Accordion header click to expand/collapse
        container.querySelectorAll('.system-prompt-header').forEach(header => {
            header.addEventListener('click', (e) => {
                // Don't toggle if clicking delete button
                if (e.target.closest('.delete-prompt-btn')) return;

                const accordion = header.closest('.system-prompt-accordion');
                if (accordion) {
                    this.togglePromptAccordion(accordion);
                }
            });
        });

        // Prompt name input - update title on change
        container.querySelectorAll('.prompt-name-input').forEach(input => {
            input.addEventListener('input', () => {
                const accordion = input.closest('.system-prompt-accordion');
                const title = accordion?.querySelector('.prompt-title');
                if (title) {
                    title.textContent = input.value || 'Untitled Prompt';
                }
            });

            input.addEventListener('blur', async () => {
                const promptId = input.dataset.promptId;
                const settings = await this.loadOpenRouterSettings();
                const prompt = settings.systemPrompts?.find(p => p.id === promptId);
                if (prompt) {
                    prompt.name = input.value;
                    await this.saveOpenRouterSettings(settings);
                }
            });
        });

        // Prompt content textarea
        container.querySelectorAll('.prompt-content-input').forEach(textarea => {
            textarea.addEventListener('blur', async () => {
                const promptId = textarea.dataset.promptId;
                const settings = await this.loadOpenRouterSettings();
                const prompt = settings.systemPrompts?.find(p => p.id === promptId);
                if (prompt) {
                    prompt.content = textarea.value;
                    await this.saveOpenRouterSettings(settings);
                }
            });
        });

        // Delete prompt button
        container.querySelectorAll('.delete-prompt-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation(); // Prevent accordion toggle
                const promptId = btn.dataset.promptId;

                // Use ConfirmDialog if available, otherwise fallback to confirm
                let confirmed = false;
                if (window.ConfirmDialog) {
                    confirmed = await ConfirmDialog.show({
                        title: 'Delete Prompt?',
                        message: 'Are you sure you want to delete this system prompt?',
                        confirmText: 'Delete',
                        type: 'danger'
                    });
                } else {
                    confirmed = confirm('Delete this system prompt?');
                }

                if (!confirmed) return;

                const settings = await this.loadOpenRouterSettings();
                settings.systemPrompts = settings.systemPrompts?.filter(p => p.id !== promptId) || [];
                await this.saveOpenRouterSettings(settings);

                // Re-render prompts list
                const listContainer = container.querySelector('#system-prompts-list');
                if (listContainer) {
                    listContainer.innerHTML = this.renderSystemPromptsList(settings.systemPrompts);
                    if (window.lucide) lucide.createIcons({ root: listContainer });
                    this.setupPromptItemListeners(container);
                }
            });
        });
    }

    /**
     * Load all installed plugins from server
     */
    async loadPlugins() {
        try {
            const url = `${this.baseUrl}/plugins/list.php`;

            const response = await fetch(url);

            // Check response status
            if (!response.ok) {
                console.error('Plugin API error:', response.status, response.statusText);
                return;
            }

            const data = await response.json();

            if (data.success && data.plugins) {
                this.plugins.clear();

                for (const plugin of data.plugins) {
                    this.plugins.set(plugin.id, plugin);

                    // Load plugin nodes if enabled
                    if (plugin.enabled && plugin.hasNodes) {
                        await this.loadPluginNodes(plugin);
                    }

                    // Load custom scripts if enabled and defined
                    if (plugin.enabled && plugin.scripts && Array.isArray(plugin.scripts)) {
                        await this.loadPluginScripts(plugin);
                    }
                }

                // Update the UI if modal exists
                this.renderPluginsList();

                // Render integration keys in settings
                await this.renderIntegrationKeys();
            }

        } catch (error) {
            console.error('Failed to load plugins:', error);
        }
    }

    /**
     * Legacy: Get a plugin's API key (kept for backward compatibility)
     */
    static getPluginApiKey(pluginId) {
        return PluginManager.getAiKey(`plugin_${pluginId}`);
    }


    /**
     * Load nodes from a plugin
     */
    async loadPluginNodes(plugin) {
        try {
            // Use the app base path for loading plugin scripts
            const nodesUrl = `${this.appBase}/plugins/${plugin.id}/nodes.js`;



            // Check if nodes.js exists by trying to fetch it
            const response = await fetch(nodesUrl, { method: 'HEAD' });

            if (response.ok) {
                // Dynamically load the script
                await this.loadScript(nodesUrl);
            }

        } catch (error) {
            console.error(`Failed to load nodes from plugin ${plugin.id}:`, error);
        }
    }

    /**
     * Dynamically load a script
     */
    loadScript(src) {
        return new Promise((resolve, reject) => {
            // Check if script is already loaded
            const isLoaded = Array.from(document.querySelectorAll('script')).some(s => {
                return s.src.includes(src) || (s.src && s.src.endsWith(src));
            });

            if (isLoaded) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = src + '?v=' + Date.now(); // Cache bust
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    /**
     * Load custom scripts from a plugin
     */
    async loadPluginScripts(plugin) {
        if (!plugin.scripts || !Array.isArray(plugin.scripts)) return;

        for (const scriptFile of plugin.scripts) {
            try {
                const scriptUrl = `${this.appBase}/plugins/${plugin.id}/${scriptFile}`;

                // Check if script exists
                const response = await fetch(scriptUrl, { method: 'HEAD' });

                if (response.ok) {
                    await this.loadScript(scriptUrl);
                }
            } catch (error) {
                console.error(`Failed to load script ${scriptFile} from plugin ${plugin.id}:`, error);
            }
        }
    }

    /**
     * Register multiple nodes from a plugin
     */
    static registerNodes(pluginId, definitions) {
        if (!definitions || typeof definitions !== 'object') return false;

        let count = 0;
        Object.entries(definitions).forEach(([type, def]) => {
            if (this.registerNode(def)) {
                count++;
            }
        });

        return count > 0;
    }

    /**
     * Register a custom node from a plugin
     */
    static registerNode(nodeDefinition) {
        if (!nodeDefinition || !nodeDefinition.type) {
            console.error('Invalid node definition');
            return false;
        }

        // Add to global NodeDefinitions
        if (window.NodeDefinitions) {
            window.NodeDefinitions[nodeDefinition.type] = nodeDefinition;

            // Also add to sidebar
            PluginManager.addNodeToSidebar(nodeDefinition);

            return true;
        }

        console.error('NodeDefinitions not available');
        return false;
    }

    /**
     * Create a new category in the sidebar
     */
    static createSidebarCategory(category) {
        const libraryEl = document.getElementById('node-library');
        if (!libraryEl) return null;

        const categoryColors = {
            'input': 'blue',
            'generation': 'purple',
            'audio': 'green',
            'editing': 'orange',
            'output': 'cyan',
            'utility': 'gray',
            'control': 'emerald'
        };

        const categoryIcons = {
            'input': 'upload',
            'generation': 'sparkles',
            'audio': 'music',
            'editing': 'sliders',
            'output': 'download',
            'utility': 'tool',
            'control': 'play-circle'
        };

        const color = categoryColors[category] || 'purple';
        const icon = categoryIcons[category] || 'box';
        // Try to get translated category label, fallback to capitalized category name
        const translatedLabel = window.t ? window.t(`categories.${category}`) : null;
        const displayLabel = (translatedLabel && translatedLabel !== `categories.${category}`)
            ? translatedLabel
            : (category.charAt(0).toUpperCase() + category.slice(1));

        const categoryEl = document.createElement('div');
        categoryEl.className = 'node-category';
        categoryEl.setAttribute('data-category', category);

        categoryEl.innerHTML = `
            <button class="category-header">
                <i data-lucide="chevron-down" class="w-4 h-4 transition-transform"></i>
                <i data-lucide="${icon}" class="w-4 h-4 text-${color}-400"></i>
                <span>${displayLabel}</span>
                <span class="ml-auto text-xs text-gray-500">0</span>
            </button>
            <div class="category-content"></div>
        `;

        // Find insert position - keep specific order
        const order = ['control', 'input', 'generation', 'editing', 'audio', 'output', 'utility'];
        const transformOrder = (c) => {
            const idx = order.indexOf(c);
            return idx === -1 ? 999 : idx;
        };

        const currentCategoryIdx = transformOrder(category);
        const childNodes = Array.from(libraryEl.children);
        let inserted = false;

        for (const child of childNodes) {
            const childCategory = child.getAttribute('data-category');
            if (transformOrder(childCategory) > currentCategoryIdx) {
                libraryEl.insertBefore(categoryEl, child);
                inserted = true;
                break;
            }
        }

        if (!inserted) {
            libraryEl.appendChild(categoryEl);
        }

        // Initialize Lucide icons
        if (window.lucide) {
            lucide.createIcons({ root: categoryEl });
        }

        // Add click handler for collapse/expand
        const header = categoryEl.querySelector('.category-header');
        header.addEventListener('click', () => {
            const content = categoryEl.querySelector('.category-content');
            const chevron = header.querySelector('[data-lucide="chevron-down"]');

            content.classList.toggle('hidden');
            if (content.classList.contains('hidden')) {
                chevron.style.transform = 'rotate(-90deg)';
            } else {
                chevron.style.transform = 'rotate(0deg)';
            }
        });

        return categoryEl;
    }

    /**
     * Add a node to the sidebar dynamically
     */
    static addNodeToSidebar(nodeDefinition) {
        // Skip in viewer mode (no sidebar exists)
        const isViewerMode = document.body.classList.contains('read-only-mode') ||
            window.location.pathname.includes('view');
        if (isViewerMode) {
            // In viewer mode, just register the node definition without modifying sidebar
            return;
        }

        // Check if sidebar exists
        const sidebar = document.getElementById('sidebar-left');
        if (!sidebar) {
            // Sidebar doesn't exist, skip adding to sidebar
            return;
        }

        const category = nodeDefinition.category || 'utility';
        const categoryColors = {
            'input': 'blue',
            'generation': 'purple',
            'audio': 'green',
            'editing': 'orange',
            'output': 'cyan',
            'utility': 'gray',
            'control': 'emerald'
        };
        const color = categoryColors[category] || 'purple';

        // Find or create the category container
        let categoryEl = document.querySelector(`.node-category[data-category="${category}"]`);
        if (!categoryEl) {
            categoryEl = PluginManager.createSidebarCategory(category);
        }

        const categoryContent = categoryEl.querySelector('.category-content');
        if (!categoryContent) return;

        // Check if node already exists
        if (categoryContent.querySelector(`[data-node-type="${nodeDefinition.type}"]`)) {
            // Update node info if it exists
            const existingNode = categoryContent.querySelector(`[data-node-type="${nodeDefinition.type}"]`);
            // ... logic to update could go here
            return;
        }

        // Create node item HTML
        const nodeItem = document.createElement('div');
        nodeItem.className = 'node-item';
        nodeItem.setAttribute('data-node-type', nodeDefinition.type);
        nodeItem.setAttribute('draggable', 'true');

        // Try to get translated name and description
        // Convert node type to translation key (e.g., "text-input" -> "text_input")
        const nodeKey = nodeDefinition.type.replace(/-/g, '_');
        const translatedName = window.t ? window.t(`plugins.${nodeKey}.name`) : null;
        const translatedDesc = window.t ? window.t(`plugins.${nodeKey}.description`) : null;

        const displayName = (translatedName && !translatedName.startsWith('plugins.'))
            ? translatedName
            : nodeDefinition.name;
        const displayDesc = (translatedDesc && !translatedDesc.startsWith('plugins.'))
            ? translatedDesc
            : (nodeDefinition.description || 'Custom node');

        nodeItem.innerHTML = `
            <div class="node-icon bg-${color}-500/20 text-${color}-400">
                <i data-lucide="${nodeDefinition.icon || 'puzzle'}" class="w-4 h-4"></i>
            </div>
            <div class="node-info">
                <span class="node-name">${displayName}</span>
                <span class="node-desc">${displayDesc}</span>
            </div>
        `;

        // Add to category
        categoryContent.appendChild(nodeItem);

        // Initialize Lucide icon
        if (window.lucide) {
            lucide.createIcons({ nodes: [nodeItem] });
        }

        // Update category count
        const countEl = categoryEl.querySelector('.category-header .text-gray-500');
        if (countEl) {
            const currentCount = parseInt(countEl.textContent) || 0;
            countEl.textContent = currentCount + 1;
        }

        // Setup drag events for the new node - use same format as editor.js
        nodeItem.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('node-type', nodeDefinition.type);
            e.dataTransfer.effectAllowed = 'copy';
            nodeItem.classList.add('dragging');

            // Create drag image
            const dragImage = nodeItem.cloneNode(true);
            dragImage.style.position = 'absolute';
            dragImage.style.top = '-1000px';
            dragImage.style.opacity = '0.8';
            document.body.appendChild(dragImage);
            e.dataTransfer.setDragImage(dragImage, 20, 20);
            setTimeout(() => dragImage.remove(), 0);
        });

        nodeItem.addEventListener('dragend', () => {
            nodeItem.classList.remove('dragging');
        });

        // Double-click to add node at center
        nodeItem.addEventListener('dblclick', () => {
            if (window.editor && typeof window.editor.addNode === 'function') {
                const center = window.editor.canvasManager?.getViewportCenter() || { x: 400, y: 300 };
                window.editor.addNode(nodeDefinition.type, center);
            }
        });
    }




    /**
     * Setup event listeners for plugin modal
     */
    setupEventListeners() {
        // Upload plugin button
        const uploadBtn = document.getElementById('btn-upload-plugin');
        const fileInput = document.getElementById('plugin-file-input');

        uploadBtn?.addEventListener('click', () => {
            fileInput?.click();
        });

        fileInput?.addEventListener('change', async (e) => {
            if (e.target.files.length > 0) {
                await this.uploadPlugin(e.target.files[0]);
                e.target.value = ''; // Reset input
            }
        });

        // Refresh plugins button
        document.getElementById('btn-refresh-plugins')?.addEventListener('click', async () => {
            await this.refreshPluginsList();
        });
    }

    /**
     * Upload and install a plugin
     */
    async uploadPlugin(file) {
        if (!file.name.endsWith('.zip')) {
            Toast.error('Invalid file', 'Please upload a ZIP file');
            return;
        }

        const formData = new FormData();
        formData.append('plugin', file);

        try {
            Toast.info('Installing...', 'Uploading and installing plugin...');

            const response = await fetch(`${this.baseUrl}/plugins/upload.php`, {

                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                Toast.success(
                    data.plugin.isUpdate ? 'Plugin Updated' : 'Plugin Installed',
                    `${data.plugin.name} v${data.plugin.version}`
                );

                // Refresh the plugins list
                await this.refreshPluginsList();

                // Reload page to load new nodes (optional)
                // window.location.reload();

            } else {
                Toast.error('Installation Failed', data.error || 'Unknown error');
            }

        } catch (error) {
            console.error('Plugin upload error:', error);
            Toast.error('Upload Failed', error.message);
        }
    }

    /**
     * Toggle plugin enabled/disabled
     */
    async togglePlugin(pluginId, enabled) {
        try {
            const response = await fetch(`${this.baseUrl}/plugins/toggle.php`, {

                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pluginId, enabled })
            });

            const data = await response.json();

            if (data.success) {
                Toast.success(
                    enabled ? 'Plugin Enabled' : 'Plugin Disabled',
                    enabled ? 'Plugin nodes are now visible' : 'Plugin nodes hidden from sidebar'
                );

                // Update local state
                const plugin = this.plugins.get(pluginId);
                if (plugin) {
                    plugin.enabled = enabled;

                    // Get plugin node types
                    const nodeTypes = plugin.nodes || [];

                    // Toggle visibility of plugin nodes in sidebar
                    nodeTypes.forEach(nodeType => {
                        this.toggleNodeInSidebar(nodeType, enabled);
                    });
                }

            } else {
                Toast.error('Error', data.error || 'Failed to toggle plugin');
            }

        } catch (error) {
            console.error('Plugin toggle error:', error);
            Toast.error('Error', error.message);
        }
    }

    /**
     * Toggle a node's visibility in the sidebar
     */
    toggleNodeInSidebar(nodeType, visible) {
        const nodeItem = document.querySelector(`.node-item[data-node-type="${nodeType}"]`);
        if (nodeItem) {
            nodeItem.style.display = visible ? '' : 'none';

            // Update category count
            const category = nodeItem.closest('.node-category');
            if (category) {
                this.updateCategoryCount(category);
            }
        }
    }

    /**
     * Update category node count
     */
    updateCategoryCount(categoryEl) {
        const visibleNodes = categoryEl.querySelectorAll('.node-item:not([style*="display: none"])').length;
        const countEl = categoryEl.querySelector('.category-header .text-gray-500');
        if (countEl) {
            countEl.textContent = visibleNodes;
        }
    }


    /**
     * Delete/uninstall a plugin
     */
    async deletePlugin(pluginId) {
        const plugin = this.plugins.get(pluginId);
        const pluginName = plugin?.name || pluginId;
        const nodeTypes = plugin?.nodes || [];

        // Count how many nodes of this plugin are on the canvas
        let nodesOnCanvas = 0;
        if (window.editorInstance?.nodeManager) {
            const allNodes = window.editorInstance.nodeManager.getAllNodes?.() || [];
            nodesOnCanvas = allNodes.filter(n => nodeTypes.includes(n.type)).length;
        }

        // Build warning message
        let message = `Are you sure you want to uninstall "${pluginName}"?\n\n`;
        message += `⚠️ This action will:\n`;
        message += `• Permanently delete the plugin files\n`;
        message += `• Remove plugin nodes from the sidebar\n`;

        if (nodesOnCanvas > 0) {
            message += `• Remove ${nodesOnCanvas} node(s) from your current canvas\n`;
        }

        message += `\nThis action cannot be undone.`;

        const confirmed = await ConfirmDialog.show({
            title: 'Uninstall Plugin',
            message: message,
            confirmText: 'Uninstall',
            type: 'danger'
        });

        if (!confirmed) return;

        try {
            const response = await fetch(`${this.baseUrl}/plugins/delete.php`, {

                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pluginId })
            });

            const data = await response.json();

            if (data.success) {
                // Remove plugin nodes from sidebar
                nodeTypes.forEach(nodeType => {
                    this.removeNodeFromSidebar(nodeType);
                });

                // Remove plugin nodes from canvas
                if (window.editorInstance?.nodeManager) {
                    this.removePluginNodesFromCanvas(nodeTypes);
                }

                // Remove from NodeDefinitions
                nodeTypes.forEach(nodeType => {
                    if (window.NodeDefinitions) {
                        delete window.NodeDefinitions[nodeType];
                    }
                });

                Toast.success('Plugin Uninstalled', `${pluginName} has been removed`);

                // Remove from local state
                this.plugins.delete(pluginId);

                // Refresh the list
                await this.refreshPluginsList();

            } else {
                Toast.error('Error', data.error || 'Failed to uninstall plugin');
            }

        } catch (error) {
            console.error('Plugin delete error:', error);
            Toast.error('Error', error.message);
        }
    }

    /**
     * Remove a node from the sidebar
     */
    removeNodeFromSidebar(nodeType) {
        const nodeItem = document.querySelector(`.node-item[data-node-type="${nodeType}"]`);
        if (nodeItem) {
            const category = nodeItem.closest('.node-category');
            nodeItem.remove();

            // Update category count
            if (category) {
                this.updateCategoryCount(category);
            }
        }
    }

    /**
     * Remove all plugin nodes from the canvas
     */
    removePluginNodesFromCanvas(nodeTypes) {
        if (!window.editorInstance?.nodeManager) return;

        const nodeManager = window.editorInstance.nodeManager;
        const allNodes = nodeManager.getAllNodes?.() || [];

        // Find nodes to remove
        const nodesToRemove = allNodes.filter(n => nodeTypes.includes(n.type));

        // Remove each node
        nodesToRemove.forEach(node => {
            nodeManager.removeNode?.(node.id);
        });

        // Re-render canvas
        window.editorInstance.canvasManager?.renderNodes?.();
    }



    /**
     * Refresh the plugins list in the modal
     */
    async refreshPluginsList() {
        await this.loadPlugins();
        this.renderPluginsList();
    }

    /**
     * Render the plugins list in the modal
     */
    renderPluginsList() {
        const container = document.getElementById('installed-plugins-list');
        if (!container) return;

        // Render all installed plugins
        if (this.plugins.size > 0) {
            container.innerHTML = this.renderInstalledPlugins();
        } else {
            container.innerHTML = `
                <div class="text-center py-6 text-gray-500">
                    <i data-lucide="puzzle" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                    <p class="text-sm">No plugins installed</p>
                    <p class="text-xs">Upload a plugin ZIP file to get started</p>
                </div>
            `;
        }

        // Reinitialize Lucide icons
        if (window.lucide) {
            lucide.createIcons({ nodes: [container] });
        }

        // Attach event listeners
        this.attachPluginEventListeners();
    }

    /**
     * Render installed custom plugins
     */
    renderInstalledPlugins() {
        let html = '';

        for (const [id, plugin] of this.plugins) {
            const colorClass = plugin.color || 'gray';

            html += `
                <div class="plugin-item" data-plugin-id="${id}">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-${colorClass}-500/20 flex items-center justify-center">
                            <i data-lucide="${plugin.icon || 'puzzle'}" class="w-5 h-5 text-${colorClass}-400"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-medium text-dark-50">${plugin.name}</h4>
                            <p class="text-xs text-gray-400">${plugin.description || 'No description'}</p>
                            <p class="text-xs text-gray-500">v${plugin.version} by ${plugin.author}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button class="btn-icon-sm text-red-400 hover:bg-red-500/20" 
                                    data-action="delete" data-plugin-id="${id}" title="Uninstall">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            <label class="toggle-switch">
                                <input type="checkbox" ${plugin.enabled ? 'checked' : ''} 
                                       data-plugin="${id}" data-custom="true">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            `;
        }

        return html;
    }

    /**
     * Attach event listeners to plugin items
     */
    attachPluginEventListeners() {
        // Toggle switches for custom plugins
        document.querySelectorAll('#plugins-list input[data-custom="true"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const pluginId = e.target.dataset.plugin;
                this.togglePlugin(pluginId, e.target.checked);
            });
        });

        // Delete buttons
        document.querySelectorAll('#plugins-list [data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const pluginId = e.currentTarget.dataset.pluginId;
                this.deletePlugin(pluginId);
            });
        });
    }

    /**
     * Get all enabled plugins
     */
    getEnabledPlugins() {
        return Array.from(this.plugins.values()).filter(p => p.enabled);
    }

    /**
     * Check if a plugin is enabled
     */
    isPluginEnabled(pluginId) {
        const plugin = this.plugins.get(pluginId);
        return plugin ? plugin.enabled : false;
    }
}

// Create global instance
window.PluginManager = PluginManager;
window.pluginManager = new PluginManager();
