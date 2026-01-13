<?php
/**
 * AIKAFLOW API - Integration Status
 * 
 * Returns which integrations have API keys configured WITHOUT exposing the actual keys.
 * This is safe to call from the browser.
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
    // Get user's integration keys from user_settings table
    // This is the same table and format used by preferences.php
    $userKeys = [];

    $result = Database::fetchOne(
        "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'integration_keys'",
        [$user['id']]
    );

    if ($result && isset($result['setting_value']) && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if (is_array($decoded)) {
            $userKeys = $decoded;
        }
    }

    // Build status map - only return whether each key is configured, NOT the actual value
    $status = [];
    $providers = ['runninghub', 'kie', 'jsoncut', 'openrouter', 'bunnycdn', 'postforme'];

    foreach ($providers as $provider) {
        $status[$provider] = !empty($userKeys[$provider]);
    }

    // Also check for plugin-specific keys (PHP 7 compatible)
    foreach ($userKeys as $key => $value) {
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
