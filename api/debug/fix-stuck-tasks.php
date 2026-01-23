<?php
/**
 * AIKAFLOW - Fix Stuck Social Post Tasks
 * 
 * GET /api/debug/fix-stuck-tasks.php
 * Marks stuck social-post node tasks as completed
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

try {
    // Find stuck social-post tasks
    $stuckTasks = Database::fetchAll(
        "SELECT nt.id, nt.node_id, nt.node_type, nt.status, nt.external_task_id, nt.execution_id
         FROM node_tasks nt
         WHERE nt.node_type = 'social-post' 
         AND nt.status = 'processing'
         ORDER BY nt.id DESC"
    );

    if (empty($stuckTasks)) {
        echo json_encode([
            'success' => true,
            'message' => 'No stuck social-post tasks found',
            'fixed' => 0
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $fixed = 0;
    $fixedIds = [];

    foreach ($stuckTasks as $task) {
        // Mark node task as completed
        Database::update(
            'node_tasks',
            [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'output_data' => json_encode(['note' => 'Auto-completed - social post was successful'])
            ],
            'id = :id',
            ['id' => $task['id']]
        );

        $fixed++;
        $fixedIds[] = $task['id'];

        // Check if this was the last pending task for the execution
        $pendingCount = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM node_tasks WHERE execution_id = ? AND status NOT IN ('completed', 'failed')",
            [$task['execution_id']]
        );

        if ((int) ($pendingCount['cnt'] ?? 0) === 0) {
            // All tasks complete - mark execution as completed
            Database::update(
                'workflow_executions',
                [
                    'status' => 'completed',
                    'progress' => 100,
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $task['execution_id']]
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Fixed $fixed stuck social-post task(s)",
        'fixed' => $fixed,
        'task_ids' => $fixedIds
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
