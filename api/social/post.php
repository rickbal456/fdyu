<?php
/**
 * AIKAFLOW API - Create Social Post
 * 
 * POST - Create a social media post via Postforme API
 * 
 * This endpoint is called by the worker during node execution.
 * Returns taskId (post_id) for async webhook handling.
 * 
 * Endpoint: /api/social/post.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['success' => false, 'error' => 'Method not allowed']);
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Required fields
$apiKey = $input['apiKey'] ?? '';
$caption = $input['caption'] ?? '';
$socialAccounts = $input['social_accounts'] ?? [];
$media = $input['media'] ?? [];
$scheduledAt = $input['scheduled_at'] ?? null;
$platformConfigs = $input['platform_configurations'] ?? null;

// Validate required fields
if (empty($apiKey)) {
    jsonResponse(['success' => false, 'error' => 'API key is required']);
}

if (empty($socialAccounts)) {
    jsonResponse(['success' => false, 'error' => 'At least one social account is required']);
}

if (empty($caption) && empty($media)) {
    jsonResponse(['success' => false, 'error' => 'Caption or media is required']);
}

// Upload media to Postforme if needed (convert URLs to Postforme media)
$postformeMedia = [];
foreach ($media as $item) {
    if (isset($item['url'])) {
        // Upload media to Postforme
        $uploadResult = uploadMediaToPostforme($apiKey, $item['url'], $item['type'] ?? 'image');
        if ($uploadResult['success']) {
            $postformeMedia[] = [
                'url' => $uploadResult['url'],
                'type' => $item['type'] ?? 'image'
            ];
        } else {
            // Use direct URL as fallback
            $postformeMedia[] = $item;
        }
    } else {
        $postformeMedia[] = $item;
    }
}

// Build Postforme payload
$payload = [
    'caption' => $caption,
    'social_accounts' => $socialAccounts
];

if (!empty($postformeMedia)) {
    $payload['media'] = $postformeMedia;
}

if ($scheduledAt) {
    $payload['scheduled_at'] = $scheduledAt;
}

if ($platformConfigs) {
    $payload['platform_configurations'] = $platformConfigs;
}

// Call Postforme API to create post
$response = callPostformeApi('/v1/social-posts', $payload, $apiKey);

if ($response['success'] && isset($response['data']['id'])) {
    jsonResponse([
        'success' => true,
        'taskId' => $response['data']['id'], // This is the post_id for webhook tracking
        'postId' => $response['data']['id'],
        'status' => $response['data']['status'] ?? 'pending',
        'message' => 'Post submitted successfully'
    ]);
} else {
    jsonResponse([
        'success' => false,
        'error' => $response['error'] ?? 'Failed to create post'
    ]);
}

/**
 * Upload media to Postforme storage
 */
function uploadMediaToPostforme(string $apiKey, string $sourceUrl, string $type = 'image'): array
{
    // First, get upload URL from Postforme
    $uploadUrlResponse = callPostformeApi('/v1/media/create-upload-url', [
        'content_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
        'file_extension' => $type === 'video' ? 'mp4' : 'jpg'
    ], $apiKey);

    if (!$uploadUrlResponse['success'] || !isset($uploadUrlResponse['data']['upload_url'])) {
        return ['success' => false, 'error' => 'Failed to get upload URL'];
    }

    $uploadUrl = $uploadUrlResponse['data']['upload_url'];
    $mediaUrl = $uploadUrlResponse['data']['media_url'] ?? $uploadUrlResponse['data']['url'] ?? null;

    // Download the source file
    $fileContent = @file_get_contents($sourceUrl);
    if (!$fileContent) {
        return ['success' => false, 'error' => 'Failed to download source file'];
    }

    // Upload to Postforme's storage
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $uploadUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . ($type === 'video' ? 'video/mp4' : 'image/jpeg'),
            'Content-Length: ' . strlen($fileContent)
        ],
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'url' => $mediaUrl];
    }

    return ['success' => false, 'error' => "Upload failed with HTTP {$httpCode}"];
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
        CURLOPT_TIMEOUT => 60
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
