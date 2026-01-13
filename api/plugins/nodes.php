<?php
/**
 * Get Available Plugin Nodes API
 * 
 * Returns a list of all enabled node types from plugins
 */

define('AIKAFLOW', true);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/PluginManager.php';

header('Content-Type: application/json');

// Load plugins
PluginManager::loadPlugins();
$plugins = PluginManager::getEnabledPlugins();

$nodes = [];

foreach ($plugins as $pluginId => $plugin) {
    // Get node types from plugin (skip plugins that have no nodeTypes or empty array)
    if (!empty($plugin['nodeTypes'])) {
        foreach ($plugin['nodeTypes'] as $nodeType) {
            // Try to build a readable name from node type
            $name = ucwords(str_replace(['aflow-', '-'], ['', ' '], $nodeType));

            $nodes[] = [
                'type' => $nodeType,
                'name' => $plugin['name'] ?? $name,
                'plugin' => $plugin['name'] ?? $pluginId,
                'category' => $plugin['category'] ?? 'utility'
            ];
        }
    }
}

// Sort by name
usort($nodes, fn($a, $b) => strcmp($a['name'], $b['name']));

echo json_encode([
    'success' => true,
    'nodes' => $nodes
]);
