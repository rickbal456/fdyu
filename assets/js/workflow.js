/**
 * AIKAFLOW - Workflow Manager
 * 
 * Handles workflow save, load, export, and execution.
 */

class WorkflowManager {
    constructor(options = {}) {
        // References
        this.nodeManager = options.nodeManager || null;
        this.connectionManager = options.connectionManager || null;
        this.canvasManager = options.canvasManager || null;

        // Current workflow state
        this.currentWorkflow = {
            id: null,
            name: 'Untitled Workflow',
            description: '',
            isPublic: false,
            createdAt: null,
            updatedAt: null
        };

        // Execution state
        this.executionState = {
            isRunning: false,
            executionId: null,
            currentNodeId: null,
            progress: 0,
            nodeStatuses: new Map()
        };

        // History for undo/redo (session-only, 10 steps max)
        this.history = [];
        this.historyIndex = -1;
        this.maxHistorySize = 10;
        this.isUndoRedoAction = false;

        // Auto-save
        this.autoSaveInterval = null;
        this.autoSaveDelay = 60000; // 1 minute
        this.hasUnsavedChanges = false;

        // Callbacks
        this.onWorkflowChange = options.onWorkflowChange || (() => { });
        this.onExecutionStart = options.onExecutionStart || (() => { });
        this.onExecutionProgress = options.onExecutionProgress || (() => { });
        this.onExecutionComplete = options.onExecutionComplete || (() => { });
        this.onExecutionError = options.onExecutionError || (() => { });
        this.onSaveStatusChange = options.onSaveStatusChange || (() => { });
        this.onHistoryChange = options.onHistoryChange || (() => { });

        // Initialize
        this.init();
    }

