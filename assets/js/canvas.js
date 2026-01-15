/**
 * AIKAFLOW - Canvas Manager
 * 
 * Handles canvas pan, zoom, grid, selection, and node rendering.
 */

class CanvasManager {
    constructor(options = {}) {
        // DOM Elements
        this.container = document.getElementById('canvas-container');
        this.nodesContainer = document.getElementById('nodes-container');
        this.gridElement = document.getElementById('canvas-grid');
        this.selectionBox = document.getElementById('selection-box');
        this.connectionsLayer = document.getElementById('connections-layer');

        // Canvas state
        this.state = {
            pan: { x: 0, y: 0 },
            zoom: 1,
            minZoom: 0.1,
            maxZoom: 3,
            zoomStep: 0.1,
            gridSize: 20,
            snapToGrid: true,
            showGrid: true,
            showMinimap: false
        };

        // Interaction state
        this.interaction = {
            isPanning: false,
            isDragging: false,
            isSelecting: false,
            isConnecting: false,
            startPoint: null,
            currentPoint: null,
            draggedNode: null,
            dragOffset: { x: 0, y: 0 },
            selectedNodesStartPositions: new Map()
        };

        // References
        this.nodeManager = options.nodeManager || new NodeManager();
        this.connectionManager = options.connectionManager || null;

        // Callbacks
        this.onNodeSelect = options.onNodeSelect || (() => { });
        this.onNodeDeselect = options.onNodeDeselect || (() => { });
        this.onNodeMove = options.onNodeMove || (() => { });
        this.onNodeDoubleClick = options.onNodeDoubleClick || (() => { });
        this.onCanvasDoubleClick = options.onCanvasDoubleClick || (() => { });
        this.onZoomChange = options.onZoomChange || (() => { });
        this.onSelectionChange = options.onSelectionChange || (() => { });

        // Bind methods
        this.handleMouseDown = this.handleMouseDown.bind(this);
        this.handleMouseMove = this.handleMouseMove.bind(this);
        this.handleMouseUp = this.handleMouseUp.bind(this);
        this.handleWheel = this.handleWheel.bind(this);
        this.handleKeyDown = this.handleKeyDown.bind(this);
        this.handleDoubleClick = this.handleDoubleClick.bind(this);
        this.handleContextMenu = this.handleContextMenu.bind(this);

        // Initialize
        this.init();
    }

    /**
     * Initialize canvas
     */
    init() {
        this.setupEventListeners();
        this.updateTransform();
        this.updateGrid();
        this.centerCanvas();
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Mouse events on container
        this.container.addEventListener('mousedown', this.handleMouseDown);
        this.container.addEventListener('mousemove', this.handleMouseMove);
        this.container.addEventListener('mouseup', this.handleMouseUp);
        this.container.addEventListener('mouseleave', this.handleMouseUp);
        this.container.addEventListener('wheel', this.handleWheel, { passive: false });
        this.container.addEventListener('dblclick', this.handleDoubleClick);
        this.container.addEventListener('contextmenu', this.handleContextMenu);

        // Keyboard events
        document.addEventListener('keydown', this.handleKeyDown);

        // Touch events for mobile
        this.container.addEventListener('touchstart', this.handleTouchStart.bind(this), { passive: false });
        this.container.addEventListener('touchmove', this.handleTouchMove.bind(this), { passive: false });
        this.container.addEventListener('touchend', this.handleTouchEnd.bind(this));

        // Prevent default drag behavior
        this.container.addEventListener('dragover', e => e.preventDefault());
        this.container.addEventListener('drop', this.handleDrop.bind(this));
    }

    /**
     * Handle mouse down
     */
    handleMouseDown(e) {
        const target = e.target;
        const point = this.getCanvasPoint(e);

        this.interaction.startPoint = point;
        this.interaction.currentPoint = point;

        // Check if clicking on a port (for connections)
        const port = target.closest('.node-port');
        if (port) {
            const nodeElement = port.closest('.workflow-node');
            if (nodeElement) {
                const nodeId = nodeElement.dataset.nodeId;
                const portId = port.dataset.portId;
                const portType = port.dataset.portType; // 'input' or 'output'
                const dataType = port.dataset.type;

                if (portType === 'output') {
                    this.startConnection(nodeId, portId, dataType, e);
                }
                return;
            }
        }

        // Check if clicking on a node
        const nodeElement = target.closest('.workflow-node');
        if (nodeElement) {
            const nodeId = nodeElement.dataset.nodeId;
            const isNodeHeader = target.closest('.node-header');

            // Only drag from header or if node is already selected
            if (isNodeHeader || this.nodeManager.isSelected(nodeId)) {
                this.startNodeDrag(nodeId, e);
            }

            // Handle selection
            if (e.shiftKey || e.ctrlKey || e.metaKey) {
                // Toggle selection
                if (this.nodeManager.isSelected(nodeId)) {
                    this.nodeManager.deselectNode(nodeId);
                } else {
                    this.nodeManager.selectNode(nodeId, true);
                }
            } else {
                // Single selection (unless already selected for multi-drag)
                if (!this.nodeManager.isSelected(nodeId)) {
                    this.nodeManager.selectNode(nodeId, false);
                }
            }

            this.updateNodeSelectionVisuals();
            this.onSelectionChange(this.nodeManager.getSelectedNodes());
            return;
        }

        // Clicking on empty canvas
        if (e.button === 0) {
            // Left click - start selection box or panning
            if (e.shiftKey) {
                this.startSelectionBox(point);
            } else {
                // Clear selection and start panning
                this.nodeManager.clearSelection();
                this.updateNodeSelectionVisuals();
                this.onSelectionChange([]);
                this.startPanning(e);
            }
        } else if (e.button === 1) {
            // Middle click - pan
            e.preventDefault();
            this.startPanning(e);
        }
    }

    /**
     * Handle mouse move
     */
    handleMouseMove(e) {
        const point = this.getCanvasPoint(e);
        this.interaction.currentPoint = point;

        // Update cursor position display
        this.updatePositionDisplay(point);

        // Handle panning
        if (this.interaction.isPanning) {
            const dx = e.clientX - this.interaction.panStart.x;
            const dy = e.clientY - this.interaction.panStart.y;

            this.state.pan.x = this.interaction.panStartOffset.x + dx;
            this.state.pan.y = this.interaction.panStartOffset.y + dy;

            this.updateTransform();
            return;
        }

        // Handle node dragging
        if (this.interaction.isDragging) {
            this.updateNodeDrag(e);
            return;
        }

        // Handle selection box
        if (this.interaction.isSelecting) {
            this.updateSelectionBox(point);
            return;
        }

        // Handle connection drawing
        if (this.interaction.isConnecting && this.connectionManager) {
            this.connectionManager.updateTempConnection(e);
            return;
        }
    }

    /**
     * Handle mouse up
     */
    handleMouseUp(e) {
        // End panning
        if (this.interaction.isPanning) {
            this.endPanning();
        }

        // End node dragging
        if (this.interaction.isDragging) {
            this.endNodeDrag();
        }

        // End selection box
        if (this.interaction.isSelecting) {
            this.endSelectionBox();
        }

        // End connection drawing
        if (this.interaction.isConnecting && this.connectionManager) {
            const target = e.target;
            const port = target.closest('.node-port');

            if (port && port.dataset.portType === 'input') {
                const nodeElement = port.closest('.workflow-node');
                if (nodeElement) {
                    this.connectionManager.endConnection(
                        nodeElement.dataset.nodeId,
                        port.dataset.portId,
                        port.dataset.type
                    );
                }
            } else {
                this.connectionManager.cancelConnection();
            }
            this.interaction.isConnecting = false;
        }

        this.container.classList.remove('grabbing');
    }

