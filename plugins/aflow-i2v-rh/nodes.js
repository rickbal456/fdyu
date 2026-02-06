/**
 * AIKAFLOW Plugin - Image to Video RH
 * 
 * This file defines the custom nodes provided by this plugin.
 * Uses the RunningHub V2 rhart-video-s API for video generation.
 * 
 * SECURITY NOTE: All API configuration (endpoints, provider details)
 * are stored server-side in plugin.json and resolved by the worker.
 * This client-side file only handles UI and node registration.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2v-rh';

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
            label: 'API Key',
            placeholder: 'Your API key',
            description: 'Or configure in Settings → Integrations',
            // Hide this field if API key is already configured in admin settings
            showIf: () => !hasApiKeyConfigured()
        },
        {
            id: 'aspectRatio',
            type: 'select',
            label: 'Aspect Ratio',
            default: '16:9',
            options: [
                { value: '16:9', label: 'Landscape (16:9)' },
                { value: '9:16', label: 'Portrait (9:16)' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: 'Motion Prompt',
            placeholder: 'Describe the motion and scene (5-4000 characters)...',
            rows: 6,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'duration',
            type: 'select',
            label: 'Duration',
            default: '10',
            options: [
                { value: '10', label: '10 seconds' },
                { value: '15', label: '15 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video RH
    PluginManager.registerNode({
        type: 'aflow-i2v-rh',
        category: 'generation',
        name: 'Image to Video RH',
        description: 'Generate Video from Image using RH Art S API (10-15s)',
        icon: 'clapperboard',
        inputs: [
            { id: 'flow', type: 'flow', label: 'Wait For' },
            { id: 'image', type: 'image', label: 'Input Image' },
            { id: 'text', type: 'text', label: 'Motion Prompt (Optional)', optional: true }
        ],
        outputs: [
            { id: 'video', type: 'video', label: 'Output Video' }
        ],
        fields: fields,
        preview: {
            type: 'video',
            source: 'output'
        },
        defaultData: {
            apiKey: '',
            aspectRatio: '16:9',
            prompt: '',
            duration: '10'
        },
        // Custom execution handler (used by worker)
        // Returns only node data - server resolves API config from plugin.json
        execute: async function (node, inputs, context) {
            const { apiKey, aspectRatio, prompt, duration } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Determine API key source
            const userProvidedKey = apiKey || getUserApiKey();
            const adminHasKey = hasApiKeyConfigured();

            if (!userProvidedKey && !adminHasKey) {
                throw new Error('API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!finalPrompt || finalPrompt.length < 5) {
                throw new Error('Motion prompt is required (minimum 5 characters). Either connect a Text Input node or enter a prompt manually.');
            }

            if (finalPrompt.length > 4000) {
                throw new Error('Motion prompt is too long (maximum 4000 characters).');
            }

            // Return payload for server-side execution
            // Server will resolve endpoint and provider config from plugin.json
            return {
                action: PLUGIN_ID,
                payload: {
                    apiKey: userProvidedKey,
                    useAdminKey: !userProvidedKey && adminHasKey,
                    image: imageUrl,
                    aspectRatio: aspectRatio,
                    prompt: finalPrompt,
                    duration: String(duration)
                }
            };
        }
    });

})();
