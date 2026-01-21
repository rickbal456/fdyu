<?php
/**
 * AIKAFLOW API - Cancel Execution
 * 
 * POST /api/workflows/cancel.php
 * Body: { id, cancelQueued? }
 * 
 * Options:
 * - id: Execution ID to cancel
 * - cancelQueued: If true, also cancel all pending/queued executions for the same workflow
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('POST');
$user = requireAuth();

$input = getJsonInput();
$executionId = (int) ($input['id'] ?? 0);
$cancelQueued = (bool) ($input['cancelQueued'] ?? true); // Default to true - cancel all queued

if (!$executionId) {
    errorResponse('Execution ID is required');
}

try {
    // Verify ownership and get workflow_id
    $execution = Database::fetchOne(
        "SELECT id, status, workflow_id FROM workflow_executions WHERE id = ? AND user_id = ?",
        [$executionId, $user['id']]
    );

    if (!$execution) {
        errorResponse('Execution not found', 404);
    }

    if (in_array($execution['status'], ['completed', 'failed', 'cancelled'])) {
        errorResponse('Execution already finished');
    }

    $cancelledIds = [$executionId];

    // Cancel the main execution
    Database::update(
        'workflow_executions',
        [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $executionId]
    );

    // Cancel pending tasks for this execution
    Database::query(
        "UPDATE node_tasks SET status = 'failed', error_message = 'Cancelled by user' 
         WHERE execution_id = ? AND status IN ('pending', 'queued', 'processing')",
        [$executionId]
    );

    // Remove from queue
    Database::query(
        "DELETE FROM task_queue WHERE payload LIKE ?",
        ['%"execution_id":' . $executionId . '%']
    );

    // If cancelQueued is true, also cancel all pending queued executions for the same workflow
    if ($cancelQueued && $execution['workflow_id']) {
        // Find all pending executions for the same workflow that haven't started yet
        $pendingExecutions = Database::fetchAll(
            "SELECT id FROM workflow_executions 
             WHERE workflow_id = ? 
             AND user_id = ? 
             AND status = 'pending' 
             AND started_at IS NULL
             AND id != ?",
            [$execution['workflow_id'], $user['id'], $executionId]
        );

        foreach ($pendingExecutions as $pending) {
            $pendingId = $pending['id'];
            $cancelledIds[] = $pendingId;

            // Cancel the execution
            Database::update(
                'workflow_executions',
                [
                    'status' => 'cancelled',
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $pendingId]
            );

            // Cancel pending tasks
            Database::query(
                "UPDATE node_tasks SET status = 'failed', error_message = 'Cancelled by user (queued execution)' 
                 WHERE execution_id = ? AND status IN ('pending', 'queued')",
                [$pendingId]
            );

            // Remove from queue
            Database::query(
                "DELETE FROM task_queue WHERE payload LIKE ?",
                ['%"execution_id":' . $pendingId . '%']
            );
        }
    }

    $queuedCount = count($cancelledIds) - 1;
    $message = 'Execution cancelled';
    if ($queuedCount > 0) {
        $message .= " (plus {$queuedCount} queued execution" . ($queuedCount > 1 ? 's' : '') . ")";
    }

    successResponse([
        'message' => $message,
        'cancelledIds' => $cancelledIds,
        'queuedCancelled' => $queuedCount
    ]);

} catch (Exception $e) {
    error_log('Cancel execution error: ' . $e->getMessage());
    errorResponse('Failed to cancel execution', 500);
}