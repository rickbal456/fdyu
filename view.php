<?php
/**
 * AIKAFLOW Viewer
 * Read-only view for shared workflows
 */

require_once __DIR__ . '/includes/auth.php';

$shareId = $_GET['share'] ?? $_GET['s'] ?? null;
$error = null;
$workflow = null;
$workflowData = null;
$isPublic = false;

if ($shareId) {
    require_once __DIR__ . '/includes/PluginManager.php';

    if (!PluginManager::isPluginEnabled('aflow-share')) {
        $error = "Workflow sharing is disabled.";
    } else {
        try {
            $pdo = Database::getInstance();

            // Try fetching from workflow_shares
            try {
                $stmt = $pdo->prepare("SELECT * FROM workflow_shares WHERE id = :id");
                $stmt->execute([':id' => $shareId]);
                $share = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($share) {
                    $workflowData = json_decode($share['workflow_data'], true);
                    $isPublic = (bool) $share['is_public'];

                    // Increment views
                    $pdo->prepare("UPDATE workflow_shares SET views = views + 1 WHERE id = :id")->execute([':id' => $shareId]);
                }
            } catch (PDOException $e) {
                // Ignore (table might not exist)
            }

            if (!$workflowData) {
                // Fallback to workflows table
                if (is_numeric($shareId)) {
                    $stmt = $pdo->prepare("SELECT * FROM workflows WHERE id = :id");
                    $stmt->execute([':id' => $shareId]);
                    $wf = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($wf) {
                        $workflowData = json_decode($wf['json_data'], true);
                        $isPublic = (bool) $wf['is_public'];
                    }
                }
            }

            if (!$workflowData) {
                $error = "Workflow not found.";
            } elseif (!$isPublic && !Auth::check()) {
                // If private, must be logged in and maybe owner (but for now just logged in check or simply block)
                // Stricter: if not public and user_id != current_user, block.
                // For simple share link, usually link implies access unless explicitly restricted.
                // But if is_public=0, maybe it means broken link or private.
                // We'll assume is_public=0 means "Private" and requires owner.
                $ownerId = $share['user_id'] ?? $wf['user_id'] ?? 0;
                $currentUser = Auth::user();
                if (!$currentUser || $currentUser['id'] != $ownerId) {
                    $error = "This workflow is private.";
                }
            }

        } catch (Exception $e) {
            $error = "Error loading workflow: " . $e->getMessage();
        }
    }
} else {
    $error = "No workflow specified.";
}

// User data (might be guest)
$user = Auth::user() ?? ['id' => 0, 'username' => 'Guest', 'email' => ''];
// Need CSRF token even for guests if running workflows is allowed (which it is for demo maybe?)
// Auth::generateCsrfToken() usually requires session. initSession calls start_session.
$csrfToken = $_SESSION['csrf_token'] ?? '';
// If no CSRF token (guest without deep session), generate one? 
// Auth::initialization handles it.

