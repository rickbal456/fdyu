<?php
/**
 * AIKAFLOW API - Admin Site Settings
 * 
 * GET  /api/admin/settings.php - Get all site settings
 * POST /api/admin/settings.php - Update site settings
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

// Check if user is admin
if ((int) $user['id'] !== 1 && ($user['role'] ?? '') !== 'admin') {
    errorResponse('Access denied. Admin privileges required.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        try {
            $rows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings");

            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            successResponse(['settings' => $settings]);
        } catch (Exception $e) {
            // Table might not exist yet
            successResponse([
                'settings' => [
                    'site_title' => 'AIKAFLOW',
                    'favicon_url' => null
                ]
            ]);
        }
        break;

    case 'POST':
        $input = getJsonInput();

        $allowedKeys = [
            // General
            'site_title',
            'logo_url_dark',
            'logo_url_light',
            'favicon_url',
            'default_theme',
            // hCaptcha
            'hcaptcha_enabled',
            'hcaptcha_site_key',
            'hcaptcha_secret_key',
            // SMTP
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_email',
            'smtp_from_name',
            // Google OAuth
            'google_auth_enabled',
            'google_client_id',
            'google_client_secret',
            // Email Templates
            'email_verification_enabled',
            'email_verification_subject',
            'email_verification_body',
            'email_welcome_subject',
            'email_welcome_body',
            'email_forgot_password_subject',
            'email_forgot_password_body',
            // Legal
            'terms_of_service',
            'privacy_policy',
            // Headway
            'headway_widget_id',
            // Custom Scripts
            'custom_footer_js',
            // Credits
            'credit_currency',
            'credit_currency_symbol',
            'credit_welcome_amount',
            'credit_default_expiry_days',
            'credit_low_threshold',
            'qris_string',
            // Workflow Settings
            'max_repeat_count',
            // Invitation System
            'invitation_enabled',
            'invitation_referrer_credits',
            'invitation_referee_credits',
            // PayPal Payment Gateway
            'paypal_enabled',
            'paypal_sandbox',
            'paypal_client_id',
            'paypal_secret_key',
            'paypal_usd_rate'
        ];
        $updated = [];

        foreach ($allowedKeys as $key) {
            if (isset($input[$key])) {
                $value = $input[$key];

                try {
                    // Upsert setting
                    Database::query(
                        "INSERT INTO site_settings (setting_key, setting_value) 
                         VALUES (?, ?) 
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$key, $value]
                    );
                    $updated[] = $key;
                } catch (Exception $e) {
                    error_log("Failed to update setting $key: " . $e->getMessage());
                }
            }
        }

        if (empty($updated)) {
            successResponse(['message' => 'No changes to save']);
        } else {
            successResponse([
                'message' => 'Settings updated successfully',
                'updated' => $updated
            ]);
        }
        break;

    default:
        errorResponse('Method not allowed', 405);
}
