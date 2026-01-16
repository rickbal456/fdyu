<?php
/**
 * AIKAFLOW API - Send WhatsApp Verification Code
 * 
 * POST /api/auth/send-whatsapp-code.php
 * 
 * Sends a verification code to the user's WhatsApp number
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Initialize session for storing OTP
Auth::initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$input = getJsonInput();
$phone = trim($input['phone'] ?? '');

if (empty($phone)) {
    errorResponse('Phone number is required');
}

// Validate phone format (basic validation - should start with + or digits)
if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    errorResponse('Invalid phone number format. Please include country code.');
}

try {
    // Check if WhatsApp verification is enabled
    $settings = Database::fetchAll(
        "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (
            'whatsapp_verification_enabled',
            'whatsapp_api_url',
            'whatsapp_api_method',
            'whatsapp_verification_message',
            'site_title'
        )"
    );

    $config = [];
    foreach ($settings as $row) {
        $config[$row['setting_key']] = $row['setting_value'];
    }

    if (($config['whatsapp_verification_enabled'] ?? '0') !== '1') {
        errorResponse('WhatsApp verification is not enabled');
    }

    $apiUrl = $config['whatsapp_api_url'] ?? '';
    if (empty($apiUrl)) {
        errorResponse('WhatsApp API is not configured');
    }

    // Check if phone number is already registered
    $existingUser = Database::fetchOne(
        "SELECT id FROM users WHERE whatsapp_phone = ?",
        [$phone]
    );

    if ($existingUser) {
        errorResponse('This WhatsApp number is already registered');
    }

    // Generate 6-digit OTP code
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = time() + 600; // 10 minutes

    // Store in session
    $_SESSION['whatsapp_otp'] = [
        'code' => $code,
        'phone' => $phone,
        'expires_at' => $expiresAt,
        'attempts' => 0
    ];

    // Prepare message
    $siteTitle = $config['site_title'] ?? 'AIKAFLOW';
    $messageTemplate = $config['whatsapp_verification_message'] ?? 'Your verification code for {{site_title}} is: {{code}}. This code expires in 10 minutes.';
    $message = str_replace(
        ['{{code}}', '{{site_title}}'],
        [$code, $siteTitle],
        $messageTemplate
    );

    // Prepare API URL with placeholders
    $apiMethod = strtoupper($config['whatsapp_api_method'] ?? 'GET');
    $finalUrl = str_replace(
        ['{{destination_number}}', '{{message}}'],
        [urlencode($phone), urlencode($message)],
        $apiUrl
    );

    // Make API call
    $ch = curl_init();

    if ($apiMethod === 'POST') {
        // For POST, extract query params from URL and send as POST data
        $urlParts = parse_url($finalUrl);
        $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . ($urlParts['path'] ?? '');
        parse_str($urlParts['query'] ?? '', $postData);

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
    } else {
        // GET request
        curl_setopt_array($ch, [
            CURLOPT_URL => $finalUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("WhatsApp API error: $error");
        errorResponse('Failed to send verification code. Please try again.');
    }

    // Log the response for debugging (optional)
    error_log("WhatsApp API response ($httpCode): $response");

    // Most WhatsApp APIs return 200 on success
    if ($httpCode >= 200 && $httpCode < 300) {
        successResponse([
            'message' => 'Verification code sent to your WhatsApp',
            'expires_in' => 600
        ]);
    } else {
        error_log("WhatsApp API failed with status $httpCode: $response");
        errorResponse('Failed to send verification code. Please check your phone number and try again.');
    }

} catch (Exception $e) {
    error_log('Send WhatsApp code error: ' . $e->getMessage());
    errorResponse('An error occurred. Please try again.');
}
