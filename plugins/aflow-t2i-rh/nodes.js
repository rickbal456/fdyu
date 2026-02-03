/**
 * AIKAFLOW Plugin - Text to Image RH (Nano Banana)
 * 
 * This file defines the custom nodes provided by this plugin.
 * Uses the RunningHub V2 rhart-image-n-pro API for image generation.
 * 
 * SECURITY NOTE: All API configuration (endpoints, provider details)
 * are stored server-side in plugin.json and resolved by the worker.
 * This client-side file only handles UI and node registration.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-t2i-rh';

    /**
     * Check if API key is configured (either plugin-specific or main provider)
     * This checks STATUS only, not the actual key value (for security)
     * @returns {boolean}
     */
    function hasApiKeyConfigured() {
        // First check plugin-specific key status
        if (PluginManager.hasApiKey(`plugin_${PLUGIN_ID}`)) return true;

        // Then check main provider key status (rhub)
        return PluginManager.hasApiKey('rhub');
    }

    /**
     * Get user's own API key from node field (if they entered one)
     * This is for users who bring their own key
     */
    function getUserApiKey() {
        // Check user's own keys (not admin-shared)
        const pluginKey = PluginManager.getAiKey(`plugin_${PLUGIN_ID}`);
        if (pluginKey) return pluginKey;

        return PluginManager.getAiKey('rhub');
    }

    // Build fields - API key field is always included but hidden when configured
    const fields = [
        {
            id: 'apiKey',
            type: 'text',
            label: window.t ? window.t('generation.api_key') : 'API Key',
            placeholder: window.t ? window.t('generation.your_api_key') : 'Your API key',
            description: window.t ? window.t('generation.configure_in_settings') : 'Or configure in Settings → Integrations',
            // Hide this field if API key is already configured in admin settings
            showIf: () => !hasApiKeyConfigured()
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: window.t ? window.t('generation.prompt') : 'Prompt',
            placeholder: window.t ? window.t('generation.prompt_placeholder_t2i_rh') : 'Describe the image you want to generate in detail (5-4000 characters)...',
            rows: 6,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'aspectRatio',
            type: 'select',
            label: window.t ? window.t('generation.aspect_ratio') : 'Aspect Ratio',
            default: '1:1',
            options: [
                { value: '1:1', label: window.t ? window.t('generation.square_1_1') : 'Square (1:1)' },
                { value: '16:9', label: window.t ? window.t('generation.landscape_16_9') : 'Landscape (16:9)' },
                { value: '9:16', label: window.t ? window.t('generation.portrait_9_16') : 'Portrait (9:16)' },
                { value: '4:3', label: window.t ? window.t('generation.landscape_4_3') : 'Landscape (4:3)' },
                { value: '3:4', label: window.t ? window.t('generation.portrait_3_4') : 'Portrait (3:4)' },
                { value: '3:2', label: window.t ? window.t('generation.landscape_3_2') : 'Landscape (3:2)' },
                { value: '2:3', label: window.t ? window.t('generation.portrait_2_3') : 'Portrait (2:3)' },
                { value: '21:9', label: window.t ? window.t('generation.ultrawide_21_9') : 'Ultrawide (21:9)' }
            ]
        },
        {
            id: 'resolution',
            type: 'select',
            label: window.t ? window.t('generation.resolution') : 'Resolution',
            default: '1k',
            options: [
                { value: '1k', label: '1K' },
                { value: '2k', label: '2K' },
                { value: '4k', label: '4K' }
            ]
        }
    ];

    // Register custom node: Text to Image RH
    PluginManager.registerNode({
        type: 'aflow-t2i-rh',
        category: 'generation',
        name: window.t ? window.t('generation.text_to_image_rh') : 'Text to Image RH',
        description: window.t ? window.t('generation.generate_image_from_text_rh') : 'Generate Image from Text using Nano Banana API',
        icon: 'image',
        inputs: [
            { id: 'flow', type: 'flow', label: window.t ? window.t('generation.wait_for') : 'Wait For' },
            { id: 'text', type: 'text', label: window.t ? window.t('generation.prompt_optional') : 'Prompt (Optional)', optional: true }
        ],
        outputs: [
            { id: 'image', type: 'image', label: window.t ? window.t('generation.output_image') : 'Output Image' }
        ],
        fields: fields,
        preview: {
            type: 'image',
            source: 'output'
        },
        defaultData: {
            apiKey: '',
            prompt: '',
            aspectRatio: '1:1',
            resolution: '1k'
        },
        // Custom execution handler (used by worker)
        // Returns only node data - server resolves API config from plugin.json
        execute: async function (node, inputs, context) {
            const { apiKey, prompt, aspectRatio, resolution } = node.data;

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Determine API key source
            const userProvidedKey = apiKey || getUserApiKey();
            const adminHasKey = hasApiKeyConfigured();

            if (!userProvidedKey && !adminHasKey) {
                throw new Error(window.t ? window.t('generation.api_key_required') : 'API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!finalPrompt || finalPrompt.length < 5) {
                throw new Error(window.t ? window.t('generation.prompt_required_t2i_rh') : 'Prompt is required (minimum 5 characters). Either connect a Text Input node or enter a prompt manually.');
            }

            if (finalPrompt.length > 4000) {
                throw new Error(window.t ? window.t('generation.prompt_too_long_rh') : 'Prompt is too long (maximum 4000 characters).');
            }

            // Return payload for server-side execution
            // Server will resolve endpoint and provider config from plugin.json
            return {
                action: PLUGIN_ID,
                payload: {
                    apiKey: userProvidedKey,
                    useAdminKey: !userProvidedKey && adminHasKey,
                    prompt: finalPrompt,
                    aspectRatio: aspectRatio,
                    resolution: resolution
                }
            };
        }
    });

})();
