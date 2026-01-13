/**
 * AIKAFLOW - Connection Manager
 * 
 * Handles connections between node ports.
 */

class ConnectionManager {
    constructor(options = {}) {
        // DOM Elements
        this.svgLayer = document.getElementById('connections-layer');
        this.connectionsGroup = document.getElementById('connections-group');
        this.tempConnection = document.getElementById('temp-connection');
        this.container = document.getElementById('canvas-container');
        this.nodesContainer = document.getElementById('nodes-container');

        // Connections storage
        this.connections = new Map();

        // Reference to canvas manager for coordinate transformation
        this.canvasManager = options.canvasManager || null;
        this.nodeManager = options.nodeManager || null;

        // Connection style
        this.style = options.style || 'bezier'; // 'bezier', 'straight', 'step'

        // Temporary connection state
        this.tempConnectionState = null;

        // Callbacks
        this.onConnectionCreate = options.onConnectionCreate || (() => { });
        this.onConnectionDelete = options.onConnectionDelete || (() => { });

        // Bind methods
        this.updateTempConnection = this.updateTempConnection.bind(this);
    }

    /**
     * Create a connection between two ports
     * @param {string} fromNodeId - Source node ID
     * @param {string} fromPortId - Source port ID
     * @param {string} toNodeId - Target node ID
     * @param {string} toPortId - Target port ID
     * @returns {Object|null} Connection object or null if invalid
     */
    createConnection(fromNodeId, fromPortId, toNodeId, toPortId) {
        // Validate nodes exist
        if (!this.nodeManager) return null;

        const fromNode = this.nodeManager.getNode(fromNodeId);
        const toNode = this.nodeManager.getNode(toNodeId);

        if (!fromNode || !toNode) {
            console.error('Invalid nodes for connection');
            return null;
        }

        // Prevent self-connection
        if (fromNodeId === toNodeId) {
            console.warn('Cannot connect node to itself');
            return null;
        }

        // Check if connection already exists
        const existingId = this.findConnection(fromNodeId, fromPortId, toNodeId, toPortId);
        if (existingId) {
            console.warn('Connection already exists');
            return null;
        }

        // Check if input port already has a connection
        const existingInputConnection = this.findConnectionToInput(toNodeId, toPortId);
        if (existingInputConnection) {
            // Remove existing connection to this input
            this.deleteConnection(existingInputConnection);
        }

        // Get port definitions to check type compatibility
        const fromDef = this.nodeManager.getNodeDefinition(fromNode.type);
        const toDef = this.nodeManager.getNodeDefinition(toNode.type);

        if (!fromDef || !toDef) return null;

        const fromPort = fromDef.outputs.find(p => p.id === fromPortId);
        const toPort = toDef.inputs.find(p => p.id === toPortId);

        if (!fromPort || !toPort) {
            console.error('Invalid ports for connection');
            return null;
        }

        // Type compatibility check (can be extended)
        if (!this.areTypesCompatible(fromPort.type, toPort.type)) {
            console.warn(`Incompatible types: ${fromPort.type} -> ${toPort.type}`);
            // Show user-friendly modal/toast
            if (window.Toast) {
                Toast.warning(
                    'Connection Not Allowed',
                    `Cannot connect ${fromPort.type} output to ${toPort.type} input. Nodes can only be connected to matching port types (same color dots).`
                );
            }
            return null;
        }


        // Create connection
        const id = Utils.generateId('conn');
        const connection = {
            id,
            from: {
                nodeId: fromNodeId,
                portId: fromPortId,
                type: fromPort.type
            },
            to: {
                nodeId: toNodeId,
                portId: toPortId,
                type: toPort.type
            }
        };

        this.connections.set(id, connection);
        this.renderConnection(connection);
        this.updatePortStates();
        this.onConnectionCreate(connection);

        return connection;
    }

