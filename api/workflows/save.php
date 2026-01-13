<?php
/**
 * AIKAFLOW API - Save Workflow
 * 
 * POST /api/workflows/save.php
 * Body: { id?, name, description?, isPublic?, data: { nodes, connections, canvas } }
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

// Get workflow data
$workflowId = isset($input['id']) ? (int)$input['id'] : null;
$name = sanitizeString($input['name'] ?? 'Untitled Workflow', 255);
$description = sanitizeString($input['description'] ?? '', 1000);
$isPublic = (bool)($input['isPublic'] ?? false);
$data = $input['data'] ?? [];

if (empty($name)) {
    errorResponse('Workflow name is required');
}

// Validate and sanitize workflow data
$validatedData = validateWorkflowData($data);

try {
    Database::beginTransaction();
    
    if ($workflowId) {
        // Update existing workflow
        $existing = Database::fetchOne(
            "SELECT id, version FROM workflows WHERE id = ? AND user_id = ?",
            [$workflowId, $user['id']]
        );
        
        if (!$existing) {
            Database::rollback();
            errorResponse('Workflow not found or access denied', 404);
        }
        
        $newVersion = (int)$existing['version'] + 1;
        
        Database::update(
            'workflows',
            [
                'name' => $name,
                'description' => $description,
                'is_public' => $isPublic ? 1 : 0,
                'json_data' => json_encode($validatedData),
                'version' => $newVersion,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = :id AND user_id = :user_id',
            ['id' => $workflowId, 'user_id' => $user['id']]
        );
        
    } else {
        // Create new workflow
        $workflowId = Database::insert('workflows', [
            'user_id' => $user['id'],
            'name' => $name,
            'description' => $description,
            'is_public' => $isPublic ? 1 : 0,
            'json_data' => json_encode($validatedData),
            'version' => 1
        ]);
    }
    
    Database::commit();
    
    successResponse([
        'workflowId' => $workflowId,
        'message' => 'Workflow saved successfully'
    ]);
    
} catch (Exception $e) {
    Database::rollback();
    error_log('Save workflow error: ' . $e->getMessage());
    errorResponse('Failed to save workflow', 500);
}