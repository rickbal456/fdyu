/**
 * AIKAFLOW Plugin - Image Input
 * 
 * Provides image file upload and URL input functionality.
 */

(function () {
    const nodeDefinitions = {
        'image-input': {
            type: 'image-input',
            category: 'input',
            name: 'Image Input',
            description: 'Upload an image or provide a URL',
            icon: 'image',
            inputs: [
                { id: 'flow', type: 'flow', label: 'Wait For' }
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
                    showIf: { source: 'upload' }
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
                source: 'input'
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
})();
