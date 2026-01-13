<?php
/**
 * AIKAFLOW API - Get Workflow
 * 
 * GET /api/workflows/get.php?id={id}
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

$workflowId = (int) ($_GET['id'] ?? 0);

if (!$workflowId) {
    errorResponse('Workflow ID is required');
}

try {
    // Get workflow
    $workflow = Database::fetchOne(
        "SELECT w.*, ws.id as share_id, ws.is_public as share_is_public 
         FROM workflows w 
         LEFT JOIN workflow_shares ws ON w.id = ws.workflow_id 
         WHERE w.id = ? AND (w.user_id = ? OR w.is_public = 1)",
        [$workflowId, $user['id']]
    );

    if (!$workflow) {
        errorResponse('Workflow not found', 404);
    }

    // Parse JSON data
    $jsonData = json_decode($workflow['json_data'], true) ?: [];

    successResponse([
        'workflow' => [
            'version' => '1.0.0',
            'workflow' => [
                'id' => (int) $workflow['id'],
                'name' => $workflow['name'],
                'description' => $workflow['description'],
                'isPublic' => (bool) $workflow['is_public'],
                'shareId' => $workflow['share_id'] ?? null,
                'shareIsPublic' => isset($workflow['share_is_public']) ? (bool) $workflow['share_is_public'] : true,
                'createdAt' => $workflow['created_at'],
                'updatedAt' => $workflow['updated_at']
            ],
            'nodes' => $jsonData['nodes'] ?? [],
            'connections' => $jsonData['connections'] ?? [],
            'canvas' => $jsonData['canvas'] ?? ['pan' => ['x' => 0, 'y' => 0], 'zoom' => 1]
        ]
    ]);

} catch (Exception $e) {
    error_log('Get workflow error: ' . $e->getMessage());
    errorResponse('Failed to load workflow', 500);
}