?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($workflowData['workflow']['name'] ?? $workflowData['name'] ?? 'Shared Workflow') ?> -
        AIKAFLOW Viewer</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            50: 'var(--color-dark-50)',
                            100: 'var(--color-dark-100)',
                            200: 'var(--color-dark-200)',
                            300: 'var(--color-dark-300)',
                            400: 'var(--color-dark-400)',
                            500: 'var(--color-dark-500)',
                            600: 'var(--color-dark-600)',
                            700: 'var(--color-dark-700)',
                            800: 'var(--color-dark-800)',
                            900: 'var(--color-dark-900)',
                            950: 'var(--color-dark-950)',
                        },
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/editor.css">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        /* Viewer specific overrides */
        #sidebar-left {
            display: none !important;
        }

        .toolbar-btn:not(#btn-theme-toggle) {
            display: none;
        }

        #btn-save,
        #btn-new,
        #btn-open,
        #btn-run,
        #btn-history {
            display: none !important;
        }

        /* Hide API key fields in viewer mode */
        body.read-only-mode .node-field-group[data-field-type="api-key"],
        body.read-only-mode .node-field-group[data-field-id="apiKey"],
        body.read-only-mode .api-key-field,
        body.read-only-mode [data-field-id*="apiKey"],
        body.read-only-mode [data-field-id*="api_key"] {
            display: none !important;
        }

        /* Read-only Mode Strictness */
        body.read-only-mode .workflow-node {
            pointer-events: none !important;
        }

        body.read-only-mode .node-field-input,
        body.read-only-mode .node-field-textarea,
        body.read-only-mode .node-field-select {
            pointer-events: none !important;
            opacity: 0.9;
        }

        body.read-only-mode .connection-delete-btn {
            display: none !important;
        }

        body.read-only-mode .connection-line {
            pointer-events: none !important;
            cursor: default !important;
        }

        /* Ensure canvas controls still work */
        body.read-only-mode .canvas-control-btn {
            pointer-events: auto !important;
        }

        /* Add indicator */
        .read-only-ribbon {
            position: absolute;
            top: 20px;
            right: -20px;
            background: #eab308;
            color: #000;
            width: 100px;
            text-align: center;
            transform: rotate(45deg);
            font-size: 10px;
            font-weight: bold;
            z-index: 99;
            pointer-events: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        /* Loading Screen */
        .viewer-loading-overlay {
            position: fixed;
            inset: 0;
            background: var(--color-dark-950);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            transition: opacity 0.4s ease;
        }

        .viewer-loading-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .viewer-loading-logo {
            width: 4rem;
            height: 4rem;
            background: linear-gradient(135deg, #0ea5e9, #8b5cf6);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }

        .viewer-loading-text {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--color-dark-200);
        }

        .viewer-loading-progress {
            width: 200px;
            height: 4px;
            background: var(--color-dark-800);
            border-radius: 2px;
            overflow: hidden;
        }

        .viewer-loading-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #0ea5e9, #8b5cf6);
            width: 0%;
            transition: width 0.3s ease;
        }

        .viewer-loading-status {
            font-size: 0.75rem;
            color: var(--color-dark-500);
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        /* Clone Confirmation Modal */
        .clone-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .clone-modal-overlay.hidden {
            display: none;
        }

        .clone-modal {
            background: var(--color-dark-900);
            border: 1px solid var(--color-dark-700);
            border-radius: 1rem;
            padding: 1.5rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }

        .clone-modal-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--color-dark-50);
            margin-bottom: 0.75rem;
        }

        .clone-modal-message {
            font-size: 0.875rem;
            color: var(--color-dark-400);
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .clone-modal-buttons {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
    </style>
</head>

<body class="bg-dark-950 text-dark-50 font-sans h-screen overflow-hidden select-none read-only-mode">

    <?php if ($error): ?>
        <div class="flex items-center justify-center h-full flex-col gap-4">
            <div class="w-16 h-16 rounded-full bg-red-500/20 text-red-500 flex items-center justify-center">
                <i data-lucide="alert-circle" class="w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-semibold"><?= htmlspecialchars($error) ?></h1>
            <a href="index.php" class="text-primary-400 hover:text-primary-300">Go to Editor</a>
        </div>
    <?php else: ?>

        <div class="flex h-full flex-col">
            <!-- Top Navigation -->
            <header
                class="h-14 bg-dark-900 border-b border-dark-700 flex items-center justify-between px-4 flex-shrink-0 z-50">
                <!-- Left: Logo & Title -->
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 bg-gradient-to-br from-primary-500 to-purple-600 rounded-lg flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-primary-500/20">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </div>
                        <span
                            class="font-bold text-lg tracking-tight bg-gradient-to-r from-dark-50 to-dark-400 bg-clip-text text-transparent hidden sm:block">
                            AIKAFLOW <span class="text-xs font-normal text-dark-400 ml-1">Viewer</span>
                        </span>
                    </div>

                    <div class="h-6 w-px bg-dark-700 mx-2 hidden sm:block"></div>

                    <div class="flex items-center gap-2 min-w-0 flex-1 sm:flex-initial">
                        <span
                            class="font-medium text-sm text-dark-200 truncate max-w-[120px] sm:max-w-none"><?= htmlspecialchars($workflowData['workflow']['name'] ?? $workflowData['name'] ?? 'Shared Workflow') ?></span>
                    </div>
                </div>

                <!-- Center: Toolbar (Hidden in view mode via CSS) -->
                <div class="flex items-center gap-1 bg-dark-800 p-1 rounded-lg border border-dark-700 hidden">
                    <!-- Run hidden -->
                </div>

                <!-- Right: Actions -->
                <div class="flex items-center gap-3">
                    <button id="btn-theme-toggle" class="toolbar-btn" title="Toggle Theme">
                        <i data-lucide="moon" class="w-4 h-4"></i>
                    </button>

                    <button id="btn-clone-workflow" class="btn-primary px-3 py-1.5 text-sm flex items-center gap-2"
                        title="Clone Workflow">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                        <span class="hidden sm:inline">Clone Workflow</span>
                    </button>
                </div>
            </header>

            <!-- Main Workspace -->
            <div class="flex-1 flex overflow-hidden relative">
                <!-- Canvas -->
                <main class="flex-1 relative overflow-hidden bg-dark-950" id="canvas-container">
                    <div id="canvas-grid" class="absolute inset-0 pointer-events-none"></div>
                    <svg id="connections-layer" class="absolute inset-0 overflow-visible"
                        style="z-index: 10; pointer-events: none;">
                        <g id="connections-group" style="pointer-events: auto;"></g>
                    </svg>
                    <div id="nodes-container" class="absolute inset-0 z-20"></div>

                    <!-- Canvas Controls -->
                    <div class="absolute bottom-4 left-4 flex items-center gap-2 z-30">
                        <button id="btn-toggle-grid" class="canvas-control-btn active" title="Toggle Grid">
                            <i data-lucide="grid-3x3" class="w-4 h-4"></i>
                        </button>
                        <button id="btn-toggle-snap" class="canvas-control-btn active" title="Toggle Snap">
                            <i data-lucide="magnet" class="w-4 h-4"></i>
                        </button>
                    </div>

                    <div class="overflow-hidden absolute top-0 right-0 w-20 h-20 pointer-events-none">
                        <div class="read-only-ribbon">READ ONLY</div>
                    </div>
                </main>

                <!-- Properties Panel -->
                <aside id="sidebar-right"
                    class="w-80 bg-dark-900 border-l border-dark-700 flex flex-col flex-shrink-0 z-40 hidden">
                    <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                        <h3 class="font-semibold text-dark-50">Properties</h3>
                        <button id="btn-close-properties" class="p-1 text-gray-400 hover:text-dark-50 rounded"><i
                                data-lucide="x" class="w-5 h-5"></i></button>
                    </div>
                    <div id="properties-content" class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-4"></div>
                </aside>

                <!-- Gallery Panel -->
                <aside id="gallery-panel"
                    class="fixed right-0 top-0 h-full w-96 bg-dark-900 border-l border-dark-700 flex flex-col z-50 transform translate-x-full transition-transform duration-300">
                    <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i data-lucide="images" class="w-5 h-5 text-primary-400"></i>
                            <h3 class="font-semibold text-dark-50">Generated Content</h3>
                        </div>
                        <button id="btn-close-gallery" class="p-1 text-gray-400 hover:text-dark-50 rounded"><i
                                data-lucide="x" class="w-5 h-5"></i></button>
                    </div>
                    <div id="gallery-content" class="flex-1 overflow-y-auto custom-scrollbar p-4">
                        <div id="gallery-grid" class="grid grid-cols-2 gap-3"></div>
                        <div id="gallery-empty" class="text-center py-12">
                            <i data-lucide="image-off" class="w-12 h-12 text-dark-600 mx-auto mb-3"></i>
                            <p class="text-dark-500 text-sm">No generated content yet</p>
                        </div>
                    </div>
                </aside>

                <!-- History Panel (Read-only view usually doesn't show history unless we want to show session history) -->
                <aside id="history-panel"
                    class="fixed right-0 top-0 h-full w-96 bg-dark-900 border-l border-dark-700 flex flex-col z-50 transform translate-x-full transition-transform duration-300">
                    <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i data-lucide="history" class="w-5 h-5 text-blue-400"></i>
                            <h3 class="font-semibold text-dark-50">Session History</h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="btn-refresh-history" class="p-1 text-gray-400 hover:text-dark-50 rounded"
                                title="Refresh history">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                            </button>
                            <button id="btn-close-history" class="p-1 text-gray-400 hover:text-dark-50 rounded">
                                <i data-lucide="x" class="w-5 h-5"></i>
                            </button>
                        </div>
                    </div>
                    <!-- History Tabs -->
                    <div class="px-4 py-2 border-b border-dark-700 flex gap-2">
                        <button class="history-tab active" data-tab="current">
                            <i data-lucide="play-circle" class="w-4 h-4"></i>
                            <span>Current</span>
                        </button>
                        <button class="history-tab" data-tab="completed">
                            <i data-lucide="check-circle" class="w-4 h-4"></i>
                            <span>Completed</span>
                        </button>
                        <button class="history-tab" data-tab="aborted">
                            <i data-lucide="octagon" class="w-4 h-4"></i>
                            <span>Aborted</span>
                        </button>
                    </div>
                    <div id="history-content" class="flex-1 overflow-y-auto custom-scrollbar p-4">
                        <div id="history-list" class="space-y-3"></div>
                        <div id="history-empty" class="text-center py-12">
                            <i data-lucide="clock" class="w-12 h-12 text-dark-600 mx-auto mb-3"></i>
                            <p class="text-dark-500 text-sm">No run history</p>
                        </div>
                    </div>
                </aside>
                <div class="absolute top-4 right-20 flex items-center gap-2 z-30">
                    <button id="btn-gallery" class="canvas-control-btn" title="View Generated Content">
                        <i data-lucide="images" class="w-4 h-4"></i>
                    </button>
                    <!-- History button hidden in viewer mode via CSS -->
                </div>
            </div>

            <!-- Bottom Status Bar -->
            <footer
                class="h-8 bg-dark-900 border-t border-dark-700 flex items-center justify-between px-4 text-xs text-dark-400 flex-shrink-0 z-50">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></div>
                        <span id="system-status">Viewer Mode</span>
                    </div>
                </div>
            </footer>
        </div>

        <!-- Modals -->
        <div id="execution-modal"
            class="fixed inset-0 bg-black/80 flex items-center justify-center z-[100] hidden backdrop-blur-sm opacity-0 transition-opacity duration-300">
            <div
                class="bg-dark-900 rounded-2xl border border-dark-700 w-full max-w-2xl mx-4 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
                <div class="p-6 border-b border-dark-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-dark-50 flex items-center gap-2">
                        <i data-lucide="play-circle" class="w-5 h-5 text-primary-500"></i>
                        Running Workflow
                    </h3>
                    <button id="btn-close-execution"
                        class="p-2 text-dark-400 hover:text-dark-50 rounded-lg hover:bg-dark-800 transition-colors">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-dark-300">Progress</span>
                            <span id="execution-progress-text" class="text-sm text-gray-400">0%</span>
                        </div>
                        <div class="h-2 bg-dark-700 rounded-full overflow-hidden">
                            <div id="execution-progress-bar"
                                class="h-full bg-gradient-to-r from-primary-500 to-cyan-500 transition-all duration-300"
                                style="width: 0%"></div>
                        </div>
                    </div>
                    <div id="execution-nodes" class="space-y-3"></div>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div id="toast-container" class="fixed bottom-20 right-4 z-[200] space-y-2"></div>

        <!-- Loading Overlay -->
        <div id="viewer-loading-overlay" class="viewer-loading-overlay">
            <div class="viewer-loading-logo">
                <i data-lucide="eye" class="w-8 h-8 text-white"></i>
            </div>
            <div class="viewer-loading-text">Loading Shared Workflow</div>
            <div class="viewer-loading-progress">
                <div id="viewer-loading-progress-bar" class="viewer-loading-progress-bar"></div>
            </div>
            <div id="viewer-loading-status" class="viewer-loading-status">Initializing...</div>
        </div>

        <!-- Clone Confirmation Modal -->
        <div id="clone-modal" class="clone-modal-overlay hidden">
            <div class="clone-modal">
                <div class="clone-modal-title">Clone Workflow</div>
                <div class="clone-modal-message">
                    Are you sure you want to clone this workflow? A copy will be created in your account.
                </div>
                <div class="clone-modal-buttons">
                    <button id="clone-modal-cancel" class="btn-secondary">Cancel</button>
                    <button id="clone-modal-confirm" class="btn-primary">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                        Clone
                    </button>
                </div>
            </div>
        </div>

        <!-- Initialization -->
        <script>
            window.AIKAFLOW = {
                user: <?= json_encode(['id' => $user['id'], 'username' => $user['username']]) ?>,
                csrf: <?= json_encode($csrfToken) ?>,
                apiUrl: './api', // Use relative URL to avoid mixed content issues
                version: '1.0.0',
                sharedWorkflow: <?= json_encode($workflowData) ?>
            };
        </script>

        <script src="assets/js/utils.js"></script>
        <script src="assets/js/nodes.js"></script>
        <script src="assets/js/canvas.js"></script>
        <script src="assets/js/connections.js"></script>
        <script src="assets/js/properties.js"></script>
        <script src="assets/js/workflow.js"></script>
        <script src="assets/js/modals.js"></script>
        <script src="assets/js/api.js"></script>
        <script src="assets/js/plugins.js"></script>
        <script src="assets/js/panels.js"></script>
        <script src="assets/js/editor.js"></script>

        <script>
            // Auto-load shared workflow with loading progress
            document.addEventListener('DOMContentLoaded', () => {
                const loadingOverlay = document.getElementById('viewer-loading-overlay');
                const loadingBar = document.getElementById('viewer-loading-progress-bar');
                const loadingStatus = document.getElementById('viewer-loading-status');

                let loadProgress = 0;

                // Update loading progress
                const updateProgress = (percent, status) => {
                    loadProgress = percent;
                    if (loadingBar) loadingBar.style.width = `${percent}%`;
                    if (loadingStatus) loadingStatus.textContent = status;
                };

                // Hide loading overlay
                const hideLoading = () => {
                    if (loadingOverlay) {
                        loadingOverlay.classList.add('hidden');
                        setTimeout(() => loadingOverlay.remove(), 400);
                    }
                };

                updateProgress(10, 'Loading scripts...');

                // Initialize lucide icons
                if (window.lucide) lucide.createIcons();

                // Track plugin loading progress
                let totalPlugins = 0;
                let loadedPlugins = 0;

                // Listen for plugin load events
                const originalLoadPluginNodes = window.PluginManager?.prototype?.loadPluginNodes;
                if (window.PluginManager && originalLoadPluginNodes) {
                    window.PluginManager.prototype.loadPluginNodes = async function (plugin) {
                        const result = await originalLoadPluginNodes.call(this, plugin);
                        loadedPlugins++;
                        const pluginProgress = 30 + Math.min(50, (loadedPlugins / Math.max(totalPlugins, 1)) * 50);
                        updateProgress(pluginProgress, `Loading plugins... (${loadedPlugins}/${totalPlugins})`);
                        return result;
                    };
                }

                updateProgress(20, 'Initializing editor...');

                const checkEditor = setInterval(() => {
                    if (window.editorInstance && window.editorInstance.isInitialized && window.editorInstance.workflowManager) {
                        clearInterval(checkEditor);

                        updateProgress(80, 'Loading workflow...');

                        if (window.AIKAFLOW.sharedWorkflow) {
                            try {
                                if (!window.AIKAFLOW.sharedWorkflow) throw new Error('No workflow data found');

                                window.editorInstance.workflowManager.deserialize(window.AIKAFLOW.sharedWorkflow);

                                updateProgress(90, 'Rendering nodes...');

                                // Fit view
                                setTimeout(() => {
                                    try {
                                        window.editorInstance.canvasManager?.fitToView();
                                    } catch (err) {
                                        console.warn('Fit to view failed:', err);
                                    }

                                    updateProgress(100, 'Complete!');

                                    // Hide loading after a small delay
                                    setTimeout(hideLoading, 300);
                                }, 100);
                            } catch (e) {
                                console.error('Failed to load shared workflow:', e);
                                if (window.Toast) Toast.error('Failed to load workflow data: ' + e.message);
                                hideLoading();
                            }

                            document.body.classList.add('read-only-mode');

                            // Disable save buttons and other edits visually (redundant to CSS but good backup)
                            const saveBtn = document.getElementById('btn-save');
                            if (saveBtn) saveBtn.style.display = 'none';
                        } else {
                            hideLoading();
                        }
                    }
                }, 100);

                // Timeout after 10 seconds
                setTimeout(() => {
                    clearInterval(checkEditor);
                    hideLoading();
                }, 10000);

                // Clone Modal Logic
                const cloneBtn = document.getElementById('btn-clone-workflow');
                const cloneModal = document.getElementById('clone-modal');
                const cloneModalCancel = document.getElementById('clone-modal-cancel');
                const cloneModalConfirm = document.getElementById('clone-modal-confirm');

                // Show clone confirmation modal
                const showCloneModal = () => {
                    if (cloneModal) {
                        cloneModal.classList.remove('hidden');
                        if (window.lucide) lucide.createIcons({ nodes: [cloneModal] });
                    }
                };

                // Hide clone confirmation modal
                const hideCloneModal = () => {
                    if (cloneModal) cloneModal.classList.add('hidden');
                };

                // Execute clone action
                const executeClone = async () => {
                    hideCloneModal();

                    if (!window.AIKAFLOW.sharedWorkflow) {
                        if (window.Toast) Toast.error('No workflow data to clone');
                        return;
                    }

                    try {
                        const originalText = cloneBtn.innerHTML;
                        cloneBtn.disabled = true;
                        cloneBtn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> <span>Cloning...</span>';
                        if (window.lucide) lucide.createIcons({ nodes: [cloneBtn] });

                        const workflowData = window.AIKAFLOW.sharedWorkflow;
                        const name = "Copy of " + (workflowData.workflow?.name || workflowData.name || 'Shared Workflow');

                        const response = await fetch(window.AIKAFLOW.apiUrl + '/workflows/save.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                name: name,
                                description: workflowData.workflow?.description || workflowData.description || 'Cloned from shared workflow',
                                data: workflowData,
                                isPublic: false
                            })
                        });

                        const result = await response.json();

                        if (result.success && result.workflowId) {
                            if (window.Toast) Toast.success('Workflow cloned successfully!');
                            setTimeout(() => {
                                window.location.href = 'index.php?id=' + result.workflowId;
                            }, 1000);
                        } else {
                            throw new Error(result.error || 'Failed to clone workflow');
                        }

                    } catch (error) {
                        console.error('Clone failed:', error);
                        if (window.Toast) Toast.error(error.message);
                        cloneBtn.disabled = false;
                        cloneBtn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i> <span>Clone Workflow</span>';
                        if (window.lucide) lucide.createIcons({ nodes: [cloneBtn] });
                    }
                };

                if (cloneBtn) {
                    cloneBtn.addEventListener('click', () => {
                        // Check auth - if not logged in, redirect to login
                        if (!window.AIKAFLOW.user || !window.AIKAFLOW.user.id || window.AIKAFLOW.user.id <= 0) {
                            if (window.Toast) Toast.info('Redirecting to login...', 'Please login to clone this workflow');
                            setTimeout(() => {
                                window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
                            }, 1500);
                            return;
                        }

                        // Show confirmation modal for logged-in users
                        showCloneModal();
                    });
                }

                // Clone modal button handlers
                if (cloneModalCancel) {
                    cloneModalCancel.addEventListener('click', hideCloneModal);
                }

                if (cloneModalConfirm) {
                    cloneModalConfirm.addEventListener('click', executeClone);
                }

                // Close modal on backdrop click
                if (cloneModal) {
                    cloneModal.addEventListener('click', (e) => {
                        if (e.target === cloneModal) hideCloneModal();
                    });
                }

                // Close modal on Escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && cloneModal && !cloneModal.classList.contains('hidden')) {
                        hideCloneModal();
                    }
                });

                // Load gallery content from shared workflow outputs (if any)
                setTimeout(() => {
                    if (window.AIKAFLOW.sharedWorkflow?.outputs) {
                        const galleryGrid = document.getElementById('gallery-grid');
                        const galleryEmpty = document.getElementById('gallery-empty');

                        if (galleryGrid && window.AIKAFLOW.sharedWorkflow.outputs.length > 0) {
                            galleryEmpty?.classList.add('hidden');

                            window.AIKAFLOW.sharedWorkflow.outputs.forEach(output => {
                                if (output.url) {
                                    const item = document.createElement('div');
                                    item.className = 'relative rounded-lg overflow-hidden bg-dark-800 aspect-video group cursor-pointer';

                                    if (output.type === 'video') {
                                        item.innerHTML = `
                                            <video src="${output.url}" class="w-full h-full object-cover" poster="${output.thumbnail || ''}"></video>
                                            <div class="absolute inset-0 flex items-center justify-center bg-black/30 group-hover:bg-black/50 transition-colors">
                                                <i data-lucide="play-circle" class="w-10 h-10 text-white"></i>
                                            </div>
                                        `;
                                    } else {
                                        item.innerHTML = `<img src="${output.url}" class="w-full h-full object-cover" alt="Output">`;
                                    }

                                    item.addEventListener('click', () => {
                                        window.open(output.url, '_blank');
                                    });

                                    galleryGrid.appendChild(item);
                                }
                            });

                            if (window.lucide) lucide.createIcons({ nodes: [galleryGrid] });
                        }
                    }
                }, 500);
            });
        </script>

    <?php endif; ?>
</body>

</html>