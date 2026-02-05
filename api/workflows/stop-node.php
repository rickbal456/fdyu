<?php

/**
 * AIKAFLOW - Stop Processing Node API
 * 
 * Stops a processing node and marks it as failed.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$executionId = (int)($input['execution_id'] ?? 0);
$nodeTaskId = (int)($input['node_task_id'] ?? 0);

if (!$executionId || !$nodeTaskId) {
    jsonResponse(['success' => false, 'error' => 'execution_id and node_task_id are required']);
}

try {
    // Get the node task
    $nodeTask = Database::fetchOne(
        "SELECT * FROM node_tasks WHERE id = ? AND execution_id = ?",
        [$nodeTaskId, $executionId]
    );

    if (!$nodeTask) {
        jsonResponse(['success' => false, 'error' => 'Node task not found']);
    }

    if ($nodeTask['status'] !== 'processing' && $nodeTask['status'] !== 'pending') {
        jsonResponse(['success' => false, 'error' => 'Node is not processing. Current status: ' . $nodeTask['status']]);
    }

    // Mark node task as failed (stopped by user)
    Database::update(
        'node_tasks',
        [
            'status' => 'failed',
            'error_message' => 'Stopped by user',
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $nodeTaskId]
    );

    // Remove any pending poll tasks for this node from task_queue
    Database::query(
        "DELETE FROM task_queue WHERE task_type = 'poll_api_status' AND payload LIKE ?",
        ['%"node_task_id":' . $nodeTaskId . '%']
    );

    // Log it
    error_log("Node task $nodeTaskId stopped by user (execution $executionId)");

    jsonResponse([
        'success' => true,
        'message' => 'Node stopped',
        'node_task_id' => $nodeTaskId
    ]);
} catch (Exception $e) {
    error_log('Stop node error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
