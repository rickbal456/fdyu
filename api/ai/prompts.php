<?php
/**
 * AIKAFLOW API - Get OpenRouter System Prompts
 * 
 * Returns the list of system prompts configured by admin.
 * Used by the enhance dropdown to show available prompt options.
 * 
 * GET /api/ai/prompts.php
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
    // Get OpenRouter settings from site_settings
    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'openrouter_settings'"
    );

    $prompts = [];
    $model = 'openai/gpt-4o-mini';

    if ($result && $result['setting_value']) {
        $settings = json_decode($result['setting_value'], true);
        $prompts = $settings['systemPrompts'] ?? [];
        $model = $settings['model'] ?? 'openai/gpt-4o-mini';
    }

    // Check if OpenRouter is configured
    $keysResult = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );

    $isConfigured = false;
    if ($keysResult && $keysResult['setting_value']) {
        $keys = json_decode($keysResult['setting_value'], true);
        $isConfigured = !empty($keys['openrouter']);
    }

    successResponse([
        'isConfigured' => $isConfigured,
        'model' => $model,
        'systemPrompts' => $prompts
    ]);

} catch (Exception $e) {
    error_log('Get OpenRouter prompts error: ' . $e->getMessage());
    errorResponse('Failed to get prompts: ' . $e->getMessage(), 500);
}
