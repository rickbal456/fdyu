<?php
/**
 * AIKAFLOW API - Cleanup Executions
 * 
 * POST /api/workflows/cleanup.php - Clean up old/aborted executions
 * DELETE /api/workflows/cleanup.php - Delete specific execution
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        handleCleanup($user);
        break;
    case 'DELETE':
        handleDelete($user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * Clean up old executions
 */
function handleCleanup(array $user): void
{
    $input = getJsonInput();

    // What to clean: 'aborted', 'failed', 'completed', 'old', 'all'
    $type = $input['type'] ?? 'aborted';

    // For 'old' type: how many days old
    $days = min(365, max(1, (int) ($input['days'] ?? 30)));

    try {
        $deleted = 0;

        switch ($type) {
            case 'aborted':
                // Delete all cancelled/aborted executions
                $deleted = Database::delete(
                    'workflow_executions',
                    'user_id = :user_id AND status = :status',
                    ['user_id' => $user['id'], 'status' => 'cancelled']
                );
                break;

            case 'failed':
                // Delete all failed executions
                $deleted = Database::delete(
                    'workflow_executions',
                    'user_id = :user_id AND status = :status',
                    ['user_id' => $user['id'], 'status' => 'failed']
                );
                break;

            case 'completed':
                // Delete completed executions older than X days
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $deleted = Database::delete(
                    'workflow_executions',
                    'user_id = :user_id AND status = :status AND completed_at < :cutoff',
                    ['user_id' => $user['id'], 'status' => 'completed', 'cutoff' => $cutoff]
                );
                break;

            case 'old':
                // Delete all executions older than X days (regardless of status)
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $deleted = Database::delete(
                    'workflow_executions',
                    'user_id = :user_id AND created_at < :cutoff AND status NOT IN (:running, :pending)',
                    ['user_id' => $user['id'], 'cutoff' => $cutoff, 'running' => 'running', 'pending' => 'pending']
                );
                break;

            case 'all':
                // Delete all non-running executions
                $deleted = Database::delete(
                    'workflow_executions',
                    'user_id = :user_id AND status NOT IN (:running, :pending)',
                    ['user_id' => $user['id'], 'running' => 'running', 'pending' => 'pending']
                );
                break;

            default:
                errorResponse('Invalid cleanup type');
        }

        successResponse([
            'message' => "Cleaned up $deleted execution(s)",
            'deleted' => $deleted,
            'type' => $type
        ]);

    } catch (Exception $e) {
        error_log('Cleanup executions error: ' . $e->getMessage());
        errorResponse('Failed to cleanup executions', 500);
    }
}

/**
 * Delete a specific execution
 */
function handleDelete(array $user): void
{
    $input = getJsonInput();
    $executionId = (int) ($input['id'] ?? 0);

    if (!$executionId) {
        errorResponse('Execution ID is required');
    }

    try {
        // Verify ownership and not running
        $execution = Database::fetchOne(
            "SELECT id, status FROM workflow_executions WHERE id = ? AND user_id = ?",
            [$executionId, $user['id']]
        );

        if (!$execution) {
            errorResponse('Execution not found', 404);
        }

        if (in_array($execution['status'], ['running', 'pending'])) {
            errorResponse('Cannot delete running execution. Cancel it first.');
        }

        // Delete execution (cascades to node_tasks, flow_executions)
        $deleted = Database::delete(
            'workflow_executions',
            'id = :id AND user_id = :user_id',
            ['id' => $executionId, 'user_id' => $user['id']]
        );

        if ($deleted > 0) {
            successResponse(['message' => 'Execution deleted']);
        } else {
            errorResponse('Execution not found', 404);
        }

    } catch (Exception $e) {
        error_log('Delete execution error: ' . $e->getMessage());
        errorResponse('Failed to delete execution', 500);
    }
}
