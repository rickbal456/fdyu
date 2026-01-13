/**
 * AIKAFLOW - Properties Panel Manager
 * 
 * Handles the right sidebar properties panel for editing node configurations.
 */

class PropertiesPanel {
    constructor(options = {}) {
        // DOM Elements
        this.panel = document.getElementById('sidebar-right');
        this.nodeInfo = document.getElementById('properties-node-info');
        this.nodeIcon = document.getElementById('properties-node-icon');
        this.nodeName = document.getElementById('properties-node-name');
        this.nodeId = document.getElementById('properties-node-id');
        this.content = document.getElementById('properties-content');
        this.footer = document.getElementById('properties-footer');
        this.closeBtn = document.getElementById('btn-close-properties');
        this.deleteBtn = document.getElementById('btn-delete-node');

        // State
        this.currentNode = null;
        this.isOpen = false;

        // References
        this.nodeManager = options.nodeManager || null;
        this.canvasManager = options.canvasManager || null;

        // Callbacks
        this.onNodeUpdate = options.onNodeUpdate || (() => { });
        this.onNodeDelete = options.onNodeDelete || (() => { });
        this.onClose = options.onClose || (() => { });

        // Debounced update for text inputs
        this.debouncedUpdate = Utils.debounce((nodeId, field, value) => {
            this.updateNodeField(nodeId, field, value);
        }, 300);

        // Initialize
        this.init();
    }

    /**
     * Initialize properties panel
     */
    init() {
        this.setupEventListeners();
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Close button
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', () => this.close());
        }

