<?php
/**
 * AIKAFLOW API - User Language Preference
 * 
 * POST /api/user/language.php - Save user's language preference
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
$language = trim($input['language'] ?? '');

// Validate language code
$supportedLanguages = ['en', 'id', 'ar'];
if (!in_array($language, $supportedLanguages)) {
    errorResponse('Invalid language code. Supported: ' . implode(', ', $supportedLanguages));
}

try {
    // Update user's language preference
    Database::query(
        "UPDATE users SET language = ? WHERE id = ?",
        [$language, $user['id']]
    );

    successResponse(['message' => 'Language preference saved', 'language' => $language]);
} catch (Exception $e) {
    error_log('Failed to save language preference: ' . $e->getMessage());
    errorResponse('Failed to save language preference');
}
