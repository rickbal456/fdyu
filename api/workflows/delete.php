<?php
/**
 * AIKAFLOW API - Delete Workflow
 * 
 * DELETE /api/workflows/delete.php?id={id}
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod(['DELETE', 'POST']);
$user = requireAuth();

$workflowId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$workflowId) {
    errorResponse('Workflow ID is required');
}

try {
    // Check ownership
    $workflow = Database::fetchOne(
        "SELECT id FROM workflows WHERE id = ? AND user_id = ?",
        [$workflowId, $user['id']]
    );
    
    if (!$workflow) {
        errorResponse('Workflow not found or access denied', 404);
    }
    
    // Delete workflow (cascades to executions and tasks)
    Database::delete('workflows', 'id = :id', ['id' => $workflowId]);
    
    successResponse(['message' => 'Workflow deleted successfully']);
    
} catch (Exception $e) {
    error_log('Delete workflow error: ' . $e->getMessage());
    errorResponse('Failed to delete workflow', 500);
}