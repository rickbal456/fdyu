<?php
/**
 * AIKAFLOW Plugin API - Delete/Uninstall plugin
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

// Only accept POST or DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

$pluginsDir = dirname(dirname(__DIR__)) . '/plugins';
$pluginDir = $pluginsDir . '/' . $pluginId;

if (!is_dir($pluginDir)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plugin not found']);
    exit;
}

try {
    // Recursively delete plugin directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($pluginDir);

    echo json_encode([
        'success' => true,
        'message' => 'Plugin uninstalled successfully',
        'pluginId' => $pluginId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
