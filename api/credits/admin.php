<?php
/**
 * AIKAFLOW API - Credit Admin Operations
 * 
 * Admin-only endpoints for managing credits
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

// Check admin
if ((int) $user['id'] !== 1 && ($user['role'] ?? '') !== 'admin') {
    errorResponse('Access denied', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'pending':
        handlePendingRequests();
        break;
    case 'approve':
        handleApprove($user);
        break;
    case 'reject':
        handleReject($user);
        break;
    case 'adjust':
        handleAdjust($user);
        break;
    case 'node-costs':
        handleNodeCosts();
        break;
    case 'coupons':
        handleCoupons();
        break;
    case 'packages':
        handlePackages();
        break;
    case 'reorder-packages':
        handleReorderPackages();
        break;
    case 'user-balance':
        handleUserBalance();
        break;
    case 'bank-accounts':
        handleBankAccounts();
        break;
    case 'reorder-banks':
        handleReorderBanks();
        break;
    default:
        errorResponse('Invalid action');
}

function handlePendingRequests()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        errorResponse('Method not allowed', 405);

    $status = $_GET['status'] ?? 'pending';
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Build query with optional search
    $params = [$status];
    $searchClause = '';
    if (!empty($search)) {
        $searchClause = "AND u.username LIKE ?";
        $params[] = "%{$search}%";
    }

    // Get total count
    $countSql = "SELECT COUNT(*) FROM topup_requests tr 
                 JOIN users u ON tr.user_id = u.id 
                 WHERE tr.status = ? {$searchClause}";
    $total = (int) Database::fetchColumn($countSql, $params);

    // Get paginated requests
    $sql = "SELECT tr.*, u.username, u.email, cp.name as package_name
            FROM topup_requests tr
            JOIN users u ON tr.user_id = u.id
            LEFT JOIN credit_packages cp ON tr.package_id = cp.id
            WHERE tr.status = ? {$searchClause}
            ORDER BY tr.created_at DESC
            LIMIT {$limit} OFFSET {$offset}";
    $requests = Database::fetchAll($sql, $params);

    successResponse([
        'requests' => $requests,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => ceil($total / $limit),
            'hasMore' => ($page * $limit) < $total
        ]
    ]);
}

function handleApprove($admin)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        errorResponse('Method not allowed', 405);

    $input = getJsonInput();
    $requestId = $input['request_id'] ?? null;
    $notes = $input['notes'] ?? '';

    if (!$requestId)
        errorResponse('Request ID required');

    $request = Database::fetchOne(
        "SELECT * FROM topup_requests WHERE id = ? AND status = 'pending'",
        [$requestId]
    );

    if (!$request)
        errorResponse('Request not found or already processed');

    // Calculate total credits
    $totalCredits = (int) $request['credits_requested'] + (int) $request['bonus_credits'];

    // Get current balance
    $currentBalance = Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
         WHERE user_id = ? AND remaining > 0 
         AND (expires_at IS NULL OR expires_at >= CURDATE())",
        [$request['user_id']]
    );

    // Add to credit ledger
    Database::insert('credit_ledger', [
        'user_id' => $request['user_id'],
        'credits' => $totalCredits,
        'remaining' => $totalCredits,
        'source' => 'topup',
        'expires_at' => $request['credits_expire_at']
    ]);

    // Log transaction
    $newBalance = (float) $currentBalance + $totalCredits;
    Database::insert('credit_transactions', [
        'user_id' => $request['user_id'],
        'type' => 'topup',
        'amount' => $totalCredits,
        'balance_after' => $newBalance,
        'description' => 'Top-up approved: ' . $request['credits_requested'] . ' credits' .
            ($request['bonus_credits'] > 0 ? ' + ' . $request['bonus_credits'] . ' bonus' : ''),
        'reference_id' => 'topup_' . $requestId
    ]);

    // Update request status
    Database::query(
        "UPDATE topup_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?",
        [$admin['id'], $notes, $requestId]
    );

    // Send email to user
    try {
        $targetUser = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$request['user_id']]);
        if ($targetUser) {
            $emailService = new EmailService();
            // TODO: Send approval email
        }
    } catch (Exception $e) {
        // Log but don't fail
    }

    successResponse([
        'message' => 'Top-up approved',
        'credits_added' => $totalCredits,
        'new_balance' => $newBalance
    ]);
}

function handleReject($admin)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        errorResponse('Method not allowed', 405);

    $input = getJsonInput();
    $requestId = $input['request_id'] ?? null;
    $reason = $input['reason'] ?? '';

    if (!$requestId)
        errorResponse('Request ID required');

    $request = Database::fetchOne(
        "SELECT * FROM topup_requests WHERE id = ? AND status = 'pending'",
        [$requestId]
    );

    if (!$request)
        errorResponse('Request not found or already processed');

    // Update request status
    Database::query(
        "UPDATE topup_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?",
        [$admin['id'], $reason, $requestId]
    );

    // If coupon was used, revert the usage
    if ($request['coupon_id']) {
        Database::query("UPDATE credit_coupons SET used_count = used_count - 1 WHERE id = ? AND used_count > 0", [$request['coupon_id']]);
        Database::query("DELETE FROM credit_coupon_usage WHERE topup_request_id = ?", [$requestId]);
    }

    successResponse(['message' => 'Top-up rejected']);
}

function handleAdjust($admin)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        errorResponse('Method not allowed', 405);

    $input = getJsonInput();
    $userId = $input['user_id'] ?? null;
    $amount = (float) ($input['amount'] ?? 0);
    $reason = $input['reason'] ?? 'Admin adjustment';
    $expiresAt = $input['expires_at'] ?? null;

    if (!$userId || $amount == 0)
        errorResponse('User ID and amount required');

    // Get current balance
    $currentBalance = Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
         WHERE user_id = ? AND remaining > 0 
         AND (expires_at IS NULL OR expires_at >= CURDATE())",
        [$userId]
    );

    if ($amount > 0) {
        // Add credits
        Database::insert('credit_ledger', [
            'user_id' => $userId,
            'credits' => $amount,
            'remaining' => $amount,
            'source' => 'adjustment',
            'expires_at' => $expiresAt
        ]);
    } else {
        // Deduct credits (mark as used from oldest first) - simplified: just record negative transaction
    }

    $newBalance = (float) $currentBalance + $amount;

    Database::insert('credit_transactions', [
        'user_id' => $userId,
        'type' => 'adjustment',
        'amount' => $amount,
        'balance_after' => max(0, $newBalance),
        'description' => $reason,
        'reference_id' => 'adj_' . time()
    ]);

    successResponse([
        'message' => 'Credits adjusted',
        'amount' => $amount,
        'new_balance' => max(0, $newBalance)
    ]);
}

function handleNodeCosts()
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Ensure enhancement actions exist in the database with defaults
        $defaultActions = [
            ['node_type' => 'action_enhance', 'cost_per_call' => 1, 'description' => 'Text Enhancement AI'],
            ['node_type' => 'action_enhance_image', 'cost_per_call' => 10, 'description' => 'Image Enhancement AI']
        ];

        foreach ($defaultActions as $action) {
            $exists = Database::fetchOne(
                "SELECT id FROM node_costs WHERE node_type = ?",
                [$action['node_type']]
            );
            if (!$exists) {
                try {
                    Database::insert('node_costs', $action);
                } catch (Exception $e) {
                    // Ignore if already exists
                }
            }
        }

        $costs = Database::fetchAll("SELECT * FROM node_costs ORDER BY node_type");
        successResponse(['node_costs' => $costs]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getJsonInput();
        $nodeType = $input['node_type'] ?? '';
        $cost = (float) ($input['cost'] ?? 1);
        $description = $input['description'] ?? '';

        if (empty($nodeType))
            errorResponse('Node type required');

        Database::query(
            "INSERT INTO node_costs (node_type, cost_per_call, description) 
             VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE cost_per_call = VALUES(cost_per_call), description = VALUES(description)",
            [$nodeType, $cost, $description]
        );

        successResponse(['message' => 'Node cost updated']);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = getJsonInput();
        $nodeType = $input['node_type'] ?? '';
        if (empty($nodeType))
            errorResponse('Node type required');
        Database::query("DELETE FROM node_costs WHERE node_type = ?", [$nodeType]);
        successResponse(['message' => 'Node cost deleted']);
    }
}

function handleCoupons()
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $coupons = Database::fetchAll("SELECT * FROM credit_coupons ORDER BY created_at DESC");
        successResponse(['coupons' => $coupons]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getJsonInput();

        $data = [
            'code' => strtoupper(trim($input['code'] ?? '')),
            'type' => $input['type'] ?? 'percentage',
            'value' => (float) ($input['value'] ?? 0),
            'min_purchase' => (float) ($input['min_purchase'] ?? 0),
            'max_uses' => $input['max_uses'] ? (int) $input['max_uses'] : null,
            'valid_from' => $input['valid_from'] ?: null,
            'valid_until' => $input['valid_until'] ?: null,
            'is_active' => $input['is_active'] ?? true
        ];

        if (empty($data['code']))
            errorResponse('Coupon code required');

        if (isset($input['id']) && $input['id']) {
            // Update
            Database::query(
                "UPDATE credit_coupons SET code=?, type=?, value=?, min_purchase=?, max_uses=?, valid_from=?, valid_until=?, is_active=? WHERE id=?",
                [$data['code'], $data['type'], $data['value'], $data['min_purchase'], $data['max_uses'], $data['valid_from'], $data['valid_until'], $data['is_active'], $input['id']]
            );
            successResponse(['message' => 'Coupon updated']);
        } else {
            // Create
            $id = Database::insert('credit_coupons', $data);
            successResponse(['message' => 'Coupon created', 'id' => $id]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = getJsonInput();
        $id = $input['id'] ?? null;
        if (!$id)
            errorResponse('Coupon ID required');
        Database::query("DELETE FROM credit_coupons WHERE id = ?", [$id]);
        successResponse(['message' => 'Coupon deleted']);
    }
}

function handlePackages()
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $packages = Database::fetchAll("SELECT * FROM credit_packages ORDER BY sort_order");
        successResponse(['packages' => $packages]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getJsonInput();

        $data = [
            'name' => trim($input['name'] ?? ''),
            'credits' => (int) ($input['credits'] ?? 0),
            'price' => (float) ($input['price'] ?? 0),
            'bonus_credits' => (int) ($input['bonus_credits'] ?? 0),
            'description' => $input['description'] ?? '',
            'is_active' => $input['is_active'] ?? true,
            'sort_order' => (int) ($input['sort_order'] ?? 0)
        ];

        if (empty($data['name']) || $data['credits'] <= 0)
            errorResponse('Name and credits required');

        if (isset($input['id']) && $input['id']) {
            Database::query(
                "UPDATE credit_packages SET name=?, credits=?, price=?, bonus_credits=?, description=?, is_active=?, sort_order=? WHERE id=?",
                [$data['name'], $data['credits'], $data['price'], $data['bonus_credits'], $data['description'], $data['is_active'], $data['sort_order'], $input['id']]
            );
            successResponse(['message' => 'Package updated']);
        } else {
            $id = Database::insert('credit_packages', $data);
            successResponse(['message' => 'Package created', 'id' => $id]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = getJsonInput();
        $id = $input['id'] ?? null;
        if (!$id)
            errorResponse('Package ID required');
        Database::query("DELETE FROM credit_packages WHERE id = ?", [$id]);
        successResponse(['message' => 'Package deleted']);
    }
}

function handleReorderPackages()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('POST method required');
    }

    $input = getJsonInput();
    $order = $input['order'] ?? [];

    if (empty($order) || !is_array($order)) {
        errorResponse('Order array required');
    }

    // Update sort_order for each package
    foreach ($order as $index => $packageId) {
        Database::query(
            "UPDATE credit_packages SET sort_order = ? WHERE id = ?",
            [$index, (int) $packageId]
        );
    }

    successResponse(['message' => 'Package order updated']);
}

function handleUserBalance()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        errorResponse('Method not allowed', 405);

    $userId = $_GET['user_id'] ?? null;
    if (!$userId)
        errorResponse('User ID required');

    $balance = Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
         WHERE user_id = ? AND remaining > 0 
         AND (expires_at IS NULL OR expires_at >= CURDATE())",
        [$userId]
    );

    successResponse(['user_id' => $userId, 'balance' => (float) $balance]);
}

function handleBankAccounts()
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $banks = Database::fetchAll("SELECT * FROM payment_bank_accounts ORDER BY sort_order");
        successResponse(['banks' => $banks]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getJsonInput();

        $data = [
            'bank_name' => trim($input['bank_name'] ?? ''),
            'account_number' => trim($input['account_number'] ?? ''),
            'account_holder' => trim($input['account_holder'] ?? ''),
            'logo_url' => $input['logo_url'] ?? null,
            'is_active' => $input['is_active'] ?? true,
            'sort_order' => (int) ($input['sort_order'] ?? 0)
        ];

        if (empty($data['bank_name']) || empty($data['account_number']) || empty($data['account_holder']))
            errorResponse('Bank name, account number and account holder are required');

        if (isset($input['id']) && $input['id']) {
            Database::query(
                "UPDATE payment_bank_accounts SET bank_name=?, account_number=?, account_holder=?, logo_url=?, is_active=?, sort_order=? WHERE id=?",
                [$data['bank_name'], $data['account_number'], $data['account_holder'], $data['logo_url'], $data['is_active'], $data['sort_order'], $input['id']]
            );
            successResponse(['message' => 'Bank account updated']);
        } else {
            $id = Database::insert('payment_bank_accounts', $data);
            successResponse(['message' => 'Bank account created', 'id' => $id]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = getJsonInput();
        $id = $input['id'] ?? null;
        if (!$id)
            errorResponse('Bank account ID required');
        Database::query("DELETE FROM payment_bank_accounts WHERE id = ?", [$id]);
        successResponse(['message' => 'Bank account deleted']);
    }
}

function handleReorderBanks()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('POST method required');
    }

    $input = getJsonInput();
    $order = $input['order'] ?? [];

    if (empty($order) || !is_array($order)) {
        errorResponse('Order array required');
    }

    foreach ($order as $index => $bankId) {
        Database::query(
            "UPDATE payment_bank_accounts SET sort_order = ? WHERE id = ?",
            [$index, (int) $bankId]
        );
    }

    successResponse(['message' => 'Bank order updated']);
}
