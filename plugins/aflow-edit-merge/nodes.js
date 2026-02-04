/**
 * AIKAFLOW Plugin - Video Merge
 * 
 * Merge multiple videos into one using API.
 */

(function () {
    const nodeDefinitions = {
        'video-merge': {
            type: 'video-merge',
            category: 'editing',
            name: 'Video Merge',
            description: 'Merge multiple videos into one',
            icon: 'layers',
            inputs: [
                { id: 'video1', type: 'video', label: 'Video 1' },
                { id: 'video2', type: 'video', label: 'Video 2' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Merged Video' }
            ],
            fields: [
                {
                    id: 'outputRatio',
                    type: 'select',
                    label: 'Output Ratio',
                    default: 'portrait',
                    options: [
                        { value: 'portrait', label: '9:16 Portrait (TikTok, Reels, Shorts)' },
                        { value: 'landscape', label: '16:9 Landscape (YouTube)' },
                        { value: 'square', label: '1:1 Square (Instagram Feed)' }
                    ]
                },
                {
                    id: 'transition',
                    type: 'select',
                    label: 'Transition',
                    default: 'none',
                    options: [
                        { value: 'none', label: 'None (Direct Cut)' },
                        { value: 'fade', label: 'Fade' },
                        { value: 'dissolve', label: 'Dissolve' },
                        { value: 'wipe', label: 'Wipe' }
                    ]
                },
                {
                    id: 'transitionDuration',
                    type: 'slider',
                    label: 'Transition Duration (s)',
                    default: 1,
                    min: 0.5,
                    max: 3,
                    step: 0.5,
                    showIf: { transition: ['fade', 'dissolve', 'wipe'] }
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                outputRatio: 'portrait',
                transition: 'none',
                transitionDuration: 1
            },
            // API execution - handled by worker.php using apiMapping
            executionType: 'api'
        }
    };

    // Register with plugin system
    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-merge', nodeDefinitions);
    }

    // Also add to global NodeDefinitions for immediate availability
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
