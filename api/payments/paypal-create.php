<?php
/**
 * AIKAFLOW API - PayPal Create Order
 * 
 * POST /api/payments/paypal-create.php - Creates a PayPal order for credit top-up
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

// Get PayPal settings
$paypalEnabled = false;
$paypalSandbox = true;
$paypalClientId = '';
$paypalSecretKey = '';
$currencyCode = 'USD';
$usdRate = 1;

try {
    $settings = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('paypal_enabled', 'paypal_sandbox', 'paypal_client_id', 'paypal_secret_key', 'credit_currency', 'paypal_usd_rate')");
    foreach ($settings as $setting) {
        if ($setting['setting_key'] === 'paypal_enabled')
            $paypalEnabled = $setting['setting_value'] === '1';
        if ($setting['setting_key'] === 'paypal_sandbox')
            $paypalSandbox = $setting['setting_value'] === '1';
        if ($setting['setting_key'] === 'paypal_client_id')
            $paypalClientId = $setting['setting_value'] ?? '';
        if ($setting['setting_key'] === 'paypal_secret_key')
            $paypalSecretKey = $setting['setting_value'] ?? '';
        if ($setting['setting_key'] === 'credit_currency')
            $currencyCode = $setting['setting_value'] ?? 'USD';
        if ($setting['setting_key'] === 'paypal_usd_rate')
            $usdRate = (float) ($setting['setting_value'] ?? 1);
    }
} catch (Exception $e) {
    errorResponse('Failed to load PayPal configuration', 500);
}

if (!$paypalEnabled || empty($paypalClientId) || empty($paypalSecretKey)) {
    errorResponse('PayPal is not configured');
}

// Validate USD rate for non-USD currencies
if ($currencyCode !== 'USD' && $usdRate <= 0) {
    errorResponse('USD conversion rate not configured. Please set conversion rate in admin settings.');
}

$input = getJsonInput();
$packageId = (int) ($input['package_id'] ?? 0);
$couponCode = $input['coupon_code'] ?? null;

if (!$packageId) {
    errorResponse('Package ID is required');
}

// Get package details
$package = Database::fetchOne("SELECT * FROM credit_packages WHERE id = ? AND is_active = 1", [$packageId]);
if (!$package) {
    errorResponse('Invalid package');
}

// Calculate final amount
$amount = (float) $package['price'];
$discount = 0;
$couponId = null;

// Apply coupon if provided
if ($couponCode) {
    $coupon = Database::fetchOne(
        "SELECT * FROM credit_coupons WHERE code = ? AND is_active = 1 
         AND (valid_from IS NULL OR valid_from <= CURDATE())
         AND (valid_until IS NULL OR valid_until >= CURDATE())
         AND (max_uses IS NULL OR used_count < max_uses)",
        [$couponCode]
    );

    if ($coupon) {
        if ($coupon['type'] === 'percentage') {
            $discount = $amount * ($coupon['value'] / 100);
        } elseif ($coupon['type'] === 'fixed_discount') {
            $discount = min($coupon['value'], $amount);
        }
        $couponId = $coupon['id'];
    }
}

$finalAmount = max(0, $amount - $discount);

// Convert to USD if needed
$usdAmount = $finalAmount;
if ($currencyCode !== 'USD' && $usdRate > 0) {
    $usdAmount = $finalAmount / $usdRate;
}

// PayPal API base URL
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
    error_log('PayPal token error: ' . $tokenResponse);
    errorResponse('Failed to authenticate with PayPal', 500);
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;

if (!$accessToken) {
    errorResponse('Failed to get PayPal access token', 500);
}

// Create PayPal order
$orderData = [
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'reference_id' => 'credits_' . $package['id'] . '_' . $user['id'] . '_' . time(),
            'description' => $package['name'] . ' - ' . $package['credits'] . ' Credits',
            'amount' => [
                'currency_code' => 'USD',
                'value' => number_format($usdAmount, 2, '.', '')
            ]
        ]
    ],
    'application_context' => [
        'brand_name' => 'AIKAFLOW',
        'landing_page' => 'NO_PREFERENCE',
        'user_action' => 'PAY_NOW',
        'return_url' => APP_URL . '/api/payments/paypal-return.php',
        'cancel_url' => APP_URL . '/'
    ]
];

$ch = curl_init("$apiBase/v2/checkout/orders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
$orderResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 201) {
    error_log('PayPal order creation error: ' . $orderResponse);
    errorResponse('Failed to create PayPal order', 500);
}

$order = json_decode($orderResponse, true);

// Store order info in session for capture verification
$_SESSION['paypal_order'] = [
    'order_id' => $order['id'],
    'package_id' => $packageId,
    'coupon_id' => $couponId,
    'user_id' => $user['id'],
    'amount' => $amount,
    'discount' => $discount,
    'final_amount' => $finalAmount,
    'credits' => $package['credits'],
    'bonus_credits' => $package['bonus_credits']
];

successResponse([
    'order_id' => $order['id'],
    'status' => $order['status']
]);
