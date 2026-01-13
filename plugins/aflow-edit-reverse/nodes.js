/**
 * AIKAFLOW Plugin - Reverse Video
 * 
 * Reverse video playback direction.
 */

(function () {
    const nodeDefinitions = {
        'reverse-video': {
            type: 'reverse-video',
            category: 'editing',
            name: 'Reverse Video',
            description: 'Reverse video playback',
            icon: 'rewind',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Reversed Video' }
            ],
            fields: [
                {
                    id: 'reverseAudio',
                    type: 'checkbox',
                    label: 'Reverse Audio Too',
                    default: true
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                reverseAudio: true
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-reverse', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
