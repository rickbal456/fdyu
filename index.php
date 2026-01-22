<?php
/**
 * AIKAFLOW - Main Workflow Editor
 * 
 * The primary interface for creating and editing AI video workflows.
 * Requires authentication to access.
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/PluginManager.php';

// Require login - redirects to login.php if not authenticated
Auth::requireLogin();

// Get current user data
$user = Auth::user();
$csrfToken = Auth::generateCsrfToken();

// Get user's workflows count for display
$workflowCount = 0;
try {
    $workflowCount = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM workflows WHERE user_id = ?",
        [$user['id']]
    );
} catch (Exception $e) {
    // Ignore errors
}

// Check if in "login as user" mode (admin impersonating)
Auth::initSession();
$isImpersonating = isset($_SESSION['original_admin_id']);

// Load site settings
$siteSettings = [];
try {
    $rows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings");
    foreach ($rows as $row) {
        $siteSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Use defaults
}
$siteTitle = $siteSettings['site_title'] ?? 'AIKAFLOW';
$headwayWidgetId = $siteSettings['headway_widget_id'] ?? '';
$defaultTheme = $siteSettings['default_theme'] ?? 'dark';
$customFooterJs = $siteSettings['custom_footer_js'] ?? '';
$logoUrlDark = $siteSettings['logo_url_dark'] ?? '';
$logoUrlLight = $siteSettings['logo_url_light'] ?? '';
$faviconUrl = $siteSettings['favicon_url'] ?? '';
$invitationEnabled = ($siteSettings['invitation_enabled'] ?? '0') === '1';
$whatsappVerificationEnabled = ($siteSettings['whatsapp_verification_enabled'] ?? '0') === '1';

// Redirect to WhatsApp verification if enabled and user doesn't have a verified number
if ($whatsappVerificationEnabled && ($user['whatsapp_phone'] === null || $user['whatsapp_phone'] === '')) {
    header('Location: verify-whatsapp.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($siteTitle) ?>">
    <meta name=" csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars($siteTitle) ?> - Workflow Editor</title>

    <?php if (!empty($faviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>

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
                            950: 'var(--color-dark-950)'
                        },
                        primary: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- App Styles -->
    <link rel="stylesheet" href="assets/css/editor.css">
</head>

<body class="bg-dark-950 text-gray-100 overflow-hidden">
    <!-- Initial Loading Overlay with Progress -->
    <div id="app-loading-overlay"
        class="fixed inset-0 bg-dark-950 z-[9999] flex flex-col items-center justify-center gap-4">
        <div class="relative w-16 h-16">
            <div
                class="absolute inset-0 flex items-center justify-center animate-pulse <?= empty($faviconUrl) ? 'rounded-lg bg-gradient-to-br from-primary-500 to-purple-600' : '' ?>">
                <?php if (!empty($faviconUrl)): ?>
                    <img src="<?= htmlspecialchars($faviconUrl) ?>" alt="Loading..." class="w-full h-full object-contain">
                <?php else: ?>
                    <i data-lucide="workflow" class="w-8 h-8 text-white"></i>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-dark-200 text-lg font-medium"><?= htmlspecialchars($siteTitle) ?></p>
        <div class="w-48 h-1 bg-dark-800 rounded-full overflow-hidden">
            <div id="app-loading-progress-bar"
                class="h-full bg-gradient-to-r from-primary-500 to-purple-600 transition-all duration-300"
                style="width: 0%"></div>
        </div>
        <p id="app-loading-status" class="text-dark-500 text-xs">Initializing...</p>
    </div>

    <!-- App Container -->
    <div id="app" class="h-screen flex flex-col">

        <?php if ($isImpersonating): ?>
            <!-- Impersonation Banner -->
            <div id="impersonation-banner"
                class="bg-yellow-500 text-black px-4 py-2 flex items-center justify-between text-sm font-medium">
                <div class="flex items-center gap-2">
                    <i data-lucide="user-check" class="w-4 h-4"></i>
                    <span>You are logged in as <strong><?= htmlspecialchars($user['username']) ?></strong></span>
                </div>
                <button id="btn-return-to-admin"
                    class="px-3 py-1 bg-black/20 hover:bg-black/30 rounded text-xs font-semibold transition-colors">
                    Return to Admin
                </button>
            </div>
        <?php endif; ?>

        <!-- Top Header Bar -->
        <header
            class="header-bar h-12 bg-dark-900 border-b border-dark-700 flex items-center justify-between px-4 flex-shrink-0 z-50">
            <!-- Left: Logo & Workflow Name -->
            <div class="flex items-center gap-4">
                <!-- Mobile Hamburger Button -->
                <button id="btn-toggle-sidebar" class="toolbar-btn" title="Toggle Sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <!-- Logo -->
                <a href="index.php"
                    class="flex items-center gap-2 text-dark-50 hover:text-primary-400 transition-colors">
                    <?php if (!empty($logoUrlDark) || !empty($logoUrlLight)): ?>
                        <?php if (!empty($faviconUrl)): ?>
                            <img src="<?= htmlspecialchars($faviconUrl) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                                class="h-8 w-8 object-contain sm:hidden">
                        <?php endif; ?>

                        <?php if (!empty($logoUrlDark)): ?>
                            <img src="<?= htmlspecialchars($logoUrlDark) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                                class="h-8 w-auto <?= !empty($faviconUrl) ? 'hidden sm:dark:block' : 'hidden dark:block' ?>">
                        <?php endif; ?>

                        <?php if (!empty($logoUrlLight)): ?>
                            <img src="<?= htmlspecialchars($logoUrlLight) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                                class="h-8 w-auto <?= !empty($faviconUrl) ? 'hidden sm:block dark:hidden' : 'dark:hidden' ?>">
                        <?php endif; ?>
                    <?php else: ?>
                        <div
                            class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center">
                            <i data-lucide="play" class="w-4 h-4"></i>
                        </div>
                        <span class="font-bold text-lg hidden sm:block"><?= htmlspecialchars($siteTitle) ?></span>
                    <?php endif; ?>
                </a>

                <!-- Divider -->
                <div class="w-px h-6 bg-dark-600 hidden sm:block"></div>

                <!-- Workflow Name (Editable) -->
                <div class="flex items-center gap-2">
                    <input type="text" type="text" id="workflow-name" value="Untitled Workflow"
                        class="bg-transparent border-none text-dark-50 font-medium focus:outline-none focus:ring-2 focus:ring-primary-500 rounded px-2 py-1 max-w-[200px]"
                        spellcheck="false">
                    <button id="btn-save-name" class="p-1 text-gray-400 hover:text-white transition-colors hidden"
                        title="Save name">
                        <i data-lucide="check" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- Save Status -->
                <span id="save-status" class="text-xs text-gray-500 hidden sm:block">
                    <i data-lucide="cloud" class="w-3 h-3 inline"></i>
                    <span>All changes saved</span>
                </span>
            </div>

            <!-- Center: Main Toolbar -->
            <div id="main-toolbar" class="flex items-center gap-1">
                <!-- File Operations -->
                <div class="flex items-center gap-1 mr-2 toolbar-group">
                    <button id="btn-new" class="toolbar-btn" title="New Workflow (Ctrl+N)">
                        <i data-lucide="file-plus" class="w-4 h-4"></i>
                    </button>
                    <button id="btn-open" class="toolbar-btn" title="Open Workflow (Ctrl+O)">
                        <i data-lucide="folder-open" class="w-4 h-4"></i>
                    </button>
                    <button id="btn-save" class="toolbar-btn" title="Save Workflow (Ctrl+S)">
                        <i data-lucide="save" class="w-4 h-4"></i>
                    </button>
                    <button id="btn-export" class="toolbar-btn" title="Export Workflow">
                        <i data-lucide="download" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="w-px h-6 bg-dark-600 toolbar-divider"></div>

                <!-- Edit Operations -->
                <div class="flex items-center gap-1 mx-2 toolbar-group">
                    <button id="btn-undo" class="toolbar-btn" title="Undo (Ctrl+Z)" disabled>
                        <i data-lucide="undo-2" class="w-4 h-4"></i>
                    </button>
                    <button id="btn-redo" class="toolbar-btn" title="Redo (Ctrl+Y)" disabled>
                        <i data-lucide="redo-2" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="w-px h-6 bg-dark-600 toolbar-divider"></div>

                <!-- View Operations -->
                <div class="flex items-center gap-1 mx-2 toolbar-group">
                    <button id="btn-zoom-in" class="toolbar-btn" title="Zoom In">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i>
                    </button>
                    <span id="zoom-level" class="text-xs text-gray-400 min-w-[40px] text-center">100%</span>
                    <button id="btn-zoom-out" class="toolbar-btn" title="Zoom Out">
                        <i data-lucide="zoom-out" class="w-4 h-4"></i>
                    </button>
                    <button id="btn-zoom-fit" class="toolbar-btn" title="Fit to Screen">
                        <i data-lucide="maximize" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="w-px h-6 bg-dark-600 toolbar-divider"></div>

                <!-- Run Workflow -->
                <button id="btn-run"
                    class="ml-2 px-4 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white text-sm font-medium rounded-lg flex items-center gap-2 transition-all shadow-lg shadow-green-500/20">
                    <i data-lucide="play" class="w-4 h-4"></i>
                    <span class="hidden sm:inline" data-i18n="toolbar.run">Run</span>
                </button>
            </div>

            <!-- Right: User Menu -->
            <div class="flex items-center gap-2 md:gap-3 relative">
                <!-- Theme Toggle (hidden on mobile) -->
                <button id="btn-theme-toggle" class="toolbar-btn hidden-mobile" title="Toggle Theme">
                    <i data-lucide="moon" class="w-4 h-4"></i>
                </button>

                <!-- Language Switcher (Desktop) -->
                <div class="relative hidden-mobile" id="lang-switcher-desktop">
                    <button id="btn-lang-toggle-desktop" class="toolbar-btn flex items-center gap-1.5"
                        title="Change Language">
                        <i data-lucide="globe" class="w-4 h-4"></i>
                        <span id="lang-current-desktop" class="text-xs font-medium">EN</span>
                        <i data-lucide="chevron-down" class="w-3 h-3"></i>
                    </button>
                    <div id="lang-dropdown-desktop"
                        class="absolute right-0 top-full mt-2 w-40 bg-dark-800 border border-dark-600 rounded-lg shadow-xl hidden z-50 overflow-hidden">
                        <button type="button" data-lang="en"
                            class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-700 flex items-center gap-2 transition-colors">
                            <span class="w-5">🇺🇸</span>
                            <span>English</span>
                        </button>
                        <button type="button" data-lang="id"
                            class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-700 flex items-center gap-2 transition-colors">
                            <span class="w-5">🇮🇩</span>
                            <span>Bahasa Indonesia</span>
                        </button>
                        <button type="button" data-lang="ar"
                            class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-700 flex items-center gap-2 transition-colors">
                            <span class="w-5">🇸🇦</span>
                            <span>العربية</span>
                        </button>
                    </div>
                </div>

                <!-- Notifications -->
                <button id="btn-notifications" class="toolbar-btn relative" title="Notifications">
                    <i data-lucide="bell" class="w-4 h-4"></i>
                    <span id="notification-badge"
                        class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center hidden">0</span>
                </button>

                <!-- User Dropdown -->
                <div class="relative" id="user-menu-container">
                    <button id="btn-user-menu"
                        class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-dark-700 transition-colors">
                        <div
                            class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white font-medium text-sm">

                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                        </div>
                        <span
                            class="text-sm text-dark-300 hidden md:block"><?= htmlspecialchars($user['username']) ?></span>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-dark-400 hidden md:block"></i>

                    </button>

                    <!-- Dropdown Menu -->
                    <div id="user-dropdown"
                        class="absolute right-0 top-full mt-2 w-56 bg-dark-800 border border-dark-600 rounded-xl shadow-xl hidden z-50 overflow-hidden">
                        <div class="p-3 border-b border-dark-600">
                            <p class="text-sm font-medium text-dark-50"><?= htmlspecialchars($user['username']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($user['email']) ?></p>
                        </div>

                        <div class="p-1">
                            <button type="button" id="menu-my-workflows" class="dropdown-item w-full">
                                <i data-lucide="folder" class="w-4 h-4"></i>
                                <span data-i18n="menu.my_workflows">My Workflows</span>
                                <span class="ml-auto text-xs text-gray-500"><?= $workflowCount ?></span>
                            </button>
                            <button type="button" id="menu-api-keys" class="dropdown-item w-full">
                                <i data-lucide="key" class="w-4 h-4"></i>
                                <span data-i18n="menu.api_keys">API Keys</span>
                            </button>
                            <?php if ($invitationEnabled): ?>
                                <button type="button" id="menu-invitation" class="dropdown-item w-full">
                                    <i data-lucide="gift" class="w-4 h-4"></i>
                                    <span data-i18n="menu.invite_friends">Invite Friends</span>
                                </button>
                            <?php endif; ?>
                            <button type="button" id="menu-shortcuts" class="dropdown-item w-full">
                                <i data-lucide="command" class="w-4 h-4"></i>
                                <span data-i18n="menu.keyboard_shortcuts">Keyboard Shortcuts</span>
                            </button>
                            <button type="button" id="menu-settings" class="dropdown-item w-full">
                                <i data-lucide="settings" class="w-4 h-4"></i>
                                <span data-i18n="menu.settings">Settings</span>
                            </button>
                        </div>
                        <!-- Language Switcher (Mobile) -->
                        <div class="p-1 border-t border-dark-600 md:hidden">
                            <div class="relative" id="lang-submenu-container">
                                <button type="button" id="btn-lang-mobile" class="dropdown-item w-full justify-between">
                                    <span class="flex items-center gap-2">
                                        <i data-lucide="globe" class="w-4 h-4"></i>
                                        <span data-i18n="menu.language">Language</span>
                                    </span>
                                    <span class="flex items-center gap-1 text-gray-400">
                                        <span id="lang-current-mobile" class="text-xs">English</span>
                                        <i data-lucide="chevron-right" class="w-3 h-3"></i>
                                    </span>
                                </button>
                                <div id="lang-submenu-mobile"
                                    class="hidden mt-1 bg-dark-700 rounded-lg overflow-hidden">
                                    <button type="button" data-lang="en"
                                        class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-600 flex items-center gap-2 transition-colors">
                                        <span class="w-5">🇺🇸</span>
                                        <span>English</span>
                                    </button>
                                    <button type="button" data-lang="id"
                                        class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-600 flex items-center gap-2 transition-colors">
                                        <span class="w-5">🇮🇩</span>
                                        <span>Bahasa Indonesia</span>
                                    </button>
                                    <button type="button" data-lang="ar"
                                        class="lang-option w-full px-3 py-2 text-sm text-left hover:bg-dark-600 flex items-center gap-2 transition-colors">
                                        <span class="w-5">🇸🇦</span>
                                        <span>العربية</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php if ((int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin'): ?>
                            <div class="p-1 border-t border-dark-600">
                                <button type="button" id="menu-admin" class="dropdown-item w-full text-primary-400">
                                    <i data-lucide="shield" class="w-4 h-4"></i>
                                    <span data-i18n="menu.administration">Administration</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="p-1 border-t border-dark-600">
                            <a href="logout" class="dropdown-item text-red-400 hover:bg-red-500/10">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                <span data-i18n="menu.sign_out">Sign Out</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Mobile Toolbar More Button (shown after profile on mobile) -->
                <button id="btn-toolbar-more" class="toolbar-btn md:hidden" title="More Options">
                    <i data-lucide="more-vertical" class="w-4 h-4"></i>
                </button>

                <!-- Toolbar Dropdown (for mobile) -->
                <div id="toolbar-dropdown" class="hidden">
                    <button data-action="theme"><i data-lucide="moon" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.toggle_theme">Toggle Theme</span></button>
                    <button data-action="settings"><i data-lucide="settings" class="w-4 h-4"></i> <span
                            data-i18n="menu.settings">Settings</span></button>
                    <div class="border-t border-dark-600 my-1"></div>
                    <button data-action="new"><i data-lucide="file-plus" class="w-4 h-4"></i> <span
                            data-i18n="workflow.new_workflow">New Workflow</span></button>
                    <button data-action="open"><i data-lucide="folder-open" class="w-4 h-4"></i> <span
                            data-i18n="workflow.open_workflow">Open Workflow</span></button>
                    <button data-action="save"><i data-lucide="save" class="w-4 h-4"></i> <span
                            data-i18n="workflow.save_workflow">Save Workflow</span></button>
                    <button data-action="export"><i data-lucide="download" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.export">Export</span></button>
                    <div class="border-t border-dark-600 my-1"></div>
                    <button data-action="undo"><i data-lucide="undo-2" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.undo">Undo</span></button>
                    <button data-action="redo"><i data-lucide="redo-2" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.redo">Redo</span></button>
                    <div class="border-t border-dark-600 my-1"></div>
                    <button data-action="zoom-in"><i data-lucide="zoom-in" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.zoom_in">Zoom In</span></button>
                    <button data-action="zoom-out"><i data-lucide="zoom-out" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.zoom_out">Zoom Out</span></button>
                    <button data-action="zoom-fit"><i data-lucide="maximize" class="w-4 h-4"></i> <span
                            data-i18n="toolbar.fit_screen">Fit to Screen</span></button>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <div class="flex-1 flex overflow-hidden">

            <!-- Sidebar Overlay (for mobile) -->
            <div id="sidebar-overlay"></div>

            <!-- Left Sidebar: Node Library -->
            <aside id="sidebar-left" class="w-64 bg-dark-900 border-r border-dark-700 flex flex-col flex-shrink-0 z-40">
                <!-- Sidebar Header -->
                <div class="p-3 border-b border-dark-700">
                    <!-- Mobile Header Row -->
                    <div class="flex items-center justify-between mb-3 md:hidden">
                        <span class="font-semibold text-dark-50" data-i18n="sidebar.node_library">Node Library</span>
                        <button id="btn-close-sidebar"
                            class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <!-- Mobile Workflow Name & Run Button (hidden on desktop) -->
                    <div id="mobile-workflow-controls" class="flex items-center gap-2 mb-3 md:hidden">
                        <input type="text" id="workflow-name-mobile" value="Untitled Workflow"
                            class="min-w-0 flex-1 bg-dark-800 border border-dark-600 text-dark-50 font-medium rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 truncate"
                            spellcheck="false">
                        <button id="btn-run-mobile"
                            class="p-2 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg flex items-center justify-center transition-all shadow-lg shadow-green-500/20 flex-shrink-0"
                            title="Run Workflow">
                            <i data-lucide="play" class="w-5 h-5"></i>
                        </button>
                    </div>

                    <div class="relative">
                        <i data-lucide="search"
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                        <input type="text" id="node-search" name="node_search_query" placeholder="Search nodes..."
                            data-i18n-placeholder="sidebar.search_nodes" autocomplete="off" readonly
                            onfocus="this.removeAttribute('readonly')"
                            class="w-full pl-9 pr-3 py-2 bg-dark-800 border border-dark-600 rounded-lg text-sm text-dark-50 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">


                    </div>
                </div>


                <!-- Node Categories -->
                <div class="flex-1 overflow-y-auto custom-scrollbar" id="node-library">

                    <!-- Control Nodes (Built-in) -->
                    <div class="node-category" data-category="control">
                        <button class="category-header">
                            <i data-lucide="chevron-down" class="w-4 h-4 transition-transform"></i>
                            <i data-lucide="play-circle" class="w-4 h-4 text-emerald-400"></i>
                            <span data-i18n="categories.control">Control</span>
                            <span class="ml-auto text-xs text-gray-500">2</span>
                        </button>
                        <div class="category-content">
                            <div class="node-item" data-node-type="manual-trigger" draggable="true">
                                <div class="node-icon bg-emerald-500/20 text-emerald-400">
                                    <i data-lucide="play-circle" class="w-4 h-4"></i>
                                </div>
                                <div class="node-info">
                                    <span class="node-name" data-i18n="workflow.start_flow">Start Flow</span>
                                    <span class="node-desc" data-i18n="workflow.begin_sequence">Begin sequence</span>
                                </div>
                            </div>
                            <div class="node-item" data-node-type="flow-merge" draggable="true">
                                <div class="node-icon bg-emerald-500/20 text-emerald-400">
                                    <i data-lucide="git-merge" class="w-4 h-4"></i>
                                </div>
                                <div class="node-info">
                                    <span class="node-name" data-i18n="workflow.flow_merge">Flow Merge</span>
                                    <span class="node-desc" data-i18n="workflow.combine_flows">Combine flows</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Plugin nodes will be dynamically inserted here -->
                </div>


                <!-- Sidebar Footer -->
                <?php if ((int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin'): ?>
                    <div class="p-3 border-t border-dark-700">
                        <button id="btn-plugins"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2 bg-dark-800 hover:bg-dark-700 text-gray-400 hover:text-dark-50 rounded-lg text-sm transition-colors">
                            <i data-lucide="puzzle" class="w-4 h-4"></i>
                            <span data-i18n="sidebar.manage_plugins">Manage Plugins</span>

                        </button>
                    </div>
                <?php endif; ?>
            </aside>

            <!-- Main Canvas Area -->
            <main class="flex-1 relative overflow-hidden bg-dark-950" id="canvas-container">
                <!-- Canvas Background Grid -->
                <div id="canvas-grid" class="absolute inset-0 pointer-events-none"></div>

                <!-- SVG Layer for Connections -->
                <svg id="connections-layer" class="absolute inset-0 overflow-visible"
                    style="z-index: 10; pointer-events: none;">
                    <defs>
                        <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                            <polygon points="0 0, 10 3.5, 0 7" fill="#6b7280" />
                        </marker>
                        <!-- Gradient for active connections -->
                        <linearGradient id="connection-gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:#8b5cf6" />
                            <stop offset="100%" style="stop-color:#06b6d4" />
                        </linearGradient>
                    </defs>
                    <!-- Connection lines will be added here dynamically -->
                    <g id="connections-group" style="pointer-events: auto;"></g>
                    <!-- Temporary connection line while dragging - placed last to be on top -->
                    <path id="temp-connection" class="hidden" fill="none" stroke="#8b5cf6" stroke-width="3" />
                </svg>

                <!-- Nodes Container -->
                <div id="nodes-container" class="absolute inset-0 z-20">
                    <!-- Nodes will be rendered here dynamically -->

                </div>

                <!-- Selection Box -->
                <div id="selection-box"
                    class="absolute border-2 border-primary-500 bg-primary-500/10 pointer-events-none hidden z-30">
                </div>

                <!-- Canvas Controls (Bottom Left) -->
                <div class="absolute bottom-4 left-4 flex items-center gap-2 z-30">
                    <!-- Minimap Toggle -->
                    <button id="btn-toggle-minimap" class="canvas-control-btn" title="Toggle Minimap">
                        <i data-lucide="map" class="w-4 h-4"></i>
                    </button>

                    <!-- Grid Toggle -->
                    <button id="btn-toggle-grid" class="canvas-control-btn active" title="Toggle Grid">
                        <i data-lucide="grid-3x3" class="w-4 h-4"></i>
                    </button>

                    <!-- Snap Toggle -->
                    <button id="btn-toggle-snap" class="canvas-control-btn active" title="Toggle Snap to Grid">
                        <i data-lucide="magnet" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- Minimap (Bottom Right) -->
                <div id="minimap"
                    class="absolute bottom-4 right-4 w-48 h-32 bg-dark-900/90 border border-dark-600 rounded-lg overflow-hidden z-30 hidden">
                    <div id="minimap-content" class="relative w-full h-full">
                        <!-- Minimap nodes will be rendered here -->
                    </div>
                    <div id="minimap-viewport"
                        class="absolute border-2 border-primary-500 bg-primary-500/20 cursor-move"></div>
                </div>

                <!-- Canvas Actions (Top Right) -->
                <div class="absolute top-4 right-4 flex items-center gap-2 z-40 canvas-controls">
                    <!-- Gallery Button -->
                    <button id="btn-gallery" class="canvas-control-btn" title="View Generated Content">
                        <i data-lucide="images" class="w-4 h-4"></i>
                    </button>

                    <!-- History Button -->
                    <button id="btn-history" class="canvas-control-btn" title="Workflow Run History">
                        <i data-lucide="history" class="w-4 h-4"></i>
                    </button>

                    <!-- Share Button will be injected by aflow-share plugin -->
                </div>


                <!-- Empty State -->
                <div id="empty-state" class="absolute inset-0 flex items-center justify-center z-30">
                    <div class="text-center max-w-md px-4">
                        <div class="w-20 h-20 rounded-2xl bg-dark-800 flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="workflow" class="w-10 h-10 text-dark-400"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-dark-400 mb-2" data-i18n="workflow.drag_nodes">Start
                            Building Your Workflow</h3>
                        <p class="text-dark-500 mb-6" data-i18n="workflow.or_use_quick_add">Click the button below or
                            drag a Start Flow node from the sidebar
                            to begin.</p>

                        <div class="flex flex-wrap justify-center gap-3">
                            <button class="quick-add-btn" data-node-type="manual-trigger">
                                <i data-lucide="play-circle" class="w-4 h-4"></i>
                                <span data-i18n="workflow.start_flow">Add Start Flow</span>
                            </button>
                        </div>
                    </div>
                </div>

            </main>

            <!-- Right Sidebar: Properties Panel -->
            <aside id="sidebar-right"
                class="w-80 bg-dark-900 border-l border-dark-700 flex flex-col flex-shrink-0 z-40 hidden">
                <!-- Properties Header -->
                <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i data-lucide="settings-2" class="w-5 h-5 text-gray-400"></i>
                        <h3 class="font-semibold text-dark-50" data-i18n="panels.properties">Properties</h3>
                    </div>
                    <button id="btn-close-properties"
                        class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">

                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <!-- Node Info -->
                <div id="properties-node-info" class="p-4 border-b border-dark-700">
                    <div class="flex items-center gap-3">
                        <div id="properties-node-icon"
                            class="w-10 h-10 rounded-lg flex items-center justify-center bg-purple-500/20 text-purple-400">
                            <i data-lucide="box" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h4 id="properties-node-name" class="font-medium text-dark-50">Node Name</h4>
                            <p id="properties-node-id" class="text-xs text-dark-400">node-id</p>
                        </div>

                    </div>
                </div>

                <!-- Properties Content -->
                <div id="properties-content" class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-4">
                    <!-- Dynamic form fields will be inserted here -->
                    <p class="text-dark-500 text-sm" data-i18n="panels.select_node">Select a node to view its
                        properties.</p>
                </div>


                <!-- Properties Footer -->
                <div id="properties-footer" class="p-4 border-t border-dark-700 hidden">
                    <button id="btn-delete-node"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-lg transition-colors">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        <span data-i18n="panels.delete_node">Delete Node</span>
                    </button>
                </div>
            </aside>

            <!-- Gallery Panel (Right Slide-over) -->
            <aside id="gallery-panel"
                class="fixed right-0 top-0 h-full w-96 bg-dark-900 border-l border-dark-700 flex flex-col z-50 transform translate-x-full transition-transform duration-300">
                <!-- Gallery Header -->
                <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i data-lucide="images" class="w-5 h-5 text-primary-400"></i>
                        <h3 class="font-semibold text-dark-50" data-i18n="panels.generated_content">Generated Content
                        </h3>
                    </div>
                    <button id="btn-close-gallery"
                        class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <!-- Content Retention Notice (shown when enabled) -->
                <div id="gallery-retention-notice"
                    class="px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 hidden">
                    <p class="text-xs text-amber-400 flex items-center gap-2">
                        <i data-lucide="clock" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span id="gallery-retention-text" data-i18n="panels.content_retention_notice">Files are stored
                            for <strong id="retention-days-value">0</strong> days</span>
                    </p>
                </div>

                <!-- Gallery Tabs -->
                <div class="flex border-b border-dark-700">
                    <button
                        class="gallery-tab flex-1 py-2 px-4 text-sm font-medium text-dark-50 border-b-2 border-primary-500"
                        data-tab="manual">
                        <i data-lucide="mouse-pointer-click" class="w-4 h-4 inline mr-1"></i>
                        Manual
                    </button>
                    <button
                        class="gallery-tab flex-1 py-2 px-4 text-sm font-medium text-dark-400 hover:text-dark-200 border-b-2 border-transparent"
                        data-tab="api">
                        <i data-lucide="code" class="w-4 h-4 inline mr-1"></i>
                        API
                    </button>
                </div>

                <!-- Gallery Content -->
                <div id="gallery-content" class="flex-1 overflow-y-auto custom-scrollbar p-4">
                    <div id="gallery-grid" class="grid grid-cols-2 gap-3">
                        <!-- Generated content items will be inserted here -->
                    </div>
                    <div id="gallery-empty" class="text-center py-12">
                        <i data-lucide="image-off" class="w-12 h-12 text-dark-600 mx-auto mb-3"></i>
                        <p class="text-dark-500 text-sm" data-i18n="panels.no_content_yet">No generated content yet</p>
                        <p class="text-dark-600 text-xs mt-1" data-i18n="panels.run_workflow_generate">Run your workflow
                            to generate content</p>
                    </div>
                </div>
            </aside>

            <!-- History Panel (Right Slide-over) -->
            <aside id="history-panel"
                class="fixed right-0 top-0 h-full w-96 bg-dark-900 border-l border-dark-700 flex flex-col z-50 transform translate-x-full transition-transform duration-300">
                <!-- History Header -->
                <div class="p-4 border-b border-dark-700 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i data-lucide="history" class="w-5 h-5 text-blue-400"></i>
                        <h3 class="font-semibold text-dark-50" data-i18n="panels.run_history">Run History</h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="btn-refresh-history"
                            class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors"
                            title="Refresh history">
                            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        </button>
                        <button id="btn-close-history"
                            class="p-1 text-gray-400 hover:text-dark-50 rounded transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <!-- History Tabs -->
                <div class="px-4 py-2 border-b border-dark-700 flex gap-2">
                    <button class="history-tab active" data-tab="current">
                        <i data-lucide="play-circle" class="w-4 h-4"></i>
                        <span data-i18n="panels.current">Current</span>
                    </button>
                    <button class="history-tab" data-tab="completed">
                        <i data-lucide="check-circle" class="w-4 h-4"></i>
                        <span data-i18n="panels.completed">Completed</span>
                    </button>
                    <button class="history-tab" data-tab="aborted">
                        <i data-lucide="octagon" class="w-4 h-4"></i>
                        <span data-i18n="panels.aborted">Aborted</span>
                    </button>
                </div>

                <!-- History Content -->
                <div id="history-content" class="flex-1 overflow-y-auto custom-scrollbar p-4">
                    <div id="history-list" class="space-y-3">
                        <!-- History items will be inserted here -->
                    </div>
                    <div id="history-empty" class="text-center py-12">
                        <i data-lucide="clock" class="w-12 h-12 text-dark-600 mx-auto mb-3"></i>
                        <p class="text-dark-500 text-sm" data-i18n="panels.no_runs_yet">No workflow runs yet</p>
                        <p class="text-dark-600 text-xs mt-1" data-i18n="panels.start_workflow_history">Start a workflow
                            to see run history</p>
                    </div>
                </div>

                <!-- Cleanup action footer for aborted tab (fixed at bottom) -->
                <div id="history-cleanup-action" class="hidden p-4 border-t border-dark-700 bg-dark-900 flex-shrink-0">
                    <button id="btn-cleanup-aborted"
                        class="w-full py-2 px-4 text-sm text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded transition-colors flex items-center justify-center gap-2">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        <span data-i18n="panels.clear_aborted">Clear All Aborted Runs</span>
                    </button>
                </div>
            </aside>

            <!-- Share Modal will be injected by aflow-share plugin -->
        </div>


        <!-- Bottom Status Bar -->
        <footer
            class="h-8 bg-dark-900 border-t border-dark-700 flex items-center justify-between px-4 text-xs text-gray-500 flex-shrink-0 z-50">
            <div class="flex items-center gap-4">
                <span id="status-nodes">Nodes: 0</span>
                <span id="status-connections">Connections: 0</span>
                <span id="status-selected">Selected: None</span>
            </div>
            <div class="flex items-center gap-4">
                <span id="status-execution" class="hidden">
                    <i data-lucide="loader-2" class="w-3 h-3 inline animate-spin"></i>
                    <span>Executing...</span>
                </span>
                <span id="status-position">X: 0, Y: 0</span>
                <span id="status-zoom">Zoom: 100%</span>
            </div>
        </footer>
    </div>

    <!-- Modals Container -->
    <div id="modals-container">

        <!-- New Workflow Modal -->
        <div id="modal-new" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-md">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-white" data-i18n="workflow.new_workflow">New Workflow</h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="text-gray-400 mb-4">Create a new workflow? Unsaved changes will be lost.</p>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-300 mb-2"
                            data-i18n="workflow.workflow_name">Workflow Name</label>
                        <input type="text" id="new-workflow-name" class="form-input" placeholder="My Awesome Workflow">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2"
                            data-i18n="modals.select_template">Template</label>
                        <select id="new-workflow-template" class="form-select">
                            <option value="blank">Blank Canvas</option>
                            <option value="image-to-video">Image to Video</option>
                            <option value="text-to-video">Text to Video</option>
                            <option value="music-video">Music Video</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel" data-i18n="common.cancel">Cancel</button>
                    <button class="btn-primary" id="btn-confirm-new" data-i18n="modals.create_workflow">Create
                        Workflow</button>
                </div>
            </div>
        </div>

        <!-- Open Workflow Modal -->
        <div id="modal-open" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-2xl">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-white" data-i18n="workflow.open_workflow">Open Workflow</h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <input type="text" id="search-workflows" class="form-input" placeholder="Search workflows...">
                    </div>
                    <div id="workflows-list" class="space-y-2 max-h-96 overflow-y-auto custom-scrollbar">
                        <div class="text-gray-500 text-center py-8">
                            <i data-lucide="loader-2" class="w-6 h-6 inline animate-spin"></i>
                            <p class="mt-2" data-i18n="common.loading">Loading workflows...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel">Cancel</button>
                    <button class="btn-secondary" id="btn-import-file">
                        <i data-lucide="upload" class="w-4 h-4"></i>
                        <span data-i18n="modals.import_file">Import File</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Save Workflow Modal -->
        <div id="modal-save" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-md">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" data-i18n="workflow.save_workflow">Save Workflow</h3>
                    <button class="modal-close">

                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-dark-300 mb-2"
                            data-i18n="workflow.workflow_name">Workflow Name</label>
                        <input type="text" id="save-workflow-name" class="form-input" placeholder="My Workflow">

                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-dark-300 mb-2"
                            data-i18n="modals.description_optional">Description (optional)</label>
                        <textarea id="save-workflow-desc" class="form-textarea" rows="3"
                            placeholder="Describe your workflow..."></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm text-dark-300">
                            <input type="checkbox" id="save-workflow-public" class="form-checkbox">

                            <span data-i18n="modals.make_public">Make this workflow public</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel" data-i18n="common.cancel">Cancel</button>
                    <button class="btn-primary" id="btn-confirm-save" data-i18n="workflow.save_workflow">Save
                        Workflow</button>
                </div>
            </div>
        </div>

        <!-- Settings Modal -->
        <div id="modal-settings" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-lg">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" data-i18n="menu.settings">Settings</h3>
                    <button class="modal-close">

                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Settings Tabs -->
                    <div class="flex border-b border-dark-600 mb-4">
                        <button class="settings-tab active" data-tab="profile"
                            data-i18n="settings.profile">Profile</button>
                        <button class="settings-tab" data-tab="general" data-i18n="settings.general">General</button>
                        <?php if (!PluginManager::isPluginEnabled('aflow-api')): ?>
                            <button class="settings-tab" data-tab="api" data-i18n="settings.api_keys">API Keys</button>
                        <?php endif; ?>
                        <button class="settings-tab" data-tab="appearance"
                            data-i18n="settings.appearance">Appearance</button>
                    </div>

                    <!-- Profile Tab -->
                    <div id="settings-profile" class="settings-content">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.username">Username</label>
                                <input type="text" id="profile-username" class="form-input w-full"
                                    value="<?= htmlspecialchars($user['username']) ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.email">Email</label>
                                <input type="email" id="profile-email" class="form-input w-full"
                                    value="<?= htmlspecialchars($user['email']) ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2">WhatsApp Number</label>
                                <input type="tel" id="profile-whatsapp"
                                    class="form-input w-full bg-dark-700 cursor-not-allowed"
                                    value="<?= htmlspecialchars($user['whatsapp_phone'] ?? 'Not set') ?>" readonly
                                    disabled>
                            </div>
                            <div class="border-t border-dark-600 pt-4 mt-4">
                                <h4 class="text-sm font-medium text-dark-200 mb-3" data-i18n="settings.change_password">
                                    Change Password</h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-xs text-dark-400 mb-1"
                                            data-i18n="settings.current_password">Current Password</label>
                                        <input type="password" id="profile-current-password" class="form-input w-full">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-dark-400 mb-1"
                                            data-i18n="settings.new_password">New Password</label>
                                        <input type="password" id="profile-new-password" class="form-input w-full">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- General Tab -->
                    <div id="settings-general" class="settings-content hidden">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.autosave_interval">Auto-save interval</label>
                                <select class="form-select" id="setting-autosave">

                                    <option value="0">Disabled</option>
                                    <option value="30">Every 30 seconds</option>
                                    <option value="60" selected>Every minute</option>
                                    <option value="300">Every 5 minutes</option>
                                </select>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm text-dark-300">
                                    <input type="checkbox" class="form-checkbox" id="setting-confirm-delete" checked>

                                    <span data-i18n="settings.confirm_delete">Confirm before deleting nodes</span>
                                </label>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm text-dark-300">
                                    <input type="checkbox" class="form-checkbox" id="setting-snap-grid" checked>

                                    <span data-i18n="settings.snap_grid">Snap nodes to grid</span>
                                </label>
                            </div>

                            <!-- Desktop Notifications -->
                            <div class="border-t border-dark-600 pt-4 mt-4">
                                <h4 class="text-sm font-medium text-dark-200 mb-3 flex items-center gap-2">
                                    <i data-lucide="bell" class="w-4 h-4"></i>
                                    Desktop Notifications
                                </h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                            <input type="checkbox" class="form-checkbox"
                                                id="setting-notifications-enabled">
                                            <span>Enable desktop notifications</span>
                                        </label>
                                        <p class="text-xs text-dark-500 mt-1 ml-6">Get notified when workflows complete
                                            or fail</p>
                                    </div>
                                    <div id="notification-permission-status" class="hidden ml-6">
                                        <div id="notification-status-granted"
                                            class="hidden flex items-center gap-2 text-sm text-green-400">
                                            <i data-lucide="check-circle" class="w-4 h-4"></i>
                                            <span>Notifications allowed</span>
                                        </div>
                                        <div id="notification-status-denied" class="hidden">
                                            <div class="flex items-center gap-2 text-sm text-red-400 mb-2">
                                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                                                <span>Notifications blocked by browser</span>
                                            </div>
                                            <div
                                                class="bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-xs text-dark-300">
                                                <p class="font-medium text-red-400 mb-1">How to enable:</p>
                                                <ol class="list-decimal list-inside space-y-1">
                                                    <li>Click the lock icon <i data-lucide="lock"
                                                            class="w-3 h-3 inline"></i> in your browser's address bar
                                                    </li>
                                                    <li>Find "Notifications" setting</li>
                                                    <li>Change from "Block" to "Allow"</li>
                                                    <li>Refresh this page</li>
                                                </ol>
                                            </div>
                                        </div>
                                        <div id="notification-status-default" class="hidden">
                                            <button id="btn-request-notification"
                                                class="btn-secondary text-sm px-3 py-1.5 flex items-center gap-2">
                                                <i data-lucide="bell-ring" class="w-4 h-4"></i>
                                                <span>Allow Notifications</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!PluginManager::isPluginEnabled('aflow-api')): ?>
                        <!-- API Keys Tab -->
                        <div id="settings-api" class="settings-content hidden">
                            <div class="space-y-4">
                                <div class="bg-dark-800 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium text-dark-300"
                                            data-i18n="settings.your_api_key">Your API Key</label>
                                        <button id="btn-regenerate-api"
                                            class="text-xs text-primary-400 hover:text-primary-300"
                                            data-i18n="settings.regenerate">Regenerate</button>
                                    </div>
                                    <div class="flex gap-2">
                                        <input type="password" id="user-api-key" class="form-input flex-1 font-mono text-sm"
                                            value="<?= htmlspecialchars($user['api_key'] ?? '') ?>" readonly>
                                        <button id="btn-copy-api" class="btn-secondary px-3" title="Copy">
                                            <i data-lucide="copy" class="w-4 h-4"></i>
                                        </button>
                                        <button id="btn-toggle-api" class="btn-secondary px-3" title="Show/Hide">
                                            <i data-lucide="eye" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500" data-i18n="settings.api_key_hint">Use this API key to
                                    execute workflows programmatically.</p>
                            </div>
                        </div>
                    <?php endif; ?>




                    <!-- Appearance Tab -->
                    <div id="settings-appearance" class="settings-content hidden">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.theme">Theme</label>
                                <select class="form-select" id="setting-theme">

                                    <option value="dark" selected>Dark</option>
                                    <option value="light">Light (Coming Soon)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.grid_size">Grid Size</label>
                                <input type="range" id="setting-grid-size" min="10" max="50" value="20" class="w-full">

                                <div class="flex justify-between text-xs text-gray-500 mt-1">
                                    <span data-i18n="settings.small">Small</span>
                                    <span data-i18n="settings.large">Large</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2"
                                    data-i18n="settings.connection_style">Connection Style</label>
                                <select class="form-select" id="setting-connection-style">

                                    <option value="bezier" selected>Bezier Curves</option>
                                    <option value="straight">Straight Lines</option>
                                    <option value="step">Step Lines</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel" data-i18n="modals.close">Close</button>
                    <button class="btn-primary" id="btn-save-settings" data-i18n="settings.save_settings">Save
                        Settings</button>
                </div>
            </div>
        </div>

        <!-- Keyboard Shortcuts Modal -->
        <div id="modal-shortcuts" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-2xl">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" data-i18n="shortcuts.title">Keyboard Shortcuts</h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body max-h-[70vh] overflow-y-auto custom-scrollbar">
                    <!-- Workflow Section -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-dark-200 mb-3 flex items-center gap-2">
                            <i data-lucide="workflow" class="w-4 h-4 text-primary-400"></i>
                            <span data-i18n="shortcuts.workflow">Workflow</span>
                        </h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.new_workflow">New
                                    Workflow</span>
                                <kbd class="kbd">Ctrl + N</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.open_workflow">Open
                                    Workflow</span>
                                <kbd class="kbd">Ctrl + O</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.save_workflow">Save
                                    Workflow</span>
                                <kbd class="kbd">Ctrl + S</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.save_as">Save As</span>
                                <kbd class="kbd">Ctrl + Shift + S</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.export_workflow">Export
                                    Workflow</span>
                                <kbd class="kbd">Ctrl + E</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.run_workflow">Run
                                    Workflow</span>
                                <div class="flex gap-2">
                                    <kbd class="kbd">F5</kbd>
                                    <span class="text-dark-500">or</span>
                                    <kbd class="kbd">Ctrl + Enter</kbd>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Editing Section -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-dark-200 mb-3 flex items-center gap-2">
                            <i data-lucide="edit" class="w-4 h-4 text-blue-400"></i>
                            <span data-i18n="shortcuts.editing">Editing</span>
                        </h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.undo">Undo</span>
                                <kbd class="kbd">Ctrl + Z</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.redo">Redo</span>
                                <div class="flex gap-2">
                                    <kbd class="kbd">Ctrl + Y</kbd>
                                    <span class="text-dark-500">or</span>
                                    <kbd class="kbd">Ctrl + Shift + Z</kbd>
                                </div>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.delete_node">Delete Selected
                                    Node</span>
                                <kbd class="kbd">Delete</kbd>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Section -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-dark-200 mb-3 flex items-center gap-2">
                            <i data-lucide="navigation" class="w-4 h-4 text-green-400"></i>
                            <span data-i18n="shortcuts.navigation">Navigation</span>
                        </h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.toggle_properties">Toggle
                                    Properties Panel</span>
                                <kbd class="kbd">Space</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.cycle_nodes_forward">Cycle
                                    Through Nodes (Forward)</span>
                                <kbd class="kbd">Tab</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.cycle_nodes_backward">Cycle
                                    Through Nodes (Backward)</span>
                                <kbd class="kbd">Shift + Tab</kbd>
                            </div>
                        </div>
                    </div>

                    <!-- Canvas Section -->
                    <div>
                        <h4 class="text-sm font-semibold text-dark-200 mb-3 flex items-center gap-2">
                            <i data-lucide="move" class="w-4 h-4 text-purple-400"></i>
                            <span data-i18n="shortcuts.canvas">Canvas</span>
                        </h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.pan_canvas">Pan Canvas</span>
                                <kbd class="kbd">Click + Drag</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.zoom">Zoom In/Out</span>
                                <kbd class="kbd">Mouse Wheel</kbd>
                            </div>
                            <div class="flex items-center justify-between py-2 px-3 bg-dark-800/50 rounded-lg">
                                <span class="text-sm text-dark-300" data-i18n="shortcuts.select_multiple">Select
                                    Multiple Nodes</span>
                                <kbd class="kbd">Click + Drag</kbd>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel" data-i18n="modals.close">Close</button>
                </div>
            </div>
        </div>

        <!-- Execution Progress Modal -->
        <div id="modal-execution" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-lg">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" data-i18n="modals.execution_title">Workflow Execution
                    </h3>
                    <button class="modal-close">

                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Overall Progress -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-dark-300" data-i18n="workflow.overall_progress">Overall
                                Progress</span>
                            <span id="execution-progress-text" class="text-sm text-gray-400">0%</span>

                        </div>
                        <div class="h-2 bg-dark-700 rounded-full overflow-hidden">
                            <div id="execution-progress-bar"
                                class="h-full bg-gradient-to-r from-primary-500 to-cyan-500 transition-all duration-300"
                                style="width: 0%"></div>
                        </div>
                    </div>

                    <!-- Node Status List -->
                    <div id="execution-nodes" class="space-y-3 max-h-64 overflow-y-auto custom-scrollbar">
                        <!-- Node execution status items will be added here -->
                    </div>

                    <!-- Execution Log -->
                    <div class="mt-4">
                        <button id="btn-toggle-log"
                            class="flex items-center gap-2 text-sm text-gray-400 hover:text-dark-50">
                            <i data-lucide="chevron-down" class="w-4 h-4"></i>

                            <span data-i18n="workflow.execution_log">Execution Log</span>
                        </button>
                        <div id="execution-log"
                            class="hidden mt-2 p-3 bg-dark-950 rounded-lg font-mono text-xs text-gray-400 max-h-32 overflow-y-auto custom-scrollbar">
                            <!-- Log entries will be added here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" id="btn-close-execution-modal" data-i18n="modals.close">Close</button>
                    <button class="btn-danger hidden" id="btn-abort-execution">
                        <i data-lucide="octagon" class="w-4 h-4"></i>
                        <span data-i18n="modals.abort">Abort</span>
                    </button>
                    <button class="btn-primary" id="btn-start-execution">
                        <i data-lucide="play" class="w-4 h-4"></i>
                        <span data-i18n="workflow.run_workflow">Run Workflow</span>
                    </button>
                    <button class="btn-primary hidden" id="btn-view-result">
                        <i data-lucide="external-link" class="w-4 h-4"></i>
                        <span data-i18n="workflow.view_result">View Result</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Plugins Modal -->
        <div id="modal-plugins" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-2xl">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" data-i18n="sidebar.manage_plugins">Manage Plugins
                    </h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Upload Plugin Section -->
                    <div
                        class="mb-4 p-4 border-2 border-dashed border-dark-600 rounded-lg bg-dark-800/50 hover:border-primary-500 transition-colors">
                        <div class="text-center">
                            <i data-lucide="upload-cloud" class="w-8 h-8 mx-auto mb-2 text-dark-400"></i>
                            <p class="text-sm text-dark-300 mb-2" data-i18n="modals.upload_plugin">Upload Plugin</p>
                            <p class="text-xs text-gray-500 mb-3">Upload a .zip file containing your plugin</p>
                            <button id="btn-upload-plugin" class="btn-primary text-sm px-4 py-2">
                                <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                                <span data-i18n="modals.choose_file">Choose File</span>
                            </button>
                            <input type="file" id="plugin-file-input" accept=".zip" class="hidden">
                        </div>
                    </div>

                    <!-- Plugins List (dynamically populated) -->
                    <div id="plugins-list" class="space-y-3">
                        <div id="installed-plugins-list" class="space-y-2">
                            <div class="text-center py-6 text-gray-500">
                                <i data-lucide="loader" class="w-8 h-8 mx-auto mb-2 opacity-50 animate-spin"></i>
                                <p class="text-sm" data-i18n="common.loading">Loading plugins...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="btn-refresh-plugins" class="btn-secondary">
                        <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                        <span data-i18n="modals.refresh">Refresh</span>
                    </button>
                    <button class="btn-secondary modal-cancel" data-i18n="modals.close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ((int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin'): ?>
        <!-- Administration Modal -->
        <div id="modal-admin" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-4xl">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50 flex items-center gap-2">
                        <i data-lucide="shield" class="w-5 h-5 text-primary-400"></i>
                        <span data-i18n="admin.administration">Administration</span>
                    </h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body max-h-[70vh] overflow-y-auto custom-scrollbar">
                    <!-- Admin Tabs -->
                    <div class="flex border-b border-dark-600 mb-4 overflow-x-auto">
                        <button class="admin-tab active" data-tab="users" data-i18n="admin.users">Users</button>
                        <button class="admin-tab" data-tab="site" data-i18n="admin.site_settings">Site Settings</button>
                        <button class="admin-tab" data-tab="credits" data-i18n="admin.credits_tab">Credits</button>
                        <button class="admin-tab" data-tab="integrations"
                            data-i18n="admin.integrations">Integrations</button>
                    </div>

                    <!-- Users Tab -->
                    <div id="admin-users" class="admin-content">
                        <div class="flex items-center justify-between mb-4 gap-4">
                            <div class="flex items-center gap-3 flex-1">
                                <h4 class="text-sm font-medium text-dark-300 whitespace-nowrap"
                                    data-i18n="admin.user_management">User Management</h4>
                                <div class="relative flex-1 max-w-xs">
                                    <input type="text" id="admin-user-search" class="form-input w-full text-sm pl-8"
                                        placeholder="Search users...">
                                    <i data-lucide="search"
                                        class="w-4 h-4 text-dark-500 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
                                </div>
                            </div>
                            <button id="btn-admin-add-user" class="btn-primary text-sm px-3 py-1">
                                <i data-lucide="user-plus" class="w-4 h-4 inline mr-1"></i>
                                <span data-i18n="admin.add_user">Add User</span>
                            </button>
                        </div>
                        <div id="admin-users-list" class="space-y-2">
                            <div class="text-center py-8 text-dark-400">
                                <i data-lucide="loader" class="w-6 h-6 mx-auto animate-spin"></i>
                                <p class="mt-2 text-sm" data-i18n="common.loading">Loading users...</p>
                            </div>
                        </div>
                    </div>

                    <!-- Site Settings Tab -->
                    <div id="admin-site" class="admin-content hidden">
                        <div class="space-y-6">
                            <!-- General -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">General</h4>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Site Title</label>
                                    <input type="text" id="admin-site-title" class="form-input w-full" value="AIKAFLOW">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Logo (Dark Mode)</label>
                                    <p class="text-xs text-dark-500 mb-2">Used when site is in dark mode - typically a
                                        light/white logo</p>
                                    <div class="flex items-center gap-3">
                                        <input type="text" id="admin-logo-url-dark" class="form-input flex-1"
                                            placeholder="Logo URL for dark mode">
                                        <button type="button" id="btn-upload-logo-dark" class="btn-secondary px-3">
                                            <i data-lucide="upload" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <input type="file" id="logo-dark-file-input" accept="image/*" class="hidden">
                                    <div id="logo-dark-preview" class="mt-2 hidden bg-dark-800 p-2 rounded inline-block">
                                        <img src="" alt="Dark mode logo preview" class="h-10 object-contain">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Logo (Light Mode)</label>
                                    <p class="text-xs text-dark-500 mb-2">Used when site is in light mode - typically a
                                        dark/colored logo</p>
                                    <div class="flex items-center gap-3">
                                        <input type="text" id="admin-logo-url-light" class="form-input flex-1"
                                            placeholder="Logo URL for light mode">
                                        <button type="button" id="btn-upload-logo-light" class="btn-secondary px-3">
                                            <i data-lucide="upload" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <input type="file" id="logo-light-file-input" accept="image/*" class="hidden">
                                    <div id="logo-light-preview" class="mt-2 hidden bg-dark-200 p-2 rounded inline-block">
                                        <img src="" alt="Light mode logo preview" class="h-10 object-contain">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Favicon</label>
                                    <div class="flex items-center gap-3">
                                        <input type="text" id="admin-favicon-url" class="form-input flex-1"
                                            placeholder="Favicon URL">
                                        <button type="button" id="btn-upload-favicon" class="btn-secondary px-3">
                                            <i data-lucide="upload" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <input type="file" id="favicon-file-input" accept=".ico,.png,.svg,image/*"
                                        class="hidden">
                                    <div id="favicon-preview" class="mt-2 hidden bg-dark-800 p-2 rounded inline-block">
                                        <img src="" alt="Favicon preview" class="h-8 object-contain">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Default Theme</label>
                                    <select id="admin-default-theme" class="form-select w-full">
                                        <option value="dark">Dark Mode</option>
                                        <option value="light">Light Mode</option>
                                    </select>
                                </div>
                            </div>

                            <!-- hCaptcha -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">hCaptcha</h4>
                                <div>
                                    <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                        <input type="checkbox" id="admin-hcaptcha-enabled" class="form-checkbox">
                                        <span>Enable hCaptcha on Login & Register</span>
                                    </label>
                                </div>
                                <div id="hcaptcha-fields" class="space-y-4 hidden">
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">hCaptcha Site
                                            Key</label>
                                        <input type="text" id="admin-hcaptcha-site-key" class="form-input w-full"
                                            placeholder="Enter your hCaptcha site key">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">hCaptcha Secret
                                            Key</label>
                                        <input type="password" id="admin-hcaptcha-secret-key" class="form-input w-full"
                                            placeholder="Enter your hCaptcha secret key">
                                    </div>
                                </div>
                            </div>

                            <!-- Google OAuth -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Google OAuth
                                    Login</h4>
                                <div>
                                    <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                        <input type="checkbox" id="admin-google-auth-enabled" class="form-checkbox">
                                        <span>Enable Google Login/Register</span>
                                    </label>
                                    <p class="text-xs text-dark-500 mt-1">Allow users to sign in using their Google account
                                    </p>
                                </div>
                                <div id="google-auth-fields" class="space-y-4 hidden">
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">Google Client ID</label>
                                        <input type="text" id="admin-google-client-id" class="form-input w-full"
                                            placeholder="Enter your Google OAuth Client ID">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">Google Client
                                            Secret</label>
                                        <input type="password" id="admin-google-client-secret" class="form-input w-full"
                                            placeholder="Enter your Google OAuth Client Secret">
                                    </div>
                                    <div class="bg-dark-800 p-3 rounded-lg text-xs text-dark-400">
                                        <p class="font-medium text-dark-300 mb-1">Setup Instructions:</p>
                                        <ol class="list-decimal list-inside space-y-1">
                                            <li>Go to <a href="https://console.cloud.google.com/apis/credentials"
                                                    target="_blank" class="text-primary-400 hover:underline">Google Cloud
                                                    Console</a></li>
                                            <li>Create OAuth 2.0 Client ID</li>
                                            <li>Set authorized redirect URI to: <code
                                                    class="bg-dark-700 px-1 rounded"><?= htmlspecialchars(APP_URL) ?>/api/auth/google-callback.php</code>
                                            </li>
                                        </ol>
                                    </div>
                                </div>
                            </div>

                            <!-- Invitation System -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Invitation
                                    System</h4>
                                <div>
                                    <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                        <input type="checkbox" id="admin-invitation-enabled" class="form-checkbox">
                                        <span>Enable Invitation/Referral System</span>
                                    </label>
                                    <p class="text-xs text-dark-500 mt-1">Allow users to share invitation codes for organic
                                        growth</p>
                                </div>
                                <div id="invitation-fields" class="space-y-4 hidden">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">Referrer
                                                Credits</label>
                                            <input type="number" id="admin-invitation-referrer-credits"
                                                class="form-input w-full" placeholder="50" min="0">
                                            <p class="text-xs text-dark-500 mt-1">Credits given to code owner</p>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">New User
                                                Credits</label>
                                            <input type="number" id="admin-invitation-referee-credits"
                                                class="form-input w-full" placeholder="50" min="0">
                                            <p class="text-xs text-dark-500 mt-1">Credits given to new user</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- WhatsApp Verification -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">WhatsApp
                                    Verification</h4>
                                <div>
                                    <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                        <input type="checkbox" id="admin-whatsapp-verification-enabled"
                                            class="form-checkbox">
                                        <span>Enable WhatsApp Verification on Registration</span>
                                    </label>
                                    <p class="text-xs text-dark-500 mt-1">Require users to verify their WhatsApp number
                                        during registration</p>
                                </div>
                                <div id="whatsapp-verification-fields" class="space-y-4 hidden">
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">API URL
                                            Template</label>
                                        <input type="text" id="admin-whatsapp-api-url"
                                            class="form-input w-full font-mono text-sm"
                                            placeholder="https://api.example.com/send.php?number={{destination_number}}&message={{message}}&token=xxx">
                                        <p class="text-xs text-dark-500 mt-1">Use <code
                                                class="bg-dark-700 px-1 rounded">{{destination_number}}</code> and <code
                                                class="bg-dark-700 px-1 rounded">{{message}}</code> as placeholders</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">HTTP Method</label>
                                        <select id="admin-whatsapp-api-method" class="form-select w-full">
                                            <option value="GET">GET</option>
                                            <option value="POST">POST</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">Verification Message
                                            Template</label>
                                        <textarea id="admin-whatsapp-verification-message"
                                            class="form-textarea w-full text-sm" rows="3"
                                            placeholder="Your verification code for {{site_title}} is: {{code}}. This code expires in 10 minutes."></textarea>
                                        <p class="text-xs text-dark-500 mt-1">Use <code
                                                class="bg-dark-700 px-1 rounded">{{code}}</code> and <code
                                                class="bg-dark-700 px-1 rounded">{{site_title}}</code> as placeholders</p>
                                    </div>
                                    <div class="bg-dark-800 p-3 rounded-lg text-xs text-dark-400">
                                        <p class="font-medium text-dark-300 mb-1">Example API URL:</p>
                                        <code
                                            class="block text-dark-400 break-all">https://x2.woonotif.com/api/send.php?number={{destination_number}}&type=text&message={{message}}&instance_id=YOUR_ID&access_token=YOUR_TOKEN</code>
                                    </div>
                                </div>
                            </div>

                            <!-- SMTP Email -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">SMTP Email</h4>
                                <div>
                                    <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                        <input type="checkbox" id="admin-smtp-enabled" class="form-checkbox">
                                        <span>Enable SMTP Email</span>
                                    </label>
                                </div>
                                <div id="smtp-fields" class="space-y-4 hidden">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">SMTP Host</label>
                                            <input type="text" id="admin-smtp-host" class="form-input w-full"
                                                placeholder="smtp.example.com">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">Port</label>
                                            <input type="number" id="admin-smtp-port" class="form-input w-full" value="587">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">Username</label>
                                            <input type="text" id="admin-smtp-username" class="form-input w-full">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">Password</label>
                                            <input type="password" id="admin-smtp-password" class="form-input w-full">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">From Email</label>
                                            <input type="email" id="admin-smtp-from-email" class="form-input w-full"
                                                placeholder="noreply@example.com">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-dark-300 mb-2">From Name</label>
                                            <input type="text" id="admin-smtp-from-name" class="form-input w-full"
                                                value="AIKAFLOW">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-dark-300 mb-2">Encryption</label>
                                        <select id="admin-smtp-encryption" class="form-select w-full">
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="none">None</option>
                                        </select>
                                    </div>
                                    <!-- Test Email -->
                                    <div class="pt-4 mt-4 border-t border-dark-600">
                                        <label class="block text-sm font-medium text-dark-300 mb-2">Test SMTP
                                            Configuration</label>
                                        <div class="flex gap-2">
                                            <input type="email" id="admin-smtp-test-email" class="form-input flex-1"
                                                placeholder="Enter email to send test">
                                            <button type="button" id="btn-smtp-test-email"
                                                class="btn-secondary px-4 py-2 whitespace-nowrap flex items-center gap-2">
                                                <i data-lucide="send" class="w-4 h-4"></i>
                                                Send Test
                                            </button>
                                        </div>
                                        <p class="text-xs text-dark-500 mt-2">Save settings first, then test to verify your
                                            configuration.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Email Templates -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Email Templates
                                </h4>
                                <p class="text-xs text-dark-400">Use placeholders: {{username}}, {{verification_link}},
                                    {{login_link}}, {{reset_link}}</p>

                                <!-- Welcome Email -->
                                <details class="bg-dark-700 rounded-lg">
                                    <summary class="p-3 cursor-pointer text-sm font-medium text-dark-200">Welcome Email
                                    </summary>
                                    <div class="p-3 space-y-3 border-t border-dark-600">
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Subject</label>
                                            <input type="text" id="admin-email-welcome-subject"
                                                class="form-input w-full text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Body</label>
                                            <textarea id="admin-email-welcome-body" class="form-textarea w-full text-sm"
                                                rows="4"></textarea>
                                        </div>
                                    </div>
                                </details>

                                <!-- Forgot Password Email -->
                                <details class="bg-dark-700 rounded-lg">
                                    <summary class="p-3 cursor-pointer text-sm font-medium text-dark-200">Password Reset
                                        Email</summary>
                                    <div class="p-3 space-y-3 border-t border-dark-600">
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Subject</label>
                                            <input type="text" id="admin-email-forgot-subject"
                                                class="form-input w-full text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Body</label>
                                            <textarea id="admin-email-forgot-body" class="form-textarea w-full text-sm"
                                                rows="4"></textarea>
                                        </div>
                                    </div>
                                </details>
                                <!-- Email Verification -->
                                <details class="bg-dark-700 rounded-lg">
                                    <summary class="p-3 cursor-pointer text-sm font-medium text-dark-200">Email Verification
                                    </summary>
                                    <div class="p-3 space-y-3 border-t border-dark-600">
                                        <div class="flex items-center justify-between p-3 bg-dark-800 rounded-lg">
                                            <div>
                                                <label class="text-sm font-medium text-dark-200">Require Email
                                                    Verification</label>
                                                <p class="text-xs text-dark-400">Users must verify their email before
                                                    logging in</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" id="admin-email-verification-enabled"
                                                    class="sr-only peer">
                                                <div
                                                    class="w-11 h-6 bg-dark-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600">
                                                </div>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Subject</label>
                                            <input type="text" id="admin-email-verification-subject"
                                                class="form-input w-full text-sm"
                                                placeholder="Verify your email - AIKAFLOW">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-dark-400 mb-1">Body</label>
                                            <textarea id="admin-email-verification-body"
                                                class="form-textarea w-full text-sm" rows="5"
                                                placeholder="Hello {{username}},&#10;&#10;Please click the link below to verify your email:&#10;{{verification_link}}"></textarea>
                                        </div>
                                        <p class="text-xs text-dark-500">
                                            <i data-lucide="info" class="w-3 h-3 inline"></i>
                                            Only works when SMTP is properly configured above.
                                        </p>
                                    </div>
                                </details>
                            </div>

                            <!-- Legal Pages -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Legal Pages</h4>

                                <details class="bg-dark-700 rounded-lg">
                                    <summary class="p-3 cursor-pointer text-sm font-medium text-dark-200">Terms of Service
                                    </summary>
                                    <div class="p-3 pt-0">
                                        <textarea id="admin-terms-of-service" class="form-textarea w-full text-sm" rows="6"
                                            placeholder="Enter your Terms of Service (Markdown supported)"></textarea>
                                    </div>
                                </details>

                                <details class="bg-dark-700 rounded-lg">
                                    <summary class="p-3 cursor-pointer text-sm font-medium text-dark-200">Privacy Policy
                                    </summary>
                                    <div class="p-3 pt-0">
                                        <textarea id="admin-privacy-policy" class="form-textarea w-full text-sm" rows="6"
                                            placeholder="Enter your Privacy Policy (Markdown supported)"></textarea>
                                    </div>
                                </details>
                            </div>

                            <!-- Custom Scripts -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Custom Scripts
                                </h4>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Footer JavaScript</label>
                                    <textarea id="admin-custom-footer-js" class="form-textarea w-full font-mono text-sm"
                                        rows="6" placeholder="<!-- Add tracking, analytics, or chat widget code here -->
<script>
  // Your custom JavaScript code
</script>"></textarea>
                                    <p class="text-xs text-dark-500 mt-1">
                                        <i data-lucide="info" class="w-3 h-3 inline"></i>
                                        This code will be injected before the closing &lt;/body&gt; tag. Use for analytics,
                                        chat widgets, or custom tracking.
                                    </p>
                                </div>
                            </div>

                            <!-- Headway Widget -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-dark-200 border-b border-dark-600 pb-2">Changelog Widget
                                    (Headway)</h4>
                                <div>
                                    <label class="block text-sm font-medium text-dark-300 mb-2">Headway Widget ID</label>
                                    <input type="text" id="admin-headway-widget-id" class="form-input w-full"
                                        placeholder="e.g. abcd1234">
                                    <p class="text-xs text-dark-500 mt-1">Get your widget ID from <a
                                            href="https://headwayapp.co" target="_blank"
                                            class="text-primary-400 hover:underline">headwayapp.co</a></p>
                                </div>
                            </div>

                            <button id="btn-admin-save-site" class="btn-primary">Save Site Settings</button>
                        </div>
                    </div>

                    <!-- Credits Tab -->
                    <div id="admin-credits" class="admin-content hidden">
                        <!-- Sub-tabs (Pills) -->
                        <div class="flex flex-wrap gap-2 mb-6 bg-dark-800/50 p-2 rounded-lg">
                            <button class="credit-subtab active px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="requests">
                                <i data-lucide="inbox" class="w-4 h-4 inline mr-1"></i>Requests
                            </button>
                            <button class="credit-subtab px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="settings">
                                <i data-lucide="settings" class="w-4 h-4 inline mr-1"></i>Settings
                            </button>
                            <button class="credit-subtab px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="packages">
                                <i data-lucide="package" class="w-4 h-4 inline mr-1"></i>Packages
                            </button>
                            <button class="credit-subtab px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="nodecosts">
                                <i data-lucide="cpu" class="w-4 h-4 inline mr-1"></i>Node Costs
                            </button>
                            <button class="credit-subtab px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="coupons">
                                <i data-lucide="ticket" class="w-4 h-4 inline mr-1"></i>Coupons
                            </button>
                            <button class="credit-subtab px-4 py-2 rounded-lg text-sm font-medium transition-all"
                                data-subtab="banks">
                                <i data-lucide="landmark" class="w-4 h-4 inline mr-1"></i>Banks
                            </button>
                        </div>

                        <!-- Requests Subtab -->
                        <div id="credit-subtab-requests" class="credit-subtab-content">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-base font-semibold text-dark-100">Top-up Requests</h4>
                                <div class="flex items-center gap-2">
                                    <select id="topup-request-filter"
                                        class="form-select text-xs py-1 px-2 bg-dark-700 border-dark-600 rounded"
                                        onchange="window.filterTopupRequests?.()">
                                        <option value="all">All Requests</option>
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                    <button class="btn-secondary text-xs px-3 py-1"
                                        onclick="window.loadAdminPendingRequests?.()">
                                        <i data-lucide="refresh-cw" class="w-3 h-3 inline mr-1"></i>Refresh
                                    </button>
                                </div>
                            </div>
                            <div id="admin-pending-requests" class="space-y-3">
                                <div class="text-center py-8 text-dark-400">
                                    <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                                    <p>No requests found</p>
                                </div>
                            </div>
                        </div>

                        <!-- Settings Subtab -->
                        <div id="credit-subtab-settings" class="credit-subtab-content hidden">
                            <div class="space-y-6">
                                <!-- General Settings -->
                                <div class="bg-dark-800/30 rounded-xl p-5 border border-dark-600">
                                    <h4 class="text-base font-semibold text-dark-100 mb-4 flex items-center gap-2">
                                        <i data-lucide="sliders" class="w-4 h-4 text-primary-400"></i>
                                        General Settings
                                    </h4>
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Currency</label>
                                            <select id="admin-credit-currency" class="form-select w-full"
                                                onchange="updateCurrencySymbol()">
                                                <option value="USD" data-symbol="$">USD - US Dollar</option>
                                                <option value="EUR" data-symbol="€">EUR - Euro</option>
                                                <option value="GBP" data-symbol="£">GBP - British Pound</option>
                                                <option value="JPY" data-symbol="¥">JPY - Japanese Yen</option>
                                                <option value="CNY" data-symbol="¥">CNY - Chinese Yuan</option>
                                                <option value="IDR" data-symbol="Rp">IDR - Indonesian Rupiah</option>
                                                <option value="MYR" data-symbol="RM">MYR - Malaysian Ringgit</option>
                                                <option value="SGD" data-symbol="S$">SGD - Singapore Dollar</option>
                                                <option value="THB" data-symbol="฿">THB - Thai Baht</option>
                                                <option value="PHP" data-symbol="₱">PHP - Philippine Peso</option>
                                                <option value="VND" data-symbol="₫">VND - Vietnamese Dong</option>
                                                <option value="INR" data-symbol="₹">INR - Indian Rupee</option>
                                                <option value="KRW" data-symbol="₩">KRW - South Korean Won</option>
                                                <option value="AUD" data-symbol="A$">AUD - Australian Dollar</option>
                                                <option value="CAD" data-symbol="C$">CAD - Canadian Dollar</option>
                                                <option value="BRL" data-symbol="R$">BRL - Brazilian Real</option>
                                                <option value="MXN" data-symbol="$">MXN - Mexican Peso</option>
                                                <option value="AED" data-symbol="د.إ">AED - UAE Dirham</option>
                                                <option value="SAR" data-symbol="﷼">SAR - Saudi Riyal</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Currency
                                                Symbol</label>
                                            <input type="text" id="admin-credit-symbol" class="form-input w-full" value="$"
                                                readonly>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Welcome
                                                Credits</label>
                                            <input type="number" id="admin-credit-welcome" class="form-input w-full"
                                                value="100">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Low Balance
                                                Alert</label>
                                            <input type="number" id="admin-credit-threshold" class="form-input w-full"
                                                value="100">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Expiry
                                                Days</label>
                                            <input type="number" id="admin-credit-expiry-days" class="form-input w-full"
                                                value="365">
                                        </div>
                                    </div>
                                </div>

                                <!-- Workflow Settings -->
                                <div class="bg-dark-800/30 rounded-xl p-5 border border-dark-600">
                                    <h4 class="text-base font-semibold text-dark-100 mb-4 flex items-center gap-2">
                                        <i data-lucide="refresh-cw" class="w-4 h-4 text-cyan-400"></i>
                                        Workflow Settings
                                    </h4>
                                    <div class="grid grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Max Repeat
                                                Count</label>
                                            <input type="number" id="admin-max-repeat-count" class="form-input w-full"
                                                value="100" min="1" max="1000">
                                            <p class="text-xs text-dark-500 mt-1">Maximum times users can repeat a flow
                                                (1-1000)</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Content Retention
                                                (Days)</label>
                                            <input type="number" id="admin-content-retention-days" class="form-input w-full"
                                                value="0" min="0" max="365">
                                            <p class="text-xs text-dark-500 mt-1">How long to keep generated files. Set to 0
                                                to disable auto-deletion.</p>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-dark-400 mb-1.5">Repeat Workflow
                                            Execution Mode</label>
                                        <select id="admin-workflow-execution-mode" class="form-select w-full">
                                            <option value="sequential">Sequential (One-by-One)</option>
                                            <option value="parallel">Parallel (All at Once)</option>
                                        </select>
                                        <p class="text-xs text-dark-500 mt-1">
                                            <strong>Sequential:</strong> Repeat workflows run one after another. Safer, uses
                                            less resources.<br>
                                            <strong>Parallel:</strong> All repeat workflows run simultaneously. Faster, but
                                            uses more resources.
                                        </p>
                                    </div>
                                </div>


                                <!-- QRIS Settings -->
                                <div class="bg-dark-800/30 rounded-xl p-5 border border-dark-600">
                                    <h4 class="text-base font-semibold text-dark-100 mb-4 flex items-center gap-2">
                                        <i data-lucide="qr-code" class="w-4 h-4 text-purple-400"></i>
                                        QRIS Payment
                                    </h4>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">QRIS
                                                String</label>
                                            <textarea id="admin-qris-string"
                                                class="form-input w-full h-24 font-mono text-xs"
                                                placeholder="Paste your QRIS string here..."></textarea>
                                            <p class="text-xs text-dark-500 mt-1">This will enable QRIS payment option for
                                                users. Leave empty to disable.</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- PayPal Payment Gateway -->
                                <div class="bg-dark-800/30 rounded-xl p-5 border border-dark-600">
                                    <h4 class="text-base font-semibold text-dark-100 mb-4 flex items-center gap-2">
                                        <i data-lucide="credit-card" class="w-4 h-4 text-blue-400"></i>
                                        PayPal Payment Gateway
                                    </h4>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                                <input type="checkbox" id="admin-paypal-enabled" class="form-checkbox">
                                                <span>Enable PayPal Payments</span>
                                            </label>
                                            <p class="text-xs text-dark-500 mt-1">Accept instant payments via PayPal
                                                (credits granted immediately)</p>
                                        </div>
                                        <div id="paypal-fields" class="space-y-4 hidden">
                                            <div>
                                                <label class="flex items-center gap-2 text-sm text-dark-300 cursor-pointer">
                                                    <input type="checkbox" id="admin-paypal-sandbox" class="form-checkbox">
                                                    <span>Sandbox Mode (Testing)</span>
                                                </label>
                                                <p class="text-xs text-dark-500 mt-1">Use PayPal sandbox for testing</p>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Client
                                                    ID</label>
                                                <input type="text" id="admin-paypal-client-id" class="form-input w-full"
                                                    placeholder="PayPal Client ID from developer.paypal.com">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Secret
                                                    Key</label>
                                                <input type="password" id="admin-paypal-secret-key"
                                                    class="form-input w-full" placeholder="PayPal Secret Key">
                                            </div>
                                            <div id="paypal-usd-rate-field">
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">USD Conversion
                                                    Rate</label>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-dark-300 text-sm">1 USD =</span>
                                                    <input type="number" id="admin-paypal-usd-rate" class="form-input w-32"
                                                        placeholder="17000" step="0.01">
                                                    <span class="text-dark-400 text-sm"
                                                        id="usd-rate-currency-label">IDR</span>
                                                </div>
                                                <p class="text-xs text-dark-500 mt-1">Required for non-USD currencies.
                                                    PayPal only accepts USD.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button id="btn-save-credit-settings" class="btn-primary">
                                    <i data-lucide="save" class="w-4 h-4 inline mr-1"></i>
                                    Save Settings
                                </button>
                            </div>
                        </div>

                        <!-- Packages Subtab -->
                        <div id="credit-subtab-packages" class="credit-subtab-content hidden">
                            <div class="space-y-4">
                                <!-- Add Package Form (Accordion) -->
                                <details class="bg-dark-800/30 rounded-xl border border-dark-600 group">
                                    <summary
                                        class="p-4 cursor-pointer flex items-center justify-between text-dark-100 font-medium hover:bg-dark-700/30 rounded-xl">
                                        <span class="flex items-center gap-2">
                                            <i data-lucide="plus-circle" class="w-4 h-4 text-primary-400"></i>
                                            Add New Package
                                        </span>
                                        <i data-lucide="chevron-down"
                                            class="w-4 h-4 transition-transform group-open:rotate-180"></i>
                                    </summary>
                                    <div class="p-4 border-t border-dark-600">
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                            <div class="col-span-2">
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Package
                                                    Name</label>
                                                <input type="text" id="new-package-name" class="form-input w-full"
                                                    placeholder="e.g. Starter">
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-xs font-medium text-dark-400 mb-1.5">Credits</label>
                                                <input type="number" id="new-package-credits" class="form-input w-full"
                                                    placeholder="500">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Price</label>
                                                <input type="number" id="new-package-price" class="form-input w-full"
                                                    placeholder="50000">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Bonus
                                                    Credits</label>
                                                <input type="number" id="new-package-bonus" class="form-input w-full"
                                                    placeholder="0" value="0">
                                            </div>
                                            <div class="col-span-2 md:col-span-3">
                                                <label
                                                    class="block text-xs font-medium text-dark-400 mb-1.5">Description</label>
                                                <input type="text" id="new-package-description" class="form-input w-full"
                                                    placeholder="Short description">
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <button id="btn-create-package" class="btn-primary text-sm">
                                                <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>Create Package
                                            </button>
                                            <button type="button" onclick="window.cancelEditPackage?.()"
                                                class="btn-secondary text-sm">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </details>

                                <!-- Packages List -->
                                <div id="admin-packages-list" class="space-y-2">
                                    <div class="text-center py-8 text-dark-400">Loading packages...</div>
                                </div>
                            </div>
                        </div>

                        <!-- Node Costs Subtab -->
                        <div id="credit-subtab-nodecosts" class="credit-subtab-content hidden">
                            <div class="space-y-4">
                                <!-- Add Node Cost Form -->
                                <div class="bg-dark-800/30 rounded-xl p-4 border border-dark-600">
                                    <h4 class="text-sm font-medium text-dark-200 mb-3">Add Node Cost</h4>
                                    <div class="flex gap-3 items-end">
                                        <div class="flex-1">
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Select Node
                                                Type</label>
                                            <select id="new-node-type-select" class="form-select w-full">
                                                <option value="">-- Select a node --</option>
                                            </select>
                                        </div>
                                        <div class="w-32">
                                            <label class="block text-xs font-medium text-dark-400 mb-1.5">Cost per
                                                Call</label>
                                            <input type="number" id="new-node-cost" class="form-input w-full"
                                                placeholder="0.00" step="0.01">
                                        </div>
                                        <button id="btn-add-node-cost" class="btn-primary px-4">
                                            <i data-lucide="plus" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Node Costs List -->
                                <div id="admin-node-costs" class="space-y-2">
                                    <!-- Populated by JS -->
                                </div>
                            </div>
                        </div>

                        <!-- Coupons Subtab -->
                        <div id="credit-subtab-coupons" class="credit-subtab-content hidden">
                            <div class="space-y-4">
                                <!-- Add Coupon Form (Accordion) -->
                                <details class="bg-dark-800/30 rounded-xl border border-dark-600 group">
                                    <summary
                                        class="p-4 cursor-pointer flex items-center justify-between text-dark-100 font-medium hover:bg-dark-700/30 rounded-xl">
                                        <span class="flex items-center gap-2">
                                            <i data-lucide="plus-circle" class="w-4 h-4 text-primary-400"></i>
                                            Add New Coupon
                                        </span>
                                        <i data-lucide="chevron-down"
                                            class="w-4 h-4 transition-transform group-open:rotate-180"></i>
                                    </summary>
                                    <div class="p-4 border-t border-dark-600">
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Coupon
                                                    Code</label>
                                                <input type="text" id="new-coupon-code" class="form-input w-full font-mono"
                                                    placeholder="SAVE20">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Type</label>
                                                <select id="new-coupon-type" class="form-select w-full">
                                                    <option value="percentage">Percentage (%)</option>
                                                    <option value="fixed_discount">Fixed Discount</option>
                                                    <option value="bonus_credits">Bonus Credits</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Value</label>
                                                <input type="number" id="new-coupon-value" class="form-input w-full"
                                                    placeholder="20">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Max
                                                    Uses</label>
                                                <input type="number" id="new-coupon-max-uses" class="form-input w-full"
                                                    placeholder="Unlimited">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Valid
                                                    From</label>
                                                <input type="date" id="new-coupon-valid-from" class="form-input w-full">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Valid
                                                    Until</label>
                                                <input type="date" id="new-coupon-valid-until" class="form-input w-full">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Min
                                                    Purchase</label>
                                                <input type="number" id="new-coupon-min-purchase" class="form-input w-full"
                                                    placeholder="0" value="0">
                                            </div>
                                        </div>
                                        <button id="btn-create-coupon" class="btn-primary text-sm">
                                            <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>Create Coupon
                                        </button>
                                    </div>
                                </details>

                                <!-- Coupons List -->
                                <div id="admin-coupons-list" class="space-y-2">
                                    <div class="text-center py-8 text-dark-400">Loading coupons...</div>
                                </div>
                            </div>
                        </div>
                        <!-- Banks Subtab -->
                        <div id="credit-subtab-banks" class="credit-subtab-content hidden">
                            <div class="space-y-4">
                                <!-- Add Bank Form (Accordion) -->
                                <details class="bg-dark-800/30 rounded-xl border border-dark-600 group">
                                    <summary
                                        class="p-4 cursor-pointer flex items-center justify-between text-dark-100 font-medium hover:bg-dark-700/30 rounded-xl">
                                        <span class="flex items-center gap-2">
                                            <i data-lucide="plus-circle" class="w-4 h-4 text-primary-400"></i>
                                            Add New Bank Account
                                        </span>
                                        <i data-lucide="chevron-down"
                                            class="w-4 h-4 transition-transform group-open:rotate-180"></i>
                                    </summary>
                                    <div class="p-4 border-t border-dark-600">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Bank
                                                    Name</label>
                                                <input type="text" id="new-bank-name" class="form-input w-full"
                                                    placeholder="e.g. Bank Central Asia">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Account
                                                    Number</label>
                                                <input type="text" id="new-bank-account" class="form-input w-full"
                                                    placeholder="e.g. 1234567890">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-400 mb-1.5">Account
                                                    Holder</label>
                                                <input type="text" id="new-bank-holder" class="form-input w-full"
                                                    placeholder="e.g. PT AIKAFLOW">
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <button id="btn-create-bank" class="btn-primary text-sm">
                                                <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>Add Bank
                                            </button>
                                            <button type="button" onclick="window.cancelEditBank?.()"
                                                class="btn-secondary text-sm">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </details>

                                <p class="text-xs text-dark-500">
                                    <i data-lucide="grip-vertical" class="w-3 h-3 inline mr-1"></i>
                                    Drag banks to reorder. Users will see banks in this order during top-up.
                                </p>

                                <!-- Banks List -->
                                <div id="admin-banks-list" class="space-y-2">
                                    <div class="text-center py-8 text-dark-400">Loading banks...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Integrations Tab -->
                    <div id="admin-integrations" class="admin-content hidden">
                        <div class="space-y-4">
                            <div>
                                <h4 class="text-sm font-medium text-dark-50 mb-2">Integration API Keys</h4>
                                <p class="text-xs text-dark-400 mb-4">Configure API keys for your installed plugins and
                                    integrations.</p>
                            </div>

                            <!-- Dynamic Integration Keys Container -->
                            <div id="integration-keys-container" class="space-y-3">
                                <div class="text-center py-4 text-gray-500">
                                    <i data-lucide="loader" class="w-6 h-6 mx-auto mb-2 opacity-50 animate-spin"></i>
                                    <p class="text-sm">Loading integrations...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add/Edit User Modal -->
        <div id="modal-admin-user" class="modal hidden">
            <div class="modal-backdrop"></div>
            <div class="modal-content w-full max-w-md">
                <div class="modal-header">
                    <h3 class="text-lg font-semibold text-dark-50" id="admin-user-title">Add User</h3>
                    <button class="modal-close">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="admin-user-id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-1">Username</label>
                            <input type="text" id="admin-user-username" class="form-input w-full">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-1">Email</label>
                            <input type="email" id="admin-user-email" class="form-input w-full">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-1">WhatsApp Number</label>
                            <input type="tel" id="admin-user-whatsapp" class="form-input w-full"
                                placeholder="+628123456789">
                            <p class="text-xs text-dark-500 mt-1">Include country code (e.g., +62 for Indonesia)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-1">Password</label>
                            <input type="password" id="admin-user-password" class="form-input w-full"
                                placeholder="Leave blank to keep current">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-1">Role</label>
                            <select id="admin-user-role" class="form-select w-full">
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div id="admin-user-credits-container" class="hidden">
                            <label class="block text-sm font-medium text-dark-300 mb-1">Adjust Credits</label>
                            <div class="flex gap-2 items-center">
                                <input type="number" id="admin-user-credits" class="form-input flex-1"
                                    placeholder="Amount (+/-)">
                                <span class="text-xs text-dark-400">Current: <span
                                        id="admin-user-current-credits">0</span></span>
                            </div>
                            <p class="text-xs text-dark-500 mt-1">Enter positive to add, negative to deduct</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary modal-cancel">Cancel</button>
                    <button id="btn-admin-save-user" class="btn-primary">Save User</button>
                </div>
            </div>
        </div>
    <?php endif; ?>


    </div>

    <!-- Context Menu -->
    <div id="context-menu"
        class="fixed bg-dark-800 border border-dark-600 rounded-lg shadow-xl py-1 z-[100] hidden min-w-[160px]">
        <button class="context-menu-item" data-action="duplicate">
            <i data-lucide="copy" class="w-4 h-4"></i>
            <span>Duplicate</span>
            <span class="shortcut">Ctrl+D</span>
        </button>
        <button class="context-menu-item" data-action="delete">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
            <span>Delete</span>
            <span class="shortcut">Del</span>
        </button>
        <div class="context-menu-divider"></div>
        <button class="context-menu-item" data-action="copy">
            <i data-lucide="clipboard-copy" class="w-4 h-4"></i>
            <span>Copy</span>
            <span class="shortcut">Ctrl+C</span>
        </button>
        <button class="context-menu-item" data-action="paste">
            <i data-lucide="clipboard-paste" class="w-4 h-4"></i>
            <span>Paste</span>
            <span class="shortcut">Ctrl+V</span>
        </button>
        <div class="context-menu-divider"></div>
        <button class="context-menu-item" data-action="select-all">
            <i data-lucide="box-select" class="w-4 h-4"></i>
            <span>Select All</span>
            <span class="shortcut">Ctrl+A</span>
        </button>
    </div>

    <!-- Toast Notifications Container -->
    <div id="toast-container" class="fixed bottom-20 right-4 z-[200] space-y-2"></div>

    <!-- Hidden file input for import -->
    <input type="file" id="file-import" accept=".json,.aikaflow" class="hidden">

    <!-- App Data (for JS) -->
    <script>
        window.AIKAFLOW = {
            user: {
                id: <?= json_encode($user['id']) ?>,
                username: <?= json_encode($user['username']) ?>,
                email: <?= json_encode($user['email']) ?>,
                api_key: <?= json_encode($user['api_key'] ?? '') ?>,
                role: <?= json_encode($user['role'] ?? 'user') ?>,
                isAdmin: <?= json_encode((int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin') ?>,
                isImpersonating: <?= json_encode($isImpersonating) ?>,
                language: <?= json_encode($user['language'] ?? 'en') ?>
            },
            csrf: <?= json_encode($csrfToken) ?>,
            apiUrl: <?= json_encode(APP_URL . '/api') ?>,
            baseUrl: <?= json_encode(APP_URL) ?>,
            siteTitle: <?= json_encode($siteTitle) ?>,
            version: '1.0.0'
        };
    </script>

    <!-- App Scripts -->
    <script src="assets/js/i18n.js"></script>
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
    <script src="assets/js/notifications.js"></script>
    <script src="assets/js/editor.js"></script>
    <?php if ((int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin'): ?>
        <script src="assets/js/admin.js?v=<?= time() ?>"></script>
    <?php endif; ?>

    <!-- Plugin Scripts -->
    <?php
    // Load scripts from enabled plugins
    PluginManager::loadPlugins();
    $plugins = PluginManager::getEnabledPlugins();
    foreach ($plugins as $plugin):
        if (!empty($plugin['scripts'])):
            foreach ($plugin['scripts'] as $script):
                $scriptPath = 'plugins/' . $plugin['id'] . '/' . $script;
                if (file_exists(__DIR__ . '/' . $scriptPath)):
                    ?>
                    <script src="<?= htmlspecialchars($scriptPath) ?>"></script>
                    <?php
                endif;
            endforeach;
        endif;
    endforeach;
    ?>

    <?php if (!empty($headwayWidgetId)): ?>
        <!-- Headway Changelog Widget -->
        <script>
            var HW_config = {
                selector: "#btn-notifications",
                account: "<?= htmlspecialchars($headwayWidgetId) ?>"
            };
        </script>
        <script async src="https://cdn.headwayapp.co/widget.js"></script>
    <?php endif; ?>

    <!-- Initialize Lucide Icons -->
    <script>
        lucide.createIcons();
    </script>

    <?php if (!empty($customFooterJs)): ?>
        <!-- Custom Footer Scripts -->
        <?= str_replace('\\n', "\n", $customFooterJs) ?>
    <?php endif; ?>
</body>

</html>