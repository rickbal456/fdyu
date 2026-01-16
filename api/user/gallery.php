<?php
/**
 * AIKAFLOW API - User Gallery
 * 
 * Stores and retrieves generated content for user's gallery
 * 
 * GET /api/user/gallery.php - Get gallery items
 * POST /api/user/gallery.php - Add item to gallery
 * DELETE /api/user/gallery.php - Remove item from gallery
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
 * Get gallery items
 */
function handleGet(array $user): void
{
    $workflowId = isset($_GET['workflow_id']) ? (int) $_GET['workflow_id'] : null;
    $source = isset($_GET['source']) ? $_GET['source'] : null; // 'manual' or 'api'
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    try {
        $params = ['user_id' => $user['id']];
        $whereClause = 'user_id = :user_id';

        if ($workflowId) {
            $whereClause .= ' AND workflow_id = :workflow_id';
            $params['workflow_id'] = $workflowId;
        }

        // Filter by source if specified
        if ($source && in_array($source, ['manual', 'api'])) {
            $whereClause .= ' AND source = :source';
            $params['source'] = $source;
        }

        $items = Database::fetchAll(
            "SELECT id, workflow_id, item_type as type, url, node_id, node_type, source, metadata, created_at
             FROM user_gallery 
             WHERE {$whereClause}
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Parse metadata JSON
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['workflow_id'] = $item['workflow_id'] ? (int) $item['workflow_id'] : null;
            $item['source'] = $item['source'] ?? 'manual';
            $item['metadata'] = $item['metadata'] ? json_decode($item['metadata'], true) : null;
        }

        $total = Database::fetchColumn(
            "SELECT COUNT(*) FROM user_gallery WHERE {$whereClause}",
            $params
        );

        successResponse([
            'items' => $items,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset,
            'source' => $source
        ]);

    } catch (Exception $e) {
        error_log('Get gallery error: ' . $e->getMessage());
        errorResponse('Failed to get gallery items', 500);
    }
}

/**
 * Add item to gallery
 */
function handlePost(array $user): void
{
    $input = getJsonInput();

    if (!isset($input['url']) || empty($input['url'])) {
        errorResponse('URL is required');
    }

    $type = $input['type'] ?? 'image';
    if (!in_array($type, ['image', 'video', 'audio'])) {
        $type = 'image';
    }

    $workflowId = isset($input['workflowId']) ? (int) $input['workflowId'] : null;

    try {
        // Verify workflow ownership if provided
        if ($workflowId) {
            $workflow = Database::fetchOne(
                "SELECT id FROM workflows WHERE id = ? AND user_id = ?",
                [$workflowId, $user['id']]
            );
            if (!$workflow) {
                $workflowId = null; // Reset if not owned
            }
        }

        // Determine source (manual or api)
        $source = isset($input['source']) && in_array($input['source'], ['manual', 'api'])
            ? $input['source']
            : 'manual';

        $id = Database::insert('user_gallery', [
            'user_id' => $user['id'],
            'workflow_id' => $workflowId,
            'item_type' => $type,
            'url' => sanitizeString($input['url'], 512),
            'node_id' => isset($input['nodeId']) ? sanitizeString($input['nodeId'], 50) : null,
            'node_type' => isset($input['nodeType']) ? sanitizeString($input['nodeType'], 50) : null,
            'source' => $source,
            'metadata' => isset($input['metadata']) ? json_encode($input['metadata']) : null
        ]);

        successResponse([
            'message' => 'Item added to gallery',
            'id' => $id
        ]);

    } catch (Exception $e) {
        error_log('Add to gallery error: ' . $e->getMessage());
        errorResponse('Failed to add item to gallery', 500);
    }
}

/**
 * Remove item from gallery
 */
function handleDelete(array $user): void
{
    $input = getJsonInput();
    $itemId = isset($input['id']) ? (int) $input['id'] : 0;

    if (!$itemId) {
        errorResponse('Item ID is required');
    }

    try {
        $deleted = Database::delete(
            'user_gallery',
            'id = :id AND user_id = :user_id',
            ['id' => $itemId, 'user_id' => $user['id']]
        );

        if ($deleted > 0) {
            successResponse(['message' => 'Item removed from gallery']);
        } else {
            errorResponse('Item not found', 404);
        }

    } catch (Exception $e) {
        error_log('Delete gallery item error: ' . $e->getMessage());
        errorResponse('Failed to remove item from gallery', 500);
    }
}
