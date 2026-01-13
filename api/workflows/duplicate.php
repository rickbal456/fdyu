<?php
/**
 * AIKAFLOW API - Duplicate Workflow
 * 
 * POST /api/workflows/duplicate.php
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
$workflowId = (int)($input['id'] ?? 0);

if (!$workflowId) {
    errorResponse('Workflow ID is required');
}

try {
    // Get original workflow
    $original = Database::fetchOne(
        "SELECT * FROM workflows WHERE id = ? AND (user_id = ? OR is_public = 1)",
        [$workflowId, $user['id']]
    );
    
    if (!$original) {
        errorResponse('Workflow not found', 404);
    }
    
    // Create duplicate
    $newId = Database::insert('workflows', [
        'user_id' => $user['id'],
        'name' => $original['name'] . ' (Copy)',
        'description' => $original['description'],
        'is_public' => 0, // Duplicates are private by default
        'json_data' => $original['json_data'],
        'version' => 1
    ]);
    
    successResponse([
        'workflowId' => $newId,
        'message' => 'Workflow duplicated successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Duplicate workflow error: ' . $e->getMessage());
    errorResponse('Failed to duplicate workflow', 500);
}