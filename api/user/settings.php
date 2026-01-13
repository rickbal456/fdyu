<?php
/**
 * AIKAFLOW API - User Settings
 * 
 * GET /api/user/settings.php - Get settings
 * POST /api/user/settings.php - Update settings
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get site config values needed by frontend
    $maxRepeatCount = 100; // Default
    $maxRepeatSetting = Database::fetchOne("SELECT setting_value FROM site_settings WHERE setting_key = 'max_repeat_count'");
    if ($maxRepeatSetting && $maxRepeatSetting['setting_value']) {
        $maxRepeatCount = (int) $maxRepeatSetting['setting_value'];
    }

    // Return user settings with site config
    successResponse([
        'user' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'apiKey' => $user['api_key'],
            'createdAt' => $user['created_at'],
            'lastLogin' => $user['last_login']
        ],
        'siteConfig' => [
            'maxRepeatCount' => $maxRepeatCount
        ]
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $updates = [];
    $errors = [];

    // Email update
    if (isset($input['email'])) {
        $email = filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $errors['email'] = 'Invalid email address';
        } else {
            // Check if email is taken by another user
            $existing = Database::fetchOne(
                "SELECT id FROM users WHERE email = ? AND id != ?",
                [$email, $user['id']]
            );

            if ($existing) {
                $errors['email'] = 'Email is already in use';
            } else {
                $updates['email'] = $email;
            }
        }
    }

    // Username update
    if (isset($input['username'])) {
        $username = trim($input['username']);

        if (strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'Username must be 3-50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors['username'] = 'Username can only contain letters, numbers, and underscores';
        } else {
            // Check if username is taken
            $existing = Database::fetchOne(
                "SELECT id FROM users WHERE username = ? AND id != ?",
                [$username, $user['id']]
            );

            if ($existing) {
                $errors['username'] = 'Username is already taken';
            } else {
                $updates['username'] = $username;
            }
        }
    }

    // Check for validation errors
    if (!empty($errors)) {
        jsonResponse([
            'success' => false,
            'errors' => $errors
        ], 400);
    }

    // Apply updates
    if (!empty($updates)) {
        try {
            Database::update(
                'users',
                $updates,
                'id = :id',
                ['id' => $user['id']]
            );

            successResponse([
                'message' => 'Settings updated successfully',
                'updated' => array_keys($updates)
            ]);

        } catch (Exception $e) {
            error_log('Update settings error: ' . $e->getMessage());
            errorResponse('Failed to update settings', 500);
        }
    } else {
        successResponse(['message' => 'No changes to save']);
    }

} else {
    errorResponse('Method not allowed', 405);
}