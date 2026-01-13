/**
 * AIKAFLOW - Node Definitions & Management
 * 
 * Core node definitions. Plugin nodes are loaded dynamically.
 * This file contains only essential control flow nodes.
 */

// Site config cache (loaded from API)
window.AIKAFLOW_CONFIG = window.AIKAFLOW_CONFIG || { maxRepeatCount: 100 };

const NodeDefinitions = {
    // ============================================
    // Control Nodes (Flow Control / Triggers)
    // These are the only built-in nodes
    // All other nodes are loaded from plugins
    // ============================================
    'manual-trigger': {
        type: 'manual-trigger',
        category: 'control',
        name: 'Start Flow',
        description: 'Starting point of a workflow flow',
        icon: 'play-circle',
        inputs: [],
        outputs: [
            { id: 'flow', type: 'flow', label: 'Start' }
        ],
        fields: [
            {
                id: 'flowName',
                type: 'text',
                label: 'Flow Name',
                placeholder: 'My Flow',
                description: 'Optional name for this flow'
            },
            {
                id: 'priority',
                type: 'number',
                label: 'Priority',
                default: 0,
                min: 0,
                max: 100,
                description: 'Lower numbers run first when using Run All'
            },
            {
                id: 'enableRepeat',
                type: 'checkbox',
                label: 'Enable Repeat',
                hint: 'Run this entire flow multiple times to generate variations'
            },
            {
                id: 'repeatCount',
                type: 'number',
                label: 'Repeat Count',
                min: 1,
                get max() { return window.AIKAFLOW_CONFIG.maxRepeatCount || 100; },
                default: 1,
                showIf: { enableRepeat: true },
                get hint() { return `Number of times to run the flow (1-${window.AIKAFLOW_CONFIG.maxRepeatCount || 100})`; }
            }
        ],
        preview: null,
        defaultData: {
            flowName: '',
            priority: 0,
            enableRepeat: false,
            repeatCount: 1
        },
        // Special flag for trigger nodes
        isTrigger: true,
        // Allow inline run button
        hasRunButton: true
    },

    'flow-merge': {
        type: 'flow-merge',
        category: 'control',
        name: 'Flow Merge',
        description: 'Wait for multiple flows before continuing',
        icon: 'git-merge',
        inputs: [
            { id: 'flow1', type: 'flow', label: 'Flow 1' },
            { id: 'flow2', type: 'flow', label: 'Flow 2' }
        ],
        outputs: [
            { id: 'flow', type: 'flow', label: 'Flow' }
        ],
        fields: [
            {
                id: 'mode',
                type: 'select',
                label: 'Mode',
                default: 'all',
                options: [
                    { value: 'all', label: 'Wait for All' },
                    { value: 'any', label: 'Continue on Any' }
                ]
            }
        ],
        preview: null,
        defaultData: {
            mode: 'all'
        }
    }
};


/**
 * Node Manager - Manages node instances in the workflow
 */
class NodeManager {
    constructor() {
        this.nodes = new Map();
        this.selectedNodes = new Set();
        this.clipboard = [];
    }

    /**
     * Create a new node instance
     * @param {string} type - Node type
     * @param {Object} position - { x, y } position
     * @param {Object} data - Optional initial data
     * @returns {Object} Node instance
     */
    createNode(type, position = { x: 100, y: 100 }, data = {}) {
        const definition = NodeDefinitions[type];
        if (!definition) {
            console.error(`Unknown node type: ${type}`);
            return null;
        }

        const id = Utils.generateId('node');
        const node = {
            id,
            type,
            category: definition.category,
            name: definition.name,
            position: { ...position },
            data: { ...Utils.deepClone(definition.defaultData), ...data },
            status: 'idle', // idle, pending, processing, completed, error
            outputs: {}, // Stores output values after execution
            error: null
        };

        this.nodes.set(id, node);
        return node;
    }

    /**
     * Get node by ID
     * @param {string} id - Node ID
     * @returns {Object|null}
     */
    getNode(id) {
        return this.nodes.get(id) || null;
    }

