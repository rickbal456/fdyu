/**
 * AIKAFLOW Plugin - Nano Banana Image to Image
 * 
 * Transform images using AI with the Nano Banana model via RunningHub API.
 * Supports prompt-based image editing with resolution options.
 * 
 * API Key is configured in Administration → Integrations.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2i-banana';

    /**
     * Check if API key is configured in admin settings
     * @returns {boolean}
     */
    function hasApiKeyConfigured() {
        return PluginManager.hasApiKey('rhub');
    }

    // Build fields - API key is configured in admin Integrations
    const fields = [
        {
            id: 'prompt',
            type: 'textarea',
            label: 'Edit Prompt',
            placeholder: 'Describe how you want to transform the image (5-4000 characters)...',
            rows: 6,
            disabledWhenConnected: 'text'
        },
        {
            id: 'resolution',
            type: 'select',
            label: 'Resolution',
            default: '1k',
            options: [
                { value: '1k', label: '1K (Standard)' },
                { value: '2k', label: '2K (High)' },
                { value: '4k', label: '4K (Ultra)' }
            ]
        }
    ];

    // Register custom node: Nano Banana Image to Image
    PluginManager.registerNode({
        type: 'aflow-i2i-banana',
        category: 'generation',
        name: 'Nano Banana Image to Image',
        description: 'Transform images with AI using Nano Banana model',
        icon: 'image',
        inputs: [
            { id: 'flow', type: 'flow', label: 'Wait For', optional: true },
            { id: 'image', type: 'image', label: 'Input Image' },
            { id: 'text', type: 'text', label: 'Prompt (Optional)', optional: true }
        ],
        outputs: [
            { id: 'image', type: 'image', label: 'Output Image' }
        ],
        fields: fields,
        preview: {
            type: 'image',
            source: 'output'
        },
        defaultData: {
            prompt: '',
            resolution: '1k'
        },
        execute: async function (node, inputs, context) {
            const { prompt, resolution } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Check if admin has configured the key
            if (!hasApiKeyConfigured()) {
                throw new Error('RunningHub API Key is required. Configure it in Administration → Integrations.');
            }

            if (!finalPrompt || finalPrompt.trim().length < 5) {
                throw new Error('Prompt is too short (minimum 5 characters).');
            }

            if (finalPrompt.length > 4000) {
                throw new Error('Prompt is too long (maximum 4000 characters).');
            }

            if (!imageUrl) {
                throw new Error('Input image is required.');
            }

            // Return payload for server-side execution
            return {
                action: PLUGIN_ID,
                payload: {
                    image: imageUrl,
                    prompt: finalPrompt,
                    resolution: resolution
                }
            };
        }
    });

})();
