/**
 * AIKAFLOW Plugin - Video Trim
 * 
 * Trim video to specific start and end times.
 */

(function () {
    const nodeDefinitions = {
        'video-trim': {
            type: 'video-trim',
            category: 'editing',
            name: 'Video Trim',
            description: 'Trim video to specific duration',
            icon: 'scissors',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Trimmed Video' }
            ],
            fields: [
                {
                    id: 'startTime',
                    type: 'text',
                    label: 'Start Time',
                    placeholder: '00:00:00',
                    description: 'Format: HH:MM:SS or seconds'
                },
                {
                    id: 'endTime',
                    type: 'text',
                    label: 'End Time',
                    placeholder: '00:00:10',
                    description: 'Format: HH:MM:SS or seconds'
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                startTime: '00:00:00',
                endTime: '00:00:10'
            },
            executionType: 'api'
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-trim', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
