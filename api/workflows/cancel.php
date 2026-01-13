<?php
/**
 * AIKAFLOW API - Cancel Execution
 * 
 * POST /api/workflows/cancel.php
 * Body: { id }
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
$executionId = (int)($input['id'] ?? 0);

if (!$executionId) {
    errorResponse('Execution ID is required');
}

try {
    // Verify ownership
    $execution = Database::fetchOne(
        "SELECT id, status FROM workflow_executions WHERE id = ? AND user_id = ?",
        [$executionId, $user['id']]
    );
    
    if (!$execution) {
        errorResponse('Execution not found', 404);
    }
    
    if (in_array($execution['status'], ['completed', 'failed', 'cancelled'])) {
        errorResponse('Execution already finished');
    }
    
    // Cancel execution
    Database::update(
        'workflow_executions',
        [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $executionId]
    );
    
    // Cancel pending tasks
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
    
    successResponse(['message' => 'Execution cancelled']);
    
} catch (Exception $e) {
    error_log('Cancel execution error: ' . $e->getMessage());
    errorResponse('Failed to cancel execution', 500);
}