    /**
     * Get all nodes
     * @returns {Array}
     */
    getAllNodes() {
        return Array.from(this.nodes.values());
    }

    /**
     * Update node data
     * @param {string} id - Node ID
     * @param {Object} updates - Data updates
     */
    updateNode(id, updates) {
        const node = this.nodes.get(id);
        if (node) {
            Object.assign(node, updates);
        }
    }

    /**
     * Update node data field
     * @param {string} id - Node ID
     * @param {string} field - Field name
     * @param {*} value - New value
     */
    updateNodeData(id, field, value) {
        const node = this.nodes.get(id);
        if (node) {
            node.data[field] = value;
        }
    }

    /**
     * Delete node by ID
     * @param {string} id - Node ID
     * @returns {boolean}
     */
    deleteNode(id) {
        this.selectedNodes.delete(id);
        return this.nodes.delete(id);
    }

    /**
     * Delete multiple nodes
     * @param {Array} ids - Node IDs
     */
    deleteNodes(ids) {
        ids.forEach(id => this.deleteNode(id));
    }

    /**
     * Select a node
     * @param {string} id - Node ID
     * @param {boolean} addToSelection - Add to existing selection
     */
    selectNode(id, addToSelection = false) {
        if (!addToSelection) {
            this.selectedNodes.clear();
        }
        this.selectedNodes.add(id);
    }

    /**
     * Deselect a node
     * @param {string} id - Node ID
     */
    deselectNode(id) {
        this.selectedNodes.delete(id);
    }

    /**
     * Clear selection
     */
    clearSelection() {
        this.selectedNodes.clear();
    }

    /**
     * Get selected nodes
     * @returns {Array}
     */
    getSelectedNodes() {
        return Array.from(this.selectedNodes)
            .map(id => this.nodes.get(id))
            .filter(Boolean);
    }

    /**
     * Check if node is selected
     * @param {string} id - Node ID
     * @returns {boolean}
     */
    isSelected(id) {
        return this.selectedNodes.has(id);
    }

    /**
     * Move node to position
     * @param {string} id - Node ID
     * @param {Object} position - { x, y }
     * @param {boolean} snap - Snap to grid
     */
    moveNode(id, position, snap = true) {
        const node = this.nodes.get(id);
        if (node) {
            node.position = {
                x: snap ? Utils.snapToGrid(position.x) : position.x,
                y: snap ? Utils.snapToGrid(position.y) : position.y
            };
        }
    }

    /**
     * Move selected nodes by delta
     * @param {Object} delta - { x, y } delta
     * @param {boolean} snap - Snap to grid
     */
    moveSelectedNodes(delta, snap = true) {
        this.selectedNodes.forEach(id => {
            const node = this.nodes.get(id);
            if (node) {
                const newX = node.position.x + delta.x;
                const newY = node.position.y + delta.y;
                node.position = {
                    x: snap ? Utils.snapToGrid(newX) : newX,
                    y: snap ? Utils.snapToGrid(newY) : newY
                };
            }
        });
    }

    /**
     * Duplicate selected nodes
     * @param {Object} offset - Position offset for duplicates
     * @returns {Array} New node IDs
     */
    duplicateSelectedNodes(offset = { x: 40, y: 40 }) {
        const newNodeIds = [];
        const idMapping = new Map();

        this.selectedNodes.forEach(id => {
            const original = this.nodes.get(id);
            if (original) {
                const newNode = this.createNode(
                    original.type,
                    {
                        x: original.position.x + offset.x,
                        y: original.position.y + offset.y
                    },
                    Utils.deepClone(original.data)
                );
                idMapping.set(id, newNode.id);
                newNodeIds.push(newNode.id);
            }
        });

        // Clear selection and select new nodes
        this.clearSelection();
        newNodeIds.forEach(id => this.selectNode(id, true));

        return { newNodeIds, idMapping };
    }

    /**
     * Copy selected nodes to clipboard
     */
    copySelectedNodes() {
        this.clipboard = this.getSelectedNodes().map(node => ({
            type: node.type,
            data: Utils.deepClone(node.data),
            relativePosition: { ...node.position }
        }));
    }

