/**
 * AIKAFLOW Plugin - Image to Video Veo3.1
 * 
 * This file defines the custom nodes provided by this plugin.
 * Uses the RunningHub Veo3.1 Image-to-Video API for video generation.
 * 
 * API Key is configured in Administration → Integrations.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-i2v-veo31';

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
            id: 'aspectRatio',
            type: 'select',
            label: 'Aspect Ratio',
            default: '9:16',
            options: [
                { value: '9:16', label: 'Portrait (Vertical)' },
                { value: '16:9', label: 'Landscape (Horizontal)' }
            ]
        },
        {
            id: 'resolution',
            type: 'select',
            label: 'Resolution',
            default: '720p',
            options: [
                { value: '720p', label: '720p' },
                { value: '1080p', label: '1080p' },
                { value: '4k', label: '4K' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: 'Motion Prompt',
            placeholder: 'Describe the motion and scene (5-800 characters)...',
            rows: 6,
            disabledWhenConnected: 'text'
        },
        {
            id: 'duration',
            type: 'select',
            label: 'Duration',
            default: '8',
            options: [
                { value: '8', label: '8 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video Veo3.1
    PluginManager.registerNode({
        type: 'aflow-i2v-veo31',
        category: 'generation',
        name: 'Image to Video Veo3.1',
        description: 'Generate Video from Image using Veo3.1 via RunningHub (8s, up to 3 images)',
        icon: 'clapperboard',
        inputs: [
            { id: 'flow', type: 'flow', label: 'Wait For', optional: true },
            { id: 'image', type: 'image', label: 'Input Image 1' },
            { id: 'image2', type: 'image', label: 'Input Image 2 (Optional)', optional: true },
            { id: 'image3', type: 'image', label: 'Input Image 3 (Optional)', optional: true },
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
            aspectRatio: '9:16',
            resolution: '720p',
            prompt: '',
            duration: '8'
        },
        execute: async function (node, inputs, context) {
            const { aspectRatio, resolution, prompt, duration } = node.data;
            const imageUrl = inputs.image || '';
            const imageUrl2 = inputs.image2 || '';
            const imageUrl3 = inputs.image3 || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Check if admin has configured the key
            if (!hasApiKeyConfigured()) {
                throw new Error('RunningHub API Key is required. Configure it in Administration → Integrations.');
            }

            if (!finalPrompt || finalPrompt.trim().length < 5) {
                throw new Error('Motion prompt is too short (minimum 5 characters).');
            }

            if (finalPrompt.length > 800) {
                throw new Error('Motion prompt is too long (maximum 800 characters).');
            }

            // Build imageUrls array (1-3 images, filter out empty)
            const imageUrls = [imageUrl];
            if (imageUrl2) imageUrls.push(imageUrl2);
            if (imageUrl3) imageUrls.push(imageUrl3);

            // Return payload for server-side execution
            return {
                action: PLUGIN_ID,
                payload: {
                    imageUrls: imageUrls,
                    aspectRatio: aspectRatio,
                    resolution: resolution,
                    prompt: finalPrompt,
                    duration: String(duration)
                }
            };
        }
    });

})();
