/**
 * AIKAFLOW Plugin - Audio Volume
 * 
 * Modify audio volume in video.
 */

(function () {
    const nodeDefinitions = {
        'audio-volume': {
            type: 'audio-volume',
            category: 'editing',
            name: 'Audio Volume',
            description: 'Modify audio volume in video',
            icon: 'volume-2',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Output Video' }
            ],
            fields: [
                {
                    id: 'volume',
                    type: 'slider',
                    label: 'Volume (%)',
                    default: 100,
                    min: 0,
                    max: 200,
                    step: 5
                },
                {
                    id: 'fadeIn',
                    type: 'slider',
                    label: 'Fade In (seconds)',
                    default: 0,
                    min: 0,
                    max: 5,
                    step: 0.5
                },
                {
                    id: 'fadeOut',
                    type: 'slider',
                    label: 'Fade Out (seconds)',
                    default: 0,
                    min: 0,
                    max: 5,
                    step: 0.5
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                volume: 100,
                fadeIn: 0,
                fadeOut: 0
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-audio-volume', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
