/**
 * AIKAFLOW Plugin - Image to Video KAPI
 * 
 * This file defines the custom nodes provided by this plugin.
 * Uses the KIE.AI Sora 2 Image-to-Video API for video generation.
 * 
 * SECURITY NOTE: All API configuration (endpoints, provider details)
 * are stored server-side in plugin.json and resolved by the worker.
 * This client-side file only handles UI and node registration.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2v-kapi';

    /**
     * Check if API key is configured (either plugin-specific or main provider)
     * This checks STATUS only, not the actual key value (for security)
     * @returns {boolean}
     */
    function hasApiKeyConfigured() {
        // First check plugin-specific key status
        if (PluginManager.hasApiKey(`plugin_${PLUGIN_ID}`)) return true;

        // Then check main provider key status (kapi)
        return PluginManager.hasApiKey('kapi');
    }

    /**
     * Get user's own API key from node field (if they entered one)
     * This is for users who bring their own key
     */
    function getUserApiKey() {
        // Check user's own keys (not admin-shared)
        const pluginKey = PluginManager.getAiKey(`plugin_${PLUGIN_ID}`);
        if (pluginKey) return pluginKey;

        return PluginManager.getAiKey('kapi');
    }

    // Build fields - API key field is always included but hidden when configured
    const fields = [
        {
            id: 'apiKey',
            type: 'text',
            label: window.t ? window.t('generation.api_key') : 'API Key',
            placeholder: window.t ? window.t('generation.your_api_key_kie') : 'Your KIE.AI API key',
            description: window.t ? window.t('generation.configure_in_settings') : 'Or configure in Settings → Integrations',
            // Hide this field if API key is already configured in admin settings
            showIf: () => !hasApiKeyConfigured()
        },
        {
            id: 'aspect_ratio',
            type: 'select',
            label: window.t ? window.t('generation.aspect_ratio_kapi') : 'Aspect Ratio',
            default: 'landscape',
            options: [
                { value: 'landscape', label: window.t ? window.t('generation.landscape_kapi') : 'Landscape (Horizontal)' },
                { value: 'portrait', label: window.t ? window.t('generation.portrait_kapi') : 'Portrait (Vertical)' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: window.t ? window.t('generation.motion_prompt') : 'Motion Prompt',
            placeholder: window.t ? window.t('generation.motion_prompt_placeholder_kapi') : 'Describe the animation, motion and scene in detail (up to 10000 characters)...',
            rows: 6,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'n_frames',
            type: 'select',
            label: window.t ? window.t('generation.duration_seconds') : 'Duration (seconds)',
            default: '10',
            options: [
                { value: '10', label: window.t ? window.t('generation.10_seconds') : '10 seconds' },
                { value: '15', label: window.t ? window.t('generation.15_seconds') : '15 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video KAPI
    PluginManager.registerNode({
        type: 'aflow-i2v-kapi',
        category: 'generation',
        name: window.t ? window.t('generation.image_to_video_kapi') : 'Image to Video KAPI',
        description: window.t ? window.t('generation.generate_video_from_image_kapi') : 'Generate Video from Image using Sora 2 via KIE.AI (10-15s)',
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
            aspect_ratio: 'landscape',
            prompt: '',
            n_frames: '10'
        },
        // Custom execution handler (used by worker)
        // Returns only node data - server resolves API config from plugin.json
        execute: async function (node, inputs, context) {
            const { apiKey, aspect_ratio, prompt, n_frames } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Check if admin has configured the key in Settings → Integrations
            const adminHasKey = hasApiKeyConfigured();

            // API key priority: Admin key > User's node key
            // If admin configured key, we DON'T send apiKey (server will use admin key)
            // If admin didn't configure, check if user provided a key
            const userKey = apiKey && apiKey.trim() ? apiKey.trim() : '';

            if (!adminHasKey && !userKey) {
                throw new Error(window.t ? window.t('generation.api_key_required_kie') : 'KIE.AI API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!finalPrompt || finalPrompt.trim().length === 0) {
                throw new Error(window.t ? window.t('generation.prompt_required_kapi') : 'Motion prompt is required. Either connect a Text Input node or enter a prompt manually.');
            }

            if (finalPrompt.length > 10000) {
                throw new Error(window.t ? window.t('generation.prompt_too_long_kapi') : 'Motion prompt is too long (maximum 10000 characters).');
            }

            // Return payload for server-side execution
            // Server will resolve endpoint and provider config from plugin.json
            // Only include apiKey if user explicitly provided one - otherwise server will use admin key
            const payload = {
                image: imageUrl,
                aspect_ratio: aspect_ratio,
                prompt: finalPrompt,
                n_frames: String(n_frames)
            };

            // Only add apiKey if admin hasn't configured one
            // Admin key takes precedence over user's key
            if (!adminHasKey && userKey) {
                payload.apiKey = userKey;
            }

            return {
                action: PLUGIN_ID,
                payload: payload
            };
        }
    });

})();
