<?php
/**
 * AIKAFLOW API - Social Accounts Management
 * 
 * GET    - List connected social accounts
 * DELETE - Disconnect a social account
 * 
 * Endpoint: /api/social/accounts.php
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

// Get API key from site_settings (configured by admin in Administration → Integrations)
$integrationKeys = [];
try {
    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );
    if ($result && isset($result['setting_value']) && $result['setting_value']) {
        $integrationKeys = json_decode($result['setting_value'], true) ?: [];
    }
} catch (Exception $e) {
    // Ignore preference errors
}

// Check for both new key 'sapi' and old key 'postforme' for backward compatibility
$postformeApiKey = $integrationKeys['sapi'] ?? $integrationKeys['postforme'] ?? '';

if (empty($postformeApiKey)) {
    jsonResponse(['success' => false, 'error' => 'API key not configured. Please add it in Administration → Integrations.']);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // List social accounts from Postforme API
        listSocialAccounts($postformeApiKey);
        break;

    case 'DELETE':
        // Disconnect a social account
        $accountId = $_GET['id'] ?? '';
        if (empty($accountId)) {
            jsonResponse(['success' => false, 'error' => 'Account ID required']);
        }
        disconnectAccount($postformeApiKey, $accountId);
        break;

    default:
        http_response_code(405);
        jsonResponse(['success' => false, 'error' => 'Method not allowed']);
}

/**
 * List connected social accounts from Postforme API
 */
function listSocialAccounts(string $apiKey): void
{
    $response = callPostformeApi('GET', '/v1/social-accounts', null, $apiKey);

    if ($response['success'] && isset($response['data']['data'])) {
        $accounts = array_map(function ($account) {
            return [
                'id' => $account['id'] ?? '',
                'platform' => $account['platform'] ?? '',
                'username' => $account['username'] ?? $account['user_id'] ?? '',
                'user_id' => $account['user_id'] ?? '',
                'status' => $account['status'] ?? 'unknown',
                'profile_photo_url' => $account['profile_photo_url'] ?? null
            ];
        }, $response['data']['data']);

        jsonResponse([
            'success' => true,
            'accounts' => $accounts,
            'total' => $response['data']['meta']['total'] ?? count($accounts)
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'error' => $response['error'] ?? 'Failed to fetch accounts',
            'accounts' => []
        ]);
    }
}

/**
 * Disconnect a social account
 */
function disconnectAccount(string $apiKey, string $accountId): void
{
    $response = callPostformeApi('DELETE', "/v1/social-accounts/{$accountId}", null, $apiKey);

    if ($response['success']) {
        jsonResponse(['success' => true, 'message' => 'Account disconnected']);
    } else {
        jsonResponse([
            'success' => false,
            'error' => $response['error'] ?? 'Failed to disconnect account'
        ]);
    }
}

/**
 * Call Postforme API
 */
function callPostformeApi(string $method, string $endpoint, ?array $data, string $apiKey): array
{
    $baseUrl = 'https://api.postforme.dev';
    $url = $baseUrl . $endpoint;

    $ch = curl_init();

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } elseif ($method === 'PUT' || $method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

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
