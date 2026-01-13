<?php
/**
 * AIKAFLOW API - Check External Task Status
 * 
 * GET /api/proxy/status.php?task_id={id}&provider={provider}&api_key={key}
 * 
 * Note: API key must be provided as a query parameter since system-level
 * API keys are no longer configured. The API key should be the one
 * configured by the user in the Integration tab.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('GET');
$user = requireAuth();

$taskId = $_GET['task_id'] ?? '';
$provider = $_GET['provider'] ?? 'runninghub';
$apiKey = $_GET['api_key'] ?? '';

if (!$taskId) {
    errorResponse('task_id is required');
}

if (!$apiKey) {
    errorResponse('api_key is required. Please configure it in the Integration tab.');
}

try {
    $response = null;
    $baseUrl = '';

    switch ($provider) {
        case 'runninghub':
            $baseUrl = defined('RUNNINGHUB_API_URL') ? RUNNINGHUB_API_URL : 'https://api.runninghub.ai';
            break;

        case 'kie':
            $baseUrl = defined('KIE_API_URL') ? KIE_API_URL : 'https://api.kie.ai';
            break;

        case 'jsoncut':
            $baseUrl = defined('JSONCUT_API_URL') ? JSONCUT_API_URL : 'https://api.jsoncut.com';
            break;

        default:
            errorResponse('Unknown provider: ' . $provider);
    }

    $response = httpRequest($baseUrl . "/v1/task/{$taskId}", [
        'method' => 'GET',
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey
        ]
    ]);

    if (!$response['success']) {
        errorResponse($response['error'] ?? 'Failed to check status', $response['httpCode'] ?? 500);
    }

    $data = $response['data'];

    // Normalize response
    successResponse([
        'taskId' => $taskId,
        'provider' => $provider,
        'status' => $data['status'] ?? 'unknown',
        'progress' => $data['progress'] ?? 0,
        'completed' => in_array($data['status'] ?? '', ['completed', 'success', 'done']),
        'failed' => in_array($data['status'] ?? '', ['failed', 'error']),
        'resultUrl' => $data['result_url'] ?? $data['output_url'] ?? null,
        'error' => $data['error'] ?? null
    ]);

} catch (Exception $e) {
    error_log('Status check error: ' . $e->getMessage());
    errorResponse('Failed to check status', 500);
}