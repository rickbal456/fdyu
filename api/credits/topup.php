<?php
/**
 * AIKAFLOW API - Credit Top-up
 * 
 * GET  /api/credits/topup.php - Get available packages
 * POST /api/credits/topup.php - Create top-up request
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email.php';
require_once __DIR__ . '/../../includes/PluginManager.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetPackages();
        break;
    case 'POST':
        handleCreateTopup($user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function handleGetPackages()
{
    try {
        // Get active packages
        $packages = Database::fetchAll(
            "SELECT id, name, credits, price, bonus_credits, description 
             FROM credit_packages 
             WHERE is_active = TRUE 
             ORDER BY sort_order ASC"
        );

        // Get currency settings
        $settings = Database::fetchAll(
            "SELECT setting_key, setting_value FROM site_settings 
             WHERE setting_key LIKE 'credit_%' OR setting_key = 'qris_string'"
        );

        $config = [];
        foreach ($settings as $s) {
            $key = str_replace('credit_', '', $s['setting_key']);
            $config[$key] = $s['setting_value'];
        }

        // Get active bank accounts
        $banks = Database::fetchAll(
            "SELECT id, bank_name, account_number, account_holder, logo_url 
             FROM payment_bank_accounts 
             WHERE is_active = TRUE 
             ORDER BY sort_order ASC"
        );

        // Get QRIS setting
        $qrisString = Database::fetchColumn(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'qris_string'"
        );

        // Get node costs and map to plugin names
        $nodeCosts = Database::fetchAll(
            "SELECT node_type, cost_per_call, description FROM node_costs ORDER BY node_type"
        );

        // Load plugins to get node names
        PluginManager::loadPlugins();
        $enabledPlugins = PluginManager::getEnabledPlugins();

        // Build a map of node_type => plugin_name
        $nodeNames = [];
        foreach ($enabledPlugins as $plugin) {
            if (!empty($plugin['nodeTypes'])) {
                foreach ($plugin['nodeTypes'] as $nodeType) {
                    $nodeNames[$nodeType] = $plugin['name'] ?? $nodeType;
                }
            }
        }

        // Add node_name to each node cost
        foreach ($nodeCosts as &$nodeCost) {
            $nodeCost['node_name'] = $nodeNames[$nodeCost['node_type']] ?? $nodeCost['node_type'];
        }

        // Get PayPal settings
        $paypalSettings = Database::fetchAll(
            "SELECT setting_key, setting_value FROM site_settings 
             WHERE setting_key IN ('paypal_enabled', 'paypal_sandbox', 'paypal_client_id')"
        );
        $paypalConfig = ['enabled' => false, 'sandbox' => true, 'client_id' => null];
        foreach ($paypalSettings as $ps) {
            if ($ps['setting_key'] === 'paypal_enabled')
                $paypalConfig['enabled'] = $ps['setting_value'] === '1';
            if ($ps['setting_key'] === 'paypal_sandbox')
                $paypalConfig['sandbox'] = $ps['setting_value'] === '1';
            if ($ps['setting_key'] === 'paypal_client_id')
                $paypalConfig['client_id'] = $ps['setting_value'];
        }

        successResponse([
            'packages' => $packages,
            'currency' => $config['currency'] ?? 'IDR',
            'currency_symbol' => $config['currency_symbol'] ?? 'Rp',
            'banks' => $banks,
            'qris_string' => $qrisString ?: null,
            'node_costs' => $nodeCosts,
            'paypal' => $paypalConfig
        ]);
    } catch (Exception $e) {
        errorResponse('Failed to fetch packages: ' . $e->getMessage(), 500);
    }
}

function handleCreateTopup($user)
{
    try {
        $packageId = $_POST['package_id'] ?? null;
        $couponCode = trim($_POST['coupon_code'] ?? '');

        if (!$packageId) {
            errorResponse('Package ID is required');
        }

        // Get package
        $package = Database::fetchOne(
            "SELECT * FROM credit_packages WHERE id = ? AND is_active = TRUE",
            [$packageId]
        );

        if (!$package) {
            errorResponse('Invalid package');
        }

        $amount = (float) $package['price'];
        $credits = (int) $package['credits'];
        $bonusCredits = (int) $package['bonus_credits'];
        $discount = 0;
        $couponId = null;

        // Apply coupon if provided
        if ($couponCode) {
            $coupon = Database::fetchOne(
                "SELECT * FROM credit_coupons 
                 WHERE code = ? AND is_active = TRUE 
                 AND (valid_from IS NULL OR valid_from <= CURDATE())
                 AND (valid_until IS NULL OR valid_until >= CURDATE())
                 AND (max_uses IS NULL OR used_count < max_uses)",
                [$couponCode]
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

            // Check minimum purchase
            if ($amount < (float) $coupon['min_purchase']) {
                errorResponse('Minimum purchase for this coupon is ' . $coupon['min_purchase']);
            }

            $couponId = $coupon['id'];

            switch ($coupon['type']) {
                case 'percentage':
                    $discount = $amount * ($coupon['value'] / 100);
                    break;
                case 'fixed_discount':
                    $discount = min($coupon['value'], $amount);
                    break;
                case 'bonus_credits':
                    $bonusCredits += (int) $coupon['value'];
                    break;
            }
        }

        $finalAmount = max(0, $amount - $discount);

        // Handle file upload for payment proof
        $paymentProof = null;
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../uploads/proofs/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
            $filename = 'proof_' . $user['id'] . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $targetPath)) {
                $paymentProof = 'uploads/proofs/' . $filename;
            }
        }

        // Get expiry days setting
        $expiryDays = Database::fetchColumn(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'"
        ) ?? 365;

        $expiresAt = date('Y-m-d', strtotime("+{$expiryDays} days"));

        // Create top-up request
        $requestId = Database::insert('topup_requests', [
            'user_id' => $user['id'],
            'package_id' => $packageId,
            'coupon_id' => $couponId,
            'amount' => $amount,
            'discount' => $discount,
            'final_amount' => $finalAmount,
            'credits_requested' => $credits,
            'bonus_credits' => $bonusCredits,
            'payment_proof' => $paymentProof,
            'credits_expire_at' => $expiresAt
        ]);

        // Record coupon usage
        if ($couponId) {
            Database::insert('credit_coupon_usage', [
                'coupon_id' => $couponId,
                'user_id' => $user['id'],
                'topup_request_id' => $requestId
            ]);
            Database::query("UPDATE credit_coupons SET used_count = used_count + 1 WHERE id = ?", [$couponId]);
        }

        // Send email notification to admin
        // TODO: Implement admin notification email

        successResponse([
            'request_id' => $requestId,
            'amount' => $amount,
            'discount' => $discount,
            'final_amount' => $finalAmount,
            'credits' => $credits + $bonusCredits,
            'message' => 'Top-up request created. Please complete payment and wait for admin approval.'
        ]);
    } catch (Exception $e) {
        errorResponse('Failed to create top-up request: ' . $e->getMessage(), 500);
    }
}
