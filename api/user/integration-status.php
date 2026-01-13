<?php
/**
 * AIKAFLOW API - Integration Status
 * 
 * Returns which integrations have API keys configured WITHOUT exposing the actual keys.
 * This is safe to call from the browser.
 * 
 * Integration keys are stored at the SITE level (by admin), not per-user.
 * 
 * GET /api/user/integration-status.php
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
    // Get SITE-LEVEL integration keys from site_settings table
    // These are configured by the admin, not per-user
    $siteKeys = [];

    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );

    if ($result && isset($result['setting_value']) && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if (is_array($decoded)) {
            $siteKeys = $decoded;
        }
    }

    // Build status map - only return whether each key is configured, NOT the actual value
    $status = [];
    $providers = ['runninghub', 'kie', 'jsoncut', 'openrouter', 'bunnycdn', 'postforme'];

    foreach ($providers as $provider) {
        $status[$provider] = !empty($siteKeys[$provider]);
    }

    // Also check for plugin-specific keys (PHP 7 compatible)
    foreach ($siteKeys as $key => $value) {
        if (strpos($key, 'plugin_') === 0 && !empty($value)) {
            $status[$key] = true;
        }
    }

    successResponse([
        'configured' => $status
    ]);

} catch (Exception $e) {
    error_log('Integration status error: ' . $e->getMessage());
    errorResponse('Failed to get integration status', 500);
}
