/**
 * AIKAFLOW API Plugin
 * 
 * Adds workflow API functionality with code samples, schema, and execution status.
 * Inspired by degaus.com API interface.
 */

(function () {
    'use strict';

    const PLUGIN_ID = 'aflow-api';

    /**
     * ApiPlugin class - handles all API functionality
     */
    class ApiPlugin {
        constructor() {
            this.modal = null;
            this.button = null;
            this.isInitialized = false;
            this.activeTab = 'run';
            this.codeLanguage = 'curl';
        }

        /**
         * Initialize the API plugin
         */
        init() {
            if (this.isInitialized) return;

            this.injectButton();
            this.injectModal();
            this.setupEventListeners();

            this.isInitialized = true;
        }

        /**
         * Inject API button into canvas controls
         */
        injectButton() {
            const controlsContainer = document.querySelector('.canvas-controls');
            if (!controlsContainer) {
                console.warn('API Plugin: Canvas controls container not found');
                return;
            }

            if (document.getElementById('btn-api')) return;

            this.button = document.createElement('button');
            this.button.id = 'btn-api';
            this.button.className = 'canvas-control-btn';
            this.button.title = 'Workflow API';
            this.button.innerHTML = '<i data-lucide="terminal" class="w-4 h-4"></i>';

            controlsContainer.appendChild(this.button);

            if (window.lucide) {
                lucide.createIcons({ nodes: [this.button] });
            }
        }

        /**
         * Get base API URL
         */
        getBaseUrl() {
            const apiUrl = window.AIKAFLOW?.apiUrl || '/api';
            if (apiUrl.startsWith('http')) {
                return apiUrl;
            }
            return window.location.origin + apiUrl;
        }

        /**
         * Get current workflow ID
         */
        getWorkflowId() {
            const editor = window.editorInstance || window.editor;
            return editor?.workflowManager?.currentWorkflow?.id;
        }

        /**
         * Inject API modal into the DOM
         */
        injectModal() {
            if (document.getElementById('api-modal')) return;

            const modalHtml = `
                <div id="api-modal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
                    <div class="bg-dark-800 rounded-2xl border border-dark-700 w-full max-w-3xl mx-4 overflow-hidden max-h-[90vh] flex flex-col">
                        <!-- Header -->
                        <div class="p-4 border-b border-dark-700 flex items-center justify-between flex-shrink-0">
                            <div class="flex items-center gap-3">
                                <i data-lucide="terminal" class="w-5 h-5 text-primary-400"></i>
                                <h3 class="font-semibold text-dark-50" data-i18n="api.access_for_workflow">API access for this workflow</h3>
                                <div class="flex items-center gap-2 ml-2">
                                    <code id="api-key-short" class="text-xs bg-dark-700 px-2 py-1 rounded font-mono text-dark-300 cursor-pointer" title="Click to copy">
                                        <span data-i18n="common.loading">Loading...</span>
                                    </code>
                                    <button id="btn-api-copy-key-short" class="text-dark-400 hover:text-dark-200" title="Copy API Key">
                                        <i data-lucide="copy" class="w-3 h-3"></i>
                                    </button>
                                </div>
                            </div>
                            <button id="btn-close-api" class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">
                                <i data-lucide="x" class="w-5 h-5"></i>
                            </button>
                        </div>

                        <p class="px-4 pt-3 text-sm text-dark-400" data-i18n="api.description">Use these endpoints to run this workflow and inspect its input and output schema from your own code.</p>

                        <!-- Tabs -->
                        <div class="flex border-b border-dark-700 flex-shrink-0 px-4 pt-3 api-modal-tabs">
                            <button class="api-tab px-4 py-2 text-sm font-medium flex items-center gap-2" data-tab="run">
                                <i data-lucide="play" class="w-4 h-4"></i> <span data-i18n="api.run_workflow">Run workflow</span>
                            </button>
                            <button class="api-tab active px-4 py-2 text-sm font-medium flex items-center gap-2" data-tab="schema">
                                <i data-lucide="file-json" class="w-4 h-4"></i> <span data-i18n="api.io_schema">Input & output schema</span>
                            </button>
                            <button class="api-tab px-4 py-2 text-sm font-medium flex items-center gap-2" data-tab="status">
                                <i data-lucide="activity" class="w-4 h-4"></i> <span data-i18n="api.run_status">Run status</span>
                            </button>
                        </div>

                        <!-- Content -->
                        <div class="p-4 overflow-y-auto custom-scrollbar flex-1">
                            
                            <!-- Run Workflow Tab -->
                            <div id="api-tab-run" class="api-tab-content hidden">
                                <p class="text-sm text-dark-400 mb-4">Start a new run for this workflow with optional input overrides.</p>
                                
                                <!-- Code Language -->
                                <div class="flex gap-2 mb-3">
                                    <button class="api-lang-btn active" data-lang="curl">cURL</button>
                                    <button class="api-lang-btn" data-lang="javascript">JavaScript</button>
                                    <button class="api-lang-btn" data-lang="python">Python</button>
                                </div>

                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-dark-400 uppercase tracking-wider">Run Workflow</span>
                                        <button id="btn-api-copy-run" class="text-dark-400 hover:text-dark-200" title="Copy">
                                            <i data-lucide="copy" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <pre id="api-code-run" class="bg-dark-900 p-4 rounded-lg overflow-x-auto text-sm font-mono text-dark-200 border border-dark-700 whitespace-pre-wrap"></pre>
                                </div>

                                <!-- Webhook URL Section -->
                                <div class="p-3 bg-dark-700/50 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium text-dark-300">Webhook URL (optional)</label>
                                    </div>
                                    <input type="text" id="api-webhook-url" class="form-input w-full font-mono text-sm" 
                                        placeholder="https://your-server.com/webhook">
                                    <p class="text-xs text-dark-400 mt-2">Results will be POSTed to this URL when execution completes.</p>
                                </div>
                            </div>

                            <!-- Schema Tab -->
                            <div id="api-tab-schema" class="api-tab-content">
                                <p class="text-sm text-dark-400 mb-4">Fetch the input and output schema for this workflow.</p>
                                
                                <!-- Override Info -->
                                <div class="p-3 bg-dark-700/30 rounded-lg mb-6 border-l-4 border-primary-500">
                                    <p class="text-sm text-dark-300">
                                        <i data-lucide="info" class="w-4 h-4 inline mr-1 text-primary-400"></i>
                                        Each input listed below can be <strong>overridden</strong> by adding an entry to the <code class="text-primary-400">inputs</code> array in the POST request. 
                                        Use the <code class="text-primary-400">nodeId</code> and <code class="text-primary-400">field</code> values to target the correct node or parameter.
                                    </p>
                                </div>

                                <!-- Inputs You Can Override -->
                                <div class="mb-6">
                                    <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-3">Inputs You Can Override</h4>
                                    <div id="api-schema-inputs-list" class="space-y-2">
                                        <!-- Populated dynamically -->
                                    </div>
                                </div>

                                <!-- Final Outputs (Terminal Nodes) -->
                                <div class="mb-6">
                                    <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-3">Final Outputs (Terminal Nodes)</h4>
                                    <p class="text-xs text-dark-500 mb-2">These are the last nodes in each flow - their outputs will be the main results.</p>
                                    <div id="api-schema-outputs-list" class="space-y-2">
                                        <!-- Populated dynamically -->
                                    </div>
                                </div>

                                <!-- All Node Outputs -->
                                <div class="mb-6">
                                    <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-3">All Node Outputs (Detailed)</h4>
                                    <p class="text-xs text-dark-500 mb-2">All nodes that produce outputs during execution.</p>
                                    <div id="api-schema-all-outputs-list" class="space-y-2">
                                        <!-- Populated dynamically -->
                                    </div>
                                </div>

                                <!-- Example Override -->
                                <div>
                                    <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">Example: Override Inputs</h4>
                                    <pre id="api-override-example" class="bg-dark-900 p-4 rounded-lg overflow-x-auto text-sm font-mono text-dark-200 border border-dark-700"></pre>
                                </div>
                            </div>

                            <!-- Status Tab -->
                            <div id="api-tab-status" class="api-tab-content hidden">
                                <p class="text-sm text-dark-400 mb-4">Retrieve status and outputs for a specific run.</p>

                                <!-- Get Run Status API -->
                                <div class="mb-6">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-dark-400 uppercase tracking-wider">Get Run Status</span>
                                        <button id="btn-api-copy-status" class="text-dark-400 hover:text-dark-200" title="Copy">
                                            <i data-lucide="copy" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <pre id="api-code-status" class="bg-dark-900 p-4 rounded-lg overflow-x-auto text-sm font-mono text-dark-200 border border-dark-700"></pre>
                                </div>

                                <!-- Response Format -->
                                <div class="mb-6">
                                    <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider mb-2">Response Format</h4>
                                    <pre id="api-status-response" class="bg-dark-900 p-4 rounded-lg overflow-x-auto text-sm font-mono text-dark-200 border border-dark-700"></pre>
                                </div>

                                <!-- Recent Runs -->
                                <div>
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-xs font-medium text-dark-400 uppercase tracking-wider">Recent API Runs</h4>
                                        <button id="btn-api-refresh-status" class="btn-secondary px-3 py-1 text-xs">
                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Refresh
                                        </button>
                                    </div>
                                    <div id="api-status-list" class="space-y-2">
                                        <div class="text-center py-4 text-dark-400">
                                            <p class="text-sm">Loading...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer with API Key -->
                        <div class="p-4 border-t border-dark-700 bg-dark-900/50 flex-shrink-0">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="text-sm text-dark-400" data-i18n="api.your_api_key">Your API Key:</span>
                                    <input type="password" id="api-key-display" class="form-input font-mono text-sm w-64" readonly>
                                    <button id="btn-api-copy-key" class="btn-secondary px-3" title="Copy">
                                        <i data-lucide="copy" class="w-4 h-4"></i>
                                    </button>
                                    <button id="btn-api-toggle-key" class="btn-secondary px-3" title="Show/Hide">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                </div>
                                <button id="btn-api-regenerate" class="text-xs text-red-400 hover:text-red-300" data-i18n="api.regenerate_key">
                                    Regenerate Key
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            this.modal = document.getElementById('api-modal');

            this.injectStyles();

            if (window.lucide) {
                lucide.createIcons({ root: this.modal });
            }
            // Translate the injected modal
            if (window.I18n?.translatePage) window.I18n.translatePage();
        }

        /**
         * Inject CSS styles for the modal
         */
        injectStyles() {
            if (document.getElementById('api-plugin-styles')) return;

            const styles = document.createElement('style');
            styles.id = 'api-plugin-styles';
            styles.textContent = `
                .api-tab {
                    color: var(--color-dark-400);
                    border-bottom: 2px solid transparent;
                    transition: all 0.2s;
                }
                .api-tab:hover {
                    color: var(--color-dark-200);
                }
                .api-tab.active {
                    color: var(--color-primary-400);
                    border-bottom-color: var(--color-primary-400);
                }
                /* Light mode support */
                html.light .api-tab.active, 
                body.light-mode .api-tab.active {
                    color: var(--color-primary-600);
                    border-bottom-color: var(--color-primary-600);
                }
                .api-lang-btn {
                    padding: 0.375rem 1rem;
                    font-size: 0.75rem;
                    border-radius: 0.5rem;
                    background: var(--color-dark-700);
                    color: var(--color-dark-300);
                    transition: all 0.2s;
                }
                .api-lang-btn:hover {
                    background: var(--color-dark-600);
                }
                .api-lang-btn.active {
                    background: var(--color-primary-500);
                    color: white;
                }
                .api-input-card {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 0.75rem 1rem;
                    background: var(--color-dark-700);
                    border-radius: 0.5rem;
                    border: 1px solid var(--color-dark-600);
                }
                .api-input-card .input-name {
                    font-weight: 500;
                    color: var(--color-dark-100);
                }
                .api-input-card .input-id {
                    font-size: 0.75rem;
                    color: var(--color-dark-400);
                    font-family: monospace;
                }
                .api-tag {
                    display: inline-flex;
                    align-items: center;
                    padding: 0.25rem 0.5rem;
                    font-size: 0.625rem;
                    border-radius: 0.25rem;
                    font-weight: 500;
                    text-transform: uppercase;
                }
                .api-tag-input { background: rgba(139, 92, 246, 0.2); color: #a78bfa; }
                .api-tag-output { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
                .api-tag-type { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
            `;
            document.head.appendChild(styles);
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Open modal
            document.getElementById('btn-api')?.addEventListener('click', () => {
                this.openModal();
            });

            // Close modal
            document.getElementById('btn-close-api')?.addEventListener('click', () => {
                this.closeModal();
            });

            // Modal backdrop click
            this.modal?.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.closeModal();
                }
            });

            // Tab switching
            document.querySelectorAll('.api-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    this.switchTab(tab.dataset.tab);
                });
            });

            // Language switching
            document.querySelectorAll('.api-lang-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    this.switchLanguage(btn.dataset.lang);
                });
            });

            // Copy buttons
            document.getElementById('btn-api-copy-key')?.addEventListener('click', () => this.copyApiKey());
            document.getElementById('btn-api-copy-key-short')?.addEventListener('click', () => this.copyApiKey());
            document.getElementById('btn-api-toggle-key')?.addEventListener('click', () => this.toggleApiKeyVisibility());
            document.getElementById('btn-api-regenerate')?.addEventListener('click', () => this.regenerateApiKey());
            document.getElementById('btn-api-copy-run')?.addEventListener('click', () => this.copyCode('api-code-run'));
            document.getElementById('btn-api-copy-status')?.addEventListener('click', () => this.copyCode('api-code-status'));
            document.getElementById('btn-api-refresh-status')?.addEventListener('click', () => this.loadExecutionStatus());

            // Short API key click to copy
            document.getElementById('api-key-short')?.addEventListener('click', () => this.copyApiKey());

            // Webhook URL change
            document.getElementById('api-webhook-url')?.addEventListener('input', () => {
                this.generateRunCode();
            });
        }

        /**
         * Open the API modal
         */
        openModal() {
            this.modal?.classList.remove('hidden');
            this.loadApiKey();
            this.generateRunCode();
            this.generateSchema();
            this.generateStatusCode();
            if (this.activeTab === 'status') {
                this.loadExecutionStatus();
            }
        }

        /**
         * Close the API modal
         */
        closeModal() {
            this.modal?.classList.add('hidden');
        }

        /**
         * Switch between tabs
         */
        switchTab(tab) {
            this.activeTab = tab;

            document.querySelectorAll('.api-tab').forEach(t => {
                t.classList.toggle('active', t.dataset.tab === tab);
            });

            document.querySelectorAll('.api-tab-content').forEach(content => {
                content.classList.toggle('hidden', content.id !== `api-tab-${tab}`);
            });

            if (tab === 'status') {
                this.loadExecutionStatus();
            }
        }

        /**
         * Switch code language
         */
        switchLanguage(lang) {
            this.codeLanguage = lang;

            document.querySelectorAll('.api-lang-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.lang === lang);
            });

            this.generateRunCode();
        }

        /**
         * Load and display API key
         */
        loadApiKey() {
            const apiKey = window.AIKAFLOW?.user?.api_key || '';

            // Full key input
            const keyInput = document.getElementById('api-key-display');
            if (keyInput) keyInput.value = apiKey;

            // Short display in header
            const shortDisplay = document.getElementById('api-key-short');
            if (shortDisplay && apiKey) {
                shortDisplay.textContent = apiKey.substring(0, 8) + '...' + apiKey.substring(apiKey.length - 4);
            }
        }

        /**
         * Generate Run Workflow code sample
         */
        generateRunCode() {
            const workflowId = this.getWorkflowId();
            const baseUrl = this.getBaseUrl();
            const webhookUrl = document.getElementById('api-webhook-url')?.value || '';

            // Build inputs array from detected input nodes - use first field of each node as example
            const inputNodes = this.getInputNodes();
            const inputs = inputNodes.map(node => {
                const fields = this.getNodeFields(node.type);
                const mainField = fields.length > 0 ? fields[0].id : this.getMainField(node.type);
                return {
                    nodeId: node.id,
                    field: mainField,
                    value: this.getPlaceholderValue(node.type)
                };
            });

            const payload = {
                workflowId: workflowId || '<WORKFLOW_ID>',
                inputs: inputs.length > 0 ? inputs : undefined,
                webhookUrl: webhookUrl || undefined
            };

            // Remove undefined keys
            Object.keys(payload).forEach(key => payload[key] === undefined && delete payload[key]);

            let code = '';
            switch (this.codeLanguage) {
                case 'curl':
                    code = this.generateCurlRun(baseUrl, payload);
                    break;
                case 'javascript':
                    code = this.generateJsRun(baseUrl, payload);
                    break;
                case 'python':
                    code = this.generatePythonRun(baseUrl, payload);
                    break;
            }

            const container = document.getElementById('api-code-run');
            if (container) container.textContent = code;
        }

        generateCurlRun(baseUrl, payload) {
            const payloadStr = JSON.stringify(payload, null, 2);
            return `curl -X POST \\
  -H "X-API-Key: YOUR_API_KEY" \\
  -H "Content-Type: application/json" \\
  "${baseUrl}/workflows/execute" \\
  -d '${payloadStr}'`;
        }

        generateJsRun(baseUrl, payload) {
            const payloadStr = JSON.stringify(payload, null, 2);
            return `const response = await fetch("${baseUrl}/workflows/execute", {
  method: "POST",
  headers: {
    "X-API-Key": "YOUR_API_KEY",
    "Content-Type": "application/json"
  },
  body: JSON.stringify(${payloadStr})
});

const result = await response.json();
// result.executionId - Use this ID to check run status`;
        }

        generatePythonRun(baseUrl, payload) {
            const payloadStr = JSON.stringify(payload, null, 2).replace(/"/g, "'").replace(/'([^']+)':/g, '"$1":');
            return `import requests

response = requests.post(
    "${baseUrl}/workflows/execute",
    headers={
        "X-API-Key": "YOUR_API_KEY",
        "Content-Type": "application/json"
    },
    json=${payloadStr}
)

result = response.json()
# result['executionId'] - Use this ID to check run status`;
        }

        /**
         * Generate Schema information
         */
        generateSchema() {
            const inputNodes = this.getInputNodes();
            const outputNodes = this.getOutputNodes();
            const allOutputNodes = this.getAllOutputCapableNodes();

            // Render Input Cards
            const inputsContainer = document.getElementById('api-schema-inputs-list');
            if (inputsContainer) {
                if (inputNodes.length === 0) {
                    inputsContainer.innerHTML = '<p class="text-sm text-dark-400">No input nodes found. Add nodes to the canvas first, then reopen this modal.</p>';
                } else {
                    inputsContainer.innerHTML = inputNodes.map(node => {
                        const name = node.data?.label || node.data?.flowName || node.name || this.getNodeTypeName(node.type);
                        const typeLabel = this.getTypeLabel(node.type);
                        const fields = this.getNodeFields(node.type);

                        // Generate fields list HTML
                        const fieldsHtml = fields.length > 0
                            ? fields.map(f => `<code class="text-xs bg-dark-600 px-1 rounded">${f.id}</code>`).join(' ')
                            : '<span class="text-dark-500">No fields</span>';

                        return `
                            <div class="api-input-card flex-col items-start gap-2">
                                <div class="flex items-center justify-between w-full">
                                    <div class="input-name">${name}</div>
                                    <div class="flex gap-2">
                                        <span class="api-tag api-tag-input">Input</span>
                                        <span class="api-tag api-tag-type">${typeLabel}</span>
                                    </div>
                                </div>
                                <div class="input-id w-full">nodeId: <code class="text-primary-400">${node.id}</code></div>
                                <div class="text-xs text-dark-400 w-full">
                                    <span class="text-dark-500">Fields:</span> ${fieldsHtml}
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }

            // Render Final Output Cards (Terminal Nodes)
            const outputsContainer = document.getElementById('api-schema-outputs-list');
            if (outputsContainer) {
                if (outputNodes.length === 0) {
                    outputsContainer.innerHTML = '<p class="text-sm text-dark-400">No terminal nodes found. Connect nodes to create a flow.</p>';
                } else {
                    outputsContainer.innerHTML = outputNodes.map(node => {
                        const name = node.data?.label || node.name || this.getNodeTypeName(node.type);
                        const typeLabel = this.getOutputTypeLabel(node.type);

                        return `
                            <div class="api-input-card">
                                <div>
                                    <div class="input-name">${name}</div>
                                    <div class="input-id">nodeId: ${node.id}</div>
                                </div>
                                <div class="flex gap-2">
                                    <span class="api-tag api-tag-output">Final Output</span>
                                    <span class="api-tag api-tag-type">${typeLabel}</span>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }

            // Render All Node Outputs (Processing Nodes)
            const allOutputsContainer = document.getElementById('api-schema-all-outputs-list');
            if (allOutputsContainer) {
                if (allOutputNodes.length === 0) {
                    allOutputsContainer.innerHTML = '<p class="text-sm text-dark-400">No processing nodes found.</p>';
                } else {
                    allOutputsContainer.innerHTML = allOutputNodes.map(node => {
                        const name = node.data?.label || node.name || this.getNodeTypeName(node.type);
                        const typeLabel = this.getOutputTypeLabel(node.type);
                        const isTerminal = outputNodes.some(n => n.id === node.id);

                        return `
                            <div class="api-input-card">
                                <div>
                                    <div class="input-name">${name}</div>
                                    <div class="input-id">nodeId: ${node.id}</div>
                                </div>
                                <div class="flex gap-2">
                                    ${isTerminal ? '<span class="api-tag api-tag-output">Final</span>' : '<span class="api-tag" style="background: rgba(251, 191, 36, 0.2); color: #fbbf24;">Intermediate</span>'}
                                    <span class="api-tag api-tag-type">${typeLabel}</span>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }

            // Generate override example
            const overrideExample = document.getElementById('api-override-example');
            if (overrideExample) {
                const exampleInputs = inputNodes.slice(0, 2).map(node => ({
                    nodeId: node.id,
                    field: this.getMainField(node.type),
                    value: this.getPlaceholderValue(node.type)
                }));

                const example = {
                    workflowId: this.getWorkflowId() || '<WORKFLOW_ID>',
                    inputs: exampleInputs.length > 0 ? exampleInputs : [
                        { nodeId: '<NODE_ID>', field: 'url', value: 'https://example.com/image.jpg' }
                    ]
                };

                overrideExample.textContent = JSON.stringify(example, null, 2);
            }
        }

        /**
         * Generate Status Check code
         */
        generateStatusCode() {
            const baseUrl = this.getBaseUrl();

            // Status check code
            const statusCode = document.getElementById('api-code-status');
            if (statusCode) {
                statusCode.textContent = `curl \\
  -H "X-API-Key: YOUR_API_KEY" \\
  "${baseUrl}/workflows/status?executionId=<EXECUTION_ID>"`;
            }

            // Response format
            const responseFormat = document.getElementById('api-status-response');
            if (responseFormat) {
                const response = {
                    runId: "...",
                    status: "running | completed | failed | canceled",
                    steps: [
                        {
                            name: "Node Name",
                            nodeId: "...",
                            status: "running | completed | failed",
                            outputs: [
                                {
                                    url: "https://...",
                                    mimeType: "video/mp4"
                                }
                            ]
                        }
                    ]
                };
                responseFormat.textContent = JSON.stringify(response, null, 2);
            }
        }

        /**
         * Get all nodes from the editor
         */
        getAllNodes() {
            const editor = window.editorInstance || window.editor;

            // Try nodeManager.getAllNodes() first (most reliable)
            if (editor?.nodeManager?.getAllNodes) {
                return editor.nodeManager.getAllNodes();
            }

            // Try nodeManager.nodes Map
            if (editor?.nodeManager?.nodes instanceof Map) {
                return Array.from(editor.nodeManager.nodes.values());
            }

            // Fallback to workflow data
            if (editor?.workflowManager?.currentWorkflow?.nodes) {
                return editor.workflowManager.currentWorkflow.nodes;
            }

            return [];
        }

        /**
         * Get all connections from the editor
         */
        getAllConnections() {
            const editor = window.editorInstance || window.editor;

            // Try connectionManager.connections Map
            if (editor?.connectionManager?.connections instanceof Map) {
                return Array.from(editor.connectionManager.connections.values());
            }

            // Fallback to workflow data
            if (editor?.workflowManager?.currentWorkflow?.connections) {
                return editor.workflowManager.currentWorkflow.connections;
            }

            return [];
        }

        /**
         * Get input nodes from workflow - nodes that have configurable fields
         */
        getInputNodes() {
            const nodes = this.getAllNodes();

            // Input types that have overridable fields
            const inputTypes = ['image-input', 'video-input', 'audio-input', 'text-input', 'manual-trigger'];

            // Get explicit input type nodes
            const explicitInputs = nodes.filter(n => inputTypes.includes(n.type));

            // Also include any node that has fields that can be overridden (like plugin nodes)
            const pluginInputs = nodes.filter(n => {
                // Skip already included
                if (inputTypes.includes(n.type)) return false;

                // Check if node has input data fields
                const def = this.getNodeDefinition(n.type);
                if (def?.fields && def.fields.length > 0) {
                    // Has configurable fields
                    return true;
                }
                return false;
            });

            return [...explicitInputs, ...pluginInputs];
        }

        /**
         * Get node definition
         */
        getNodeDefinition(type) {
            // Check global NodeDefinitions
            if (window.NodeDefinitions && window.NodeDefinitions[type]) {
                return window.NodeDefinitions[type];
            }

            // Check plugin definitions
            const editor = window.editorInstance || window.editor;
            if (editor?.nodeManager?.getNodeDefinition) {
                return editor.nodeManager.getNodeDefinition(type);
            }

            return null;
        }

        /**
         * Get output nodes from workflow (nodes with no outgoing connections - terminal nodes)
         */
        getOutputNodes() {
            const nodes = this.getAllNodes();
            const connections = this.getAllConnections();

            // Find nodes that have outgoing connections
            // Connection structure: { from: { nodeId, portId, type }, to: { nodeId, portId, type } }
            const nodesWithOutgoing = new Set();
            connections.forEach(c => {
                // Handle nested structure: c.from.nodeId
                const sourceId = c.from?.nodeId || c.sourceId || c.fromNodeId;
                if (sourceId) nodesWithOutgoing.add(sourceId);
            });

            // Terminal nodes = nodes with no outgoing connections (except triggers and input nodes)
            const excludeTypes = ['manual-trigger', 'image-input', 'video-input', 'audio-input', 'text-input'];
            const terminalNodes = nodes.filter(n =>
                !nodesWithOutgoing.has(n.id) && !excludeTypes.includes(n.type)
            );

            return terminalNodes;
        }

        /**
         * Get all nodes that can produce output (for detailed view)
         */
        getAllOutputCapableNodes() {
            const nodes = this.getAllNodes();

            // Exclude trigger nodes and input-only nodes
            const excludeTypes = ['manual-trigger', 'image-input', 'video-input', 'audio-input', 'text-input'];

            return nodes.filter(n => !excludeTypes.includes(n.type));
        }

        getNodeTypeName(type) {
            const names = {
                'image-input': 'Image Input',
                'video-input': 'Video Input',
                'audio-input': 'Audio Input',
                'text-input': 'Text Input',
                'manual-trigger': 'Start Flow'
            };
            return names[type] || type;
        }

        getMainField(type) {
            const fields = {
                'image-input': 'url',
                'video-input': 'url',
                'audio-input': 'url',
                'text-input': 'text',
                'manual-trigger': 'flowName'
            };
            return fields[type] || 'value';
        }

        /**
         * Get all configurable fields for a node type
         */
        getNodeFields(type) {
            const def = this.getNodeDefinition(type);
            if (def?.fields && Array.isArray(def.fields)) {
                return def.fields;
            }

            // Fallback for known types
            const defaultFields = {
                'image-input': [{ id: 'url', label: 'Image URL' }, { id: 'source', label: 'Source' }],
                'video-input': [{ id: 'url', label: 'Video URL' }, { id: 'source', label: 'Source' }],
                'audio-input': [{ id: 'url', label: 'Audio URL' }, { id: 'source', label: 'Source' }],
                'text-input': [{ id: 'text', label: 'Text' }],
                'manual-trigger': [{ id: 'flowName', label: 'Flow Name' }, { id: 'priority', label: 'Priority' }]
            };

            return defaultFields[type] || [];
        }

        getTypeLabel(type) {
            const labels = {
                'image-input': 'Image',
                'video-input': 'Video',
                'audio-input': 'Audio',
                'text-input': 'Text',
                'manual-trigger': 'Trigger'
            };
            return labels[type] || 'Data';
        }

        getOutputTypeLabel(type) {
            if (type.includes('video') || type.includes('i2v')) return 'Video';
            if (type.includes('image') || type.includes('t2i')) return 'Image';
            if (type.includes('audio')) return 'Audio';
            return 'Output';
        }

        getPlaceholderValue(type) {
            const values = {
                'image-input': 'https://example.com/image.jpg',
                'video-input': 'https://example.com/video.mp4',
                'audio-input': 'https://example.com/audio.mp3',
                'text-input': 'Your text prompt here',
                'manual-trigger': 'Flow 1'
            };
            return values[type] || 'value';
        }

        /**
         * Load execution status
         */
        async loadExecutionStatus() {
            const container = document.getElementById('api-status-list');
            if (!container) return;

            container.innerHTML = `
                <div class="text-center py-4 text-dark-400">
                    <i data-lucide="loader" class="w-5 h-5 mx-auto mb-2 animate-spin"></i>
                    <p class="text-sm">Loading executions...</p>
                </div>
            `;
            if (window.lucide) lucide.createIcons({ root: container });

            try {
                const workflowId = this.getWorkflowId();

                if (!workflowId) {
                    container.innerHTML = '<p class="text-sm text-dark-400 text-center py-4">Save workflow to see API executions</p>';
                    return;
                }

                const response = await fetch(`${this.getBaseUrl()}/workflows/history.php?id=${workflowId}&limit=10`);
                const data = await response.json();

                if (!data.success || !data.executions?.length) {
                    container.innerHTML = '<p class="text-sm text-dark-400 text-center py-4">No API executions yet</p>';
                    return;
                }

                container.innerHTML = data.executions.map(exec => {
                    const statusColors = {
                        'pending': 'bg-yellow-500/20 text-yellow-400',
                        'running': 'bg-blue-500/20 text-blue-400',
                        'completed': 'bg-green-500/20 text-green-400',
                        'failed': 'bg-red-500/20 text-red-400'
                    };
                    const statusClass = statusColors[exec.status] || 'bg-dark-600 text-dark-300';
                    const time = this.formatRelativeTime(exec.started_at);

                    return `
                        <div class="api-input-card">
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-mono text-dark-400">#${exec.id}</span>
                                <span class="api-tag ${statusClass}">${exec.status}</span>
                            </div>
                            <span class="text-xs text-dark-400">${time}</span>
                        </div>
                    `;
                }).join('');

            } catch (error) {
                console.error('Failed to load executions:', error);
                container.innerHTML = '<p class="text-sm text-red-400 text-center py-4">Failed to load executions</p>';
            }
        }

        formatRelativeTime(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
            return `${Math.floor(diff / 86400)}d ago`;
        }

        /**
         * Copy API key to clipboard
         */
        copyApiKey() {
            const keyInput = document.getElementById('api-key-display');
            if (!keyInput?.value) {
                Toast?.warning('No API key to copy');
                return;
            }

            navigator.clipboard.writeText(keyInput.value).then(() => {
                Toast?.success('API key copied!');
            }).catch(() => {
                keyInput.select();
                document.execCommand('copy');
                Toast?.success('API key copied!');
            });
        }

        /**
         * Copy code from element
         */
        copyCode(elementId) {
            const element = document.getElementById(elementId);
            if (!element?.textContent) {
                Toast?.warning('No code to copy');
                return;
            }

            navigator.clipboard.writeText(element.textContent).then(() => {
                Toast?.success('Code copied!');
            }).catch(() => {
                Toast?.error('Failed to copy');
            });
        }

        /**
         * Toggle API key visibility
         */
        toggleApiKeyVisibility() {
            const keyInput = document.getElementById('api-key-display');
            const toggleBtn = document.getElementById('btn-api-toggle-key');

            if (keyInput) {
                const isPassword = keyInput.type === 'password';
                keyInput.type = isPassword ? 'text' : 'password';

                if (toggleBtn) {
                    toggleBtn.innerHTML = `<i data-lucide="${isPassword ? 'eye-off' : 'eye'}" class="w-4 h-4"></i>`;
                    if (window.lucide) lucide.createIcons({ nodes: [toggleBtn] });
                }
            }
        }

        /**
         * Regenerate API key
         */
        async regenerateApiKey() {
            if (!confirm('Are you sure? This will invalidate your current API key.')) {
                return;
            }

            try {
                const response = await fetch(`${this.getBaseUrl()}/user/regenerate-api-key.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });

                const data = await response.json();

                if (data.success && data.apiKey) {
                    window.AIKAFLOW.user.api_key = data.apiKey;
                    this.loadApiKey();
                    Toast?.success('API key regenerated!');
                } else {
                    throw new Error(data.error || 'Failed to regenerate');
                }
            } catch (error) {
                Toast?.error(error.message);
            }
        }
    }

    // Initialize plugin when DOM is ready
    function initPlugin() {
        const checkInterval = setInterval(() => {
            if (window.editorInstance || document.querySelector('.canvas-controls')) {
                clearInterval(checkInterval);
                const apiPlugin = new ApiPlugin();
                apiPlugin.init();
                window.AflowApiPlugin = apiPlugin;
            }
        }, 100);

        setTimeout(() => clearInterval(checkInterval), 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlugin);
    } else {
        initPlugin();
    }

})();
