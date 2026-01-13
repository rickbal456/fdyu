/**
 * AIKAFLOW Plugin - Video Input
 * 
 * Provides video file upload and URL input functionality.
 */

(function () {
    const nodeDefinitions = {
        'video-input': {
            type: 'video-input',
            category: 'input',
            name: 'Video Input',
            description: 'Upload a video or provide a URL',
            icon: 'video',
            inputs: [],
            outputs: [
                { id: 'video', type: 'video', label: 'Video' }
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
                    label: 'Video File',
                    accept: 'video/*',
                    showIf: { source: 'upload' }
                },
                {
                    id: 'url',
                    type: 'text',
                    label: 'Video URL',
                    placeholder: 'https://example.com/video.mp4',
                    showIf: { source: 'url' }
                }
            ],
            preview: {
                type: 'video',
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
                    return { video: file };
                } else if (source === 'url' && url) {
                    return { video: url };
                }

                throw new Error('No video provided');
            }
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-input-video', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