    /**
     * Check if two port types are compatible
     */
    areTypesCompatible(fromType, toType) {
        // 'any' type is compatible with everything
        if (fromType === 'any' || toType === 'any') {
            return true;
        }

        // Exact match
        if (fromType === toType) {
            return true;
        }

        // Define type compatibility rules
        const compatibilityMap = {
            'flow': ['flow'],
            'image': ['image'],
            'video': ['video'],
            'audio': ['audio'],
            'text': ['text']
        };

        const compatible = compatibilityMap[fromType] || [];
        return compatible.includes(toType);
    }

    /**
     * Delete a connection by ID
     */
    deleteConnection(connectionId) {
        const connection = this.connections.get(connectionId);
        if (!connection) return false;

        // Remove SVG element
        const element = this.connectionsGroup.querySelector(`[data-connection-id="${connectionId}"]`);
        if (element) {
            element.remove();
        }

        this.connections.delete(connectionId);
        this.updatePortStates();
        this.onConnectionDelete(connection);

        return true;
    }

    /**
     * Find connection ID by endpoints
     */
    findConnection(fromNodeId, fromPortId, toNodeId, toPortId) {
        for (const [id, conn] of this.connections) {
            if (conn.from.nodeId === fromNodeId &&
                conn.from.portId === fromPortId &&
                conn.to.nodeId === toNodeId &&
                conn.to.portId === toPortId) {
                return id;
            }
        }
        return null;
    }

    /**
     * Find connection to a specific input port
     */
    findConnectionToInput(nodeId, portId) {
        for (const [id, conn] of this.connections) {
            if (conn.to.nodeId === nodeId && conn.to.portId === portId) {
                return id;
            }
        }
        return null;
    }

    /**
     * Get all connections for a node
     */
    getConnectionsForNode(nodeId) {
        const result = {
            inputs: [],
            outputs: []
        };

        for (const [id, conn] of this.connections) {
            if (conn.from.nodeId === nodeId) {
                result.outputs.push({ ...conn, id });
            }
            if (conn.to.nodeId === nodeId) {
                result.inputs.push({ ...conn, id });
            }
        }

        return result;
    }

    /**
     * Remove all connections for a node
     */
    removeConnectionsForNode(nodeId) {
        const toRemove = [];

        for (const [id, conn] of this.connections) {
            if (conn.from.nodeId === nodeId || conn.to.nodeId === nodeId) {
                toRemove.push(id);
            }
        }

        toRemove.forEach(id => this.deleteConnection(id));
    }

    /**
     * Duplicate connections for node mapping
     */
    duplicateConnectionsForNodes(idMapping) {
        const newConnections = [];

        for (const [, conn] of this.connections) {
            const newFromId = idMapping.get(conn.from.nodeId);
            const newToId = idMapping.get(conn.to.nodeId);

            // Only duplicate if both endpoints were duplicated
            if (newFromId && newToId) {
                newConnections.push({
                    fromNodeId: newFromId,
                    fromPortId: conn.from.portId,
                    toNodeId: newToId,
                    toPortId: conn.to.portId
                });
            }
        }

        // Create the new connections
        newConnections.forEach(c => {
            this.createConnection(c.fromNodeId, c.fromPortId, c.toNodeId, c.toPortId);
        });
    }

    /**
     * Start drawing a temporary connection
     */
    startConnection(nodeId, portId, dataType, e) {
        const portElement = this.getPortElement(nodeId, portId, 'output');
        if (!portElement) return;

        const portPos = this.getPortPosition(portElement);

        this.tempConnectionState = {
            fromNodeId: nodeId,
            fromPortId: portId,
            fromType: dataType,
            startPos: portPos
        };

        // Show temp connection
        this.tempConnection.classList.remove('hidden');
        this.updateTempConnectionPath(portPos, portPos);
    }

