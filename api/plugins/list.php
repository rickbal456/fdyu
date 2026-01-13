<?php
/**
 * AIKAFLOW Plugin API - List installed plugins
 */

// Suppress PHP errors from being output as HTML
error_reporting(0);
ini_set('display_errors', 0);

define('AIKAFLOW', true);

// Set JSON header early
header('Content-Type: application/json');

try {
    require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Auth error: ' . $e->getMessage()]);
    exit;
}



// Plugin list is publicly accessible for shared workflow viewing
// Authentication is NOT required for listing plugins (read-only operation)
// If you need to restrict this, add Auth::check() back

$pluginsDir = dirname(dirname(__DIR__)) . '/plugins';
$plugins = [];

// Scan plugins directory
if (is_dir($pluginsDir)) {
    $dirs = scandir($pluginsDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..' || $dir === 'index.php' || $dir === '.htaccess') {
            continue;
        }

        $pluginPath = $pluginsDir . '/' . $dir;
        $manifestPath = $pluginPath . '/plugin.json';

        if (is_dir($pluginPath) && file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if ($manifest) {
                $pluginType = $manifest['type'] ?? 'node'; // default is node plugin
                $hasNodes = !empty($manifest['nodeTypes']) || !empty($manifest['nodes']);

                $plugins[] = [
                    'id' => $dir,
                    'name' => $manifest['name'] ?? $dir,
                    'description' => $manifest['description'] ?? '',
                    'version' => $manifest['version'] ?? '1.0.0',
                    'author' => $manifest['author'] ?? 'Unknown',
                    'icon' => $manifest['icon'] ?? 'puzzle',
                    'category' => $manifest['category'] ?? 'utility',
                    'color' => $manifest['color'] ?? 'gray',
                    'type' => $pluginType,
                    'enabled' => $manifest['enabled'] ?? true,
                    'nodeTypes' => $manifest['nodeTypes'] ?? $manifest['nodes'] ?? [],
                    'hasNodes' => $hasNodes,
                    'scripts' => $manifest['scripts'] ?? [],
                    'capabilities' => $manifest['capabilities'] ?? [],
                    'configFields' => $manifest['configFields'] ?? [],
                    'settings' => $manifest['settings'] ?? [],
                    'apiConfig' => $manifest['apiConfig'] ?? null,
                    'optionalProviders' => $manifest['optionalProviders'] ?? []
                ];
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'plugins' => $plugins
]);
