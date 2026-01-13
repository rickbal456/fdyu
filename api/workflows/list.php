<?php
/**
 * AIKAFLOW API - List Workflows
 * 
 * GET /api/workflows/list.php
 * Query params: page, limit, search
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

// Get query parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$search = sanitizeString($_GET['search'] ?? '', 100);
$offset = ($page - 1) * $limit;

try {
    // Build query
    $params = ['user_id' => $user['id']];
    $whereClause = 'user_id = :user_id';
    
    if ($search) {
        $whereClause .= ' AND (name LIKE :search OR description LIKE :search)';
        $params['search'] = "%{$search}%";
    }
    
    // Get total count
    $totalSql = "SELECT COUNT(*) FROM workflows WHERE {$whereClause}";
    $total = (int)Database::fetchColumn($totalSql, $params);
    
    // Get workflows
    $sql = "
        SELECT 
            id, 
            name, 
            description, 
            thumbnail_url,
            is_public,
            version,
            created_at, 
            updated_at,
            JSON_LENGTH(json_data, '$.nodes') as node_count
        FROM workflows 
        WHERE {$whereClause}
        ORDER BY updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    
    $workflows = Database::fetchAll($sql, $params);
    
    // Format response
    $formattedWorkflows = array_map(function($workflow) {
        return [
            'id' => (int)$workflow['id'],
            'name' => $workflow['name'],
            'description' => $workflow['description'],
            'thumbnail' => $workflow['thumbnail_url'],
            'isPublic' => (bool)$workflow['is_public'],
            'version' => (int)$workflow['version'],
            'nodeCount' => (int)($workflow['node_count'] ?? 0),
            'createdAt' => $workflow['created_at'],
            'updatedAt' => $workflow['updated_at']
        ];
    }, $workflows);
    
    successResponse([
        'workflows' => $formattedWorkflows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    error_log('List workflows error: ' . $e->getMessage());
    errorResponse('Failed to load workflows', 500);
}