    /**
     * Handle mouse wheel (zoom)
     */
    handleWheel(e) {
        e.preventDefault();

        const delta = e.deltaY > 0 ? -1 : 1;
        const zoomFactor = 1 + (this.state.zoomStep * delta);

        // Get mouse position relative to container
        const rect = this.container.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        this.zoomAtPoint(zoomFactor, mouseX, mouseY);
    }

    /**
     * Handle double click
     */
    handleDoubleClick(e) {
        const target = e.target;
        const nodeElement = target.closest('.workflow-node');

        if (nodeElement) {
            const nodeId = nodeElement.dataset.nodeId;
            const node = this.nodeManager.getNode(nodeId);
            this.onNodeDoubleClick(node);
        } else {
            // Double click on canvas
            const point = this.getCanvasPoint(e);
            this.onCanvasDoubleClick(point);
        }
    }

    /**
     * Handle context menu
     */
    handleContextMenu(e) {
        e.preventDefault();

        const target = e.target;
        const nodeElement = target.closest('.workflow-node');

        if (nodeElement) {
            const nodeId = nodeElement.dataset.nodeId;

            // Select node if not selected
            if (!this.nodeManager.isSelected(nodeId)) {
                this.nodeManager.selectNode(nodeId, false);
                this.updateNodeSelectionVisuals();
                this.onSelectionChange(this.nodeManager.getSelectedNodes());
            }
        }

        // Show context menu (handled by editor.js)
        const event = new CustomEvent('canvas-contextmenu', {
            detail: {
                x: e.clientX,
                y: e.clientY,
                canvasPoint: this.getCanvasPoint(e),
                hasSelection: this.nodeManager.selectedNodes.size > 0
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * Handle key down
     */
    handleKeyDown(e) {
        // Check for read-only mode
        if (document.body.classList.contains('read-only-mode')) {
            return;
        }

        // Ignore if typing in input
        if (e.target.matches('input, textarea, select')) {
            return;
        }

        const key = e.key.toLowerCase();
        const ctrl = e.ctrlKey || e.metaKey;

        // Delete selected nodes
        if (key === 'delete' || key === 'backspace') {
            this.deleteSelectedNodes();
            e.preventDefault();
        }

        // Select all
        if (ctrl && key === 'a') {
            this.selectAllNodes();
            e.preventDefault();
        }

        // Copy
        if (ctrl && key === 'c') {
            this.nodeManager.copySelectedNodes();
            e.preventDefault();
        }

        // Paste
        if (ctrl && key === 'v') {
            const center = this.getViewportCenter();
            this.nodeManager.pasteNodes(center);
            this.renderNodes();
            e.preventDefault();
        }

        // Duplicate
        if (ctrl && key === 'd') {
            this.duplicateSelectedNodes();
            e.preventDefault();
        }

        // Undo/Redo would be handled by workflow manager

        // Escape - clear selection
        if (key === 'escape') {
            this.nodeManager.clearSelection();
            this.updateNodeSelectionVisuals();
            this.onSelectionChange([]);

            if (this.interaction.isConnecting && this.connectionManager) {
                this.connectionManager.cancelConnection();
                this.interaction.isConnecting = false;
            }
        }

        // Arrow keys - move selected nodes
        if (['arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(key)) {
            const step = e.shiftKey ? 50 : 10;
            const delta = {
                x: key === 'arrowleft' ? -step : key === 'arrowright' ? step : 0,
                y: key === 'arrowup' ? -step : key === 'arrowdown' ? step : 0
            };

            if (this.nodeManager.selectedNodes.size > 0) {
                this.nodeManager.moveSelectedNodes(delta, this.state.snapToGrid);
                this.renderNodes();
                e.preventDefault();
            }
        }

        // Zoom shortcuts
        if (ctrl && (key === '=' || key === '+')) {
            this.zoomIn();
            e.preventDefault();
        }
        if (ctrl && key === '-') {
            this.zoomOut();
            e.preventDefault();
        }
        if (ctrl && key === '0') {
            this.resetZoom();
            e.preventDefault();
        }
    }

    /**
     * Handle touch start
     */
    handleTouchStart(e) {
        if (e.touches.length === 1) {
            // Single touch - treat as mouse
            const touch = e.touches[0];
            this.handleMouseDown({
                clientX: touch.clientX,
                clientY: touch.clientY,
                target: touch.target,
                button: 0,
                preventDefault: () => e.preventDefault()
            });
        } else if (e.touches.length === 2) {
            // Pinch zoom
            e.preventDefault();
            this.interaction.pinchDistance = this.getPinchDistance(e.touches);
            this.interaction.pinchCenter = this.getPinchCenter(e.touches);
            this.interaction.initialZoom = this.state.zoom;
        }
    }

    /**
     * Handle touch move
     */
    handleTouchMove(e) {
        if (e.touches.length === 1) {
            const touch = e.touches[0];
            this.handleMouseMove({
                clientX: touch.clientX,
                clientY: touch.clientY,
                target: touch.target
            });
        } else if (e.touches.length === 2) {
            e.preventDefault();
            const newDistance = this.getPinchDistance(e.touches);
            const scale = newDistance / this.interaction.pinchDistance;
            const newZoom = Utils.clamp(
                this.interaction.initialZoom * scale,
                this.state.minZoom,
                this.state.maxZoom
            );

            this.state.zoom = newZoom;
            this.updateTransform();
            this.onZoomChange(this.state.zoom);
        }
    }

    /**
     * Handle touch end
     */
    handleTouchEnd(e) {
        this.handleMouseUp({
            target: e.target
        });
    }

    /**
     * Handle drop (from sidebar)
     */
    handleDrop(e) {
        e.preventDefault();

        const nodeType = e.dataTransfer.getData('node-type');
        if (!nodeType) return;

        const point = this.getCanvasPoint(e);
        const snappedPoint = this.state.snapToGrid ? {
            x: Utils.snapToGrid(point.x, this.state.gridSize),
            y: Utils.snapToGrid(point.y, this.state.gridSize)
        } : point;

        // Create node
        const node = this.nodeManager.createNode(nodeType, snappedPoint);
        if (node) {
            this.renderNode(node);
            this.nodeManager.selectNode(node.id);
            this.updateNodeSelectionVisuals();
            this.onSelectionChange([node]);
            this.hideEmptyState();
        }
    }

    /**
     * Start panning
     */
    startPanning(e) {
        this.interaction.isPanning = true;
        this.interaction.panStart = { x: e.clientX, y: e.clientY };
        this.interaction.panStartOffset = { ...this.state.pan };
        this.container.classList.add('grabbing');
    }

    /**
     * End panning
     */
    endPanning() {
        this.interaction.isPanning = false;
        this.container.classList.remove('grabbing');
    }

    /**
     * Start node drag
     */
    startNodeDrag(nodeId, e) {
        this.interaction.isDragging = true;
        this.interaction.draggedNode = nodeId;

        const node = this.nodeManager.getNode(nodeId);
        const point = this.getCanvasPoint(e);

        this.interaction.dragOffset = {
            x: point.x - node.position.x,
            y: point.y - node.position.y
        };

        // Store start positions for all selected nodes
        this.interaction.selectedNodesStartPositions.clear();
        this.nodeManager.getSelectedNodes().forEach(n => {
            this.interaction.selectedNodesStartPositions.set(n.id, { ...n.position });
        });

        // Add dragging class
        const element = this.nodesContainer.querySelector(`[data-node-id="${nodeId}"]`);
        if (element) {
            element.classList.add('dragging');
        }
    }

    /**
     * Update node drag
     */
    updateNodeDrag(e) {
        if (!this.interaction.draggedNode) return;

        const point = this.getCanvasPoint(e);
        const mainNode = this.nodeManager.getNode(this.interaction.draggedNode);
        const startPos = this.interaction.selectedNodesStartPositions.get(this.interaction.draggedNode);

        if (!mainNode || !startPos) return;

        // Calculate delta from start
        const newX = point.x - this.interaction.dragOffset.x;
        const newY = point.y - this.interaction.dragOffset.y;

        const delta = {
            x: newX - startPos.x,
            y: newY - startPos.y
        };

        // Move all selected nodes
        this.nodeManager.selectedNodes.forEach(nodeId => {
            const node = this.nodeManager.getNode(nodeId);
            const nodeStartPos = this.interaction.selectedNodesStartPositions.get(nodeId);

            if (node && nodeStartPos) {
                let targetX = nodeStartPos.x + delta.x;
                let targetY = nodeStartPos.y + delta.y;

                if (this.state.snapToGrid) {
                    targetX = Utils.snapToGrid(targetX, this.state.gridSize);
                    targetY = Utils.snapToGrid(targetY, this.state.gridSize);
                }

                node.position.x = targetX;
                node.position.y = targetY;

                // Update DOM
                const element = this.nodesContainer.querySelector(`[data-node-id="${nodeId}"]`);
                if (element) {
                    element.style.left = `${node.position.x}px`;
                    element.style.top = `${node.position.y}px`;
                }
            }
        });

        // Update connections
        if (this.connectionManager) {
            this.connectionManager.updateAllConnections();
        }
    }

    /**
     * End node drag
     */
    endNodeDrag() {
        if (this.interaction.draggedNode) {
            const element = this.nodesContainer.querySelector(`[data-node-id="${this.interaction.draggedNode}"]`);
            if (element) {
                element.classList.remove('dragging');
            }

            this.onNodeMove(this.nodeManager.getSelectedNodes());
        }

        this.interaction.isDragging = false;
        this.interaction.draggedNode = null;
        this.interaction.selectedNodesStartPositions.clear();
    }

    /**
     * Start selection box
     */
    startSelectionBox(point) {
        this.interaction.isSelecting = true;
        this.interaction.selectionStart = point;

        this.selectionBox.style.left = `${point.x}px`;
        this.selectionBox.style.top = `${point.y}px`;
        this.selectionBox.style.width = '0px';
        this.selectionBox.style.height = '0px';
        this.selectionBox.classList.remove('hidden');
    }

    /**
     * Update selection box
     */
    updateSelectionBox(point) {
        const start = this.interaction.selectionStart;

        const left = Math.min(start.x, point.x);
        const top = Math.min(start.y, point.y);
        const width = Math.abs(point.x - start.x);
        const height = Math.abs(point.y - start.y);

        this.selectionBox.style.left = `${left}px`;
        this.selectionBox.style.top = `${top}px`;
        this.selectionBox.style.width = `${width}px`;
        this.selectionBox.style.height = `${height}px`;

        // Select nodes that intersect with selection box
        const selectionRect = { x: left, y: top, width, height };

        this.nodeManager.getAllNodes().forEach(node => {
            const nodeElement = this.nodesContainer.querySelector(`[data-node-id="${node.id}"]`);
            if (!nodeElement) return;

            const nodeRect = {
                x: node.position.x,
                y: node.position.y,
                width: nodeElement.offsetWidth,
                height: nodeElement.offsetHeight
            };

            if (Utils.rectsIntersect(selectionRect, nodeRect)) {
                this.nodeManager.selectNode(node.id, true);
            } else {
                this.nodeManager.deselectNode(node.id);
            }
        });

        this.updateNodeSelectionVisuals();
    }

    /**
     * End selection box
     */
    endSelectionBox() {
        this.interaction.isSelecting = false;
        this.selectionBox.classList.add('hidden');
        this.onSelectionChange(this.nodeManager.getSelectedNodes());
    }

    /**
     * Start connection from port
     */
    startConnection(nodeId, portId, dataType, e) {
        if (!this.connectionManager) return;

        this.interaction.isConnecting = true;
        this.connectionManager.startConnection(nodeId, portId, dataType, e);
    }

    /**
     * Get canvas point from mouse event
     */
    getCanvasPoint(e) {
        const rect = this.container.getBoundingClientRect();
        const x = (e.clientX - rect.left - this.state.pan.x) / this.state.zoom;
        const y = (e.clientY - rect.top - this.state.pan.y) / this.state.zoom;
        return { x, y };
    }

    /**
     * Get screen point from canvas point
     */
    getScreenPoint(canvasPoint) {
        const rect = this.container.getBoundingClientRect();
        return {
            x: canvasPoint.x * this.state.zoom + this.state.pan.x + rect.left,
            y: canvasPoint.y * this.state.zoom + this.state.pan.y + rect.top
        };
    }

    /**
     * Get viewport center in canvas coordinates
     */
    getViewportCenter() {
        const rect = this.container.getBoundingClientRect();
        return {
            x: (rect.width / 2 - this.state.pan.x) / this.state.zoom,
            y: (rect.height / 2 - this.state.pan.y) / this.state.zoom
        };
    }

    /**
     * Zoom at specific point
     */
    zoomAtPoint(factor, screenX, screenY) {
        const oldZoom = this.state.zoom;
        const newZoom = Utils.clamp(oldZoom * factor, this.state.minZoom, this.state.maxZoom);

        if (newZoom === oldZoom) return;

        // Calculate pan adjustment to zoom at mouse position
        const scale = newZoom / oldZoom;
        this.state.pan.x = screenX - (screenX - this.state.pan.x) * scale;
        this.state.pan.y = screenY - (screenY - this.state.pan.y) * scale;
        this.state.zoom = newZoom;

        this.updateTransform();
        this.updateGrid();
        this.onZoomChange(this.state.zoom);
    }

    /**
     * Zoom in
     */
    zoomIn() {
        const rect = this.container.getBoundingClientRect();
        this.zoomAtPoint(1 + this.state.zoomStep, rect.width / 2, rect.height / 2);
    }

    /**
     * Zoom out
     */
    zoomOut() {
        const rect = this.container.getBoundingClientRect();
        this.zoomAtPoint(1 - this.state.zoomStep, rect.width / 2, rect.height / 2);
    }

    /**
     * Reset zoom to 100%
     */
    resetZoom() {
        const rect = this.container.getBoundingClientRect();
        const factor = 1 / this.state.zoom;
        this.zoomAtPoint(factor, rect.width / 2, rect.height / 2);
    }

    /**
     * Set zoom level
     */
    setZoom(zoom) {
        const rect = this.container.getBoundingClientRect();
        const factor = zoom / this.state.zoom;
        this.zoomAtPoint(factor, rect.width / 2, rect.height / 2);
    }

    /**
     * Fit all nodes in view
     */
    fitToView(padding = 50) {
        const nodes = this.nodeManager.getAllNodes();
        if (nodes.length === 0) {
            this.centerCanvas();
            return;
        }

        // Calculate bounding box
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

        nodes.forEach(node => {
            const element = this.nodesContainer.querySelector(`[data-node-id="${node.id}"]`);
            const width = element ? element.offsetWidth : 200;
            const height = element ? element.offsetHeight : 150;

            minX = Math.min(minX, node.position.x);
            minY = Math.min(minY, node.position.y);
            maxX = Math.max(maxX, node.position.x + width);
            maxY = Math.max(maxY, node.position.y + height);
        });

        const rect = this.container.getBoundingClientRect();
        const contentWidth = maxX - minX + padding * 2;
        const contentHeight = maxY - minY + padding * 2;

        // Calculate zoom to fit
        const zoomX = rect.width / contentWidth;
        const zoomY = rect.height / contentHeight;
        const newZoom = Utils.clamp(Math.min(zoomX, zoomY), this.state.minZoom, this.state.maxZoom);

        // Calculate pan to center
        const centerX = (minX + maxX) / 2;
        const centerY = (minY + maxY) / 2;

        this.state.zoom = newZoom;
        this.state.pan.x = rect.width / 2 - centerX * newZoom;
        this.state.pan.y = rect.height / 2 - centerY * newZoom;

        this.updateTransform();
        this.updateGrid();
        this.onZoomChange(this.state.zoom);
    }

    /**
     * Center canvas
     */
    centerCanvas() {
        const rect = this.container.getBoundingClientRect();
        this.state.pan.x = rect.width / 2;
        this.state.pan.y = rect.height / 2;
        this.updateTransform();
    }

    /**
     * Update transform
     */
    updateTransform() {
        const transform = `translate(${this.state.pan.x}px, ${this.state.pan.y}px) scale(${this.state.zoom})`;
        this.nodesContainer.style.transform = transform;

        // Update connections layer transform
        if (this.connectionsLayer) {
            // SVG connections need different handling
            const svgContent = this.connectionsLayer.querySelector('#connections-group');
            if (svgContent) {
                svgContent.setAttribute('transform',
                    `translate(${this.state.pan.x}, ${this.state.pan.y}) scale(${this.state.zoom})`
                );
            }

            // Also transform temp-connection
            const tempConnection = this.connectionsLayer.querySelector('#temp-connection');
            if (tempConnection) {
                tempConnection.setAttribute('transform',
                    `translate(${this.state.pan.x}, ${this.state.pan.y}) scale(${this.state.zoom})`
                );
            }
        }

        // Update zoom display
        const zoomDisplay = document.getElementById('zoom-level');
        if (zoomDisplay) {
            zoomDisplay.textContent = `${Math.round(this.state.zoom * 100)}%`;
        }

        const statusZoom = document.getElementById('status-zoom');
        if (statusZoom) {
            statusZoom.textContent = `Zoom: ${Math.round(this.state.zoom * 100)}%`;
        }

        // Update minimap viewport
        this.updateMinimap();
    }

    /**
     * Update grid
     */
    updateGrid() {
        if (!this.gridElement) return;

        const gridSize = this.state.gridSize * this.state.zoom;
        this.gridElement.style.backgroundSize = `${gridSize}px ${gridSize}px`;
        this.gridElement.style.backgroundPosition =
            `${this.state.pan.x % gridSize}px ${this.state.pan.y % gridSize}px`;
    }

    /**
     * Toggle grid visibility
     */
    toggleGrid() {
        this.state.showGrid = !this.state.showGrid;
        if (this.gridElement) {
            this.gridElement.classList.toggle('hidden', !this.state.showGrid);
        }
        return this.state.showGrid;
    }

    /**
     * Toggle snap to grid
     */
    toggleSnapToGrid() {
        this.state.snapToGrid = !this.state.snapToGrid;
        return this.state.snapToGrid;
    }

    /**
     * Update position display
     */
    updatePositionDisplay(point) {
        const display = document.getElementById('status-position');
        if (display) {
            display.textContent = `X: ${Math.round(point.x)}, Y: ${Math.round(point.y)}`;
        }
    }

    /**
     * Get pinch distance
     */
    getPinchDistance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }

    /**
     * Get pinch center
     */
    getPinchCenter(touches) {
        return {
            x: (touches[0].clientX + touches[1].clientX) / 2,
            y: (touches[0].clientY + touches[1].clientY) / 2
        };
    }

    /**
     * Render a single node
     */
    renderNode(node) {
        const definition = this.nodeManager.getNodeDefinition(node.type);
        if (!definition) return;

        // Check if node already exists
        let element = this.nodesContainer.querySelector(`[data-node-id="${node.id}"]`);

        if (!element) {
            element = this.createNodeElement(node, definition);
            this.nodesContainer.appendChild(element);
        }

        // Always update the element content/state (previews, fields, etc.)
        this.updateNodeElement(element, node, definition);


        // Position
        element.style.left = `${node.position.x}px`;
        element.style.top = `${node.position.y}px`;

        // Selection state
        element.classList.toggle('selected', this.nodeManager.isSelected(node.id));

        // Status - remove all status classes first
        const statusClasses = ['executing', 'completed', 'error', 'pending', 'queued', 'running', 'skipped', 'failed'];
        statusClasses.forEach(cls => element.classList.remove(cls));

        // Apply current status class
        if (node.status) {
            switch (node.status) {
                case 'processing':
                    element.classList.add('executing');
                    break;
                case 'completed':
                    element.classList.add('completed');
                    break;
                case 'error':
                case 'failed':
                    element.classList.add('error');
                    break;
                case 'pending':
                    element.classList.add('pending');
                    break;
                case 'queued':
                    element.classList.add('queued');
                    break;
                case 'running':
                    element.classList.add('running');
                    break;
                case 'skipped':
                    element.classList.add('skipped');
                    break;
            }
        }

    }

    /**
     * Create node DOM element
     */
    createNodeElement(node, definition) {
        const colors = Utils.getCategoryColor(definition.category);
        const icon = Utils.getNodeIcon(node.type);

        const element = document.createElement('div');
        element.className = 'workflow-node';
        element.dataset.nodeId = node.id;
        element.dataset.nodeType = node.type;
        element.dataset.category = definition.category;

        // Build node HTML
        let html = `
            <div class="node-header">
                <div class="node-header-icon" style="background-color: ${colors.bg}; color: ${colors.text}">
                    <i data-lucide="${icon}" class="w-4 h-4"></i>
                </div>
                <span class="node-header-title">${definition.name}</span>
                <div class="node-header-actions">
                    ${definition.hasRunButton ? `
                    <button class="node-action-btn node-run-btn" data-action="run-flow" title="Run this flow">
                        <i data-lucide="play" class="w-3 h-3"></i>
                    </button>
                    ` : ''}
                    ${definition.category === 'generation' ? `
                    <button class="node-action-btn" data-action="history" title="Generation History">
                        <i data-lucide="history" class="w-3 h-3"></i>
                    </button>
                    ` : ''}
                    <button class="node-action-btn" data-action="delete" title="Delete Node">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>

                </div>
            </div>
            <div class="node-content">
        `;


        // Add preview area if applicable
        if (definition.preview) {
            html += `
                <div class="node-preview" data-preview-type="${definition.preview.type}">
                    <div class="node-preview-placeholder">
                        <i data-lucide="${this.getPreviewPlaceholderIcon(definition.preview.type)}" class="w-8 h-8"></i>
                        <span>No preview</span>
                    </div>
                </div>
            `;
        }

        // Add compact fields (only show first 2 important fields in node)
        // Also respect showIf conditions (both object and function)
        const compactFields = definition.fields.filter(f => {
            // Only show text, textarea, select fields
            if (!['text', 'textarea', 'select'].includes(f.type)) return false;

            // Check showIf condition
            if (f.showIf) {
                // If showIf is a function, call it
                if (typeof f.showIf === 'function') {
                    try {
                        return f.showIf(node.data);
                    } catch (e) {
                        console.warn('showIf function error:', e);
                        return true; // Show field on error
                    }
                }
                // If showIf is an object, check it later (handled by updateCompactFieldVisibility)
            }
            return true;
        }).slice(0, 2);

        if (compactFields.length > 0) {
            html += '<div class="node-fields">';
            compactFields.forEach(field => {
                html += this.createCompactFieldHtml(field, node.data[field.id]);
            });
            html += '</div>';
        }

        html += '</div>'; // End node-content

        // Add ports section OUTSIDE of node-content
        html += '<div class="node-ports">';

        // Input ports (left side)
        html += '<div class="node-ports-left">';
        if (definition.inputs && definition.inputs.length > 0) {
            definition.inputs.forEach(port => {
                html += `
                    <div class="node-port node-port-left" 
                         data-port-id="${port.id}" 
                         data-port-type="input" 
                         data-type="${port.type}"
                         title="${port.label} (${port.type})">
                         <div class="node-port-dot"></div>
                        <!-- Label removed for clean look, used tooltip -->
                    </div>

                `;
            });
        }
        html += '</div>';

        // Output ports (right side)
        html += '<div class="node-ports-right">';
        if (definition.outputs && definition.outputs.length > 0) {
            definition.outputs.forEach(port => {
                html += `
                    <div class="node-port node-port-right" 
                         data-port-id="${port.id}" 
                         data-port-type="output" 
                         data-type="${port.type}"
                         title="${port.label} (${port.type})">
                        <!-- Label removed for clean look, used tooltip -->
                        <div class="node-port-dot"></div>
                    </div>

                `;
            });
        }
        html += '</div>';

        html += '</div>'; // End node-ports

        element.innerHTML = html;

        // Initialize Lucide icons in the node
        if (window.lucide) {
            lucide.createIcons({ nodes: [element] });
        }

        // Add event listeners for node actions
        element.querySelectorAll('.node-action-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                // Get action from button (may click on icon inside)
                const targetBtn = e.target.closest('.node-action-btn');
                const action = targetBtn?.dataset?.action || btn.dataset.action;
                if (action === 'delete') {

                    // Check settings for confirm delete
                    const confirmDelete = localStorage.getItem('aikaflow_settings') ?
                        JSON.parse(localStorage.getItem('aikaflow_settings')).confirmDelete : true;

                    if (confirmDelete && window.ConfirmDialog) {
                        window.ConfirmDialog.delete(node.name || 'this node')
                            .then(confirmed => {
                                if (confirmed) this.deleteNode(node.id);
                            });
                    } else {
                        this.deleteNode(node.id);
                    }
                } else if (action === 'history') {
                    // Open generation history for this node
                    this.showGenerationHistory(node.id, node.type);
                } else if (action === 'run-flow') {
                    // Run this specific flow starting from this trigger node
                    if (this.onRunFlow) {
                        this.onRunFlow(node.id);
                    } else if (window.editorInstance) {
                        window.editorInstance.runSingleFlow(node.id);
                    }
                }
            });
        });


        // Prevention of drag on buttons (fixes clickability issue)
        element.querySelectorAll('.node-action-btn').forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });
        });

        // Initialize visibility
        this.updateCompactFieldVisibility(element);

        // Add event listeners for compact fields

        element.querySelectorAll('.node-field-input, .node-field-textarea, .node-field-select').forEach(input => {
            input.addEventListener('change', (e) => {
                const fieldId = e.target.dataset.fieldId;
                const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
                this.nodeManager.updateNodeData(node.id, fieldId, value);

                // Update visibility of other fields
                this.updateCompactFieldVisibility(element);
            });


            input.addEventListener('click', (e) => {
                e.stopPropagation();
            });

            input.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });
        });

        // Helper function for custom prompt modal in canvas
        const showCanvasCustomPromptModal = (text, textarea, btn) => {
            // Remove existing modal if any
            const existingModal = document.querySelector('.custom-prompt-modal-overlay');
            if (existingModal) existingModal.remove();

            // Create modal HTML
            const modalHtml = `
                <div class="custom-prompt-modal-overlay">
                    <div class="custom-prompt-modal">
                        <div class="custom-prompt-modal-header">
                            <h3>${window.t ? window.t('enhance.custom_prompt_title') : 'Custom Enhancement Prompt'}</h3>
                            <button class="custom-prompt-modal-close" type="button">
                                <i data-lucide="x" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <div class="custom-prompt-modal-body">
                            <label class="custom-prompt-label">
                                ${window.t ? window.t('enhance.enter_prompt') : 'Enter your enhancement prompt:'}
                            </label>
                            <textarea class="custom-prompt-textarea" rows="4" placeholder="${window.t ? window.t('enhance.prompt_placeholder') : 'e.g., Make this text more professional and concise...'}"></textarea>
                        </div>
                        <div class="custom-prompt-modal-footer">
                            <button class="custom-prompt-cancel btn-secondary" type="button">
                                ${window.t ? window.t('common.cancel') : 'Cancel'}
                            </button>
                            <button class="custom-prompt-submit btn-primary" type="button">
                                <i data-lucide="sparkles" class="w-4 h-4"></i>
                                ${window.t ? window.t('enhance.run_enhancement') : 'Run Enhancement'}
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // Add modal to document
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = document.querySelector('.custom-prompt-modal-overlay');
            const promptTextarea = modal.querySelector('.custom-prompt-textarea');

            // Initialize icons
            if (window.lucide) {
                lucide.createIcons({ root: modal });
            }

            // Focus on textarea
            promptTextarea.focus();

            // Close handlers
            const closeModal = () => modal.remove();
            modal.querySelector('.custom-prompt-modal-close').addEventListener('click', closeModal);
            modal.querySelector('.custom-prompt-cancel').addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            // Submit handler
            modal.querySelector('.custom-prompt-submit').addEventListener('click', async () => {
                const customPrompt = promptTextarea.value.trim();
                if (!customPrompt) {
                    Toast?.error(window.t ? window.t('enhance.prompt_required') : 'Please enter a prompt');
                    return;
                }
                if (!text.trim()) {
                    Toast?.error(window.t ? window.t('enhance.text_required') : 'Please enter some text in the node first');
                    closeModal();
                    return;
                }

                // Show loading state
                btn.classList.add('loading');
                const originalIcon = btn.innerHTML;
                btn.innerHTML = '<i data-lucide="loader" class="w-3 h-3 animate-spin"></i>';
                if (window.lucide) lucide.createIcons({ nodes: [btn] });
                closeModal();

                try {
                    // Use custom prompt directly
                    const enhanced = await window.AIKAFLOWTextEnhance.enhanceWithCustomPrompt(text, customPrompt);
                    if (textarea) {
                        textarea.value = enhanced;
                        textarea.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    Toast?.success('Text enhanced!');
                } catch (error) {
                    Toast?.error('Enhancement failed: ' + error.message);
                } finally {
                    btn.classList.remove('loading');
                    btn.innerHTML = originalIcon;
                    if (window.lucide) lucide.createIcons({ nodes: [btn] });
                }
            });
        };

        // Add event listener for field action buttons (like AI enhance)
        element.querySelectorAll('.node-field-action-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const action = btn.dataset.action;
                const fieldId = btn.dataset.fieldId;

                if (action === 'enhance') {
                    const textarea = element.querySelector(`textarea[data-field-id="${fieldId}"]`);
                    const text = textarea?.value || '';

                    if (!text.trim()) {
                        Toast?.warning('Please enter some text first');
                        return;
                    }

                    // Check if OpenRouter is configured
                    if (!window.AIKAFLOWTextEnhance) {
                        Toast?.error('Text enhancement not available');
                        return;
                    }

                    // Check if dropdown already exists, remove it
                    const existingDropdown = document.querySelector('.canvas-enhance-dropdown');
                    if (existingDropdown) {
                        existingDropdown.remove();
                        return;
                    }

                    // Fetch system prompts from server
                    let prompts = [];
                    try {
                        const res = await fetch('./api/ai/prompts.php');
                        const data = await res.json();
                        if (data.success) {
                            prompts = data.systemPrompts || [];
                            if (!data.isConfigured) {
                                Toast?.error('OpenRouter API key not configured');
                                return;
                            }
                        }
                    } catch (err) {
                        console.error('Failed to fetch prompts:', err);
                    }

                    // Create dropdown HTML - Custom Prompt first
                    let dropdownHtml = `
                        <div class="enhance-dropdown-item" data-prompt-id="custom">
                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                            <span>${window.t ? window.t('enhance.custom_prompt') : 'Custom Prompt'}</span>
                        </div>
                    `;

                    if (prompts.length > 0) {
                        dropdownHtml += '<div class="enhance-dropdown-divider"></div>';
                        prompts.forEach(p => {
                            dropdownHtml += `
                                <div class="enhance-dropdown-item" data-prompt-id="${p.id}">
                                    <i data-lucide="message-square" class="w-4 h-4"></i>
                                    <span>${p.name}</span>
                                </div>
                            `;
                        });
                    }

                    // Create dropdown element
                    const dropdown = document.createElement('div');
                    dropdown.className = 'canvas-enhance-dropdown enhance-dropdown';
                    dropdown.innerHTML = dropdownHtml;

                    // Position relative to button
                    const btnRect = btn.getBoundingClientRect();
                    dropdown.style.position = 'fixed';
                    dropdown.style.left = `${btnRect.left}px`;
                    dropdown.style.top = `${btnRect.bottom + 5}px`;
                    dropdown.style.zIndex = '10000';
                    document.body.appendChild(dropdown);

                    // Initialize icons
                    if (window.lucide) {
                        lucide.createIcons({ root: dropdown });
                    }

                    // Handle item clicks
                    dropdown.querySelectorAll('.enhance-dropdown-item').forEach(item => {
                        item.addEventListener('click', async () => {
                            const promptId = item.dataset.promptId || null;
                            dropdown.remove();

                            // Handle custom prompt
                            if (promptId === 'custom') {
                                showCanvasCustomPromptModal(text, textarea, btn);
                                return;
                            }

                            // Show loading state
                            btn.classList.add('loading');
                            const originalIcon = btn.innerHTML;
                            btn.innerHTML = '<i data-lucide="loader" class="w-3 h-3 animate-spin"></i>';
                            if (window.lucide) lucide.createIcons({ nodes: [btn] });

                            try {
                                const enhanced = await window.AIKAFLOWTextEnhance.enhance(text, promptId);
                                if (textarea) {
                                    textarea.value = enhanced;
                                    textarea.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                Toast?.success('Text enhanced!');
                            } catch (error) {
                                Toast?.error('Enhancement failed: ' + error.message);
                            } finally {
                                btn.classList.remove('loading');
                                btn.innerHTML = originalIcon;
                                if (window.lucide) lucide.createIcons({ nodes: [btn] });
                            }
                        });
                    });

                    // Close dropdown on outside click
                    const closeDropdown = (evt) => {
                        if (!dropdown.contains(evt.target) && evt.target !== btn) {
                            dropdown.remove();
                            document.removeEventListener('click', closeDropdown);
                        }
                    };
                    setTimeout(() => document.addEventListener('click', closeDropdown), 10);
                }
            });

            btn.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });
        });

        // Add event listeners for ports
        element.querySelectorAll('.node-port').forEach(port => {
            port.addEventListener('mousedown', (e) => {
                e.stopPropagation();

                const portType = port.dataset.portType;
                const portId = port.dataset.portId;
                const dataType = port.dataset.type;

                if (portType === 'output') {
                    this.startConnection(node.id, portId, dataType, e);
                }
            });

            port.addEventListener('mouseup', (e) => {
                // Handle connection end
                if (this.interaction.isConnecting && this.connectionManager) {
                    const portType = port.dataset.portType;
                    if (portType === 'input') {
                        this.connectionManager.endConnection(
                            node.id,
                            port.dataset.portId,
                            port.dataset.type
                        );
                        this.interaction.isConnecting = false;
                    }
                }
            });

            port.addEventListener('mouseenter', (e) => {
                if (this.interaction.isConnecting) {
                    port.classList.add('hover');
                }
            });

            port.addEventListener('mouseleave', (e) => {
                port.classList.remove('hover');
            });
        });

        return element;
    }

    /**
     * Update existing node element
     */
    updateNodeElement(element, node, definition) {
        // Update preview if there's output data
        const preview = element.querySelector('.node-preview');
        if (preview && definition.preview) {
            let previewValue = null;
            const previewType = definition.preview.type;
            const previewSource = definition.preview.source;

            // Check based on preview source type
            if (previewSource === 'input') {
                // For input nodes, get preview from node.data
                previewValue = this.getInputNodePreviewValue(node, definition);
            } else if (previewSource === 'output' && node.outputs) {
                // For output nodes, get preview from node.outputs
                const outputKey = Object.keys(node.outputs)[0];
                if (outputKey && node.outputs[outputKey]) {
                    previewValue = node.outputs[outputKey];
                }
            }

            if (previewValue) {
                this.updateNodePreview(preview, previewType, previewValue);
            }
        }


        // Update field values
        definition.fields.forEach(field => {
            const input = element.querySelector(`[data-field-id="${field.id}"]`);
            if (input && node.data[field.id] !== undefined) {
                if (input.type === 'checkbox') {
                    input.checked = node.data[field.id];
                } else {
                    input.value = node.data[field.id];
                }
            }
        });
    }

    /**
     * Check if a node type is an input node
     */
    isInputNode(nodeType) {
        return ['image-input', 'video-input', 'audio-input'].includes(nodeType);
    }

    /**
     * Get the preview type for an input node
     */
    getInputNodePreviewType(nodeType) {
        const types = {
            'image-input': 'image',
            'video-input': 'video',
            'audio-input': 'audio'
        };
        return types[nodeType] || 'image';
    }

    /**
     * Get the preview value from input node data
     */
    getInputNodePreviewValue(node, definition) {
        const source = node.data?.source || 'upload';

        if (source === 'upload' && node.data?.file) {
            // For uploaded files, prefer server URL over dataUrl (to avoid loading large base64)
            return node.data.file.url || node.data.file.previewUrl || node.data.file.dataUrl;
        } else if (source === 'url' && node.data?.url) {
            // For URL input
            return node.data.url;
        }

        return null;
    }



    /**
     * Update node preview
     */
    updateNodePreview(previewElement, type, value) {
        // Handle empty value (show placeholder)
        if (!value) {
            const icon = this.getPreviewPlaceholderIcon(type);
            previewElement.innerHTML = `
                <div class="node-preview-placeholder">
                    <i data-lucide="${icon}" class="w-8 h-8"></i>
                    <span>No preview</span>
                </div>
            `;
            if (window.lucide) {
                lucide.createIcons({ nodes: [previewElement] });
            }
            return;
        }

        let html = '';

        switch (type) {
            case 'image':
                html = `<img src="${value}" alt="Preview" />`;
                break;
            case 'video':
                html = `<video src="${value}" controls muted></video>`;
                break;
            case 'audio':
                html = `<audio src="${value}" controls></audio>`;
                break;
            default:
                // Fallback (shouldn't be reached if type is valid)
                html = `<div class="node-preview-placeholder">
                    <i data-lucide="file" class="w-8 h-8"></i>
                    <span>Output ready</span>
                </div>`;
        }

        previewElement.innerHTML = html;

        if (window.lucide) {
            lucide.createIcons({ nodes: [previewElement] });
        }
    }

    /**
     * Get preview placeholder icon
     */
    getPreviewPlaceholderIcon(type) {
        const icons = {
            'image': 'image',
            'video': 'video',
            'audio': 'music'
        };
        return icons[type] || 'file';
    }

    /**
     * Update visibility of compact fields based on conditions
     */
    updateCompactFieldVisibility(nodeElement) {
        const fields = nodeElement.querySelectorAll('.node-field[data-show-if-key]');
        fields.forEach(field => {
            const key = field.dataset.showIfKey;
            const requiredValue = field.dataset.showIfValue;

            // Find the input that controls this key within the same node
            // Note: form inputs have data-field-id
            const controller = nodeElement.querySelector(`[data-field-id="${key}"]`);

            if (controller) {
                const currentValue = controller.type === 'checkbox' ? String(controller.checked) : String(controller.value);

                if (currentValue === requiredValue) {
                    field.style.display = 'flex';
                } else {
                    field.style.display = 'none';
                }
            }
        });
    }

    /**
     * Create compact field HTML for node body
     */
    createCompactFieldHtml(field, value) {

        let inputHtml = '';

        switch (field.type) {
            case 'text':
                inputHtml = `
                    <input type="text" 
                           class="node-field-input" 
                           data-field-id="${field.id}"
                           placeholder="${field.placeholder || ''}"
                           value="${Utils.escapeHtml(value || '')}">
                `;
                break;

            case 'textarea':
                // Check if this field has a labelAction (like AI enhance)
                let actionBtn = '';
                if (field.labelAction && field.labelAction.id === 'enhance') {
                    actionBtn = `
                        <button type="button" class="node-field-action-btn" 
                                data-action="enhance" 
                                data-field-id="${field.id}"
                                title="${field.labelAction.title || 'Enhance with AI'}">
                            <i data-lucide="wand-2" class="w-3 h-3"></i>
                        </button>
                    `;
                }
                inputHtml = `
                    <textarea class="node-field-input node-field-textarea" 
                              data-field-id="${field.id}"
                              placeholder="${field.placeholder || ''}"
                              rows="2">${Utils.escapeHtml(value || '')}</textarea>
                `;
                // Wrap in container if has action
                if (actionBtn) {
                    inputHtml = `<div class="node-field-with-action">${inputHtml}${actionBtn}</div>`;
                }
                break;

            case 'select':
                const options = (field.options || [])
                    .map(opt => `<option value="${opt.value}" ${value === opt.value ? 'selected' : ''}>${opt.label}</option>`)
                    .join('');
                inputHtml = `
                    <select class="node-field-input node-field-select" data-field-id="${field.id}">
                        ${options}
                    </select>
                `;
                break;

            default:
                return '';
        }

        // Add visibility data attributes
        const showIfAttrs = field.showIf
            ? `data-show-if-key="${Object.keys(field.showIf)[0]}" data-show-if-value="${Object.values(field.showIf)[0]}"`
            : '';

        return `
            <div class="node-field" ${showIfAttrs}>
                <label class="node-field-label">${field.label}</label>
                ${inputHtml}
            </div>
        `;
    }

    /**
     * Render all nodes
     */
    renderNodes() {
        // Clear existing nodes (but preserve connections layer)
        const existingNodes = this.nodesContainer.querySelectorAll('.workflow-node');
        existingNodes.forEach(el => el.remove());

        // Render each node
        this.nodeManager.getAllNodes().forEach(node => {
            this.renderNode(node);
        });

        // Update empty state
        if (this.nodeManager.getCount() === 0) {
            this.showEmptyState();
        } else {
            this.hideEmptyState();
        }

        // Update status bar
        this.updateStatusBar();

        // Update connections
        if (this.connectionManager) {
            this.connectionManager.renderConnections();
        }

        // Update minimap
        this.updateMinimap();
    }

    /**
     * Delete a node
     */
    deleteNode(nodeId) {
        // Remove connections
        if (this.connectionManager) {
            this.connectionManager.removeConnectionsForNode(nodeId);
        }

        // Remove from manager
        this.nodeManager.deleteNode(nodeId);

        // Remove DOM element
        const element = this.nodesContainer.querySelector(`[data-node-id="${nodeId}"]`);
        if (element) {
            element.remove();
        }

        this.updateStatusBar();
        this.onSelectionChange([]);

        if (this.nodeManager.getCount() === 0) {
            this.showEmptyState();
        }
    }

    /**
     * Delete selected nodes
     */
    deleteSelectedNodes() {
        const selectedIds = Array.from(this.nodeManager.selectedNodes);

        selectedIds.forEach(id => {
            this.deleteNode(id);
        });

        this.nodeManager.clearSelection();
    }

    /**
     * Select all nodes
     */
    selectAllNodes() {
        this.nodeManager.getAllNodes().forEach(node => {
            this.nodeManager.selectNode(node.id, true);
        });
        this.updateNodeSelectionVisuals();
        this.onSelectionChange(this.nodeManager.getSelectedNodes());
    }

    /**
     * Duplicate selected nodes
     */
    duplicateSelectedNodes() {
        const result = this.nodeManager.duplicateSelectedNodes();

        // Duplicate connections between selected nodes
        if (this.connectionManager && result.idMapping) {
            this.connectionManager.duplicateConnectionsForNodes(result.idMapping);
        }

        this.renderNodes();
        this.onSelectionChange(this.nodeManager.getSelectedNodes());
    }

    /**
     * Update node selection visuals
     */
    updateNodeSelectionVisuals() {
        this.nodesContainer.querySelectorAll('.workflow-node').forEach(element => {
            const nodeId = element.dataset.nodeId;
            element.classList.toggle('selected', this.nodeManager.isSelected(nodeId));
        });
    }

    /**
     * Show empty state
     */
    showEmptyState() {
        const emptyState = document.getElementById('empty-state');
        if (emptyState) {
            emptyState.classList.remove('hidden');
        }
    }

    /**
     * Hide empty state
     */
    hideEmptyState() {
        const emptyState = document.getElementById('empty-state');
        if (emptyState) {
            emptyState.classList.add('hidden');
        }
    }

    /**
     * Update status bar
     */
    updateStatusBar() {
        const nodesCount = document.getElementById('status-nodes');
        const connectionsCount = document.getElementById('status-connections');
        const selectedCount = document.getElementById('status-selected');

        if (nodesCount) {
            nodesCount.textContent = `Nodes: ${this.nodeManager.getCount()}`;
        }

        if (connectionsCount && this.connectionManager) {
            connectionsCount.textContent = `Connections: ${this.connectionManager.getCount()}`;
        }

        if (selectedCount) {
            const count = this.nodeManager.selectedNodes.size;
            selectedCount.textContent = count > 0 ? `Selected: ${count}` : 'Selected: None';
        }
    }

    /**
     * Clear canvas
     */
    clear() {
        this.nodeManager.clear();
        if (this.connectionManager) {
            this.connectionManager.clear();
        }
        this.renderNodes();
        this.centerCanvas();
        this.showEmptyState();
    }

    /**
     * Get canvas state for saving
     */
    getState() {
        return {
            pan: { ...this.state.pan },
            zoom: this.state.zoom
        };
    }

    /**
     * Restore canvas state
     */
    setState(state) {
        if (state.pan) {
            this.state.pan = { ...state.pan };
        }
        if (state.zoom) {
            this.state.zoom = state.zoom;
        }
        this.updateTransform();
        this.updateGrid();
        this.onZoomChange(this.state.zoom);
    }

    /**
     * Update minimap to show nodes
     */
    updateMinimap() {
        const minimapContent = document.getElementById('minimap-content');
        const minimapViewport = document.getElementById('minimap-viewport');
        const minimap = document.getElementById('minimap');

        if (!minimapContent || !minimap) return;

        // Clear existing minimap nodes
        minimapContent.querySelectorAll('.minimap-node').forEach(el => el.remove());

        const nodes = this.nodeManager.getAllNodes();
        if (nodes.length === 0) return;

        // Calculate bounding box of all nodes
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

        nodes.forEach(node => {
            const element = this.nodesContainer.querySelector(`[data-node-id="${node.id}"]`);
            const width = element ? element.offsetWidth : 200;
            const height = element ? element.offsetHeight : 100;

            minX = Math.min(minX, node.position.x);
            minY = Math.min(minY, node.position.y);
            maxX = Math.max(maxX, node.position.x + width);
            maxY = Math.max(maxY, node.position.y + height);
        });

        // Add padding
        const padding = 50;
        minX -= padding;
        minY -= padding;
        maxX += padding;
        maxY += padding;

        const contentWidth = maxX - minX;
        const contentHeight = maxY - minY;

        // Get minimap dimensions
        const minimapRect = minimap.getBoundingClientRect();
        const minimapWidth = minimapRect.width;
        const minimapHeight = minimapRect.height;

        // Calculate scale to fit all nodes in minimap
        const scale = Math.min(minimapWidth / contentWidth, minimapHeight / contentHeight);

        // Render each node as a minimap node
        nodes.forEach(node => {
            const element = this.nodesContainer.querySelector(`[data-node-id="${node.id}"]`);
            const width = element ? element.offsetWidth : 200;
            const height = element ? element.offsetHeight : 100;

            const minimapNode = document.createElement('div');
            minimapNode.className = 'minimap-node';
            if (this.nodeManager.isSelected(node.id)) {
                minimapNode.classList.add('selected');
            }

            // Position and size relative to minimap
            minimapNode.style.left = `${(node.position.x - minX) * scale}px`;
            minimapNode.style.top = `${(node.position.y - minY) * scale}px`;
            minimapNode.style.width = `${Math.max(width * scale, 3)}px`;
            minimapNode.style.height = `${Math.max(height * scale, 2)}px`;

            minimapContent.appendChild(minimapNode);
        });

        // Update viewport rectangle
        if (minimapViewport) {
            const containerRect = this.container.getBoundingClientRect();

            // Calculate viewport position in canvas coordinates
            const viewportLeft = -this.state.pan.x / this.state.zoom;
            const viewportTop = -this.state.pan.y / this.state.zoom;
            const viewportWidth = containerRect.width / this.state.zoom;
            const viewportHeight = containerRect.height / this.state.zoom;

            // Convert to minimap coordinates
            minimapViewport.style.left = `${(viewportLeft - minX) * scale}px`;
            minimapViewport.style.top = `${(viewportTop - minY) * scale}px`;
            minimapViewport.style.width = `${viewportWidth * scale}px`;
            minimapViewport.style.height = `${viewportHeight * scale}px`;
        }
    }

    /**
     * Show generation history modal for a node
     */
    async showGenerationHistory(nodeId, nodeType) {
        // Create or get modal
        let modal = document.getElementById('modal-generation-history');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'modal-generation-history';
            // Add inline styles for proper overlay display
            modal.style.cssText = `
                position: fixed;
                inset: 0;
                z-index: 9999;
                display: none;
                align-items: center;
                justify-content: center;
                background: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(4px);
            `;
            modal.innerHTML = `
                <div style="background: #1e1e2e; border: 1px solid #374151; border-radius: 12px; width: 90%; max-width: 800px; max-height: 80vh; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);">
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #374151;">
                        <h2 class="modal-title" style="display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 600; color: #f3f4f6; margin: 0;">
                            <i data-lucide="history" class="w-5 h-5"></i>
                            Generation History
                        </h2>
                        <button data-action="close" style="background: none; border: none; color: #9ca3af; cursor: pointer; padding: 4px; border-radius: 4px;">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <div style="padding: 20px; overflow-y: auto; max-height: calc(80vh - 60px);">
                        <div id="generation-history-content" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
                            <div style="text-align: center; color: #6b7280; grid-column: 1 / -1; padding: 32px 0;">
                                <i data-lucide="loader" class="w-8 h-8 animate-spin" style="margin: 0 auto 8px; display: block;"></i>
                                Loading history...
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Add close handlers
            modal.addEventListener('click', (e) => {
                if (e.target === modal || e.target.closest('[data-action="close"]')) {
                    modal.style.display = 'none';
                }
            });

            if (window.lucide) {
                lucide.createIcons({ nodes: [modal] });
            }
        }

        // Show modal
        modal.style.display = 'flex';

        // Update title with node type
        const title = modal.querySelector('.modal-title');
        if (title) {
            const nodeDef = this.nodeManager?.getNodeDefinition(nodeType);
            title.innerHTML = `<i data-lucide="history" class="w-5 h-5"></i> ${nodeDef?.name || nodeType} History`;
            if (window.lucide) lucide.createIcons({ nodes: [title] });
        }

        // Load history
        const content = modal.querySelector('#generation-history-content');
        try {
            const node = this.nodeManager?.getNode(nodeId);

            // Get history from node's outputs or stored data
            const outputs = node?.outputs || {};
            const hasOutput = Object.keys(outputs).length > 0;

            if (hasOutput) {
                let html = '';
                for (const [key, value] of Object.entries(outputs)) {
                    if (typeof value === 'string' && (value.includes('http') || value.startsWith('data:'))) {
                        // Image or video URL
                        if (value.includes('.mp4') || value.includes('video')) {
                            html += `
                                <div class="bg-dark-800 rounded-lg overflow-hidden">
                                    <video src="${value}" controls class="w-full aspect-video object-cover"></video>
                                    <div class="p-2 text-xs text-dark-400">${key}</div>
                                </div>
                            `;
                        } else {
                            html += `
                                <div class="bg-dark-800 rounded-lg overflow-hidden">
                                    <img src="${value}" alt="${key}" class="w-full aspect-video object-cover">
                                    <div class="p-2 text-xs text-dark-400">${key}</div>
                                </div>
                            `;
                        }
                    }
                }

                if (html) {
                    content.innerHTML = html;
                } else {
                    content.innerHTML = `
                        <div class="text-center text-dark-400 col-span-full py-8">
                            <i data-lucide="file-x" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                            <p>No visual outputs available</p>
                        </div>
                    `;
                    if (window.lucide) lucide.createIcons({ nodes: [content] });
                }
            } else {
                content.innerHTML = `
                    <div class="text-center text-dark-400 col-span-full py-8">
                        <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                        <p>No generation history yet</p>
                        <p class="text-sm mt-1">Run the workflow to generate content</p>
                    </div>
                `;
                if (window.lucide) lucide.createIcons({ nodes: [content] });
            }
        } catch (error) {
            content.innerHTML = `
                <div class="text-center text-red-400 col-span-full py-8">
                    <i data-lucide="alert-circle" class="w-8 h-8 mx-auto mb-2"></i>
                    <p>Failed to load history</p>
                    <p class="text-sm">${error.message}</p>
                </div>
            `;
            if (window.lucide) lucide.createIcons({ nodes: [content] });
        }
    }
}

// Make available globally
window.CanvasManager = CanvasManager;