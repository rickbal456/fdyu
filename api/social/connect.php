<?php
/**
 * AIKAFLOW API - Social Account Connection
 * 
 * POST - Generate OAuth URL for connecting a social account
 * 
 * Endpoint: /api/social/connect.php
 */

declare(strict_types=1);

// Set JSON header early
header('Content-Type: application/json');

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

// Require authentication
$user = requireAuth();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['success' => false, 'error' => 'Method not allowed']);
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);
$platform = $input['platform'] ?? '';

if (empty($platform)) {
    jsonResponse(['success' => false, 'error' => 'Platform is required']);
}

// Validate platform
$validPlatforms = ['instagram', 'tiktok', 'facebook', 'youtube', 'x', 'linkedin', 'pinterest', 'bluesky', 'threads'];
if (!in_array($platform, $validPlatforms)) {
    jsonResponse(['success' => false, 'error' => 'Invalid platform']);
}

// Get Postforme API key
$integrationKeys = [];
try {
    $pref = Database::fetchOne(
        "SELECT value FROM user_preferences WHERE user_id = ? AND `key` = 'integration_keys'",
        [$user['id']]
    );
    if ($pref && $pref['value']) {
        $integrationKeys = json_decode($pref['value'], true) ?: [];
    }
} catch (Exception $e) {
    // Ignore
}

// Check for both new key 'sapi' and old key 'postforme' for backward compatibility
$postformeApiKey = $integrationKeys['sapi'] ?? $integrationKeys['postforme'] ?? '';

if (empty($postformeApiKey)) {
    jsonResponse(['success' => false, 'error' => 'API key not configured']);
}

// Build callback URL
$appUrl = defined('APP_URL') ? APP_URL : '';
$callbackUrl = rtrim($appUrl, '/') . '/api/social/callback.php';

// Build request payload
$payload = [
    'platform' => $platform,
    'external_id' => 'aikaflow_user_' . $user['id'],
    'permissions' => ['posts']
];

// Add platform-specific data if needed
if ($platform === 'instagram') {
    $payload['platform_data'] = [
        'instagram' => ['connection_type' => 'instagram']
    ];
} elseif ($platform === 'linkedin') {
    $payload['platform_data'] = [
        'linkedin' => ['connection_type' => 'personal']
    ];
}

// Call Postforme API to get auth URL
$response = callPostformeApi('/v1/social-accounts/auth-url', $payload, $postformeApiKey);

if ($response['success'] && isset($response['data']['url'])) {
    jsonResponse([
        'success' => true,
        'authUrl' => $response['data']['url'],
        'platform' => $platform
    ]);
} else {
    jsonResponse([
        'success' => false,
        'error' => $response['error'] ?? 'Failed to generate authorization URL'
    ]);
}

/**
 * Call Postforme API
 */
function callPostformeApi(string $endpoint, array $data, string $apiKey): array
{
    $baseUrl = 'https://api.postforme.dev';
    $url = $baseUrl . $endpoint;

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }

    $responseData = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $responseData];
    }

    return [
        'success' => false,
        'error' => $responseData['message'] ?? $responseData['error'] ?? "HTTP {$httpCode}",
        'data' => $responseData
    ];
}
