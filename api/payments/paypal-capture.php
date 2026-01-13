<?php
/**
 * AIKAFLOW API - PayPal Capture Payment
 * 
 * POST /api/payments/paypal-capture.php - Captures PayPal payment and grants credits
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$input = getJsonInput();
$orderId = $input['order_id'] ?? '';

if (empty($orderId)) {
    errorResponse('Order ID is required');
}

// Verify order matches session
$sessionOrder = $_SESSION['paypal_order'] ?? null;
if (!$sessionOrder || $sessionOrder['order_id'] !== $orderId || $sessionOrder['user_id'] !== $user['id']) {
    errorResponse('Invalid order');
}

// Get PayPal settings
$paypalSandbox = true;
$paypalClientId = '';
$paypalSecretKey = '';

try {
    $settings = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('paypal_sandbox', 'paypal_client_id', 'paypal_secret_key')");
    foreach ($settings as $setting) {
        if ($setting['setting_key'] === 'paypal_sandbox')
            $paypalSandbox = $setting['setting_value'] === '1';
        if ($setting['setting_key'] === 'paypal_client_id')
            $paypalClientId = $setting['setting_value'] ?? '';
        if ($setting['setting_key'] === 'paypal_secret_key')
            $paypalSecretKey = $setting['setting_value'] ?? '';
    }
} catch (Exception $e) {
    errorResponse('Failed to load PayPal configuration', 500);
}

$apiBase = $paypalSandbox
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com';

// Get access token
$ch = curl_init("$apiBase/v1/oauth2/token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
curl_setopt($ch, CURLOPT_USERPWD, "$paypalClientId:$paypalSecretKey");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$tokenResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    errorResponse('Failed to authenticate with PayPal', 500);
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;

if (!$accessToken) {
    errorResponse('Failed to get PayPal access token', 500);
}

// Capture the payment
$ch = curl_init("$apiBase/v2/checkout/orders/$orderId/capture");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
$captureResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 201) {
    error_log('PayPal capture error: ' . $captureResponse);
    errorResponse('Failed to capture payment', 500);
}

$capture = json_decode($captureResponse, true);

if ($capture['status'] !== 'COMPLETED') {
    errorResponse('Payment not completed: ' . ($capture['status'] ?? 'unknown'));
}

// Clear session order
unset($_SESSION['paypal_order']);

// Grant credits
try {
    $totalCredits = $sessionOrder['credits'] + $sessionOrder['bonus_credits'];

    // Get expiry days
    $expiryDays = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'") ?: 365;
    $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

    // Add to credit ledger
    Database::insert('credit_ledger', [
        'user_id' => $user['id'],
        'credits' => $totalCredits,
        'remaining' => $totalCredits,
        'source' => 'topup',
        'expires_at' => $expiresAt
    ]);

    // Get new balance
    $newBalance = (int) Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())",
        [$user['id']]
    );

    // Log transaction
    Database::insert('credit_transactions', [
        'user_id' => $user['id'],
        'type' => 'topup',
        'amount' => $totalCredits,
        'balance_after' => $newBalance,
        'description' => 'PayPal top-up',
        'reference_id' => 'paypal_' . $orderId
    ]);

    // Update coupon usage if used
    if ($sessionOrder['coupon_id']) {
        Database::query("UPDATE credit_coupons SET used_count = used_count + 1 WHERE id = ?", [$sessionOrder['coupon_id']]);
    }

    successResponse([
        'message' => 'Payment successful!',
        'credits_added' => $totalCredits,
        'new_balance' => $newBalance
    ]);

} catch (Exception $e) {
    error_log('Credit grant error after PayPal payment: ' . $e->getMessage());
    // Payment was captured but credit grant failed - this is critical
    errorResponse('Payment received but credit grant failed. Please contact support with order ID: ' . $orderId, 500);
}
