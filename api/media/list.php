<?php
/**
 * AIKAFLOW API - List Media Assets
 * 
 * GET /api/media/list.php?type={type}&page={page}&limit={limit}
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

$type = sanitizeString($_GET['type'] ?? '', 20);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

try {
    $params = ['user_id' => $user['id']];
    $whereClause = 'user_id = :user_id';
    
    if ($type && in_array($type, ['image', 'video', 'audio'])) {
        $whereClause .= ' AND file_type = :file_type';
        $params['file_type'] = $type;
    }
    
    // Get total count
    $total = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM media_assets WHERE {$whereClause}",
        $params
    );
    
    // Get assets
    $assets = Database::fetchAll(
        "SELECT * FROM media_assets 
         WHERE {$whereClause}
         ORDER BY created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );
    
    $formatted = array_map(function($asset) {
        return [
            'id' => (int)$asset['id'],
            'url' => $asset['cdn_url'],
            'filename' => $asset['original_filename'],
            'fileType' => $asset['file_type'],
            'fileSize' => (int)$asset['file_size'],
            'createdAt' => $asset['created_at']
        ];
    }, $assets);
    
    successResponse([
        'assets' => $formatted,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    error_log('List media error: ' . $e->getMessage());
    errorResponse('Failed to list media', 500);
}