    /**
     * Paste nodes from clipboard
     * @param {Object} position - Base position for paste
     * @returns {Array} New node IDs
     */
    pasteNodes(position = { x: 100, y: 100 }) {
        if (this.clipboard.length === 0) return [];

        // Calculate offset from first node
        const firstNode = this.clipboard[0];
        const offset = {
            x: position.x - firstNode.relativePosition.x,
            y: position.y - firstNode.relativePosition.y
        };

        const newNodeIds = [];

        this.clipboard.forEach(nodeData => {
            const newNode = this.createNode(
                nodeData.type,
                {
                    x: nodeData.relativePosition.x + offset.x,
                    y: nodeData.relativePosition.y + offset.y
                },
                nodeData.data
            );
            newNodeIds.push(newNode.id);
        });

        // Select pasted nodes
        this.clearSelection();
        newNodeIds.forEach(id => this.selectNode(id, true));

        return newNodeIds;
    }

    /**
     * Get node definition
     * @param {string} type - Node type
     * @returns {Object|null}
     */
    getNodeDefinition(type) {
        return NodeDefinitions[type] || null;
    }

    /**
     * Get all node definitions
     * @returns {Object}
     */
    getAllDefinitions() {
        return NodeDefinitions;
    }

    /**
     * Get nodes by category
     * @param {string} category - Category name
     * @returns {Array}
     */
    getDefinitionsByCategory(category) {
        return Object.values(NodeDefinitions).filter(def => def.category === category);
    }

    /**
     * Search node definitions
     * @param {string} query - Search query
     * @returns {Array}
     */
    searchDefinitions(query) {
        const lowerQuery = query.toLowerCase();
        return Object.values(NodeDefinitions).filter(def =>
            def.name.toLowerCase().includes(lowerQuery) ||
            def.description.toLowerCase().includes(lowerQuery) ||
            def.type.toLowerCase().includes(lowerQuery)
        );
    }

    /**
     * Set node status
     * @param {string} id - Node ID
     * @param {string} status - New status
     * @param {string} error - Error message (if status is 'error')
     */
    setNodeStatus(id, status, error = null) {
        const node = this.nodes.get(id);
        if (node) {
            node.status = status;
            node.error = error;
        }
    }

    /**
     * Set node output value
     * @param {string} id - Node ID
     * @param {string} outputId - Output port ID
     * @param {*} value - Output value
     */
    setNodeOutput(id, outputId, value) {
        const node = this.nodes.get(id);
        if (node) {
            node.outputs[outputId] = value;
        }
    }

    /**
     * Reset all node statuses
     */
    resetAllStatuses() {
        this.nodes.forEach(node => {
            node.status = 'idle';
            node.error = null;
            node.outputs = {};
        });
    }

    /**
     * Serialize all nodes for saving
     * @returns {Array}
     */
    serialize() {
        return this.getAllNodes().map(node => ({
            id: node.id,
            type: node.type,
            position: { ...node.position },
            data: Utils.deepClone(node.data)
        }));
    }

    /**
     * Deserialize and load nodes
     * @param {Array} nodesData - Serialized nodes array
     */
    deserialize(nodesData) {
        this.nodes.clear();
        this.selectedNodes.clear();

        nodesData.forEach(nodeData => {
            const definition = NodeDefinitions[nodeData.type];
            if (definition) {
                const node = {
                    id: nodeData.id,
                    type: nodeData.type,
                    category: definition.category,
                    name: definition.name,
                    position: { ...nodeData.position },
                    data: { ...Utils.deepClone(definition.defaultData), ...nodeData.data },
                    status: 'idle',
                    outputs: {},
                    error: null
                };
                this.nodes.set(node.id, node);
            }
        });
    }

    /**
     * Get count of nodes
     * @returns {number}
     */
    getCount() {
        return this.nodes.size;
    }

    /**
     * Clear all nodes
     */
    clear() {
        this.nodes.clear();
        this.selectedNodes.clear();
        this.clipboard = [];
    }
}

// Create global instance
window.NodeDefinitions = NodeDefinitions;
window.NodeManager = NodeManager;