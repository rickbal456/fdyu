<?php
/**
 * AIKAFLOW API - Verify WhatsApp Code
 * 
 * POST /api/auth/verify-whatsapp-code.php
 * 
 * Verifies the OTP code sent to user's WhatsApp
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Initialize session
Auth::initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$input = getJsonInput();
$code = trim($input['code'] ?? '');
$phone = trim($input['phone'] ?? '');

if (empty($code)) {
    errorResponse('Verification code is required');
}

if (empty($phone)) {
    errorResponse('Phone number is required');
}

try {
    // Check if OTP exists in session
    if (!isset($_SESSION['whatsapp_otp'])) {
        errorResponse('No verification code found. Please request a new code.');
    }

    $otpData = $_SESSION['whatsapp_otp'];

    // Check if phone matches
    if ($otpData['phone'] !== $phone) {
        errorResponse('Phone number does not match. Please request a new code.');
    }

    // Check expiry
    if (time() > $otpData['expires_at']) {
        unset($_SESSION['whatsapp_otp']);
        errorResponse('Verification code has expired. Please request a new code.');
    }

    // Check attempts (max 5 attempts)
    if ($otpData['attempts'] >= 5) {
        unset($_SESSION['whatsapp_otp']);
        errorResponse('Too many failed attempts. Please request a new code.');
    }

    // Increment attempts
    $_SESSION['whatsapp_otp']['attempts']++;

    // Verify code
    if ($otpData['code'] !== $code) {
        $remainingAttempts = 5 - $_SESSION['whatsapp_otp']['attempts'];
        errorResponse("Invalid verification code. $remainingAttempts attempts remaining.");
    }

    // Code is valid - mark as verified
    $_SESSION['whatsapp_verified'] = [
        'phone' => $phone,
        'verified_at' => time()
    ];

    // Clear OTP data
    unset($_SESSION['whatsapp_otp']);

    successResponse([
        'message' => 'WhatsApp number verified successfully',
        'verified' => true
    ]);

} catch (Exception $e) {
    error_log('Verify WhatsApp code error: ' . $e->getMessage());
    errorResponse('An error occurred. Please try again.');
}