        // Delete button
        if (this.deleteBtn) {
            this.deleteBtn.addEventListener('click', () => {
                if (this.currentNode) {
                    this.onNodeDelete(this.currentNode.id);
                    this.close();
                }
            });
        }

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    }

    /**
     * Open panel with node data
     * @param {Object} node - Node object
     */
    open(node) {
        if (!node) return;

        this.currentNode = node;
        this.isOpen = true;

        // Show panel
        this.panel.classList.remove('hidden');

        // Update header info
        this.updateNodeInfo(node);

        // Render fields
        this.renderFields(node);

        // Show footer
        if (this.footer) {
            this.footer.classList.remove('hidden');
        }
    }

    /**
     * Close panel
     */
    close() {
        this.isOpen = false;
        this.currentNode = null;
        this.panel.classList.add('hidden');
        this.onClose();
    }

    /**
     * Toggle panel
     * @param {Object} node - Node object
     */
    toggle(node) {
        if (this.isOpen && this.currentNode?.id === node?.id) {
            this.close();
        } else {
            this.open(node);
        }
    }

    /**
     * Update node info in header
     * @param {Object} node - Node object
     */
    updateNodeInfo(node) {
        const definition = this.nodeManager?.getNodeDefinition(node.type);
        if (!definition) return;

        const colors = Utils.getCategoryColor(definition.category);
        const icon = Utils.getNodeIcon(node.type);

        // Update icon
        if (this.nodeIcon) {
            this.nodeIcon.style.backgroundColor = colors.bg;
            this.nodeIcon.style.color = colors.text;
            this.nodeIcon.innerHTML = `<i data-lucide="${icon}" class="w-5 h-5"></i>`;
        }

        // Update name and type
        if (this.nodeName) {
            this.nodeName.textContent = definition.name;
        }
        if (this.nodeId) {
            this.nodeId.textContent = node.id;
        }

        // Fetch and display node cost if available
        this.displayNodeCost(node.type);

        // Reinitialize Lucide icons
        if (window.lucide) {
            lucide.createIcons({ nodes: [this.nodeInfo] });
        }
    }

    /**
     * Display node cost badge in properties panel
     * @param {string} nodeType - Node type ID
     */
    async displayNodeCost(nodeType) {
        // Remove existing cost badge if any
        const existingBadge = document.getElementById('node-cost-badge');
        if (existingBadge) existingBadge.remove();

        try {
            // Check if we have cached node costs, or fetch them
            if (!window._nodeCostsCache) {
                const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/node-costs.php`);
                const data = await response.json();
                if (data.success) {
                    window._nodeCostsCache = {};
                    data.node_costs.forEach(nc => {
                        window._nodeCostsCache[nc.node_type] = parseFloat(nc.cost_per_call);
                    });
                }
            }

            const cost = window._nodeCostsCache?.[nodeType];
            if (cost && cost > 0) {
                const badge = document.createElement('div');
                badge.id = 'node-cost-badge';
                badge.className = 'flex items-center gap-1 text-xs px-2 py-1 bg-yellow-500/10 text-yellow-400 rounded-lg mt-2';
                badge.innerHTML = `
                    <i data-lucide="coins" class="w-3 h-3"></i>
                    <span>${cost} credits per run</span>
                `;
                this.nodeId?.parentNode?.appendChild(badge);

                if (window.lucide) {
                    lucide.createIcons({ nodes: [badge] });
                }
            }
        } catch (error) {
            // Silently fail - cost display is optional
            console.debug('Could not load node cost:', error);
        }
    }

    /**
     * Render all fields for a node
     * @param {Object} node - Node object
     */
    renderFields(node) {
        const definition = this.nodeManager?.getNodeDefinition(node.type);
        if (!definition) {
            this.content.innerHTML = '<p class="text-dark-400">No properties available.</p>';
            return;
        }

        // Get node connections to check for disabledWhenConnected
        const connectedInputs = this.getConnectedInputs(node.id);

        let html = '';

        // Group fields by category if applicable
        const fieldGroups = this.groupFields(definition.fields);

        fieldGroups.forEach(group => {
            if (group.title) {
                html += `
                    <div class="property-group">
                        <div class="property-group-title">
                            <i data-lucide="${group.icon || 'settings'}" class="w-4 h-4"></i>
                            <span>${group.title}</span>
                        </div>
                `;
            }

            group.fields.forEach(field => {
                // Check conditional visibility
                if (field.showIf && !this.checkCondition(field.showIf, node.data)) {
                    return;
                }

                // Check if field should be disabled based on connected input
                let fieldCopy = { ...field };
                if (field.disabledWhenConnected && connectedInputs.includes(field.disabledWhenConnected)) {
                    fieldCopy.disabled = true;
                    fieldCopy.placeholder = 'Using connected input...';
                }

                html += this.renderField(fieldCopy, node.data[field.id], node.id);
            });

            if (group.title) {
                html += '</div>';
            }
        });

        // Add action buttons if node definition has actions
        if (definition.actions && definition.actions.length > 0) {
            html += this.renderActionButtons(definition.actions, node);
        }

        // Add node status section
        html += this.renderStatusSection(node);

        // Add preview section if applicable
        if (definition.preview && node.outputs && Object.keys(node.outputs).length > 0) {
            html += this.renderPreviewSection(node, definition);
        }

        this.content.innerHTML = html;

        // Initialize Lucide icons
        if (window.lucide) {
            lucide.createIcons({ nodes: [this.content] });
        }

        // Setup field event listeners
        this.setupFieldListeners(node.id);

        // Setup action button listeners
        if (definition.actions) {
            this.setupActionListeners(definition.actions, node);
        }

        // Setup label action buttons (like AI enhance)
        this.setupLabelActions(definition, node);

        // Call onRender callback if defined
        if (definition.onRender && typeof definition.onRender === 'function') {
            definition.onRender(this.content, node.data);
        }
    }

    /**
     * Group fields logically
     * @param {Array} fields - Field definitions
     * @returns {Array} Grouped fields
     */
    groupFields(fields) {
        // For now, return all fields in one group
        // Can be extended to support field.group property
        return [{
            title: null,
            fields: fields
        }];
    }

    /**
     * Get list of connected input port IDs for a node
     * @param {string} nodeId - Node ID
     * @returns {Array} Array of input port IDs that have connections
     */
    getConnectedInputs(nodeId) {
        const connectedInputs = [];

        // Get connection manager from editor
        const connectionManager = window.editor?.connectionManager;
        if (!connectionManager) return connectedInputs;

        // Get connections for this node
        const connections = connectionManager.getConnectionsForNode(nodeId);
        if (connections && connections.inputs) {
            connections.inputs.forEach(conn => {
                if (conn.to && conn.to.portId) {
                    connectedInputs.push(conn.to.portId);
                }
            });
        }

        return connectedInputs;
    }

    /**
     * Render a single field
     * @param {Object} field - Field definition
     * @param {*} value - Current value
     * @param {string} nodeId - Node ID
     * @returns {string} HTML
     */
    renderField(field, value, nodeId) {
        const fieldId = `field-${nodeId}-${field.id}`;
        let inputHtml = '';

        switch (field.type) {
            case 'text':
                inputHtml = this.renderTextField(field, value, fieldId);
                break;

            case 'textarea':
                inputHtml = this.renderTextareaField(field, value, fieldId);
                break;

            case 'number':
                inputHtml = this.renderNumberField(field, value, fieldId);
                break;

            case 'select':
                inputHtml = this.renderSelectField(field, value, fieldId);
                break;

            case 'multiselect':
                inputHtml = this.renderMultiselectField(field, value, fieldId);
                break;

            case 'datetime':
            case 'datetime-local':
                inputHtml = this.renderDatetimeField(field, value, fieldId);
                break;

            case 'checkbox':
                inputHtml = this.renderCheckboxField(field, value, fieldId);
                break;

            case 'slider':
                inputHtml = this.renderSliderField(field, value, fieldId);
                break;

            case 'color':
                inputHtml = this.renderColorField(field, value, fieldId);
                break;

            case 'file':
                inputHtml = this.renderFileField(field, value, fieldId);
                break;

            default:
                inputHtml = this.renderTextField(field, value, fieldId);
        }

        return `
            <div class="property-field" data-field-name="${field.id}">
                ${inputHtml}
            </div>
        `;
    }

    /**
     * Render text field
     */
    renderTextField(field, value, fieldId) {
        const escapedValue = Utils.escapeHtml(value || field.default || '');

        // Check if this is a URL field that could show a media preview
        const isMediaUrlField = field.id === 'url' && value &&
            (value.startsWith('http://') || value.startsWith('https://') || value.startsWith('data:'));

        let previewHtml = '';
        if (isMediaUrlField) {
            // Detect media type from URL
            const lowerUrl = value.toLowerCase();
            const isImage = /\.(jpg|jpeg|png|gif|webp|svg|bmp)(\?.*)?$/i.test(lowerUrl) ||
                lowerUrl.includes('image') ||
                value.startsWith('data:image');
            const isVideo = /\.(mp4|webm|mov|avi|mkv)(\?.*)?$/i.test(lowerUrl) ||
                lowerUrl.includes('video') ||
                value.startsWith('data:video');
            const isAudio = /\.(mp3|wav|ogg|m4a|aac)(\?.*)?$/i.test(lowerUrl) ||
                lowerUrl.includes('audio') ||
                value.startsWith('data:audio');

            if (isImage) {
                previewHtml = `
                    <div class="url-preview mt-2" id="${fieldId}-preview">
                        <img src="${escapedValue}" alt="Preview" 
                             class="w-full max-h-40 object-contain rounded-lg bg-dark-800"
                             onerror="this.parentElement.style.display='none'">
                    </div>
                `;
            } else if (isVideo) {
                previewHtml = `
                    <div class="url-preview mt-2" id="${fieldId}-preview">
                        <video src="${escapedValue}" controls 
                               class="w-full max-h-40 rounded-lg bg-dark-800"
                               onerror="this.parentElement.style.display='none'"></video>
                    </div>
                `;
            } else if (isAudio) {
                previewHtml = `
                    <div class="url-preview mt-2" id="${fieldId}-preview">
                        <audio src="${escapedValue}" controls class="w-full"
                               onerror="this.parentElement.style.display='none'"></audio>
                    </div>
                `;
            }
        }

        return `
            <label class="property-label" for="${fieldId}">${field.label}</label>
            <input 
                type="text" 
                id="${fieldId}"
                class="form-input"
                data-field-id="${field.id}"
                data-field-type="text"
                placeholder="${field.placeholder || ''}"
                value="${escapedValue}"
            >
            ${previewHtml}
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }


    /**
     * Render textarea field
     */
    renderTextareaField(field, value, fieldId) {
        // Check if field has a label action (like AI enhance)
        let labelActionHtml = '';
        if (field.labelAction) {
            labelActionHtml = `
                <button type="button" 
                        class="label-action-btn" 
                        data-action="${field.labelAction.id}"
                        title="${field.labelAction.title || ''}">
                    <i data-lucide="${field.labelAction.icon || 'settings'}" class="w-4 h-4"></i>
                </button>
            `;
        }

        const disabledAttr = field.disabled ? 'disabled' : '';
        const disabledClass = field.disabled ? 'opacity-50' : '';

        return `
            <div class="property-label-row">
                <label class="property-label" for="${fieldId}">${field.label}</label>
                ${labelActionHtml}
            </div>
            <textarea 
                id="${fieldId}"
                class="form-textarea ${disabledClass}"
                data-field-id="${field.id}"
                data-field-type="textarea"
                data-field="text"
                placeholder="${field.placeholder || ''}"
                rows="${field.rows || 4}"
                ${disabledAttr}
            >${Utils.escapeHtml(value || field.default || '')}</textarea>
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render number field
     */
    renderNumberField(field, value, fieldId) {
        const min = field.min !== undefined ? `min="${field.min}"` : '';
        const max = field.max !== undefined ? `max="${field.max}"` : '';
        const step = field.step !== undefined ? `step="${field.step}"` : '';

        return `
            <label class="property-label" for="${fieldId}">${field.label}</label>
            <input 
                type="number" 
                id="${fieldId}"
                class="form-input"
                data-field-id="${field.id}"
                data-field-type="number"
                placeholder="${field.placeholder || ''}"
                value="${value !== undefined ? value : (field.default || '')}"
                ${min} ${max} ${step}
            >
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render select field
     */
    renderSelectField(field, value, fieldId) {
        const options = (field.options || [])
            .map(opt => {
                const selected = (value || field.default) === opt.value ? 'selected' : '';
                return `<option value="${opt.value}" ${selected}>${opt.label}</option>`;
            })
            .join('');

        return `
            <label class="property-label" for="${fieldId}">${field.label}</label>
            <select 
                id="${fieldId}"
                class="form-select"
                data-field-id="${field.id}"
                data-field-type="select"
            >
                ${options}
            </select>
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render multiselect field (checkboxes style)
     */
    renderMultiselectField(field, value, fieldId) {
        const selectedValues = Array.isArray(value) ? value : (value ? [value] : []);
        const options = field.options || [];

        let checkboxesHtml = '';
        if (options.length === 0) {
            checkboxesHtml = `
                <div class="text-sm text-dark-400 py-2">
                    <i data-lucide="info" class="w-4 h-4 inline mr-1"></i>
                    No options available. Connect social accounts first.
                </div>
            `;
        } else {
            options.forEach(opt => {
                const isChecked = selectedValues.includes(opt.value);
                const iconHtml = opt.icon ? `<i data-lucide="${opt.icon}" class="w-4 h-4 mr-2 opacity-60"></i>` : '';
                checkboxesHtml += `
                    <label class="flex items-center gap-2 p-2 rounded-lg hover:bg-dark-700/50 cursor-pointer ${isChecked ? 'bg-dark-700/30' : ''}">
                        <input type="checkbox" 
                               class="form-checkbox multiselect-option" 
                               data-field-id="${field.id}"
                               data-option-value="${opt.value}"
                               ${isChecked ? 'checked' : ''}>
                        ${iconHtml}
                        <span class="text-sm text-dark-200">${Utils.escapeHtml(opt.label)}</span>
                    </label>
                `;
            });
        }

        return `
            <label class="property-label">${field.label}</label>
            ${field.description ? `<p class="text-xs text-dark-400 mb-2">${field.description}</p>` : ''}
            <div class="multiselect-container border border-dark-600 rounded-lg p-2 max-h-48 overflow-y-auto space-y-1"
                 id="${fieldId}"
                 data-field-id="${field.id}"
                 data-field-type="multiselect">
                ${checkboxesHtml}
            </div>
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render datetime field
     */
    renderDatetimeField(field, value, fieldId) {
        // Format the value for datetime-local input
        let formattedValue = '';
        if (value) {
            const date = new Date(value);
            if (!isNaN(date.getTime())) {
                // Format: YYYY-MM-DDTHH:MM
                formattedValue = date.toISOString().slice(0, 16);
            }
        }

        return `
            <label class="property-label" for="${fieldId}">${field.label}</label>
            <input 
                type="datetime-local" 
                id="${fieldId}"
                class="form-input"
                data-field-id="${field.id}"
                data-field-type="datetime"
                value="${formattedValue}"
            >
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render checkbox field
     */
    renderCheckboxField(field, value, fieldId) {
        const checked = value !== undefined ? value : field.default;

        return `
            <label class="flex items-center gap-3 cursor-pointer">
                <input 
                    type="checkbox" 
                    id="${fieldId}"
                    class="form-checkbox"
                    data-field-id="${field.id}"
                    data-field-type="checkbox"
                    ${checked ? 'checked' : ''}
                >
                <span class="text-sm text-dark-300">${field.label}</span>
            </label>

            ${field.hint ? `<span class="property-hint ml-7">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render slider field
     */
    renderSliderField(field, value, fieldId) {
        const currentValue = value !== undefined ? value : field.default;
        const min = field.min !== undefined ? field.min : 0;
        const max = field.max !== undefined ? field.max : 100;
        const step = field.step !== undefined ? field.step : 1;
        const unit = field.unit || '';

        return `
            <div class="flex items-center justify-between mb-2">
                <label class="property-label mb-0" for="${fieldId}">${field.label}</label>
                <span class="text-sm text-dark-400" id="${fieldId}-value">${currentValue}${unit}</span>
            </div>

            <input 
                type="range" 
                id="${fieldId}"
                class="form-range w-full"
                data-field-id="${field.id}"
                data-field-type="slider"
                data-unit="${unit}"
                min="${min}"
                max="${max}"
                step="${step}"
                value="${currentValue}"
            >
            <div class="flex justify-between text-xs text-dark-500 mt-1">
                <span>${min}${unit}</span>
                <span>${max}${unit}</span>
            </div>


            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render color field
     */
    renderColorField(field, value, fieldId) {
        const currentValue = value || field.default || '#ffffff';

        return `
            <label class="property-label" for="${fieldId}">${field.label}</label>
            <div class="flex items-center gap-2">
                <input 
                    type="color" 
                    id="${fieldId}"
                    class="w-10 h-10 rounded border border-dark-600 cursor-pointer bg-transparent"
                    data-field-id="${field.id}"
                    data-field-type="color"
                    value="${currentValue}"
                >
                <input 
                    type="text" 
                    id="${fieldId}-text"
                    class="form-input flex-1 font-mono uppercase"
                    data-field-id="${field.id}"
                    data-field-type="color-text"
                    value="${currentValue}"
                    maxlength="7"
                >
            </div>
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }

    /**
     * Render file field
     */
    renderFileField(field, value, fieldId) {
        const hasFile = value && (value.name || value.url || value.dataUrl);
        const fileName = value?.name || value?.url?.split('/').pop() || '';

        // Determine media type from accept attribute
        const isImage = field.accept?.includes('image');
        const isVideo = field.accept?.includes('video');
        const isAudio = field.accept?.includes('audio');

        // Get preview URL (from dataUrl, url or CDN)
        let previewUrl = value?.dataUrl || value?.previewUrl || value?.url || '';

        // Build preview HTML based on media type
        let previewHtml = '';
        if (hasFile && previewUrl) {
            if (isImage) {
                previewHtml = `
                    <div class="file-preview mt-2">
                        <img src="${previewUrl}" alt="Preview" class="w-full max-h-40 object-contain rounded-lg bg-dark-800">
                    </div>
                `;
            } else if (isVideo) {
                previewHtml = `
                    <div class="file-preview mt-2">
                        <video src="${previewUrl}" controls class="w-full max-h-40 rounded-lg bg-dark-800"></video>
                    </div>
                `;
            } else if (isAudio) {
                previewHtml = `
                    <div class="file-preview mt-2">
                        <audio src="${previewUrl}" controls class="w-full"></audio>
                    </div>
                `;
            }
        }

        return `
            <label class="property-label">${field.label}</label>
            <div class="file-dropzone" 
                 id="${fieldId}-dropzone"
                 data-field-id="${field.id}"
                 data-accept="${field.accept || '*/*'}">
                ${hasFile ? `
                    <div class="flex items-center gap-2 text-sm">
                        <i data-lucide="${isImage ? 'image' : isVideo ? 'video' : isAudio ? 'music' : 'file'}" class="w-5 h-5 text-dark-400"></i>
                        <span class="text-dark-300 truncate max-w-[150px]">${Utils.escapeHtml(fileName)}</span>
                        <button type="button" class="file-remove-btn p-1 hover:text-red-400" data-field-id="${field.id}">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                ` : `

                    <div class="file-dropzone-icon">
                        <i data-lucide="upload-cloud" class="w-8 h-8"></i>
                    </div>
                    <p class="file-dropzone-text">
                        <strong>Click to upload</strong> or drag and drop
                    </p>
                    <p class="text-xs text-dark-500 mt-1">${this.getAcceptDescription(field.accept)}</p>
                `}

            </div>
            ${previewHtml}
            <input 
                type="file" 
                id="${fieldId}"
                class="hidden"
                data-field-id="${field.id}"
                data-field-type="file"
                accept="${field.accept || '*/*'}"
            >
            ${field.hint ? `<span class="property-hint">${field.hint}</span>` : ''}
        `;
    }


    /**
     * Get human-readable accept description
     */
    getAcceptDescription(accept) {
        if (!accept) return 'Any file';

        const descriptions = {
            'image/*': 'PNG, JPG, GIF, WebP',
            'video/*': 'MP4, WebM, MOV',
            'audio/*': 'MP3, WAV, OGG'
        };

        return descriptions[accept] || accept;
    }

    /**
     * Render status section
     */
    renderStatusSection(node) {
        const statusColors = {
            'idle': 'text-dark-400',
            'pending': 'text-yellow-400',
            'processing': 'text-purple-400',

            'completed': 'text-green-400',
            'error': 'text-red-400'
        };

        const statusIcons = {
            'idle': 'circle',
            'pending': 'clock',
            'processing': 'loader-2',
            'completed': 'check-circle',
            'error': 'alert-circle'
        };

        const status = node.status || 'idle';
        const color = statusColors[status] || statusColors.idle;
        const icon = statusIcons[status] || statusIcons.idle;
        const animate = status === 'processing' ? 'animate-spin' : '';

        let html = `
            <div class="property-group mt-4 pt-4 border-t border-dark-700">
                <div class="property-group-title">
                    <i data-lucide="activity" class="w-4 h-4"></i>
                    <span>Status</span>
                </div>
                <div class="flex items-center gap-2 ${color}">
                    <i data-lucide="${icon}" class="w-4 h-4 ${animate}"></i>
                    <span class="capitalize">${status}</span>
                </div>
        `;

        if (node.error) {
            html += `
                <div class="mt-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg text-sm text-red-400">
                    ${Utils.escapeHtml(node.error)}
                </div>
            `;
        }

        html += '</div>';

        return html;
    }

    /**
     * Render preview section
     */
    renderPreviewSection(node, definition) {
        const outputKey = Object.keys(node.outputs)[0];
        const outputValue = node.outputs[outputKey];

        if (!outputValue) return '';

        let previewHtml = '';

        switch (definition.preview?.type) {
            case 'image':
                previewHtml = `<img src="${outputValue}" alt="Output" class="w-full rounded-lg">`;
                break;
            case 'video':
                previewHtml = `<video src="${outputValue}" controls class="w-full rounded-lg"></video>`;
                break;
            case 'audio':
                previewHtml = `<audio src="${outputValue}" controls class="w-full"></audio>`;
                break;
            default:
                previewHtml = `<p class="text-sm text-dark-400">Output: ${Utils.escapeHtml(String(outputValue).slice(0, 100))}</p>`;
        }


        return `
            <div class="property-group mt-4 pt-4 border-t border-dark-700">
                <div class="property-group-title">
                    <i data-lucide="eye" class="w-4 h-4"></i>
                    <span>Preview</span>
                </div>
                <div class="preview-container">
                    ${previewHtml}
                </div>
                <button class="btn-secondary w-full mt-2" onclick="window.open('${outputValue}', '_blank')">
                    <i data-lucide="external-link" class="w-4 h-4"></i>
                    Open in New Tab
                </button>
            </div>
        `;
    }

    /**
     * Setup field event listeners
     */
    setupFieldListeners(nodeId) {
        // Text and textarea inputs (debounced)
        this.content.querySelectorAll('input[data-field-type="text"], textarea[data-field-type="textarea"]').forEach(input => {
            input.addEventListener('input', (e) => {
                this.debouncedUpdate(nodeId, e.target.dataset.fieldId, e.target.value);
            });
        });

        // Number inputs
        this.content.querySelectorAll('input[data-field-type="number"]').forEach(input => {
            input.addEventListener('change', (e) => {
                let value = e.target.value === '' ? null : parseFloat(e.target.value);

                // Enforce min/max limits
                if (value !== null) {
                    const min = parseFloat(e.target.min);
                    const max = parseFloat(e.target.max);

                    if (!isNaN(min) && value < min) {
                        value = min;
                        e.target.value = min;
                    }
                    if (!isNaN(max) && value > max) {
                        value = max;
                        e.target.value = max;
                    }
                }

                this.updateNodeField(nodeId, e.target.dataset.fieldId, value);
            });
        });

        // Select inputs
        this.content.querySelectorAll('select[data-field-type="select"]').forEach(select => {
            select.addEventListener('change', (e) => {
                this.updateNodeField(nodeId, e.target.dataset.fieldId, e.target.value);
                // Re-render to handle conditional fields
                this.refreshFields();
            });
        });

        // Checkbox inputs
        this.content.querySelectorAll('input[data-field-type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                this.updateNodeField(nodeId, e.target.dataset.fieldId, e.target.checked);
                // Re-render to handle conditional fields (showIf)
                this.refreshFields();
            });
        });

        // Slider inputs
        this.content.querySelectorAll('input[data-field-type="slider"]').forEach(slider => {
            slider.addEventListener('input', (e) => {
                const unit = e.target.dataset.unit || '';
                const valueDisplay = document.getElementById(`${e.target.id}-value`);
                if (valueDisplay) {
                    valueDisplay.textContent = `${e.target.value}${unit}`;
                }
            });

            slider.addEventListener('change', (e) => {
                const value = parseFloat(e.target.value);
                this.updateNodeField(nodeId, e.target.dataset.fieldId, value);
            });
        });

        // Color inputs
        this.content.querySelectorAll('input[data-field-type="color"]').forEach(colorInput => {
            colorInput.addEventListener('input', (e) => {
                const textInput = document.getElementById(`${e.target.id}-text`);
                if (textInput) {
                    textInput.value = e.target.value.toUpperCase();
                }
            });

            colorInput.addEventListener('change', (e) => {
                this.updateNodeField(nodeId, e.target.dataset.fieldId, e.target.value);
            });
        });

        // Color text inputs
        this.content.querySelectorAll('input[data-field-type="color-text"]').forEach(textInput => {
            textInput.addEventListener('change', (e) => {
                const value = e.target.value;
                if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
                    const colorInput = document.getElementById(e.target.id.replace('-text', ''));
                    if (colorInput) {
                        colorInput.value = value;
                    }
                    this.updateNodeField(nodeId, e.target.dataset.fieldId, value);
                }
            });
        });

        // Multiselect inputs (collect checked values)
        this.content.querySelectorAll('.multiselect-container').forEach(container => {
            const checkboxes = container.querySelectorAll('.multiselect-option');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    const fieldId = checkbox.dataset.fieldId;
                    const selectedValues = [];
                    container.querySelectorAll(`.multiselect-option[data-field-id="${fieldId}"]:checked`).forEach(cb => {
                        selectedValues.push(cb.dataset.optionValue);
                    });
                    this.updateNodeField(nodeId, fieldId, selectedValues);
                });
            });
        });

        // Datetime inputs
        this.content.querySelectorAll('input[data-field-type="datetime"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const value = e.target.value ? new Date(e.target.value).toISOString() : null;
                this.updateNodeField(nodeId, e.target.dataset.fieldId, value);
            });
        });

        // File dropzones
        this.content.querySelectorAll('.file-dropzone').forEach(dropzone => {
            const fieldId = dropzone.dataset.fieldId;
            const fileInput = this.content.querySelector(`input[data-field-id="${fieldId}"][type="file"]`);

            // Click to upload
            dropzone.addEventListener('click', (e) => {
                if (!e.target.closest('.file-remove-btn')) {
                    fileInput?.click();
                }
            });

            // Drag and drop
            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });

            dropzone.addEventListener('dragleave', () => {
                dropzone.classList.remove('dragover');
            });

            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.handleFileUpload(nodeId, fieldId, files[0]);
                }
            });

            // File input change
            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    if (e.target.files.length > 0) {
                        this.handleFileUpload(nodeId, fieldId, e.target.files[0]);
                    }
                });
            }
        });

        // File remove buttons
        this.content.querySelectorAll('.file-remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const fieldId = btn.dataset.fieldId;
                this.updateNodeField(nodeId, fieldId, null);
                this.refreshFields();
            });
        });
    }

    /**
     * Handle file upload
     */
    async handleFileUpload(nodeId, fieldId, file) {
        try {
            // Read file as data URL for preview
            const dataUrl = await Utils.readFileAsDataURL(file);

            const fileData = {
                name: file.name,
                type: file.type,
                size: file.size,
                dataUrl: dataUrl,
                file: file // Keep reference for actual upload
            };

            this.updateNodeField(nodeId, fieldId, fileData);
            this.refreshFields();

            // Show toast
            if (window.Toast) {
                Toast.success('File uploaded', file.name);
            }
        } catch (error) {
            console.error('File upload error:', error);
            if (window.Toast) {
                Toast.error('Upload failed', error.message);
            }
        }
    }

    /**
     * Update node field value
     */
    updateNodeField(nodeId, fieldId, value) {
        if (!this.nodeManager) return;

        this.nodeManager.updateNodeData(nodeId, fieldId, value);

        // Update node in canvas if needed
        this.onNodeUpdate(nodeId, fieldId, value);

        // Re-render the specific node on canvas to update preview
        // This is especially important for input nodes (file/url changes)
        if (this.canvasManager && (fieldId === 'file' || fieldId === 'url' || fieldId === 'source')) {
            this.canvasManager.renderNodes();
        }
    }


    /**
     * Check conditional visibility
     */
    checkCondition(condition, data) {
        // Support function callbacks for dynamic conditions
        if (typeof condition === 'function') {
            return condition(data);
        }

        // Object-based condition checking
        for (const [field, expected] of Object.entries(condition)) {
            const actual = data[field];

            if (Array.isArray(expected)) {
                if (!expected.includes(actual)) {
                    return false;
                }
            } else {
                if (actual !== expected) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Refresh fields (for conditional visibility)
     */
    refreshFields() {
        if (this.currentNode) {
            const node = this.nodeManager?.getNode(this.currentNode.id);
            if (node) {
                this.currentNode = node;
                this.renderFields(node);
            }
        }
    }

    /**
     * Update for external changes
     */
    update() {
        if (this.currentNode && this.isOpen) {
            const node = this.nodeManager?.getNode(this.currentNode.id);
            if (node) {
                this.currentNode = node;
                this.updateNodeInfo(node);
                this.renderFields(node);
            } else {
                this.close();
            }
        }
    }

    /**
     * Check if panel is showing specific node
     */
    isShowingNode(nodeId) {
        return this.isOpen && this.currentNode?.id === nodeId;
    }

    /**
     * Render action buttons for a node
     */
    renderActionButtons(actions, node) {
        if (!actions || actions.length === 0) return '';

        const buttons = actions.map(action => {
            const colorClass = action.color || 'primary';
            return `
                <button type="button" 
                        class="btn-${colorClass} w-full flex items-center justify-center gap-2 node-action-btn"
                        data-action="${action.id}"
                        data-node-id="${node.id}">
                    <i data-lucide="${action.icon || 'play'}" class="w-4 h-4"></i>
                    <span>${action.label}</span>
                </button>
            `;
        }).join('');

        return `
            <div class="property-group mt-4">
                <div class="property-group-title">
                    <i data-lucide="zap" class="w-4 h-4"></i>
                    <span>Actions</span>
                </div>
                <div class="space-y-2">
                    ${buttons}
                </div>
            </div>
        `;
    }

    /**
     * Setup event listeners for action buttons
     */
    setupActionListeners(actions, node) {
        if (!actions) return;

        const definition = this.nodeManager?.getNodeDefinition(node.type);
        if (!definition) return;

        this.content.querySelectorAll('.node-action-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const actionId = btn.dataset.action;
                const action = actions.find(a => a.id === actionId);

                if (action && typeof action.handler === 'function') {
                    // Create update field helper
                    const updateField = (fieldId, value) => {
                        const currentNode = this.nodeManager?.getNode(node.id);
                        if (currentNode) {
                            currentNode.data[fieldId] = value;
                            this.nodeManager?.updateNode(node.id, { data: currentNode.data });
                        }
                    };

                    try {
                        await action.handler(this.content, node.data, updateField);
                    } catch (error) {
                        console.error('Action error:', error);
                    }
                }
            });
        });
    }

    /**
     * Setup label action buttons (like AI enhance on textarea)
     */
    setupLabelActions(definition, node) {
        if (!definition.fields) return;

        definition.fields.forEach(field => {
            if (!field.labelAction) return;

            const actionBtn = this.content.querySelector(`.label-action-btn[data-action="${field.labelAction.id}"]`);
            if (!actionBtn) return;

            // Handle AI enhance action specifically
            if (field.labelAction.id === 'enhance') {
                actionBtn.addEventListener('click', (e) => {
                    e.stopPropagation();

                    // Check if dropdown already exists
                    let dropdown = this.content.querySelector('.enhance-dropdown');
                    if (dropdown) {
                        dropdown.remove();
                        return;
                    }

                    // Get OpenRouter settings for system prompts
                    const settings = window.AIKAFLOWTextEnhance?.getSettings() || { systemPrompts: [] };
                    const prompts = settings.systemPrompts || [];

                    // Create dropdown HTML
                    let dropdownHtml = `
                        <div class="enhance-dropdown-item" data-prompt-id="">
                            <i data-lucide="sparkles" class="w-4 h-4"></i>
                            <span>Default Enhancement</span>
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
                    dropdown = document.createElement('div');
                    dropdown.className = 'enhance-dropdown';
                    dropdown.innerHTML = dropdownHtml;

                    // Position relative to button
                    const labelRow = actionBtn.closest('.property-label-row');
                    if (labelRow) {
                        labelRow.style.position = 'relative';
                        labelRow.appendChild(dropdown);
                    } else {
                        this.content.appendChild(dropdown);
                    }

                    // Initialize icons
                    if (window.lucide) {
                        lucide.createIcons({ root: dropdown });
                    }

                    // Handle item clicks
                    dropdown.querySelectorAll('.enhance-dropdown-item').forEach(item => {
                        item.addEventListener('click', async () => {
                            const promptId = item.dataset.promptId || null;
                            const textarea = this.content.querySelector(`textarea[data-field-id="${field.id}"]`);
                            const text = textarea?.value || node.data[field.id] || '';

                            if (!text.trim()) {
                                alert('Please enter some text first');
                                dropdown.remove();
                                return;
                            }

                            // Show loading state
                            actionBtn.classList.add('loading');
                            const originalIcon = actionBtn.innerHTML;
                            actionBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i>';
                            if (window.lucide) lucide.createIcons({ nodes: [actionBtn] });
                            dropdown.remove();

                            try {
                                // Deduct credits first
                                const deductRes = await fetch('api/credits/deduct.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ action: 'enhance' })
                                });
                                const deductData = await deductRes.json();

                                if (!deductData.success) {
                                    if (deductRes.status === 402) {
                                        throw new Error(`Insufficient credits. Required: ${deductData.required}, Balance: ${deductData.balance}`);
                                    }
                                    throw new Error(deductData.error || 'Failed to deduct credits');
                                }

                                const enhanced = await window.AIKAFLOWTextEnhance.enhance(text, promptId);
                                if (textarea) {
                                    textarea.value = enhanced;
                                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            } catch (error) {
                                alert('Enhancement failed: ' + error.message);
                            } finally {
                                actionBtn.classList.remove('loading');
                                actionBtn.innerHTML = originalIcon;
                                if (window.lucide) lucide.createIcons({ nodes: [actionBtn] });
                            }
                        });
                    });

                    // Close dropdown on outside click
                    const closeDropdown = (evt) => {
                        if (!dropdown.contains(evt.target) && evt.target !== actionBtn) {
                            dropdown.remove();
                            document.removeEventListener('click', closeDropdown);
                        }
                    };
                    setTimeout(() => document.addEventListener('click', closeDropdown), 10);
                });
            }
        });
    }
}

// Make available globally
window.PropertiesPanel = PropertiesPanel;