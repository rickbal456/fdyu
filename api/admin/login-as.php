<?php
/**
 * AIKAFLOW API - Admin Login As User
 * 
 * POST /api/admin/login-as.php
 * Body: { "user_id": 123 }
 * 
 * Allows admin to impersonate a user session.
 * Original admin session is stored for returning.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('POST');
$adminUser = requireAuth();

// Check if user is admin
if ((int) $adminUser['id'] !== 1 && ($adminUser['role'] ?? '') !== 'admin') {
    errorResponse('Access denied. Admin privileges required.', 403);
}

$input = getJsonInput();
$targetUserId = (int) ($input['user_id'] ?? 0);

// Special case: return to admin session
if ($targetUserId === 0 && isset($input['return_to_admin']) && $input['return_to_admin']) {
    Auth::initSession();

    if (!isset($_SESSION['original_admin_id'])) {
        errorResponse('No admin session to return to');
    }

    $originalAdminId = $_SESSION['original_admin_id'];
    unset($_SESSION['original_admin_id']);

    // Restore admin session
    $_SESSION['user_id'] = $originalAdminId;

    successResponse(['message' => 'Returned to admin session']);
}

if ($targetUserId <= 0) {
    errorResponse('User ID is required');
}

// Cannot impersonate yourself
if ($targetUserId === (int) $adminUser['id']) {
    errorResponse('Cannot impersonate yourself');
}

// Fetch target user
$targetUser = Database::fetchOne(
    "SELECT id, username, is_active FROM users WHERE id = ?",
    [$targetUserId]
);

if (!$targetUser) {
    errorResponse('User not found', 404);
}

if (!$targetUser['is_active']) {
    errorResponse('Cannot impersonate inactive user');
}

try {
    Auth::initSession();

    // Store original admin session for return
    $_SESSION['original_admin_id'] = $adminUser['id'];

    // Switch to target user
    $_SESSION['user_id'] = $targetUserId;

    successResponse([
        'message' => 'Now logged in as ' . $targetUser['username'],
        'user_id' => $targetUserId,
        'username' => $targetUser['username']
    ]);

} catch (Exception $e) {
    error_log('Login as user error: ' . $e->getMessage());
    errorResponse('Failed to switch user', 500);
}
