<?php
/**
 * AIKAFLOW API - Credit History
 * 
 * GET /api/credits/history.php - Get user's credit transaction history
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

try {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $type = $_GET['type'] ?? null;

    // Build query
    $whereClause = "WHERE user_id = ?";
    $params = [$user['id']];

    if ($type && in_array($type, ['topup', 'usage', 'bonus', 'refund', 'adjustment', 'expired', 'welcome'])) {
        $whereClause .= " AND type = ?";
        $params[] = $type;
    }

    // Get total count
    $total = Database::fetchColumn(
        "SELECT COUNT(*) FROM credit_transactions $whereClause",
        $params
    );

    // Get transactions
    $transactions = Database::fetchAll(
        "SELECT id, type, amount, balance_after, description, reference_id, node_type, workflow_id, created_at
         FROM credit_transactions 
         $whereClause 
         ORDER BY created_at DESC 
         LIMIT $limit OFFSET $offset",
        $params
    );

    successResponse([
        'transactions' => $transactions,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $total,
            'pages' => ceil((int) $total / $limit)
        ]
    ]);
} catch (Exception $e) {
    errorResponse('Failed to fetch history: ' . $e->getMessage(), 500);
}
