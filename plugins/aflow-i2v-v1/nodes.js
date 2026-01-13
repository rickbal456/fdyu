/**
 * RunningHub AI App Plugin - Image to Video V1
 * 
 * This file defines the custom nodes provided by this plugin.
 * It will be loaded automatically when the plugin is enabled.
 */

(function () {
    'use strict';

    // Hardcoded Webapp ID for this specific RunningHub workflow
    const WEBAPP_ID = '1973555366057390081';
    const PLUGIN_ID = 'aflow-i2v-v1';

    /**
     * Get the RunningHub API key from settings
     * Checks both the main RunningHub key and plugin-specific key
     */
    function getRunningHubApiKey() {
        // First check plugin-specific key
        const pluginKey = PluginManager.getAiKey(`plugin_${PLUGIN_ID}`);
        if (pluginKey) return pluginKey;

        // Then check main RunningHub key
        return PluginManager.getAiKey('runninghub');
    }

    /**
     * Check if RunningHub API key is configured in settings
     */
    function hasRunningHubApiKey() {
        return getRunningHubApiKey() !== '';
    }

    // Build fields - API key is shown only if not configured in settings
    const fields = [];

    // Only add API key field if not already configured
    if (!hasRunningHubApiKey()) {
        fields.push({
            id: 'apiKey',
            type: 'text',
            label: window.t ? window.t('generation.api_key') : 'API Key',
            placeholder: window.t ? window.t('generation.your_api_key') : 'Your RunningHub API key',
            description: window.t ? window.t('generation.configure_in_settings') : 'Or configure in Settings → Integrations'
        });
    }

    // Always add these fields
    fields.push(
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
    );

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
        execute: async function (node, inputs, context) {
            const { apiKey, model, prompt, duration_seconds } = node.data;
            const imageUrl = inputs.image || '';

            // Use connected text input or fall back to node's prompt field
            const finalPrompt = (inputs.text && inputs.text.trim()) ? inputs.text.trim() : prompt;

            // Get API key - use node field or fall back to settings
            const finalApiKey = apiKey || getRunningHubApiKey();

            if (!finalApiKey) {
                throw new Error(window.t ? window.t('generation.api_key_required') : 'RunningHub API Key is required. Set it in Settings → Integrations or in the node field.');
            }

            if (!finalPrompt) {
                throw new Error(window.t ? window.t('generation.prompt_required') : 'Motion prompt is required. Either connect a Text Input node or enter a prompt manually.');
            }

            // Build the API payload with hardcoded webapp ID
            const payload = {
                webappId: WEBAPP_ID,
                apiKey: finalApiKey,
                nodeInfoList: [
                    {
                        nodeId: '2',
                        fieldName: 'image',
                        fieldValue: imageUrl
                    },
                    {
                        nodeId: '1',
                        fieldName: 'model',
                        fieldValue: model
                    },
                    {
                        nodeId: '1',
                        fieldName: 'prompt',
                        fieldValue: finalPrompt
                    },
                    {
                        nodeId: '1',
                        fieldName: 'duration_seconds',
                        fieldValue: String(duration_seconds)
                    }
                ]
            };

            // Return the payload for server-side execution
            return {
                action: 'aflow-i2v-v1',
                payload: payload,
                endpoint: 'https://www.runninghub.ai/task/openapi/ai-app/run'
            };
        }
    });

})();
