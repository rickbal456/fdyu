<?php
/**
 * AIKAFLOW Ad-hoc Credit Deduction API
 * 
 * Deducts credits for specific actions (like AI Enhance)
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

Auth::initSession();

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Action required']);
    exit;
}

$userId = Auth::user()['id'];

try {
    // 1. Determine cost
    // Map action to node_type in node_costs
    $nodeType = 'action_' . $action;
    $cost = 0;

    // Check if configured in database
    $dbCost = Database::fetchOne("SELECT cost_per_call FROM node_costs WHERE node_type = ?", [$nodeType]);

    if ($dbCost) {
        $cost = floatval($dbCost['cost_per_call']);
    } else {
        // Default costs if not in DB
        if ($action === 'enhance') {
            $cost = 1; // Default 1 credit for enhancement

            // Insert default to DB so admin can change it later
            try {
                Database::insert('node_costs', [
                    'node_type' => $nodeType,
                    'cost_per_call' => $cost,
                    'description' => 'Text Enhancement AI'
                ]);
            } catch (Exception $e) {
                // Ignore insert error (might exist)
            }
        }
    }

    if ($cost <= 0) {
        // Free action
        echo json_encode(['success' => true, 'cost' => 0, 'remaining' => 0]); // Remaining not calculated to save query
        exit;
    }

    // 2. Check balance
    $totalCredits = Database::fetchColumn("SELECT SUM(remaining) FROM credit_ledger WHERE user_id = ? AND remaining > 0 AND (expires_at IS NULL OR expires_at > NOW())", [$userId]);
    $totalCredits = floatval($totalCredits ?: 0);

    if ($totalCredits < $cost) {
        http_response_code(402); // Payment Required
        echo json_encode(['success' => false, 'error' => 'Insufficient credits. Cost: ' . $cost, 'required' => $cost, 'balance' => $totalCredits]);
        exit;
    }

    // 3. Deduct credits (FIFO)
    $remainingToDeduct = $cost;
    $ledgers = Database::fetchAll("SELECT * FROM credit_ledger WHERE user_id = ? AND remaining > 0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at ASC", [$userId]);

    foreach ($ledgers as $ledger) {
        if ($remainingToDeduct <= 0)
            break;

        $deduct = min($remainingToDeduct, floatval($ledger['remaining']));
        $newRemaining = floatval($ledger['remaining']) - $deduct;

        Database::update('credit_ledger', ['remaining' => $newRemaining], 'id = :id', ['id' => $ledger['id']]);
        $remainingToDeduct -= $deduct;
    }

    // 4. Log transaction
    // Calculate new balance
    $newBalance = Database::fetchColumn("SELECT SUM(remaining) FROM credit_ledger WHERE user_id = ? AND remaining > 0 AND (expires_at IS NULL OR expires_at > NOW())", [$userId]);

    Database::insert('credit_transactions', [
        'user_id' => $userId,
        'type' => 'usage',
        'amount' => -$cost,
        'balance_after' => $newBalance ?: 0,
        'description' => 'Action: ' . $action,
        'node_type' => $nodeType
    ]);

    echo json_encode([
        'success' => true,
        'cost' => $cost,
        'balance' => $newBalance
    ]);

} catch (Exception $e) {
    error_log('Credit deduction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
