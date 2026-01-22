<?php
/**
 * AIKAFLOW API - Postforme Webhook Registration
 * 
 * Registers a webhook with Postforme API for receiving post result notifications.
 * Should be called once when API key is first configured.
 * 
 * POST - Register webhook
 * GET  - Check webhook status
 * 
 * Endpoint: /api/social/setup-webhook.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

// Get Postforme API key from request or stored settings
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$apiKey = $input['apiKey'] ?? '';

// If no API key in request, get from site_settings (configured by admin)
if (empty($apiKey)) {
    $user = requireAuth();
    $integrationKeys = [];
    try {
        $result = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
        );
        if ($result && isset($result['setting_value']) && $result['setting_value']) {
            $integrationKeys = json_decode($result['setting_value'], true) ?: [];
        }
    } catch (Exception $e) {
        // Ignore
    }
    // Check for both new key 'sapi' and old key 'postforme' for backward compatibility
    $apiKey = $integrationKeys['sapi'] ?? $integrationKeys['postforme'] ?? '';
}

if (empty($apiKey)) {
    jsonResponse(['success' => false, 'error' => 'Postforme API key is required']);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        registerWebhook($apiKey);
        break;

    case 'GET':
        checkWebhookStatus($apiKey);
        break;

    default:
        http_response_code(405);
        jsonResponse(['success' => false, 'error' => 'Method not allowed']);
}

/**
 * Register webhook with Postforme
 */
function registerWebhook(string $apiKey): void
{
    $appUrl = defined('APP_URL') ? APP_URL : '';
    $webhookUrl = rtrim($appUrl, '/') . '/api/webhook.php?source=postforme';

    // First, check if webhook already exists
    $existingWebhooks = callPostformeApi('GET', '/v1/webhooks?url=' . urlencode($webhookUrl), null, $apiKey);

    if ($existingWebhooks['success'] && !empty($existingWebhooks['data']['data'])) {
        // Webhook already exists
        $webhook = $existingWebhooks['data']['data'][0];
        jsonResponse([
            'success' => true,
            'message' => 'Webhook already registered',
            'webhookId' => $webhook['id'],
            'url' => $webhook['url']
        ]);
        return;
    }

    // Register new webhook
    $response = callPostformeApi('POST', '/v1/webhooks', [
        'url' => $webhookUrl,
        'event_types' => [
            'social.post.result.created',
            'social.post.updated',
            'social.account.created',
            'social.account.updated'
        ]
    ], $apiKey);

    if ($response['success'] && isset($response['data']['id'])) {
        // Store webhook secret for verification
        $webhookSecret = $response['data']['secret'] ?? '';

        // Store in site settings for global access
        try {
            Database::query(
                "INSERT INTO site_settings (`key`, `value`) VALUES ('postforme_webhook_secret', ?) 
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$webhookSecret]
            );
        } catch (Exception $e) {
            error_log('Failed to store webhook secret: ' . $e->getMessage());
        }

        jsonResponse([
            'success' => true,
            'message' => 'Webhook registered successfully',
            'webhookId' => $response['data']['id'],
            'url' => $webhookUrl
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'error' => $response['error'] ?? 'Failed to register webhook'
        ]);
    }
}

/**
 * Check webhook registration status
 */
function checkWebhookStatus(string $apiKey): void
{
    $appUrl = defined('APP_URL') ? APP_URL : '';
    $webhookUrl = rtrim($appUrl, '/') . '/api/webhook.php?source=postforme';

    $response = callPostformeApi('GET', '/v1/webhooks', null, $apiKey);

    if ($response['success'] && isset($response['data']['data'])) {
        $webhooks = $response['data']['data'];

        // Find our webhook
        $ourWebhook = null;
        foreach ($webhooks as $webhook) {
            if (strpos($webhook['url'], 'aikaflow') !== false || $webhook['url'] === $webhookUrl) {
                $ourWebhook = $webhook;
                break;
            }
        }

        if ($ourWebhook) {
            jsonResponse([
                'success' => true,
                'registered' => true,
                'webhook' => [
                    'id' => $ourWebhook['id'],
                    'url' => $ourWebhook['url'],
                    'events' => $ourWebhook['event_types'] ?? []
                ]
            ]);
        } else {
            jsonResponse([
                'success' => true,
                'registered' => false,
                'message' => 'Webhook not registered'
            ]);
        }
    } else {
        jsonResponse([
            'success' => false,
            'error' => $response['error'] ?? 'Failed to check webhook status'
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
    } elseif ($method !== 'GET') {
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
