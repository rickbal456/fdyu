/**
 * AIKAFLOW Plugin - Text/Prompt Input with AI Enhancement
 * 
 * Provides text input for prompts with optional AI enhancement via OpenRouter.
 * OpenRouter integration is built directly into this plugin.
 */

(function () {
    'use strict';

    /**
     * Get OpenRouter settings from localStorage
     */
    function getOpenRouterSettings() {
        try {
            const keys = JSON.parse(localStorage.getItem('aikaflow-integration-keys') || '{}');
            const openRouterSettings = JSON.parse(localStorage.getItem('aikaflow-openrouter-settings') || '{}');
            return {
                apiKey: keys.openrouter || '',
                model: openRouterSettings.model || 'openai/gpt-4o-mini',
                systemPrompts: openRouterSettings.systemPrompts || []
            };
        } catch (e) {
            return { apiKey: '', model: 'openai/gpt-4o-mini', systemPrompts: [] };
        }
    }

    /**
     * Save OpenRouter settings to localStorage
     */
    function saveOpenRouterSettings(settings) {
        try {
            localStorage.setItem('aikaflow-openrouter-settings', JSON.stringify(settings));
        } catch (e) {
            console.error('Failed to save OpenRouter settings:', e);
        }
    }

    /**
     * Enhance text using OpenRouter API
     */
    async function enhanceText(text, systemPromptId) {
        const settings = getOpenRouterSettings();

        if (!settings.apiKey) {
            throw new Error('OpenRouter API key not configured.');
        }

        // Find the system prompt
        let systemPrompt = 'You are a helpful assistant that enhances and improves text prompts for AI image and video generation. Make the prompt more descriptive, detailed, and effective while maintaining the original intent. Return only the enhanced prompt without any explanation.';

        if (systemPromptId && settings.systemPrompts) {
            const selectedPrompt = settings.systemPrompts.find(p => p.id === systemPromptId);
            if (selectedPrompt) {
                systemPrompt = selectedPrompt.content;
            }
        }

        const response = await fetch('https://openrouter.ai/api/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${settings.apiKey}`,
                'Content-Type': 'application/json',
                'HTTP-Referer': window.location.origin,
                'X-Title': 'AIKAFLOW'
            },
            body: JSON.stringify({
                model: settings.model,
                messages: [
                    { role: 'system', content: systemPrompt },
                    { role: 'user', content: text }
                ],
                max_tokens: 1000
            })
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error?.message || 'Failed to enhance text');
        }

        const data = await response.json();
        return data.choices[0]?.message?.content || text;
    }

    const nodeDefinitions = {
        'text-input': {
            type: 'text-input',
            category: 'input',
            name: 'Text Input',
            description: 'Enter text for prompts with AI enhancement',
            icon: 'type',
            inputs: [
                { id: 'flow', type: 'flow', label: 'Wait For' }
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
        getSettings: getOpenRouterSettings,
        saveSettings: saveOpenRouterSettings
    };
})();
