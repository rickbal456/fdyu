/**
 * AIKAFLOW Plugin - Video Overlay
 * 
 * Overlay one video on top of another (picture-in-picture).
 */

(function () {
    const nodeDefinitions = {
        'video-overlay': {
            type: 'video-overlay',
            category: 'editing',
            name: 'Video Overlay',
            description: 'Overlay one video on top of another',
            icon: 'layers',
            inputs: [
                { id: 'baseVideo', type: 'video', label: 'Base Video' },
                { id: 'overlayVideo', type: 'video', label: 'Overlay Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Output Video' }
            ],
            fields: [
                {
                    id: 'position',
                    type: 'select',
                    label: 'Position',
                    default: 'bottom-right',
                    options: [
                        { value: 'top-left', label: 'Top Left' },
                        { value: 'top-right', label: 'Top Right' },
                        { value: 'bottom-left', label: 'Bottom Left' },
                        { value: 'bottom-right', label: 'Bottom Right' },
                        { value: 'center', label: 'Center' }
                    ]
                },
                {
                    id: 'scale',
                    type: 'slider',
                    label: 'Overlay Scale (%)',
                    default: 25,
                    min: 10,
                    max: 100,
                    step: 5
                },
                {
                    id: 'opacity',
                    type: 'slider',
                    label: 'Opacity (%)',
                    default: 100,
                    min: 0,
                    max: 100,
                    step: 5
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                position: 'bottom-right',
                scale: 25,
                opacity: 100
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-overlay', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