    /**
     * Update temporary connection as mouse moves
     */
    updateTempConnection(e) {
        if (!this.tempConnectionState) return;

        const rect = this.container.getBoundingClientRect();
        const mousePos = {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };

        this.updateTempConnectionPath(this.tempConnectionState.startPos, mousePos);

        // Highlight compatible ports
        this.highlightCompatiblePorts(this.tempConnectionState.fromType);
    }

    /**
     * End connection drawing
     */
    endConnection(toNodeId, toPortId, toType) {
        if (!this.tempConnectionState) return;

        // Create connection
        this.createConnection(
            this.tempConnectionState.fromNodeId,
            this.tempConnectionState.fromPortId,
            toNodeId,
            toPortId
        );

        this.cancelConnection();
    }

    /**
     * Cancel connection drawing
     */
    cancelConnection() {
        this.tempConnectionState = null;
        if (this.tempConnection) {
            this.tempConnection.classList.add('hidden');
            this.tempConnection.setAttribute('d', '');
        }
        this.clearPortHighlights();
    }

    /**
     * Update temporary connection SVG path
     */
    updateTempConnectionPath(startPos, endPos) {
        // Adjust for canvas transform
        const state = this.canvasManager ? this.canvasManager.state : { pan: { x: 0, y: 0 }, zoom: 1 };

        const adjustedStart = {
            x: (startPos.x - state.pan.x) / state.zoom,
            y: (startPos.y - state.pan.y) / state.zoom
        };

        const adjustedEnd = {
            x: (endPos.x - state.pan.x) / state.zoom,
            y: (endPos.y - state.pan.y) / state.zoom
        };

        const path = Utils.generateConnectionPath(adjustedStart, adjustedEnd, this.style);
        this.tempConnection.setAttribute('d', path);
    }

    /**
     * Render a connection
     */
    renderConnection(connection) {
        // Get port positions
        const fromPortEl = this.getPortElement(connection.from.nodeId, connection.from.portId, 'output');
        const toPortEl = this.getPortElement(connection.to.nodeId, connection.to.portId, 'input');

        if (!fromPortEl || !toPortEl) return;

        const fromPos = this.getPortPositionInCanvas(fromPortEl);
        const toPos = this.getPortPositionInCanvas(toPortEl);

        // Generate path
        const pathData = Utils.generateConnectionPath(fromPos, toPos, this.style);

        // Check if path element exists
        let pathElement = this.connectionsGroup.querySelector(`[data-connection-id="${connection.id}"]`);

        if (!pathElement) {
            pathElement = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            pathElement.classList.add('connection-line');
            pathElement.dataset.connectionId = connection.id;
            pathElement.dataset.fromNode = connection.from.nodeId;
            pathElement.dataset.toNode = connection.to.nodeId;

            // Add click handler for selection/deletion
            pathElement.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectConnection(connection.id);
            });

