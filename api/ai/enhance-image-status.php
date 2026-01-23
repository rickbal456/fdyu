<?php
/**
 * AIKAFLOW API - Image Enhancement Status Check
 * 
 * Frontend polls this endpoint to check enhancement progress.
 * 
 * GET /api/ai/enhance-image-status.php?taskId={taskId}
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/PluginManager.php';
require_once __DIR__ . '/../helpers.php';

// Load plugins (for storage handler)
PluginManager::loadPlugins();

header('Content-Type: application/json');

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$taskId = $_GET['taskId'] ?? '';
$nodeId = $_GET['nodeId'] ?? '';

if (empty($taskId) && empty($nodeId)) {
    errorResponse('Task ID or Node ID is required');
}

try {
    // Find the task in database
    $whereClause = !empty($nodeId) ? "node_id = ?" : "external_task_id = ?";
    $param = !empty($nodeId) ? $nodeId : $taskId;

    $task = Database::fetchOne(
        "SELECT * FROM enhancement_tasks WHERE {$whereClause} AND user_id = ? AND provider = 'rhub-enhance'",
        [$param, $user['id']]
    );

    if (!$task) {
        errorResponse('Task not found', 404);
    }

    $status = $task['status'];
    $result = null;
    $error = null;

    if ($status === 'completed') {
        // Get result URL
        $resultData = json_decode($task['result_data'] ?? '{}', true);
        $result = $resultData['enhanced_url'] ?? $resultData['result_url'] ?? null;
    } elseif ($status === 'failed') {
        $resultData = json_decode($task['result_data'] ?? '{}', true);
        $error = $resultData['error'] ?? 'Enhancement failed';
    }

    successResponse([
        'taskId' => $task['external_task_id'],
        'nodeId' => $task['node_id'],
        'status' => $status,
        'result' => $result,
        'error' => $error
    ]);

} catch (Exception $e) {
    error_log('Image enhance status error: ' . $e->getMessage());
    errorResponse('Failed to check status: ' . $e->getMessage(), 500);
}
