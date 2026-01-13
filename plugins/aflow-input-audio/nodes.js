/**
 * AIKAFLOW Plugin - Audio Input
 * 
 * Provides audio file upload and URL input functionality.
 */

(function () {
    const nodeDefinitions = {
        'audio-input': {
            type: 'audio-input',
            category: 'input',
            name: 'Audio Input',
            description: 'Upload an audio file or provide a URL',
            icon: 'music',
            inputs: [],
            outputs: [
                { id: 'audio', type: 'audio', label: 'Audio' }
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
                    label: 'Audio File',
                    accept: 'audio/*',
                    showIf: { source: 'upload' }
                },
                {
                    id: 'url',
                    type: 'text',
                    label: 'Audio URL',
                    placeholder: 'https://example.com/audio.mp3',
                    showIf: { source: 'url' }
                }
            ],
            preview: {
                type: 'audio',
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
                    return { audio: file };
                } else if (source === 'url' && url) {
                    return { audio: url };
                }

                throw new Error('No audio provided');
            }
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-input-audio', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
