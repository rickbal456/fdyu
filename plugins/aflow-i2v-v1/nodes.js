/**
 * AIKAFLOW Plugin - Image to Video V1
 * 
 * This file defines the custom nodes provided by this plugin.
 * It will be loaded automatically when the plugin is enabled.
 * 
 * SECURITY NOTE: All API configuration (endpoints, webapp IDs, provider details)
 * are stored server-side in plugin.json and resolved by the worker.
 * This client-side file only handles UI and node registration.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2v-v1';

    /**
     * Check if API key is configured (either plugin-specific or main provider)
     * This checks STATUS only, not the actual key value (for security)
     * @returns {boolean}
     */
    function hasApiKeyConfigured() {
        // First check plugin-specific key status
        if (PluginManager.hasApiKey(`plugin_${PLUGIN_ID}`)) return true;

        // Then check main provider key status (obfuscated name)
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
            id: 'model',
            type: 'select',
            label: window.t ? window.t('generation.video_mode') : 'Video Mode',
            default: 'landscape',
            options: [
                { value: 'portrait', label: window.t ? window.t('generation.portrait') : 'Portrait (Vertical)' },
                { value: 'landscape', label: window.t ? window.t('generation.landscape') : 'Landscape (Horizontal)' },
                { value: 'portrait-hd', label: window.t ? window.t('generation.portrait_hd') : 'HD Portrait' },
                { value: 'landscape-hd', label: window.t ? window.t('generation.landscape_hd') : 'HD Landscape' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: window.t ? window.t('generation.motion_prompt') : 'Motion Prompt',
            placeholder: window.t ? window.t('generation.motion_prompt_placeholder') : 'Describe the motion and scene...',
            rows: 5,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'duration_seconds',
            type: 'select',
            label: window.t ? window.t('generation.duration_seconds') : 'Duration (seconds)',
            default: '10',
            options: [
                { value: '10', label: window.t ? window.t('generation.10_seconds') : '10 seconds' },
                { value: '15', label: window.t ? window.t('generation.15_seconds') : '15 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video V1
    PluginManager.registerNode({
        type: 'aflow-i2v-v1',
        category: 'generation',
        name: window.t ? window.t('generation.image_to_video_v1') : 'Image to Video V1',
        description: window.t ? window.t('generation.generate_video_from_image') : 'Generate Video from Image 10-15s',
        icon: 'clapperboard',
        inputs: [
            { id: 'flow', type: 'flow', label: window.t ? window.t('generation.wait_for') : 'Wait For' },
            { id: 'image', type: 'image', label: window.t ? window.t('generation.input_image') : 'Input Image' },
            { id: 'text', type: 'text', label: window.t ? window.t('generation.motion_prompt_optional') : 'Motion Prompt (Optional)', optional: true }
        ],
        outputs: [
            { id: 'video', type: 'video', label: window.t ? window.t('generation.output_video') : 'Output Video' }
        ],
        fields: fields,
        preview: {
            type: 'video',
            source: 'output'
        },
        defaultData: {
            apiKey: '',
            model: 'landscape',
            prompt: '',
            duration_seconds: '10'
        },
        // Custom execution handler (used by worker)
        // Returns only node data - server resolves API config from plugin.json
        execute: async function (node, inputs, context) {
            const { apiKey, model, prompt, duration_seconds } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Determine API key source
            const userProvidedKey = apiKey || getUserApiKey();
            const adminHasKey = hasApiKeyConfigured();

            if (!userProvidedKey && !adminHasKey) {
                throw new Error(window.t ? window.t('generation.api_key_required') : 'API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!finalPrompt) {
                throw new Error(window.t ? window.t('generation.prompt_required') : 'Motion prompt is required. Either connect a Text Input node or enter a prompt manually.');
            }

            // Return payload for server-side execution
            // Server will resolve endpoint and provider config from plugin.json
            return {
                action: PLUGIN_ID,
                payload: {
                    apiKey: userProvidedKey,
                    useAdminKey: !userProvidedKey && adminHasKey,
                    image: imageUrl,
                    model: model,
                    prompt: finalPrompt,
                    duration_seconds: String(duration_seconds)
                }
            };
        }
    });

})();
