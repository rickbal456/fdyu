/**
 * AIKAFLOW Plugin - Video Crop
 * 
 * Crop video to specific dimensions or aspect ratio.
 */

(function () {
    const nodeDefinitions = {
        'video-crop': {
            type: 'video-crop',
            category: 'editing',
            name: 'Video Crop',
            description: 'Crop video to specific dimensions',
            icon: 'crop',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Cropped Video' }
            ],
            fields: [
                {
                    id: 'aspectRatio',
                    type: 'select',
                    label: 'Aspect Ratio',
                    default: '16:9',
                    options: [
                        { value: '16:9', label: '16:9 (Landscape)' },
                        { value: '9:16', label: '9:16 (Portrait)' },
                        { value: '1:1', label: '1:1 (Square)' },
                        { value: '4:3', label: '4:3 (Standard)' },
                        { value: '4:5', label: '4:5 (Instagram)' },
                        { value: 'custom', label: 'Custom' }
                    ]
                },
                {
                    id: 'cropX',
                    type: 'number',
                    label: 'X Offset',
                    default: 0,
                    min: 0,
                    showIf: { aspectRatio: 'custom' }
                },
                {
                    id: 'cropY',
                    type: 'number',
                    label: 'Y Offset',
                    default: 0,
                    min: 0,
                    showIf: { aspectRatio: 'custom' }
                },
                {
                    id: 'cropWidth',
                    type: 'number',
                    label: 'Width',
                    default: 1920,
                    min: 1,
                    showIf: { aspectRatio: 'custom' }
                },
                {
                    id: 'cropHeight',
                    type: 'number',
                    label: 'Height',
                    default: 1080,
                    min: 1,
                    showIf: { aspectRatio: 'custom' }
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                aspectRatio: '16:9',
                cropX: 0,
                cropY: 0,
                cropWidth: 1920,
                cropHeight: 1080
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-crop', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
