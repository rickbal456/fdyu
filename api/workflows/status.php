<?php

/**
 * AIKAFLOW API - Get Execution Status
 * 
 * GET /api/workflows/status.php?id={executionId}
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('GET');
$user = requireAuth();

// Accept both 'id' and 'executionId' query parameters
$executionId = (int) ($_GET['id'] ?? $_GET['executionId'] ?? 0);

if (!$executionId) {
    errorResponse('Execution ID is required');
}

try {
    // Get execution
    $execution = Database::fetchOne(
        "SELECT * FROM workflow_executions WHERE id = ? AND user_id = ?",
        [$executionId, $user['id']]
    );

    if (!$execution) {
        errorResponse('Execution not found', 404);
    }

    // Get node statuses
    $tasks = Database::fetchAll(
        "SELECT node_id, node_type, status, result_url, output_data, error_message, started_at, completed_at 
         FROM node_tasks WHERE execution_id = ? ORDER BY id ASC",
        [$executionId]
    );

    $nodeStatuses = array_map(function ($task) {
        // Get result URL from result_url column, or fallback to output_data.video
        $resultUrl = $task['result_url'];
        if (empty($resultUrl) && !empty($task['output_data'])) {
            $outputData = json_decode($task['output_data'], true);
            if ($outputData) {
                $resultUrl = $outputData['video'] ?? $outputData['url'] ?? $outputData['image'] ?? null;
            }
        }

        return [
            'nodeId' => $task['node_id'],
            'nodeType' => $task['node_type'],
            'status' => $task['status'],
            'resultUrl' => $resultUrl,
            'error' => $task['error_message'],
            'startedAt' => $task['started_at'],
            'completedAt' => $task['completed_at']
        ];
    }, $tasks);

    // Get flow statuses (for multi-flow execution)
    $flowStatuses = [];
    $flows = Database::fetchAll(
        "SELECT flow_id, entry_node_id, flow_name, status, priority, error_message, started_at, completed_at 
         FROM flow_executions WHERE execution_id = ? ORDER BY priority ASC, id ASC",
        [$executionId]
    );

    if (!empty($flows)) {
        $flowStatuses = array_map(function ($flow) {
            return [
                'flowId' => $flow['flow_id'],
                'entryNodeId' => $flow['entry_node_id'],
                'flowName' => $flow['flow_name'],
                'status' => $flow['status'],
                'priority' => (int) $flow['priority'],
                'error' => $flow['error_message'],
                'startedAt' => $flow['started_at'],
                'completedAt' => $flow['completed_at']
            ];
        }, $flows);
    }

    // Calculate progress - account for iterations
    $repeatCount = (int) ($execution['repeat_count'] ?? 1);
    $currentIteration = (int) ($execution['current_iteration'] ?? 1);
    $iterationOutputs = json_decode($execution['iteration_outputs'] ?? '[]', true) ?: [];

    $total = count($tasks);
    $completed = count(array_filter($tasks, fn($t) => in_array($t['status'], ['completed', 'failed'])));

    // Progress calculation: (completed iterations + current iteration progress) / total iterations
    $iterationProgress = $total > 0 ? ($completed / $total) : 0;
    $overallProgress = (($currentIteration - 1) + $iterationProgress) / $repeatCount;
    $progress = round($overallProgress * 100);

    // Parse all_results from output_data for completed executions
    $allResults = [];
    if ($execution['output_data']) {
        $outputData = json_decode($execution['output_data'], true);
        $allResults = $outputData['all_results'] ?? [];
    }

    // Get count of queued executions for the same workflow
    $queuedExecutions = [];
    if ($execution['workflow_id']) {
        $queuedExecutions = Database::fetchAll(
            "SELECT id, status, created_at FROM workflow_executions 
             WHERE workflow_id = ? 
             AND user_id = ? 
             AND status = 'pending' 
             AND started_at IS NULL
             AND id != ?
             ORDER BY id ASC",
            [$execution['workflow_id'], $user['id'], $executionId]
        );
    }

    successResponse([
        'executionId' => (int) $execution['id'],
        'status' => $execution['status'],
        'progress' => $progress,
        'resultUrl' => $execution['result_url'],
        'error' => $execution['error_message'],
        'nodeStatuses' => $nodeStatuses,
        'flowStatuses' => $flowStatuses,
        'iterations' => [
            'total' => $repeatCount,
            'current' => $currentIteration,
            'completed' => count($iterationOutputs),
            'outputs' => $iterationOutputs
        ],
        'allResults' => $allResults,
        'queuedExecutions' => array_map(function ($exec) {
            return [
                'id' => (int) $exec['id'],
                'status' => $exec['status'],
                'createdAt' => $exec['created_at']
            ];
        }, $queuedExecutions),
        'queuedCount' => count($queuedExecutions),
        'startedAt' => $execution['started_at'],
        'completedAt' => $execution['completed_at']
    ]);
} catch (Exception $e) {
    error_log('Get execution status error: ' . $e->getMessage());
    errorResponse('Failed to get execution status', 500);
}
