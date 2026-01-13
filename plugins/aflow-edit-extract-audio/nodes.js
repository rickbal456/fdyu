/**
 * AIKAFLOW Plugin - Extract Audio
 * 
 * Extract audio track from video file.
 */

(function () {
    const nodeDefinitions = {
        'extract-audio': {
            type: 'extract-audio',
            category: 'editing',
            name: 'Extract Audio',
            description: 'Extract audio from video',
            icon: 'music',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'audio', type: 'audio', label: 'Audio' }
            ],
            fields: [
                {
                    id: 'format',
                    type: 'select',
                    label: 'Output Format',
                    default: 'mp3',
                    options: [
                        { value: 'mp3', label: 'MP3' },
                        { value: 'wav', label: 'WAV' },
                        { value: 'aac', label: 'AAC' },
                        { value: 'ogg', label: 'OGG' }
                    ]
                },
                {
                    id: 'bitrate',
                    type: 'select',
                    label: 'Bitrate',
                    default: '192k',
                    options: [
                        { value: '128k', label: '128 kbps' },
                        { value: '192k', label: '192 kbps' },
                        { value: '256k', label: '256 kbps' },
                        { value: '320k', label: '320 kbps' }
                    ]
                }
            ],
            preview: {
                type: 'audio',
                source: 'output'
            },
            defaultData: {
                format: 'mp3',
                bitrate: '192k'
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-extract-audio', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
