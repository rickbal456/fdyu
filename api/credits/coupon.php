<?php
/**
 * AIKAFLOW API - Coupon Validation
 * 
 * POST /api/credits/coupon.php - Validate a coupon code
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
$code = trim($input['code'] ?? '');
$packageId = $input['package_id'] ?? null;

if (empty($code)) {
    errorResponse('Coupon code is required');
}

try {
    // Find coupon
    $coupon = Database::fetchOne(
        "SELECT * FROM credit_coupons 
         WHERE code = ? AND is_active = TRUE 
         AND (valid_from IS NULL OR valid_from <= CURDATE())
         AND (valid_until IS NULL OR valid_until >= CURDATE())
         AND (max_uses IS NULL OR used_count < max_uses)",
        [$code]
    );

    if (!$coupon) {
        errorResponse('Invalid or expired coupon code');
    }

    // Check if user already used this coupon
    $used = Database::fetchColumn(
        "SELECT COUNT(*) FROM credit_coupon_usage WHERE coupon_id = ? AND user_id = ?",
        [$coupon['id'], $user['id']]
    );

    if ($used > 0) {
        errorResponse('You have already used this coupon');
    }

    // Get package price for preview calculation
    $packagePrice = 0;
    if ($packageId) {
        $package = Database::fetchOne(
            "SELECT price FROM credit_packages WHERE id = ? AND is_active = TRUE",
            [$packageId]
        );
        if ($package) {
            $packagePrice = (float) $package['price'];
        }
    }

    // Check minimum purchase
    if ($packagePrice > 0 && $packagePrice < (float) $coupon['min_purchase']) {
        errorResponse('Minimum purchase for this coupon is ' . number_format($coupon['min_purchase']));
    }

    // Calculate preview
    $discount = 0;
    $bonusCredits = 0;
    $description = '';

    switch ($coupon['type']) {
        case 'percentage':
            $discount = $packagePrice * ($coupon['value'] / 100);
            $description = $coupon['value'] . '% discount';
            break;
        case 'fixed_discount':
            $discount = min($coupon['value'], $packagePrice);
            $description = 'Discount ' . number_format($coupon['value']);
            break;
        case 'bonus_credits':
            $bonusCredits = (int) $coupon['value'];
            $description = '+' . number_format($coupon['value']) . ' bonus credits';
            break;
    }

    successResponse([
        'valid' => true,
        'type' => $coupon['type'],
        'value' => (float) $coupon['value'],
        'description' => $description,
        'preview' => [
            'original_price' => $packagePrice,
            'discount' => $discount,
            'final_price' => max(0, $packagePrice - $discount),
            'bonus_credits' => $bonusCredits
        ]
    ]);
} catch (Exception $e) {
    errorResponse('Failed to validate coupon: ' . $e->getMessage(), 500);
}
