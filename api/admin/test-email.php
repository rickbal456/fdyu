<?php
/**
 * AIKAFLOW API - Test Email
 * 
 * POST /api/admin/test-email.php - Send a test email to verify SMTP configuration
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

// Check if user is admin
if ((int) $user['id'] !== 1 && ($user['role'] ?? '') !== 'admin') {
    errorResponse('Access denied. Admin privileges required.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$input = getJsonInput();
$testEmail = trim($input['email'] ?? '');

if (empty($testEmail)) {
    errorResponse('Email address is required');
}

if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    errorResponse('Invalid email address');
}

// Check if SMTP is enabled
if (!EmailService::isEnabled()) {
    errorResponse('SMTP is not enabled or not properly configured. Please check your SMTP settings.');
}

// Send test email
$subject = 'AIKAFLOW - SMTP Test Email';
$body = "Hello!\n\nThis is a test email from AIKAFLOW to verify that your SMTP configuration is working correctly.\n\nSent at: " . date('Y-m-d H:i:s') . "\n\nIf you received this email, your SMTP settings are configured properly.";

$result = EmailService::send($testEmail, $subject, $body);

if ($result) {
    successResponse(['message' => 'Test email sent successfully to ' . $testEmail]);
} else {
    errorResponse('Failed to send test email. Please check your SMTP credentials and server logs.');
}
