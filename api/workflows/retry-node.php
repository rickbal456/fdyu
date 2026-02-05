<?php

/**
 * AIKAFLOW - Retry Failed Node API
 * 
 * Retries a failed node in a workflow execution.
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

    if ($nodeTask['status'] !== 'failed') {
        jsonResponse(['success' => false, 'error' => 'Only failed nodes can be retried. Current status: ' . $nodeTask['status']]);
    }

    // Reset node task to pending
    Database::update(
        'node_tasks',
        [
            'status' => 'pending',
            'error_message' => null,
            'external_task_id' => null,
            'result_url' => null,
            'output_data' => null,
            'started_at' => null,
            'completed_at' => null
        ],
        'id = :id',
        ['id' => $nodeTaskId]
    );

    // Queue the node for re-execution
    Database::insert('task_queue', [
        'task_type' => 'node_execution',
        'payload' => json_encode([
            'execution_id' => $executionId,
            'task_id' => $nodeTaskId,
            'node_id' => $nodeTask['node_id'],
            'node_type' => $nodeTask['node_type']
        ]),
        'priority' => 1,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // If workflow was marked as failed, reset it to running
    $execution = Database::fetchOne(
        "SELECT status FROM workflow_executions WHERE id = ?",
        [$executionId]
    );

    if ($execution && $execution['status'] === 'failed') {
        Database::update(
            'workflow_executions',
            [
                'status' => 'running',
                'error_message' => null
            ],
            'id = :id',
            ['id' => $executionId]
        );
    }

    jsonResponse([
        'success' => true,
        'message' => 'Node queued for retry',
        'node_task_id' => $nodeTaskId
    ]);
} catch (Exception $e) {
    error_log('Retry node error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
