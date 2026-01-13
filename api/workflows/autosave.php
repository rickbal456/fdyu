<?php
/**
 * AIKAFLOW API - Workflow Autosave
 * 
 * Saves workflow data automatically to database for recovery
 * 
 * GET /api/workflows/autosave.php - Get latest autosave for current/specified workflow
 * POST /api/workflows/autosave.php - Save autosave data
 * DELETE /api/workflows/autosave.php - Clear autosave after successful save
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
    case 'GET':
        handleGet($user);
        break;
    case 'POST':
        handlePost($user);
        break;
    case 'DELETE':
        handleDelete($user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * Get autosave data
 */
function handleGet(array $user): void
{
    $workflowId = isset($_GET['workflow_id']) ? (int) $_GET['workflow_id'] : null;

    try {
        if ($workflowId) {
            // Get autosave for specific workflow
            $result = Database::fetchOne(
                "SELECT json_data, saved_at FROM workflow_autosaves 
                 WHERE user_id = ? AND workflow_id = ?",
                [$user['id'], $workflowId]
            );
        } else {
            // Get autosave for new/unsaved workflow (workflow_id IS NULL)
            $result = Database::fetchOne(
                "SELECT json_data, saved_at FROM workflow_autosaves 
                 WHERE user_id = ? AND workflow_id IS NULL",
                [$user['id']]
            );
        }

        if ($result) {
            $data = json_decode($result['json_data'], true);
            successResponse([
                'hasAutosave' => true,
                'savedAt' => $result['saved_at'],
                'data' => $data
            ]);
        } else {
            successResponse([
                'hasAutosave' => false,
                'data' => null
            ]);
        }
    } catch (Exception $e) {
        error_log('Get autosave error: ' . $e->getMessage());
        errorResponse('Failed to get autosave data', 500);
    }
}

/**
 * Save autosave data
 */
function handlePost(array $user): void
{
    $input = getJsonInput();

    if (!isset($input['data']) || !is_array($input['data'])) {
        errorResponse('Workflow data is required');
    }

    $workflowId = isset($input['workflowId']) ? (int) $input['workflowId'] : null;
    $jsonData = json_encode($input['data'], JSON_UNESCAPED_UNICODE);

    try {
        if ($workflowId) {
            // Verify user owns this workflow
            $workflow = Database::fetchOne(
                "SELECT id FROM workflows WHERE id = ? AND user_id = ?",
                [$workflowId, $user['id']]
            );

            if (!$workflow) {
                errorResponse('Workflow not found', 404);
            }

            // Upsert autosave for existing workflow
            Database::query(
                "INSERT INTO workflow_autosaves (user_id, workflow_id, json_data) 
                 VALUES (:user_id, :workflow_id, :json_data)
                 ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), saved_at = CURRENT_TIMESTAMP",
                ['user_id' => $user['id'], 'workflow_id' => $workflowId, 'json_data' => $jsonData]
            );
        } else {
            // Upsert autosave for new/unsaved workflow
            // First check if one exists
            $existing = Database::fetchOne(
                "SELECT id FROM workflow_autosaves WHERE user_id = ? AND workflow_id IS NULL",
                [$user['id']]
            );

            if ($existing) {
                Database::update(
                    'workflow_autosaves',
                    ['json_data' => $jsonData],
                    'id = :id',
                    ['id' => $existing['id']]
                );
            } else {
                Database::insert('workflow_autosaves', [
                    'user_id' => $user['id'],
                    'workflow_id' => null,
                    'json_data' => $jsonData
                ]);
            }
        }

        successResponse([
            'message' => 'Autosave successful',
            'savedAt' => date('Y-m-d H:i:s')
        ]);

    } catch (Exception $e) {
        error_log('Save autosave error: ' . $e->getMessage());
        errorResponse('Failed to save autosave data', 500);
    }
}

/**
 * Delete autosave data
 */
function handleDelete(array $user): void
{
    $input = getJsonInput();
    $workflowId = isset($input['workflowId']) ? (int) $input['workflowId'] : null;

    try {
        if ($workflowId) {
            Database::delete(
                'workflow_autosaves',
                'user_id = :user_id AND workflow_id = :workflow_id',
                ['user_id' => $user['id'], 'workflow_id' => $workflowId]
            );
        } else {
            Database::delete(
                'workflow_autosaves',
                'user_id = :user_id AND workflow_id IS NULL',
                ['user_id' => $user['id']]
            );
        }

        successResponse(['message' => 'Autosave cleared']);

    } catch (Exception $e) {
        error_log('Delete autosave error: ' . $e->getMessage());
        errorResponse('Failed to clear autosave data', 500);
    }
}
