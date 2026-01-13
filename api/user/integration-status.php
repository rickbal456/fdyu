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
    // Get user's integration keys preference
    $userKeys = [];

    // Try user_preferences table first
    try {
        $altPref = Database::fetchOne(
            "SELECT value FROM user_preferences WHERE user_id = ? AND `key` = 'integration_keys'",
            [$user['id']]
        );
        if ($altPref && isset($altPref['value']) && $altPref['value']) {
            $decoded = json_decode($altPref['value'], true);
            if (is_array($decoded)) {
                $userKeys = $decoded;
            }
        }
    } catch (Exception $e) {
        // Table might not exist, continue
        error_log('user_preferences table error: ' . $e->getMessage());
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
