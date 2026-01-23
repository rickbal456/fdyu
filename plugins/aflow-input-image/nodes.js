/**
 * AIKAFLOW Plugin - Image Input with AI Enhancement
 * 
 * Provides image file upload and URL input functionality with optional AI enhancement.
 * The actual API call is proxied through the server to keep API keys secure.
 */

(function () {
    'use strict';

    /**
     * Check if RunningHub API is configured
     * @returns {boolean}
     */
    function hasRhubApiConfigured() {
        // Check via PluginManager integration status
        if (window.PluginManager?.hasApiKey) {
            return PluginManager.hasApiKey('rhub');
        }
        return window.pluginManager?.integrationStatus?.rhub === true;
    }

    /**
     * Enhance image using server-side API proxy with polling
     * @param {string} imageUrl - URL of the image to enhance
     * @param {string} prompt - Enhancement prompt
     * @param {string} [aspectRatio='auto'] - Aspect ratio (auto, 1:1, 3:2, 2:3)
     * @param {function} [onStatusUpdate] - Optional callback for status updates
     * @returns {Promise<string>} - Enhanced image URL
     */
    async function enhanceImage(imageUrl, prompt, aspectRatio = 'auto', onStatusUpdate = null) {
        if (!hasRhubApiConfigured()) {
            throw new Error('RunningHub API key not configured. Please configure it in Administration → Integrations.');
        }

        // Submit enhancement task
        const submitResponse = await fetch('./api/ai/enhance-image.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                imageUrl: imageUrl,
                prompt: prompt,
                aspectRatio: aspectRatio
            })
        });

        const submitData = await submitResponse.json();

        if (!submitData.success) {
            throw new Error(submitData.error || 'Failed to submit enhancement');
        }

        const { taskId, nodeId } = submitData;

        if (onStatusUpdate) onStatusUpdate('Processing image...');

        // Poll for result (max 3 minutes = 180 seconds, check every 3 seconds)
        const maxAttempts = 60;
        let attempt = 0;

        while (attempt < maxAttempts) {
            await new Promise(resolve => setTimeout(resolve, 3000)); // Wait 3 seconds
            attempt++;

            if (onStatusUpdate) {
                onStatusUpdate(`Enhancing... (${Math.min(attempt * 5, 100)}%)`);
            }

            try {
                const statusResponse = await fetch(`./api/ai/enhance-image-status.php?nodeId=${encodeURIComponent(nodeId)}`);
                const statusData = await statusResponse.json();

                if (!statusData.success) {
                    continue; // Keep polling
                }

                if (statusData.status === 'completed' && statusData.result) {
                    return statusData.result;
                } else if (statusData.status === 'failed') {
                    throw new Error(statusData.error || 'Enhancement failed');
                }
                // status === 'processing': continue polling
            } catch (e) {
                // Network error - continue polling
                console.warn('Status check error:', e);
            }
        }

        throw new Error('Enhancement timed out. Please try again.');
    }

    const nodeDefinitions = {
        'image-input': {
            type: 'image-input',
            category: 'input',
            name: 'Image Input',
            description: 'Upload an image or provide a URL, with optional AI enhancement',
            icon: 'image',
            inputs: [
                { id: 'flow', type: 'flow', label: 'Wait For', optional: true }
            ],
            outputs: [
                { id: 'image', type: 'image', label: 'Image' }
            ],
            fields: [
                {
                    id: 'source',
                    type: 'select',
                    label: 'Source',
                    default: 'upload',
                    options: [
                        { value: 'upload', label: 'Upload File' },
                        { value: 'url', label: 'URL' }
                    ]
                },
                {
                    id: 'file',
                    type: 'file',
                    label: 'Image File',
                    accept: 'image/*',
                    showIf: { source: 'upload' },
                    // Add enhance button on the file field for properties panel
                    labelAction: {
                        id: 'enhance-image',
                        icon: 'wand-2',
                        title: 'Enhance with AI'
                    }
                },
                {
                    id: 'url',
                    type: 'text',
                    label: 'Image URL',
                    placeholder: 'https://example.com/image.jpg',
                    showIf: { source: 'url' }
                }
            ],
            preview: {
                type: 'image',
                source: 'input',
                // Enable enhance button on the preview
                enhanceable: true
            },
            defaultData: {
                source: 'upload',
                file: null,
                url: ''
            },
            // Local execution - just passes through the file/URL
            execute: async function (nodeData, inputs, context) {
                const { source, file, url } = nodeData;

                if (source === 'upload' && file) {
                    return { image: file };
                } else if (source === 'url' && url) {
                    return { image: url };
                }

                throw new Error('No image provided');
            }
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-input-image', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }

    // Expose enhancement function globally for other plugins and UI components
    window.AIKAFLOWImageEnhance = {
        enhance: enhanceImage,
        isConfigured: hasRhubApiConfigured
    };
})();
