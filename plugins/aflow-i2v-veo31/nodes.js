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
            label: window.t ? window.t('generation.aspect_ratio') : 'Aspect Ratio',
            default: '9:16',
            options: [
                { value: '9:16', label: window.t ? window.t('generation.portrait') : 'Portrait (9:16)' },
                { value: '16:9', label: window.t ? window.t('generation.landscape') : 'Landscape (16:9)' }
            ]
        },
        {
            id: 'prompt',
            type: 'textarea',
            label: window.t ? window.t('generation.motion_prompt') : 'Motion Prompt',
            placeholder: window.t ? window.t('generation.motion_prompt_placeholder') : 'Describe the animation, motion and scene in detail (5-800 characters)...',
            rows: 6,
            disabledWhenConnected: 'text'
        },
        {
            id: 'duration',
            type: 'select',
            label: window.t ? window.t('generation.duration_seconds') : 'Duration (seconds)',
            default: '8',
            options: [
                { value: '8', label: window.t ? window.t('generation.8_seconds') : '8 seconds' }
            ]
        }
    ];

    // Register custom node: Image to Video Veo3.1
    PluginManager.registerNode({
        type: 'aflow-i2v-veo31',
        category: 'generation',
        name: window.t ? window.t('generation.image_to_video_veo31') : 'Image to Video Veo3.1',
        description: window.t ? window.t('generation.generate_video_veo31') : 'Generate Video from Image using Veo3.1 via RunningHub (8s)',
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
            aspectRatio: '9:16',
            prompt: '',
            duration: '8'
        },
        execute: async function (node, inputs, context) {
            const { aspectRatio, prompt, duration } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Check if admin has configured the key
            if (!hasApiKeyConfigured()) {
                throw new Error(window.t ? window.t('generation.api_key_required_rhub') : 'RunningHub API Key is required. Configure it in Administration → Integrations.');
            }

            if (!finalPrompt || finalPrompt.trim().length < 5) {
                throw new Error(window.t ? window.t('generation.prompt_too_short') : 'Motion prompt is too short (minimum 5 characters).');
            }

            if (finalPrompt.length > 800) {
                throw new Error(window.t ? window.t('generation.prompt_too_long') : 'Motion prompt is too long (maximum 800 characters).');
            }

            // Return payload for server-side execution
            return {
                action: PLUGIN_ID,
                payload: {
                    image: imageUrl,
                    aspectRatio: aspectRatio,
                    prompt: finalPrompt,
                    duration: String(duration)
                }
            };
        }
    });

})();
