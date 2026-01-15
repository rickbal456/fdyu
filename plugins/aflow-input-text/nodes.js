/**
 * AIKAFLOW Plugin - Text/Prompt Input with AI Enhancement
 * 
 * Provides text input for prompts with optional AI enhancement via OpenRouter.
 * The actual API call is proxied through the server to keep API keys secure.
 */

(function () {
    'use strict';

    /**
     * Get OpenRouter settings from the server
     * The API key is stored server-side, never exposed to browser
     */
    async function getOpenRouterSettings() {
        try {
            // Fetch from public settings endpoint (safe for all users)
            const response = await fetch('./api/ai/prompts.php');
            const data = await response.json();
            if (data.success) {
                return {
                    isConfigured: data.isConfigured === true,
                    model: data.model || 'openai/gpt-4o-mini',
                    systemPrompts: data.systemPrompts || []
                };
            }
        } catch (e) {
            console.log('Could not load OpenRouter settings:', e);
        }
        return { isConfigured: false, model: 'openai/gpt-4o-mini', systemPrompts: [] };
    }

    /**
     * Save OpenRouter settings - not used for regular users (admin only via plugins.js)
     */
    function saveOpenRouterSettings(settings) {
        // Settings are saved by admin through plugins.js, not here
        console.log('OpenRouter settings are managed by admin');
    }

    /**
     * Enhance text using server-side OpenRouter API proxy
     * API key is never exposed to browser
     */
    async function enhanceText(text, systemPromptId) {
        // Check if configured (sync check from cached status)
        const isConfigured = window.pluginManager?.integrationStatus?.openrouter === true;

        if (!isConfigured) {
            throw new Error('OpenRouter API key not configured. Please configure it in Administration → Integrations.');
        }

        // Call server-side endpoint
        const response = await fetch('./api/ai/enhance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                text: text,
                systemPromptId: systemPromptId
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to enhance text');
        }

        return data.enhanced;
    }

    /**
     * Enhance text using a custom user-provided prompt
     */
    async function enhanceWithCustomPrompt(text, customPrompt) {
        // Check if configured
        const isConfigured = window.pluginManager?.integrationStatus?.openrouter === true;

        if (!isConfigured) {
            throw new Error('OpenRouter API key not configured. Please configure it in Administration → Integrations.');
        }

        // Call server-side endpoint with custom prompt
        const response = await fetch('./api/ai/enhance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                text: text,
                customPrompt: customPrompt
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to enhance text');
        }

        return data.enhanced;
    }

    const nodeDefinitions = {
        'text-input': {
            type: 'text-input',
            category: 'input',
            name: 'Text Input',
            description: 'Enter text for prompts with AI enhancement',
            icon: 'type',
            inputs: [
                { id: 'flow', type: 'flow', label: 'Wait For', optional: true }
            ],
            outputs: [
                { id: 'text', type: 'text', label: 'Text' }
            ],
            fields: [
                {
                    id: 'text',
                    type: 'textarea',
                    label: 'Text / Prompt',
                    placeholder: 'Enter your text or prompt here...',
                    rows: 4,
                    // Wand icon button next to label for AI enhancement
                    labelAction: {
                        id: 'enhance',
                        icon: 'wand-2',
                        title: 'Enhance with AI'
                    }
                }
            ],
            preview: null,
            defaultData: {
                text: ''
            },
            // Local execution - just passes through the text
            execute: async function (nodeData, inputs, context) {
                const { text } = nodeData;

                if (text && text.trim()) {
                    return { text: text.trim() };
                }

                throw new Error('No text provided');
            }
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-input-text', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }

    // Expose enhancement function globally for other plugins and properties panel
    window.AIKAFLOWTextEnhance = {
        enhance: enhanceText,
        enhanceWithCustomPrompt: enhanceWithCustomPrompt,
        getSettings: getOpenRouterSettings,
        saveSettings: saveOpenRouterSettings
    };
})();
