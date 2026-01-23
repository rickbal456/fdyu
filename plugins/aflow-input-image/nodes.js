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
     * Enhance image using server-side API proxy
     * @param {string} imageUrl - URL of the image to enhance
     * @param {string} prompt - Enhancement prompt
     * @param {string} [aspectRatio='auto'] - Aspect ratio (auto, 1:1, 3:2, 2:3)
     * @returns {Promise<string>} - Enhanced image URL
     */
    async function enhanceImage(imageUrl, prompt, aspectRatio = 'auto') {
        if (!hasRhubApiConfigured()) {
            throw new Error('RunningHub API key not configured. Please configure it in Administration → Integrations.');
        }

        // Call server-side endpoint
        const response = await fetch('./api/ai/enhance-image.php', {
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

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to enhance image');
        }

        return data.enhanced;
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
