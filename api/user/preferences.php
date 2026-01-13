<?php
/**
 * AIKAFLOW API - User Preferences
 * 
 * Stores user preferences (UI settings, integration keys, etc.) in database
 * 
 * GET /api/user/preferences.php - Get all or specific preferences
 * POST /api/user/preferences.php - Save/update preferences
 * DELETE /api/user/preferences.php - Delete a preference
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($user);
        break;
    case 'POST':
        handlePost($user);
        break;
    case 'DELETE':
        handleDelete($user);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * Get user preferences
 */
function handleGet(array $user): void
{
    $key = $_GET['key'] ?? null;

    try {
        if ($key) {
            // Get specific preference
            $result = Database::fetchOne(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?",
                [$user['id'], $key]
            );

            if ($result) {
                $value = json_decode($result['setting_value'], true);
                successResponse(['key' => $key, 'value' => $value]);
            } else {
                successResponse(['key' => $key, 'value' => null]);
            }
        } else {
            // Get all preferences
            $results = Database::fetchAll(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?",
                [$user['id']]
            );

            $preferences = [];
            foreach ($results as $row) {
                $preferences[$row['setting_key']] = json_decode($row['setting_value'], true);
            }

            successResponse(['preferences' => $preferences]);
        }
    } catch (Exception $e) {
        error_log('Get user preferences error: ' . $e->getMessage());
        errorResponse('Failed to get preferences', 500);
    }
}

/**
 * Save/update user preferences
 */
function handlePost(array $user): void
{
    $input = getJsonInput();

    // Can either save a single key/value or multiple preferences at once
    if (isset($input['key']) && array_key_exists('value', $input)) {
        // Single preference
        $key = sanitizeString($input['key'], 100);
        $value = $input['value'];

        if (empty($key)) {
            errorResponse('Preference key is required');
        }

        savePreference($user['id'], $key, $value);
        successResponse(['message' => 'Preference saved', 'key' => $key]);

    } elseif (isset($input['preferences']) && is_array($input['preferences'])) {
        // Multiple preferences
        $saved = 0;
        foreach ($input['preferences'] as $key => $value) {
            $key = sanitizeString((string) $key, 100);
            if (!empty($key)) {
                savePreference($user['id'], $key, $value);
                $saved++;
            }
        }
        successResponse(['message' => "Saved $saved preference(s)"]);

    } else {
        errorResponse('Invalid request. Provide either {key, value} or {preferences: {...}}');
    }
}

/**
 * Delete a user preference
 */
function handleDelete(array $user): void
{
    $input = getJsonInput();
    $key = sanitizeString($input['key'] ?? '', 100);

    if (empty($key)) {
        errorResponse('Preference key is required');
    }

    try {
        $deleted = Database::delete(
            'user_settings',
            'user_id = :user_id AND setting_key = :key',
            ['user_id' => $user['id'], 'key' => $key]
        );

        if ($deleted > 0) {
            successResponse(['message' => 'Preference deleted', 'key' => $key]);
        } else {
            successResponse(['message' => 'Preference not found', 'key' => $key]);
        }
    } catch (Exception $e) {
        error_log('Delete user preference error: ' . $e->getMessage());
        errorResponse('Failed to delete preference', 500);
    }
}

/**
 * Save a single preference (insert or update)
 */
function savePreference(int $userId, string $key, mixed $value): void
{
    try {
        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE);

        // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert
        Database::query(
            "INSERT INTO user_settings (user_id, setting_key, setting_value) 
             VALUES (:user_id, :key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            ['user_id' => $userId, 'key' => $key, 'value' => $jsonValue]
        );
    } catch (Exception $e) {
        error_log('Save user preference error: ' . $e->getMessage());
        throw $e;
    }
}
