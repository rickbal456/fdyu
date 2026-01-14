<?php
/**
 * AIKAFLOW - Debug Queue Status
 * 
 * GET /api/debug/queue.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

try {
    // Get queue counts
    $queueStats = Database::fetchOne(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
         FROM task_queue"
    );

    // Get recent queue items
    $recentTasks = Database::fetchAll(
        "SELECT id, task_type, status, priority, attempts, locked_by, created_at, locked_at 
         FROM task_queue 
         ORDER BY id DESC 
         LIMIT 10"
    );

    // Get node_tasks status for recent execution
    $recentExecution = Database::fetchOne(
        "SELECT id, status, started_at, completed_at FROM workflow_executions ORDER BY id DESC LIMIT 1"
    );

    $nodeTasks = [];
    if ($recentExecution) {
        $nodeTasks = Database::fetchAll(
            "SELECT id, node_id, node_type, status, started_at, completed_at 
             FROM node_tasks 
             WHERE execution_id = ?",
            [$recentExecution['id']]
        );
    }

    // Check if worker has run recently
    $workerLogPath = __DIR__ . '/../../logs/worker_debug.log';
    $workerLogExists = file_exists($workerLogPath);
    $workerLogTime = $workerLogExists ? date('Y-m-d H:i:s', filemtime($workerLogPath)) : null;

    echo json_encode([
        'success' => true,
        'queue_stats' => $queueStats,
        'recent_queue_items' => $recentTasks,
        'recent_execution' => $recentExecution,
        'node_tasks' => $nodeTasks,
        'worker_log' => [
            'exists' => $workerLogExists,
            'last_modified' => $workerLogTime
        ],
        'diagnosis' => [
            'has_pending_queue' => ($queueStats['pending'] ?? 0) > 0,
            'worker_seems_running' => $workerLogExists && (time() - filemtime($workerLogPath)) < 300
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
