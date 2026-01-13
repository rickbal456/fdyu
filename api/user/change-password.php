<?php
/**
 * AIKAFLOW API - Change Password
 * 
 * POST /api/user/change-password.php
 * Body: { current_password, new_password }
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('POST');
$user = requireAuth();

$input = getJsonInput();

$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';

if (empty($currentPassword) || empty($newPassword)) {
    errorResponse('Current password and new password are required');
}

if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
    errorResponse('New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
}

try {
    $result = Auth::changePassword($user['id'], $currentPassword, $newPassword);
    
    if ($result['success']) {
        successResponse(['message' => $result['message']]);
    } else {
        errorResponse($result['error']);
    }
    
} catch (Exception $e) {
    error_log('Change password error: ' . $e->getMessage());
    errorResponse('Failed to change password', 500);
}