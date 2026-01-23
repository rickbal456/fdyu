<?php
/**
 * AIKAFLOW API - Image Enhancement via RunningHub GPT Image 1.5 Edit
 * 
 * Uses webhook for async processing - submits task and returns immediately.
 * Frontend polls /api/ai/enhance-image-status.php for result.
 * 
 * POST /api/ai/enhance-image.php - Submit enhancement task
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

try {
    $input = getJsonInput();

    $imageUrl = trim($input['imageUrl'] ?? '');
    $prompt = trim($input['prompt'] ?? '');
    $aspectRatio = $input['aspectRatio'] ?? 'auto';

    if (empty($imageUrl)) {
        errorResponse('Image URL is required');
    }

    if (empty($prompt)) {
        errorResponse('Enhancement prompt is required');
    }

    // Validate aspect ratio
    $validRatios = ['auto', '1:1', '3:2', '2:3'];
    if (!in_array($aspectRatio, $validRatios)) {
        $aspectRatio = 'auto';
    }

    // Get RunningHub API key from site_settings
    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );

    $apiKey = '';
    if ($result && $result['setting_value']) {
        $keys = json_decode($result['setting_value'], true);
        $apiKey = $keys['rhub'] ?? '';
    }

    if (empty($apiKey)) {
        errorResponse('RunningHub API key not configured. Please configure it in Administration → Integrations.');
    }

    // Build webhook URL
    $appUrl = defined('APP_URL') ? APP_URL : '';
    if (empty($appUrl)) {
        // Try to construct from request
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $appUrl = $protocol . '://' . $host;
    }
    $webhookUrl = rtrim($appUrl, '/') . '/api/webhook.php?source=rhub-enhance';

    // Submit enhancement request to RunningHub with webhook
    $ch = curl_init('https://www.runninghub.ai/openapi/v2/rhart-image-g-1.5/edit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $prompt,
            'imageUrls' => [$imageUrl],
            'aspectRatio' => $aspectRatio,
            'callbackUrl' => $webhookUrl
        ]),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $message = $error['errorMessage'] ?? $error['error'] ?? 'Failed to submit enhancement request';
        error_log('Image enhance submit error: ' . $response);
        errorResponse($message);
    }

    $data = json_decode($response, true);
    $taskId = $data['taskId'] ?? null;

    if (!$taskId) {
        errorResponse('No task ID received from RunningHub');
    }

    // Store task in database for tracking
    $nodeId = 'enhance_' . bin2hex(random_bytes(8));

    Database::insert('enhancement_tasks', [
        'user_id' => $user['id'],
        'node_id' => $nodeId,
        'external_task_id' => $taskId,
        'provider' => 'rhub-enhance',
        'task_type' => 'image_enhance',
        'status' => 'processing',
        'input_data' => json_encode([
            'original_image' => $imageUrl,
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio
        ])
    ]);

    // Return immediately with task ID for polling
    successResponse([
        'taskId' => $taskId,
        'nodeId' => $nodeId,
        'status' => 'processing',
        'message' => 'Enhancement started. Please wait...'
    ]);

} catch (Exception $e) {
    error_log('Image enhance error: ' . $e->getMessage());
    errorResponse('Failed to enhance image: ' . $e->getMessage(), 500);
}
