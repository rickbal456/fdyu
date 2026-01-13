<?php
/**
 * AIKAFLOW API - Get Execution History
 * 
 * GET /api/workflows/history.php?workflow_id={id}&limit={limit}&status={status}
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

$workflowId = (int) ($_GET['workflow_id'] ?? 0);
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$status = $_GET['status'] ?? null; // running, completed, failed, cancelled, pending

try {
    $params = ['user_id' => $user['id']];
    $whereClause = 'we.user_id = :user_id';

    if ($workflowId) {
        $whereClause .= ' AND we.workflow_id = :workflow_id';
        $params['workflow_id'] = $workflowId;
    }

    // Filter by status
    if ($status) {
        if ($status === 'current') {
            // Running or pending
            $whereClause .= " AND we.status IN ('running', 'pending', 'queued')";
        } elseif ($status === 'completed') {
            $whereClause .= " AND we.status = 'completed'";
        } elseif ($status === 'aborted') {
            // Cancelled or failed
            $whereClause .= " AND we.status IN ('cancelled', 'failed')";
        } else {
            $whereClause .= ' AND we.status = :status';
            $params['status'] = $status;
        }
    }

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) FROM workflow_executions we WHERE {$whereClause}";
    $total = (int) Database::fetchColumn($countSql, $params);

    // Get executions with workflow name
    $executions = Database::fetchAll(
        "SELECT we.id, we.workflow_id, we.status, we.result_url, we.error_message,
                we.started_at, we.completed_at, we.created_at,
                COALESCE(w.name, 'Untitled Workflow') as workflow_name
         FROM workflow_executions we
         LEFT JOIN workflows w ON we.workflow_id = w.id
         WHERE {$whereClause}
         ORDER BY we.created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    $history = [];
    foreach ($executions as $exec) {
        // Get node tasks for this execution
        $tasks = Database::fetchAll(
            "SELECT node_id, node_type, status, result_url 
             FROM node_tasks WHERE execution_id = ? ORDER BY id ASC",
            [$exec['id']]
        );

        $nodes = array_map(function ($task) {
            return [
                'id' => $task['node_id'],
                'type' => $task['node_type'],
                'status' => $task['status'],
                'name' => ucwords(str_replace('-', ' ', $task['node_type']))
            ];
        }, $tasks);

        $completedNodes = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
        $totalNodes = count($tasks);
        $progress = $totalNodes > 0 ? round(($completedNodes / $totalNodes) * 100) : 0;

        $history[] = [
            'id' => (int) $exec['id'],
            'workflowId' => $exec['workflow_id'] ? (int) $exec['workflow_id'] : null,
            'workflowName' => $exec['workflow_name'],
            'status' => $exec['status'],
            'resultUrl' => $exec['result_url'],
            'error' => $exec['error_message'],
            'startedAt' => $exec['started_at'],
            'completedAt' => $exec['completed_at'],
            'nodes' => $nodes,
            'progress' => $progress,
            'completedNodes' => $completedNodes,
            'totalNodes' => $totalNodes,
            'duration' => $exec['completed_at'] && $exec['started_at']
                ? strtotime($exec['completed_at']) - strtotime($exec['started_at'])
                : null
        ];
    }

    successResponse([
        'history' => $history,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => ($page * $limit) < $total
        ]
    ]);

} catch (Exception $e) {
    error_log('Get execution history error: ' . $e->getMessage());
    errorResponse('Failed to get execution history', 500);
}