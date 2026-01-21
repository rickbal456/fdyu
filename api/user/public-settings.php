<?php
/**
 * AIKAFLOW API - Public Site Settings
 * 
 * Returns site settings that are safe for regular users to access.
 * Does NOT expose sensitive data like API keys.
 * 
 * GET /api/user/public-settings.php
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
    $settings = [];

    // Get LLM settings (model and system prompts - NOT the API key)
    $orResult = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'llm_settings'"
    );
    if ($orResult && $orResult['setting_value']) {
        $orSettings = json_decode($orResult['setting_value'], true);
        // Only expose model and system prompts, NOT any API keys
        $settings['llm_settings'] = [
            'model' => $orSettings['model'] ?? 'openai/gpt-4o-mini',
            'systemPrompts' => $orSettings['systemPrompts'] ?? []
        ];
    }

    // Get site branding settings (safe to expose)
    $brandingKeys = ['site_title', 'logo_url', 'favicon_url', 'default_theme'];
    foreach ($brandingKeys as $key) {
        $result = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = ?",
            [$key]
        );
        if ($result) {
            $settings[$key] = $result['setting_value'];
        }
    }

    // Check if user is admin (ID 1 is always admin)
    $isAdmin = ((int) $user['id'] === 1) || (isset($user['role']) && $user['role'] === 'admin');

    successResponse([
        'settings' => $settings,
        'isAdmin' => $isAdmin
    ]);

} catch (Exception $e) {
    error_log('Public settings error: ' . $e->getMessage());
    errorResponse('Failed to load settings', 500);
}