            pathElement.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                this.deleteConnection(connection.id);
            });

            // Add hover handlers for delete button
            pathElement.addEventListener('mouseenter', (e) => {
                this.showConnectionDeleteButton(connection.id, fromPos, toPos);
            });

            pathElement.addEventListener('mouseleave', (e) => {
                // Small delay to allow clicking the button
                setTimeout(() => {
                    this.hideConnectionDeleteButton(connection.id);
                }, 100);
            });

            this.connectionsGroup.appendChild(pathElement);
        }

        pathElement.setAttribute('d', pathData);

        // Set color based on data type
        const color = this.getConnectionColor(connection.from.type);
        pathElement.style.stroke = color;
    }

    /**
     * Show delete button on connection hover
     */
    showConnectionDeleteButton(connectionId, fromPos, toPos) {
        // Calculate midpoint of the connection
        const midX = (fromPos.x + toPos.x) / 2;
        const midY = (fromPos.y + toPos.y) / 2;

        // Get or create delete button
        let deleteBtn = document.getElementById(`conn-delete-${connectionId}`);
        if (!deleteBtn) {
            deleteBtn = document.createElement('button');
            deleteBtn.id = `conn-delete-${connectionId}`;
            deleteBtn.className = 'connection-delete-btn';
            // Recycle bin icon
            deleteBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
            deleteBtn.title = 'Delete connection';

            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteConnection(connectionId);
            });

            deleteBtn.addEventListener('mouseenter', () => {
                deleteBtn.classList.add('visible');
            });

            deleteBtn.addEventListener('mouseleave', () => {
                deleteBtn.classList.remove('visible');
            });

            // Append to nodes container so it moves/scales with the canvas
            const nodesContainer = document.getElementById('nodes-container');
            if (nodesContainer) {
                nodesContainer.appendChild(deleteBtn);
            } else {
                this.container.appendChild(deleteBtn);
            }
        }

        // Position the button at the midpoint
        deleteBtn.style.left = `${midX}px`;
        deleteBtn.style.top = `${midY}px`;
        deleteBtn.classList.add('visible');
    }


    /**
     * Hide delete button
     */
    hideConnectionDeleteButton(connectionId) {
        const deleteBtn = document.getElementById(`conn-delete-${connectionId}`);
        if (deleteBtn && !deleteBtn.matches(':hover')) {
            deleteBtn.classList.remove('visible');
        }
    }


    /**
     * Render all connections
     */
    renderConnections() {
        // Clear existing
        this.connectionsGroup.innerHTML = '';

        // Render each connection
        for (const [, connection] of this.connections) {
            this.renderConnection(connection);
        }
    }

    /**
     * Update all connection paths (after node move)
     */
    updateAllConnections() {
        for (const [, connection] of this.connections) {
            this.renderConnection(connection);
        }
    }

    /**
     * Get port DOM element
     */
    getPortElement(nodeId, portId, portType) {
        const selector = `.workflow-node[data-node-id="${nodeId}"] .node-port[data-port-id="${portId}"][data-port-type="${portType}"]`;
        return this.nodesContainer.querySelector(selector);
    }

    /**
     * Get port position in screen coordinates
     */
    getPortPosition(portElement) {
        const dot = portElement.querySelector('.node-port-dot');
        const rect = (dot || portElement).getBoundingClientRect();
        const containerRect = this.container.getBoundingClientRect();

        return {
            x: rect.left + rect.width / 2 - containerRect.left,
            y: rect.top + rect.height / 2 - containerRect.top
        };
    }

    /**
     * Get port position in canvas coordinates
     */
    getPortPositionInCanvas(portElement) {
        const dot = portElement.querySelector('.node-port-dot');
        const element = dot || portElement;

        // Get the node element
        const nodeElement = portElement.closest('.workflow-node');
        if (!nodeElement) return { x: 0, y: 0 };

        // Get node position
        const nodeX = parseFloat(nodeElement.style.left) || 0;
        const nodeY = parseFloat(nodeElement.style.top) || 0;

        // Get port offset within node
        const nodeRect = nodeElement.getBoundingClientRect();
        const dotRect = element.getBoundingClientRect();

        const offsetX = (dotRect.left + dotRect.width / 2) - nodeRect.left;
        const offsetY = (dotRect.top + dotRect.height / 2) - nodeRect.top;

        // Adjust for zoom
        const state = this.canvasManager ? this.canvasManager.state : { zoom: 1 };

        return {
            x: nodeX + offsetX / state.zoom,
            y: nodeY + offsetY / state.zoom
        };
    }

    /**
     * Get connection color based on data type
     */
    getConnectionColor(dataType) {
        const colors = {
            'flow': '#9ca3af',  // Gray for flow connections
            'image': '#3b82f6',
            'video': '#8b5cf6',
            'audio': '#22c55e',
            'text': '#f97316',
            'any': '#6b7280'
        };
        return colors[dataType] || colors.any;
    }

    /**
     * Highlight compatible input ports
     */
    highlightCompatiblePorts(fromType) {
        const inputPorts = this.nodesContainer.querySelectorAll('.node-port[data-port-type="input"]');

        inputPorts.forEach(port => {
            const portType = port.dataset.type;
            const isCompatible = this.areTypesCompatible(fromType, portType);

            if (isCompatible) {
                port.classList.add('compatible');
            } else {
                port.classList.add('incompatible');
            }
        });
    }

    /**
     * Clear port highlights
     */
    clearPortHighlights() {
        if (!this.nodesContainer) return;
        const ports = this.nodesContainer.querySelectorAll('.node-port');
        ports.forEach(port => {
            port.classList.remove('compatible', 'incompatible');
        });
    }

    /**
     * Update port connected states
     */
    updatePortStates() {
        // Clear all connected states
        if (!this.nodesContainer) return;
        const ports = this.nodesContainer.querySelectorAll('.node-port');
        ports.forEach(port => port.classList.remove('connected'));

        // Mark connected ports
        for (const [, conn] of this.connections) {
            const fromPort = this.getPortElement(conn.from.nodeId, conn.from.portId, 'output');
            const toPort = this.getPortElement(conn.to.nodeId, conn.to.portId, 'input');

            if (fromPort) fromPort.classList.add('connected');
            if (toPort) toPort.classList.add('connected');
        }
    }

    /**
     * Select a connection
     */
    selectConnection(connectionId) {
        // Deselect all
        this.connectionsGroup.querySelectorAll('.connection-line').forEach(el => {
            el.classList.remove('selected');
        });

        // Select this one
        const element = this.connectionsGroup.querySelector(`[data-connection-id="${connectionId}"]`);
        if (element) {
            element.classList.add('selected');
        }

        // Clear node selection
        if (this.nodeManager) {
            this.nodeManager.clearSelection();
        }
    }

    /**
     * Get connection by ID
     */
    getConnection(id) {
        return this.connections.get(id) || null;
    }

    /**
     * Get all connections
     */
    getAllConnections() {
        return Array.from(this.connections.values());
    }

    /**
     * Get connection count
     */
    getCount() {
        return this.connections.size;
    }

    /**
     * Get all nodes connected to a node in a specific direction
     * @param {string} nodeId - Starting node ID
     * @param {string} direction - 'downstream' (outputs) or 'upstream' (inputs)
     * @returns {Array} Array of connected nodes
     */
    getConnectedNodes(nodeId, direction = 'downstream') {
        const visited = new Set();
        const result = [];
        const queue = [nodeId];

        while (queue.length > 0) {
            const currentId = queue.shift();
            if (visited.has(currentId)) continue;
            visited.add(currentId);

            // Get connections for this node - returns {inputs: [], outputs: []}
            const nodeConnections = this.getConnectionsForNode(currentId);

            // Get the appropriate array based on direction
            const connections = direction === 'downstream'
                ? nodeConnections.outputs  // Follow outputs when going downstream
                : nodeConnections.inputs;  // Follow inputs when going upstream

            if (!Array.isArray(connections)) continue;

            connections.forEach(conn => {
                let nextNodeId;

                if (direction === 'downstream') {
                    // For outputs, the next node is the target (to)
                    nextNodeId = conn.to.nodeId;
                } else {
                    // For inputs, the next node is the source (from)
                    nextNodeId = conn.from.nodeId;
                }

                if (nextNodeId && !visited.has(nextNodeId)) {
                    queue.push(nextNodeId);
                    const node = this.nodeManager?.getNode(nextNodeId);
                    if (node) {
                        result.push(node);
                    }
                }
            });
        }

        return result;
    }


    /**
     * Set connection style
     */
    setStyle(style) {
        this.style = style;
        this.renderConnections();
    }

    /**
     * Get all graph islands (disconnected subgraphs)
     * Each island is an independent flow that can be executed separately
     * @returns {Array} Array of islands, each containing { id, entryNodes, nodes }
     */
    getGraphIslands() {
        const allNodes = this.nodeManager?.getAllNodes() || [];
        if (allNodes.length === 0) return [];

        const visited = new Set();
        const islands = [];

        // Helper to get all connected nodes (both directions)
        const getConnectedComponent = (startNodeId) => {
            const component = [];
            const queue = [startNodeId];

            while (queue.length > 0) {
                const nodeId = queue.shift();
                if (visited.has(nodeId)) continue;
                visited.add(nodeId);

                const node = this.nodeManager?.getNode(nodeId);
                if (node) {
                    component.push(node);
                }

                // Get all connections for this node (both directions)
                const nodeConnections = this.getConnectionsForNode(nodeId);
                const allConns = [...(nodeConnections.inputs || []), ...(nodeConnections.outputs || [])];
                allConns.forEach(conn => {
                    const nextId = conn.from.nodeId === nodeId ? conn.to.nodeId : conn.from.nodeId;
                    if (!visited.has(nextId)) {
                        queue.push(nextId);
                    }
                });
            }

            return component;
        };

        // Find all islands
        allNodes.forEach(node => {
            if (!visited.has(node.id)) {
                const component = getConnectedComponent(node.id);
                if (component.length > 0) {
                    // Find entry nodes - ONLY trigger/start-flow nodes are true entry points
                    // Other nodes with no incoming connections are auxiliary inputs
                    const triggerNodes = component.filter(n => {
                        const def = this.nodeManager?.getNodeDefinition(n.type);
                        return def?.isTrigger || n.type === 'start-flow' || n.type === 'manual-trigger';
                    });

                    // If no trigger nodes, fallback to nodes with no incoming connections
                    let entryNodes = triggerNodes;
                    if (entryNodes.length === 0) {
                        entryNodes = component.filter(n => {
                            const nodeConns = this.getConnectionsForNode(n.id);
                            const hasIncoming = nodeConns.inputs && nodeConns.inputs.length > 0;
                            return !hasIncoming;
                        });
                    }

                    islands.push({
                        id: Utils.generateId('flow'),
                        entryNodes,
                        nodes: component,
                        // Sort by priority if trigger nodes have priority field
                        priority: entryNodes.reduce((min, n) => {
                            const priority = n.data?.priority ?? 100;
                            return Math.min(min, priority);
                        }, 100)
                    });
                }
            }
        });

        // Sort islands by priority
        islands.sort((a, b) => a.priority - b.priority);

        return islands;
    }


    /**
     * Serialize connections for saving
     */
    serialize() {
        return this.getAllConnections().map(conn => ({
            id: conn.id,
            from: { ...conn.from },
            to: { ...conn.to }
        }));
    }

    /**
     * Deserialize and load connections
     */
    deserialize(connectionsData) {
        this.clear();

        connectionsData.forEach(connData => {
            const connection = {
                id: connData.id,
                from: { ...connData.from },
                to: { ...connData.to }
            };
            this.connections.set(connection.id, connection);
        });

        this.renderConnections();
        this.updatePortStates();
    }

    /**
     * Clear all connections
     */
    clear() {
        this.connections.clear();
        this.connectionsGroup.innerHTML = '';
        this.cancelConnection();
    }

    /**
     * Get execution order (topological sort with Start Flow priority)
     * Returns nodes in order they should be executed
     * 
     * Key behaviors:
     * 1. Execution starts from Start Flow / trigger nodes
     * 2. Auxiliary input nodes (Text Input, Image Input connected as side inputs) 
     *    are resolved when the consumer node executes, not as standalone roots
     * 3. Proper topological order maintained
     */
    getExecutionOrder() {
        if (!this.nodeManager) return [];

        const nodes = this.nodeManager.getAllNodes();
        const nodeMap = new Map(nodes.map(n => [n.id, n]));

        // Identify Start Flow / trigger nodes - these are the ONLY entry points
        const startNodes = nodes.filter(node => {
            const def = this.nodeManager.getNodeDefinition(node.type);
            return def?.isTrigger || node.type === 'manual-trigger' || node.type === 'start-flow';
        });

        // If no start nodes, find nodes that are truly standalone (not connected to anything)
        // or fallback to old behavior for backward compatibility
        if (startNodes.length === 0) {
            console.warn('No Start Flow nodes found, using fallback execution order');
            return this.getExecutionOrderFallback();
        }

        // Build adjacency and reverse adjacency maps
        const outgoing = new Map(); // nodeId -> [downstream nodeIds]
        const incoming = new Map(); // nodeId -> [upstream nodeIds]

        nodes.forEach(node => {
            outgoing.set(node.id, []);
            incoming.set(node.id, []);
        });

        for (const [, conn] of this.connections) {
            const fromId = conn.from.nodeId;
            const toId = conn.to.nodeId;

            outgoing.get(fromId)?.push(toId);
            incoming.get(toId)?.push(fromId);
        }

        // Find all nodes reachable from Start Flow nodes (via forward traversal)
        const reachableFromStart = new Set();
        const startNodeQueue = [...startNodes.map(n => n.id)];

        while (startNodeQueue.length > 0) {
            const nodeId = startNodeQueue.shift();
            if (reachableFromStart.has(nodeId)) continue;
            reachableFromStart.add(nodeId);

            const downstream = outgoing.get(nodeId) || [];
            downstream.forEach(id => {
                if (!reachableFromStart.has(id)) {
                    startNodeQueue.push(id);
                }
            });
        }

        // Classify nodes
        const executionNodes = new Set(); // Nodes that should be executed
        const auxiliaryNodes = new Set(); // Input nodes that connect to execution nodes but aren't in the main flow

        nodes.forEach(node => {
            if (reachableFromStart.has(node.id)) {
                executionNodes.add(node.id);
            } else {
                // Check if this node connects to any node in the main flow
                const downstream = outgoing.get(node.id) || [];
                const connectsToMainFlow = downstream.some(id => reachableFromStart.has(id));

                if (connectsToMainFlow) {
                    auxiliaryNodes.add(node.id);
                }
            }
        });

        // Build execution order using modified Kahn's algorithm
        // Only consider nodes in the execution set
        const inDegree = new Map();
        const adjacency = new Map();

        // Initialize for execution nodes
        executionNodes.forEach(nodeId => {
            inDegree.set(nodeId, 0);
            adjacency.set(nodeId, []);
        });

        // Count incoming edges (only from execution nodes or auxiliary nodes)
        for (const [, conn] of this.connections) {
            const fromId = conn.from.nodeId;
            const toId = conn.to.nodeId;

            if (executionNodes.has(toId)) {
                // Only count edges from execution nodes, not from auxiliary nodes
                // (auxiliary nodes will be resolved when their consumer executes)
                if (executionNodes.has(fromId)) {
                    const current = inDegree.get(toId) || 0;
                    inDegree.set(toId, current + 1);

                    const adj = adjacency.get(fromId) || [];
                    adj.push(toId);
                    adjacency.set(fromId, adj);
                }
            }
        }

        // Kahn's algorithm starting from start nodes
        const queue = [];
        const result = [];

        // Start with trigger/start nodes that have 0 in-degree from execution nodes
        executionNodes.forEach(nodeId => {
            if (inDegree.get(nodeId) === 0) {
                const node = nodeMap.get(nodeId);
                const def = this.nodeManager.getNodeDefinition(node.type);
                // Prioritize actual start/trigger nodes
                if (def?.isTrigger || node.type === 'start-flow' || node.type === 'manual-trigger') {
                    queue.unshift(nodeId); // Add to front
                } else {
                    queue.push(nodeId);
                }
            }
        });

        while (queue.length > 0) {
            const nodeId = queue.shift();
            const node = nodeMap.get(nodeId);

            if (node) {
                // Before adding this node, resolve any auxiliary inputs
                // (add auxiliary nodes that this node depends on)
                const upstreamAux = (incoming.get(nodeId) || []).filter(id => auxiliaryNodes.has(id));
                upstreamAux.forEach(auxId => {
                    const auxNode = nodeMap.get(auxId);
                    if (auxNode && !result.includes(auxNode)) {
                        result.push(auxNode);
                        auxiliaryNodes.delete(auxId); // Mark as processed
                    }
                });

                result.push(node);
            }

            const neighbors = adjacency.get(nodeId) || [];
            neighbors.forEach(neighbor => {
                const degree = inDegree.get(neighbor) - 1;
                inDegree.set(neighbor, degree);

                if (degree === 0) {
                    queue.push(neighbor);
                }
            });
        }

        return result;
    }

    /**
     * Fallback execution order (old algorithm) for workflows without Start Flow
     * Used when no trigger nodes are present
     */
    getExecutionOrderFallback() {
        if (!this.nodeManager) return [];

        const nodes = this.nodeManager.getAllNodes();
        const nodeMap = new Map(nodes.map(n => [n.id, n]));

        // Build adjacency list
        const inDegree = new Map();
        const adjacency = new Map();

        nodes.forEach(node => {
            inDegree.set(node.id, 0);
            adjacency.set(node.id, []);
        });

        // Count incoming connections
        for (const [, conn] of this.connections) {
            const current = inDegree.get(conn.to.nodeId) || 0;
            inDegree.set(conn.to.nodeId, current + 1);

            const adj = adjacency.get(conn.from.nodeId) || [];
            adj.push(conn.to.nodeId);
            adjacency.set(conn.from.nodeId, adj);
        }

        // Kahn's algorithm
        const queue = [];
        const result = [];

        // Start with nodes that have no incoming connections
        inDegree.forEach((degree, nodeId) => {
            if (degree === 0) {
                queue.push(nodeId);
            }
        });

        while (queue.length > 0) {
            const nodeId = queue.shift();
            result.push(nodeMap.get(nodeId));

            const neighbors = adjacency.get(nodeId) || [];
            neighbors.forEach(neighbor => {
                const degree = inDegree.get(neighbor) - 1;
                inDegree.set(neighbor, degree);

                if (degree === 0) {
                    queue.push(neighbor);
                }
            });
        }

        return result;
    }

    /**
     * Get input data for a node
     * Returns map of input port ID to connected output value
     */
    getNodeInputs(nodeId) {
        const inputs = {};

        for (const [, conn] of this.connections) {
            if (conn.to.nodeId === nodeId) {
                const fromNode = this.nodeManager.getNode(conn.from.nodeId);
                if (fromNode && fromNode.outputs) {
                    inputs[conn.to.portId] = fromNode.outputs[conn.from.portId];
                }
            }
        }

        return inputs;
    }

    /**
     * Check if workflow is valid (all required inputs connected)
     */
    validateWorkflow() {
        const errors = [];
        const nodes = this.nodeManager.getAllNodes();

        nodes.forEach(node => {
            const definition = this.nodeManager.getNodeDefinition(node.type);
            if (!definition) return;

            // Check if required inputs are connected
            definition.inputs.forEach(input => {
                const hasConnection = Array.from(this.connections.values()).some(
                    conn => conn.to.nodeId === node.id && conn.to.portId === input.id
                );

                // Check if input is optional (support both 'optional' and 'isOptional' properties)
                const isOptional = input.optional === true || input.isOptional === true;

                if (!hasConnection && !isOptional) {
                    errors.push({
                        nodeId: node.id,
                        nodeName: definition.name,
                        portId: input.id,
                        portLabel: input.label,
                        message: `Missing input: ${input.label}`
                    });
                }
            });
        });

        return {
            valid: errors.length === 0,
            errors
        };
    }
}

// Make available globally
window.ConnectionManager = ConnectionManager;