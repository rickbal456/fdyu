<?php
/**
 * AIKAFLOW Plugin API - Toggle plugin enabled/disabled
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
    echo json_encode(['success' => false, 'error' => 'Auth error']);
    exit;
}



// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['pluginId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Plugin ID is required']);
    exit;
}

$pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['pluginId']);
$enabled = isset($input['enabled']) ? (bool) $input['enabled'] : true;

$pluginsDir = dirname(dirname(__DIR__)) . '/plugins';
$pluginDir = $pluginsDir . '/' . $pluginId;
$manifestPath = $pluginDir . '/plugin.json';

if (!is_dir($pluginDir) || !file_exists($manifestPath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plugin not found']);
    exit;
}

try {
    $manifest = json_decode(file_get_contents($manifestPath), true);

    if (!$manifest) {
        throw new Exception('Invalid plugin manifest');
    }

    $manifest['enabled'] = $enabled;

    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'success' => true,
        'message' => $enabled ? 'Plugin enabled' : 'Plugin disabled',
        'pluginId' => $pluginId,
        'enabled' => $enabled
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
