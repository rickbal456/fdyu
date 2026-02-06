/**
 * AIKAFLOW Plugin - Image to Video KAPI
 * 
 * This file defines the custom nodes provided by this plugin.
 * Uses the KIE.AI Sora 2 Image-to-Video API for video generation.
 * 
 * SECURITY NOTE: All API configuration (endpoints, provider details)
 * are stored server-side in plugin.json and resolved by the worker.
 * API Key is configured in Administration → Integrations.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2v-kapi';

    /**
     * Check if API key is configured in admin settings
     * @returns {boolean}
     */
    function hasApiKeyConfigured() {
        // Check if admin has configured kapi key
        return PluginManager.hasApiKey('kapi');
    }

    // Build fields - API key is now configured in admin Integrations
    const fields = [
        {
            id: 'aspect_ratio',
            type: 'select',
            label: 'Aspect Ratio',
            default: 'portrait',
            options: [
                { value: 'portrait', label: 'Portrait (Vertical)' },
                { value: 'landscape', label: 'Landscape (Horizontal)' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: 'Motion Prompt',
            placeholder: 'Describe the motion and scene (up to 10000 characters)...',
            rows: 6,
            // This field will be disabled when 'text' input port is connected
            disabledWhenConnected: 'text'
        },
        {
            id: 'n_frames',
            type: 'select',
            label: 'Duration',
            default: '10',
            options: [
                { value: '10', label: '10 seconds' },
                { value: '15', label: '15 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video KAPI
    PluginManager.registerNode({
        type: 'aflow-i2v-kapi',
        category: 'generation',
        name: 'Image to Video KAPI',
        description: 'Generate Video from Image using Sora 2 via KIE.AI (10-15s)',
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
            aspect_ratio: 'portrait',
            prompt: '',
            n_frames: '10'
        },
        // Custom execution handler (used by worker)
        // Returns only node data - server resolves API config from plugin.json
        execute: async function (node, inputs, context) {
            const { aspect_ratio, prompt, n_frames } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Check if admin has configured the key in Administration → Integrations
            if (!hasApiKeyConfigured()) {
                throw new Error('KIE.AI API Key is required. Configure it in Administration → Integrations → Video Generation API.');
            }

            if (!finalPrompt || finalPrompt.trim().length === 0) {
                throw new Error('Motion prompt is required. Either connect a Text Input node or enter a prompt manually.');
            }

            if (finalPrompt.length > 10000) {
                throw new Error('Motion prompt is too long (maximum 10000 characters).');
            }

            // Return payload for server-side execution
            // Server will resolve endpoint and API key from plugin.json and admin settings
            return {
                action: PLUGIN_ID,
                payload: {
                    image: imageUrl,
                    aspect_ratio: aspect_ratio,
                    prompt: finalPrompt,
                    n_frames: String(n_frames)
                }
            };
        }
    });

})();