    /**
     * Initialize workflow manager
     */
    init() {
        // Setup auto-save
        this.setupAutoSave();

        // Save initial state
        this.saveToHistory();

        // Handle page unload
        window.addEventListener('beforeunload', (e) => {
            if (this.hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
    }

    /**
     * Setup auto-save
     */
    setupAutoSave() {
        if (this.autoSaveInterval) {
            clearInterval(this.autoSaveInterval);
        }

        if (this.autoSaveDelay > 0) {
            this.autoSaveInterval = setInterval(() => {
                if (this.hasUnsavedChanges && this.currentWorkflow.id) {
                    this.saveWorkflow(true); // Silent save
                }
            }, this.autoSaveDelay);
        }
    }

    /**
     * Set auto-save interval
     */
    setAutoSaveInterval(ms) {
        this.autoSaveDelay = ms;
        this.setupAutoSave();
    }

    /**
     * Mark workflow as changed
     */
    markChanged() {
        this.hasUnsavedChanges = true;
        this.onSaveStatusChange('unsaved');

        if (!this.isUndoRedoAction) {
            this.saveToHistory();
        }
    }

    /**
     * Mark workflow as saved
     */
    markSaved() {
        this.hasUnsavedChanges = false;
        this.onSaveStatusChange('saved');
    }

    /**
     * Create new workflow
     */
    newWorkflow(name = 'Untitled Workflow', template = 'blank') {
        // Clear current state
        this.nodeManager?.clear();
        this.connectionManager?.clear();
        this.canvasManager?.clear();

        // Reset workflow info
        this.currentWorkflow = {
            id: null,
            name: name,
            description: '',
            isPublic: false,
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString()
        };

        // Apply template if specified
        if (template !== 'blank') {
            this.applyTemplate(template);
        }

        // Reset history
        this.history = [];
        this.historyIndex = -1;
        this.saveToHistory();

        this.hasUnsavedChanges = false;
        this.onWorkflowChange(this.currentWorkflow);

        return this.currentWorkflow;
    }

    /**
     * Apply workflow template
     */
    applyTemplate(templateName) {
        const templates = {
            'image-to-video': [
                { type: 'image-input', position: { x: 100, y: 200 } },
                { type: 'image-to-video', position: { x: 400, y: 200 } },
                { type: 'video-output', position: { x: 700, y: 200 } }
            ],
            'text-to-video': [
                { type: 'text-input', position: { x: 100, y: 200 } },
                { type: 'text-to-video', position: { x: 400, y: 200 } },
                { type: 'video-output', position: { x: 700, y: 200 } }
            ],
            'music-video': [
                { type: 'text-input', position: { x: 100, y: 100 } },
                { type: 'text-to-image', position: { x: 400, y: 100 } },
                { type: 'image-to-video', position: { x: 700, y: 100 } },
                { type: 'music-gen', position: { x: 400, y: 300 } },
                { type: 'add-audio', position: { x: 900, y: 200 } },
                { type: 'video-output', position: { x: 1100, y: 200 } }
            ]
        };

        const template = templates[templateName];
        if (!template) return;

        const nodeIds = [];

        // Create nodes
        template.forEach(nodeData => {
            const node = this.nodeManager.createNode(nodeData.type, nodeData.position);
            if (node) {
                nodeIds.push(node.id);
            }
        });

        // Create connections based on template logic
        // This is simplified - in a real app, templates would define connections too
        if (templateName === 'image-to-video' && nodeIds.length === 3) {
            this.connectionManager?.createConnection(nodeIds[0], 'image', nodeIds[1], 'image');
            this.connectionManager?.createConnection(nodeIds[1], 'video', nodeIds[2], 'video');
        } else if (templateName === 'text-to-video' && nodeIds.length === 3) {
            this.connectionManager?.createConnection(nodeIds[0], 'text', nodeIds[1], 'prompt');
            this.connectionManager?.createConnection(nodeIds[1], 'video', nodeIds[2], 'video');
        }

        // Render
        this.canvasManager?.renderNodes();
        this.canvasManager?.fitToView();
    }

    /**
     * Save workflow to server
     */
    async saveWorkflow(silent = false) {
        const workflowData = this.serialize();

        try {
            this.onSaveStatusChange('saving');

            const response = await API.saveWorkflow({
                id: this.currentWorkflow.id,
                name: this.currentWorkflow.name,
                description: this.currentWorkflow.description,
                isPublic: this.currentWorkflow.isPublic,
                data: workflowData
            });

            if (response.success) {
                this.currentWorkflow.id = response.workflowId;
                this.currentWorkflow.updatedAt = new Date().toISOString();
                this.markSaved();

                if (!silent && window.Toast) {
                    Toast.success('Workflow saved', this.currentWorkflow.name);
                }

                return response;
            } else {
                throw new Error(response.error || 'Save failed');
            }
        } catch (error) {
            console.error('Save error:', error);
            this.onSaveStatusChange('error');

            if (!silent && window.Toast) {
                Toast.error('Save failed', error.message);
            }

            throw error;
        }
    }

    /**
     * Load workflow from server
     */
    async loadWorkflow(workflowId) {
        try {
            const response = await API.loadWorkflow(workflowId);

            if (response.success) {
                this.deserialize(response.workflow);

                if (window.Toast) {
                    Toast.success('Workflow loaded', this.currentWorkflow.name);
                }

                return response.workflow;
            } else {
                throw new Error(response.error || 'Load failed');
            }
        } catch (error) {
            console.error('Load error:', error);

            if (window.Toast) {
                Toast.error('Load failed', error.message);
            }

            throw error;
        }
    }

    /**
     * Get list of user's workflows
     */
    async getWorkflowList() {
        try {
            const response = await API.getWorkflows();
            return response.success ? response.workflows : [];
        } catch (error) {
            console.error('Get workflows error:', error);
            return [];
        }
    }

    /**
     * Delete workflow from server
     */
    async deleteWorkflow(workflowId) {
        try {
            const response = await API.deleteWorkflow(workflowId);

            if (response.success) {
                if (this.currentWorkflow.id === workflowId) {
                    this.newWorkflow();
                }

                if (window.Toast) {
                    Toast.success('Workflow deleted');
                }

                return true;
            } else {
                throw new Error(response.error || 'Delete failed');
            }
        } catch (error) {
            console.error('Delete error:', error);

            if (window.Toast) {
                Toast.error('Delete failed', error.message);
            }

            throw error;
        }
    }

    /**
     * Export workflow to file
     */
    exportWorkflow() {
        const workflowData = this.serialize();
        const json = JSON.stringify(workflowData, null, 2);
        const filename = `${this.currentWorkflow.name.replace(/[^a-z0-9]/gi, '_')}.aikaflow`;

        Utils.downloadFile(filename, json, 'application/json');

        if (window.Toast) {
            Toast.success('Workflow exported', filename);
        }
    }

    /**
     * Import workflow from file
     */
    async importWorkflow(file) {
        try {
            const json = await Utils.readFileAsText(file);
            const data = JSON.parse(json);

            this.deserialize(data);
            this.currentWorkflow.id = null; // New workflow
            this.currentWorkflow.name = file.name.replace('.aikaflow', '').replace('.json', '');
            this.markChanged();

            if (window.Toast) {
                Toast.success('Workflow imported', this.currentWorkflow.name);
            }

            return true;
        } catch (error) {
            console.error('Import error:', error);

            if (window.Toast) {
                Toast.error('Import failed', 'Invalid workflow file');
            }

            throw error;
        }
    }

    /**
     * Serialize workflow to JSON
     */
    serialize() {
        return {
            version: '1.0.0',
            workflow: {
                id: this.currentWorkflow.id,
                name: this.currentWorkflow.name,
                description: this.currentWorkflow.description,
                isPublic: this.currentWorkflow.isPublic,
                createdAt: this.currentWorkflow.createdAt,
                updatedAt: new Date().toISOString()
            },
            nodes: this.nodeManager?.serialize() || [],
            connections: this.connectionManager?.serialize() || [],
            canvas: this.canvasManager?.getState() || { pan: { x: 0, y: 0 }, zoom: 1 }
        };
    }

    /**
     * Deserialize workflow from JSON
     */
    deserialize(data) {
        // Clear current state
        this.nodeManager?.clear();
        this.connectionManager?.clear();

        // Restore workflow info
        if (data.workflow) {
            this.currentWorkflow = {
                id: data.workflow.id,
                name: data.workflow.name || 'Imported Workflow',
                description: data.workflow.description || '',
                isPublic: data.workflow.isPublic || false,
                shareId: data.workflow.shareId || null,
                shareIsPublic: data.workflow.shareIsPublic,
                createdAt: data.workflow.createdAt,
                updatedAt: data.workflow.updatedAt
            };
        }

        // Restore nodes
        if (data.nodes && this.nodeManager) {
            this.nodeManager.deserialize(data.nodes);
        }

        // Restore connections
        if (data.connections && this.connectionManager) {
            this.connectionManager.deserialize(data.connections);
        }

        // Restore canvas state
        if (data.canvas && this.canvasManager) {
            this.canvasManager.setState(data.canvas);
        }

        // Render
        this.canvasManager?.renderNodes();

        // Only reset history when NOT in undo/redo mode
        // During undo/redo, we want to preserve the history stack
        if (!this.isUndoRedoAction) {
            this.history = [];
            this.historyIndex = -1;
            this.saveToHistory();
            this.hasUnsavedChanges = false;
        }

        this.onWorkflowChange(this.currentWorkflow);
    }

    /**
     * Save current state to history
     */
    saveToHistory() {
        if (this.isUndoRedoAction) return;

        const state = this.serialize();

        // Remove any future states if we're not at the end
        if (this.historyIndex < this.history.length - 1) {
            this.history = this.history.slice(0, this.historyIndex + 1);
        }

        // Add new state
        this.history.push(state);

        // Limit history size
        if (this.history.length > this.maxHistorySize) {
            this.history.shift();
        } else {
            this.historyIndex++;
        }

        // Notify history changed (for button updates)
        this.onHistoryChange();
    }

    /**
     * Undo last action
     */
    undo() {
        if (!this.canUndo()) return false;

        this.isUndoRedoAction = true;
        this.historyIndex--;
        this.deserialize(this.history[this.historyIndex]);
        this.isUndoRedoAction = false;
        this.hasUnsavedChanges = true;

        // Notify history changed
        this.onHistoryChange();

        return true;
    }

    /**
     * Redo last undone action
     */
    redo() {
        if (!this.canRedo()) return false;

        this.isUndoRedoAction = true;
        this.historyIndex++;
        this.deserialize(this.history[this.historyIndex]);
        this.isUndoRedoAction = false;
        this.hasUnsavedChanges = true;

        // Notify history changed
        this.onHistoryChange();

        return true;
    }

    /**
     * Check if undo is available
     */
    canUndo() {
        return this.historyIndex > 0;
    }

    /**
     * Check if redo is available
     */
    canRedo() {
        return this.historyIndex < this.history.length - 1;
    }

    /**
     * Execute workflow
     */
    async executeWorkflow() {
        // Validate workflow
        const validation = this.connectionManager?.validateWorkflow();
        if (validation && !validation.valid) {
            if (window.Toast) {
                Toast.error('Invalid workflow', validation.errors[0]?.message || 'Missing connections');
            }
            return { success: false, errors: validation.errors };
        }

        // Get execution order
        const executionOrder = this.connectionManager?.getExecutionOrder() || [];

        if (executionOrder.length === 0) {
            if (window.Toast) {
                Toast.warning('Nothing to execute', 'Add some nodes first');
            }
            return { success: false, error: 'No nodes to execute' };
        }

        // Get graph islands (flows) for grouping
        const islands = this.connectionManager?.getGraphIslands() || [];

        // Build flow info - map each node to its flow
        const nodeToFlow = new Map();
        const flows = islands.map((island, index) => {
            const entryNode = island.entryNodes[0];
            const entryDef = this.nodeManager?.getNodeDefinition(entryNode?.type);
            const isStartFlow = entryDef?.isTrigger || entryNode?.type === 'manual-trigger' || entryNode?.type === 'start-flow';

            // Get flow name from entry node data, or generate a default name
            // Always give a name to enable grouping when there are multiple islands
            let flowName = entryNode?.data?.flowName;
            if (!flowName) {
                if (isStartFlow) {
                    flowName = `Flow ${index + 1}`;
                } else {
                    // Use entry node name or type for non-trigger flows
                    flowName = entryNode?.name || entryDef?.name || `Flow ${index + 1}`;
                }
            }

            island.nodes.forEach(node => {
                nodeToFlow.set(node.id, {
                    flowId: island.id,
                    flowName,
                    flowIndex: index
                });
            });

            return {
                id: island.id,
                name: flowName,
                entryNodeId: entryNode?.id,
                entryNodeType: entryNode?.type,
                nodeCount: island.nodes.length,
                isStartFlow
            };
        });

        // Initialize execution state
        this.executionState = {
            isRunning: true,
            executionId: Utils.generateId('exec'),
            currentNodeId: null,
            progress: 0,
            nodeStatuses: new Map()
        };

        // Reset all node statuses
        this.nodeManager?.resetAllStatuses();
        executionOrder.forEach(node => {
            this.executionState.nodeStatuses.set(node.id, 'pending');
        });

        // Notify start with flow information
        this.onExecutionStart({
            executionId: this.executionState.executionId,
            nodes: executionOrder.map(n => ({
                id: n.id,
                name: n.name,
                type: n.type,
                flowId: nodeToFlow.get(n.id)?.flowId,
                flowName: nodeToFlow.get(n.id)?.flowName,
                flowIndex: nodeToFlow.get(n.id)?.flowIndex
            })),
            flows: flows // Include all flows since we now always assign names
        });

        try {
            // Start execution on server
            const response = await API.executeWorkflow({
                workflowId: this.currentWorkflow.id,
                workflowData: this.serialize()
            });

            if (response.success) {
                this.executionState.executionId = response.executionId;

                // Start polling for status
                this.pollExecutionStatus(response.executionId);

                return { success: true, executionId: response.executionId };
            } else {
                throw new Error(response.error || 'Execution failed to start');
            }
        } catch (error) {
            console.error('Execution error:', error);

            this.executionState.isRunning = false;
            this.onExecutionError({ error: error.message });

            if (window.Toast) {
                Toast.error('Execution failed', error.message);
            }

            return { success: false, error: error.message };
        }
    }

    /**
     * Execute a single flow (subset of nodes starting from a trigger)
     * @param {string} triggerNodeId - The trigger node ID
     * @param {Array} flowNodes - Array of nodes in this flow
     */
    async executeSingleFlow(triggerNodeId, flowNodes) {
        if (flowNodes.length === 0) {
            if (window.Toast) {
                Toast.warning('Empty flow', 'No nodes to execute');
            }
            return { success: false, error: 'No nodes to execute' };
        }

        // Initialize execution state
        this.executionState = {
            isRunning: true,
            executionId: Utils.generateId('exec'),
            currentNodeId: null,
            progress: 0,
            nodeStatuses: new Map()
        };

        // Reset statuses for this flow only
        flowNodes.forEach(node => {
            this.nodeManager?.setNodeStatus(node.id, 'pending');
            this.executionState.nodeStatuses.set(node.id, 'pending');
        });

        // Notify start
        this.onExecutionStart({
            executionId: this.executionState.executionId,
            nodes: flowNodes.map(n => ({ id: n.id, name: n.name, type: n.type })),
            isSingleFlow: true,
            triggerNodeId
        });

        try {
            // Create a partial workflow data with only these nodes
            const flowNodeIds = new Set(flowNodes.map(n => n.id));
            const flowConnections = this.connectionManager?.getAllConnections().filter(conn =>
                flowNodeIds.has(conn.from.nodeId) && flowNodeIds.has(conn.to.nodeId)
            ) || [];

            const partialWorkflowData = {
                version: '1.0.0',
                workflow: this.currentWorkflow,
                nodes: flowNodes.map(node => ({
                    id: node.id,
                    type: node.type,
                    position: node.position,
                    data: node.data
                })),
                connections: flowConnections.map(conn => ({
                    id: conn.id,
                    from: { ...conn.from },
                    to: { ...conn.to }
                })),
                canvas: this.canvasManager?.getState() || { pan: { x: 0, y: 0 }, zoom: 1 }
            };

            // Start execution on server
            const response = await API.executeWorkflow({
                workflowId: this.currentWorkflow.id,
                workflowData: partialWorkflowData,
                flowId: triggerNodeId // Mark this as a single flow execution
            });

            if (response.success) {
                this.executionState.executionId = response.executionId;
                this.pollExecutionStatus(response.executionId);
                return { success: true, executionId: response.executionId };
            } else {
                throw new Error(response.error || 'Execution failed to start');
            }
        } catch (error) {
            console.error('Single flow execution error:', error);

            this.executionState.isRunning = false;
            this.onExecutionError({ error: error.message });

            if (window.Toast) {
                Toast.error('Execution failed', error.message);
            }

            return { success: false, error: error.message };
        }
    }


    /**
     * Poll execution status
     */
    async pollExecutionStatus(executionId) {
        const pollInterval = 2000; // 2 seconds
        const maxPolls = 600; // 20 minutes max
        let pollCount = 0;

        // Track which result URLs have already been dispatched to gallery
        const dispatchedResults = new Set();

        const poll = async () => {
            if (!this.executionState.isRunning) return;

            try {
                const response = await API.getExecutionStatus(executionId);

                if (response.success) {
                    // Update node statuses
                    if (response.nodeStatuses) {
                        response.nodeStatuses.forEach(ns => {
                            this.executionState.nodeStatuses.set(ns.nodeId, ns.status);
                            this.nodeManager?.setNodeStatus(ns.nodeId, ns.status, ns.error);

                            if (ns.resultUrl && ns.status === 'completed') {
                                // Get output port from node definition
                                const node = this.nodeManager?.getNode(ns.nodeId);
                                const def = this.nodeManager?.getNodeDefinition(node?.type);
                                if (def?.outputs?.[0]) {
                                    this.nodeManager?.setNodeOutput(ns.nodeId, def.outputs[0].id, ns.resultUrl);
                                }

                                // Dispatch event to save to gallery (for generation/editing categories)
                                // Only dispatch if not already dispatched (avoid duplicates during polling)
                                const category = def?.category || '';
                                if (['generation', 'editing', 'output'].includes(category) && !dispatchedResults.has(ns.resultUrl)) {
                                    dispatchedResults.add(ns.resultUrl);

                                    // Determine output type from URL or node definition
                                    let outputType = 'image';
                                    if (ns.resultUrl.match(/\.(mp4|webm|mov|avi)$/i) || def?.type?.includes('video') || def?.type?.includes('v2v') || def?.type?.includes('i2v')) {
                                        outputType = 'video';
                                    } else if (ns.resultUrl.match(/\.(mp3|wav|ogg|m4a)$/i) || def?.type?.includes('audio') || def?.type?.includes('tts')) {
                                        outputType = 'audio';
                                    }

                                    document.dispatchEvent(new CustomEvent('node:output:generated', {
                                        detail: {
                                            type: outputType,
                                            url: ns.resultUrl,
                                            nodeId: ns.nodeId,
                                            nodeType: def?.type || node?.type
                                        }
                                    }));
                                }
                            }
                        });
                    }

                    // Update flow statuses (for multi-flow execution)
                    if (response.flowStatuses && response.flowStatuses.length > 0) {
                        if (!this.executionState.flowStatuses) {
                            this.executionState.flowStatuses = new Map();
                        }
                        response.flowStatuses.forEach(fs => {
                            this.executionState.flowStatuses.set(fs.flowId, {
                                status: fs.status,
                                flowName: fs.flowName,
                                error: fs.error
                            });
                            // Update trigger node with flow status
                            this.nodeManager?.setNodeStatus(fs.entryNodeId, fs.status, fs.error);
                        });
                    }

                    // Calculate progress
                    const completed = Array.from(this.executionState.nodeStatuses.values())
                        .filter(s => s === 'completed' || s === 'failed').length;
                    const total = this.executionState.nodeStatuses.size;
                    this.executionState.progress = Math.round((completed / total) * 100);

                    // Update canvas
                    this.canvasManager?.renderNodes();

                    // Notify progress
                    this.onExecutionProgress({
                        executionId,
                        progress: this.executionState.progress,
                        status: response.status,
                        nodeStatuses: response.nodeStatuses,
                        flowStatuses: response.flowStatuses
                    });


                    // Check if complete
                    if (response.status === 'completed') {
                        this.executionState.isRunning = false;
                        this.onExecutionComplete({
                            executionId,
                            resultUrl: response.resultUrl,
                            outputs: response.outputs
                        });

                        if (window.Toast) {
                            Toast.success('Execution complete!', 'Your workflow has finished processing');
                        }
                        return;
                    }

                    if (response.status === 'failed') {
                        this.executionState.isRunning = false;
                        this.onExecutionError({
                            executionId,
                            error: response.error || 'Execution failed'
                        });

                        if (window.Toast) {
                            Toast.error('Execution failed', response.error || 'Unknown error');
                        }
                        return;
                    }

                    // Continue polling
                    pollCount++;
                    if (pollCount < maxPolls) {
                        setTimeout(poll, pollInterval);
                    } else {
                        this.executionState.isRunning = false;
                        this.onExecutionError({
                            executionId,
                            error: 'Execution timeout'
                        });
                    }
                }
            } catch (error) {
                console.error('Poll error:', error);

                // Retry on network errors
                pollCount++;
                if (pollCount < maxPolls && this.executionState.isRunning) {
                    setTimeout(poll, pollInterval * 2);
                }
            }
        };

        // Start polling
        setTimeout(poll, pollInterval);
    }

    /**
     * Check for running executions and resume polling
     * Called on page load to resume tracking of any running workflows
     */
    async resumeRunningExecutions() {
        try {
            const response = await API.get('/workflows/history.php?status=current&limit=10');

            if (response.success && response.history && response.history.length > 0) {
                // Find the most recent running execution
                const runningExecution = response.history.find(
                    e => e.status === 'running' || e.status === 'pending' || e.status === 'queued'
                );

                if (runningExecution) {
                    // Set execution state
                    this.executionState = {
                        isRunning: true,
                        executionId: runningExecution.id,
                        currentNodeId: null,
                        progress: runningExecution.progress || 0,
                        nodeStatuses: new Map()
                    };

                    // Initialize node statuses from the response
                    if (runningExecution.nodes) {
                        runningExecution.nodes.forEach(node => {
                            this.executionState.nodeStatuses.set(node.id, node.status);
                        });
                    }

                    // Notify that execution is running
                    this.onExecutionStart({
                        executionId: runningExecution.id,
                        nodes: runningExecution.nodes || [],
                        isResumed: true
                    });

                    // Resume polling
                    this.pollExecutionStatus(runningExecution.id);

                    if (window.Toast) {
                        Toast.info('Resuming Execution', `Workflow "${runningExecution.workflowName}" is still running`);
                    }

                    return runningExecution;
                }
            }

            return null;
        } catch (error) {
            console.error('Error checking for running executions:', error);
            return null;
        }
    }

    /**
     * Cancel execution
     */
    async cancelExecution() {
        if (!this.executionState.isRunning) return;

        try {
            const response = await API.cancelExecution(this.executionState.executionId);

            this.executionState.isRunning = false;
            this.nodeManager?.resetAllStatuses();
            this.canvasManager?.renderNodes();

            if (window.Toast) {
                Toast.info('Execution cancelled');
            }

            return response;
        } catch (error) {
            console.error('Cancel error:', error);
            throw error;
        }
    }

    /**
     * Check if workflow is running
     */
    isRunning() {
        return this.executionState.isRunning;
    }

    /**
     * Get current workflow info
     */
    getWorkflowInfo() {
        return { ...this.currentWorkflow };
    }

    /**
     * Update workflow info
     */
    updateWorkflowInfo(updates) {
        Object.assign(this.currentWorkflow, updates);
        this.markChanged();
        this.onWorkflowChange(this.currentWorkflow);
    }

    /**
     * Save autosave to database
     */
    async saveToDatabase() {
        try {
            const data = this.serialize();
            await API.saveAutosave(data, this.currentWorkflow.id || null);
        } catch (error) {
            console.error('Database autosave error:', error);
        }
    }

    /**
     * Load autosave from database
     */
    async loadFromDatabase() {
        try {
            const response = await API.getAutosave(this.currentWorkflow.id || null);
            if (response.success && response.hasAutosave) {
                return {
                    data: response.data,
                    savedAt: response.savedAt
                };
            }
        } catch (error) {
            console.error('Database autosave load error:', error);
        }
        return null;
    }

    /**
     * Clear autosave from database
     */
    async clearAutosave() {
        try {
            await API.clearAutosave(this.currentWorkflow.id || null);
        } catch (error) {
            console.error('Database autosave clear error:', error);
        }
    }

    // Legacy localStorage methods - deprecated, kept for backward compatibility
    saveToLocalStorage() {
        // Redirect to database save
        this.saveToDatabase();
    }

    loadFromLocalStorage() {
        // This is now async, callers should use loadFromDatabase instead
        // console.warn('[WorkflowManager] loadFromLocalStorage is deprecated, use loadFromDatabase instead');
        return null;
    }

    clearLocalStorage() {
        // Redirect to database clear
        this.clearAutosave();
    }

    /**
     * Cleanup
     */
    destroy() {
        if (this.autoSaveInterval) {
            clearInterval(this.autoSaveInterval);
        }
    }
}

// Make available globally
window.WorkflowManager = WorkflowManager;