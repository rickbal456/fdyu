/**
 * AIKAFLOW Plugin - Video Speed
 * 
 * Change video playback speed (slow motion / fast forward).
 */

(function () {
    const nodeDefinitions = {
        'video-speed': {
            type: 'video-speed',
            category: 'editing',
            name: 'Video Speed',
            description: 'Change video playback speed',
            icon: 'gauge',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Output Video' }
            ],
            fields: [
                {
                    id: 'speed',
                    type: 'slider',
                    label: 'Speed Multiplier',
                    default: 1,
                    min: 0.25,
                    max: 4,
                    step: 0.25
                },
                {
                    id: 'preserveAudio',
                    type: 'checkbox',
                    label: 'Preserve Audio Pitch',
                    default: true
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                speed: 1,
                preserveAudio: true
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-speed', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
