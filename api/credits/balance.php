<?php
/**
 * AIKAFLOW API - Credit Balance
 * 
 * GET /api/credits/balance.php - Get user's credit balance
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
    // Get total available credits (non-expired, with remaining > 0)
    $totalCredits = Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
         WHERE user_id = ? AND remaining > 0 
         AND (expires_at IS NULL OR expires_at >= CURDATE())",
        [$user['id']]
    );

    // Get credits expiring soon (within 30 days)
    $expiringSoon = Database::fetchOne(
        "SELECT COALESCE(SUM(remaining), 0) as amount, MIN(expires_at) as earliest_expiry
         FROM credit_ledger 
         WHERE user_id = ? AND remaining > 0 
         AND expires_at IS NOT NULL 
         AND expires_at >= CURDATE() 
         AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        [$user['id']]
    );

    // Get currency settings
    $settings = Database::fetchAll(
        "SELECT setting_key, setting_value FROM site_settings 
         WHERE setting_key IN ('credit_currency', 'credit_currency_symbol', 'credit_low_threshold')"
    );

    $currency = 'IDR';
    $currencySymbol = 'Rp';
    $lowThreshold = 100;

    foreach ($settings as $s) {
        if ($s['setting_key'] === 'credit_currency')
            $currency = $s['setting_value'] ?? 'IDR';
        if ($s['setting_key'] === 'credit_currency_symbol')
            $currencySymbol = $s['setting_value'] ?? 'Rp';
        if ($s['setting_key'] === 'credit_low_threshold')
            $lowThreshold = (int) ($s['setting_value'] ?? 100);
    }

    $balance = (float) $totalCredits;

    successResponse([
        'balance' => $balance,
        'formatted' => number_format($balance, 0),
        'currency' => $currency,
        'currency_symbol' => $currencySymbol,
        'low_balance' => $balance < $lowThreshold,
        'low_threshold' => $lowThreshold,
        'expiring_soon' => [
            'amount' => (float) ($expiringSoon['amount'] ?? 0),
            'earliest_date' => $expiringSoon['earliest_expiry'] ?? null
        ]
    ]);
} catch (Exception $e) {
    errorResponse('Failed to fetch balance: ' . $e->getMessage(), 500);
}
