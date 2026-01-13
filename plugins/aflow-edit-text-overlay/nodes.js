/**
 * AIKAFLOW Plugin - Text Overlay
 * 
 * Add text overlay to video.
 */

(function () {
    const nodeDefinitions = {
        'text-overlay': {
            type: 'text-overlay',
            category: 'editing',
            name: 'Text Overlay',
            description: 'Add text overlay to video',
            icon: 'type',
            inputs: [
                { id: 'video', type: 'video', label: 'Video' },
                { id: 'text', type: 'text', label: 'Text (Optional)', optional: true }
            ],
            outputs: [
                { id: 'video', type: 'video', label: 'Output Video' }
            ],
            fields: [
                {
                    id: 'text',
                    type: 'textarea',
                    label: 'Text',
                    placeholder: 'Enter overlay text...',
                    rows: 2
                },
                {
                    id: 'position',
                    type: 'select',
                    label: 'Position',
                    default: 'bottom-center',
                    options: [
                        { value: 'top-left', label: 'Top Left' },
                        { value: 'top-center', label: 'Top Center' },
                        { value: 'top-right', label: 'Top Right' },
                        { value: 'center', label: 'Center' },
                        { value: 'bottom-left', label: 'Bottom Left' },
                        { value: 'bottom-center', label: 'Bottom Center' },
                        { value: 'bottom-right', label: 'Bottom Right' }
                    ]
                },
                {
                    id: 'fontSize',
                    type: 'slider',
                    label: 'Font Size',
                    default: 48,
                    min: 12,
                    max: 120,
                    step: 4
                },
                {
                    id: 'fontColor',
                    type: 'color',
                    label: 'Font Color',
                    default: '#ffffff'
                },
                {
                    id: 'backgroundColor',
                    type: 'color',
                    label: 'Background Color',
                    default: '#00000080'
                }
            ],
            preview: {
                type: 'video',
                source: 'output'
            },
            defaultData: {
                text: '',
                position: 'bottom-center',
                fontSize: 48,
                fontColor: '#ffffff',
                backgroundColor: '#00000080'
            },
            executionType: 'api'
        }
    };

    if (window.PluginManager) {
        window.PluginManager.registerNodes('aflow-edit-text-overlay', nodeDefinitions);
    }
    if (window.NodeDefinitions) {
        Object.assign(window.NodeDefinitions, nodeDefinitions);
    }
})();
