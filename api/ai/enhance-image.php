<?php
/**
 * AIKAFLOW API - Image Enhancement via RunningHub GPT Image 1.5 Edit
 * 
 * Enhances images using RunningHub's rhart-image-g-1.5/edit API.
 * API key is read from site_settings (admin-configured).
 * If BunnyCDN is configured, results are saved to CDN.
 * 
 * POST /api/ai/enhance-image.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/PluginManager.php';
require_once __DIR__ . '/../helpers.php';

// Load plugins (including storage handlers)
PluginManager::loadPlugins();

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

    // Submit enhancement request to RunningHub
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
            'aspectRatio' => $aspectRatio
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

    // Poll for result (max 120 seconds, check every 3 seconds)
    $maxAttempts = 40;
    $attempt = 0;
    $resultUrl = null;
    $status = 'RUNNING';

    while ($attempt < $maxAttempts && $status === 'RUNNING') {
        sleep(3); // Wait 3 seconds between polls
        $attempt++;

        // Query task status
        $ch = curl_init('https://www.runninghub.ai/openapi/v2/query');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'taskId' => $taskId
            ]),
            CURLOPT_TIMEOUT => 15
        ]);

        $queryResponse = curl_exec($ch);
        $queryHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($queryHttpCode !== 200) {
            error_log("Image enhance query error (attempt $attempt): $queryResponse");
            continue;
        }

        $queryData = json_decode($queryResponse, true);
        $status = $queryData['status'] ?? 'UNKNOWN';

        if ($status === 'SUCCESS') {
            // Get result URL from results array
            $results = $queryData['results'] ?? [];
            if (!empty($results) && isset($results[0]['url'])) {
                $resultUrl = $results[0]['url'];
            }
            break;
        } elseif ($status === 'FAILED') {
            $errorMessage = $queryData['errorMessage'] ?? 'Image enhancement failed';
            error_log("Image enhance failed: $errorMessage");
            errorResponse($errorMessage);
        }
    }

    if (!$resultUrl) {
        errorResponse('Enhancement timed out or no result received. Please try again.');
    }

    // Try to upload result to BunnyCDN if configured
    $finalUrl = $resultUrl;
    $cdnUrl = uploadToStorage($resultUrl, $user['id']);
    if ($cdnUrl) {
        $finalUrl = $cdnUrl;
    }

    successResponse([
        'enhanced' => $finalUrl,
        'originalResult' => $resultUrl,
        'storageCdn' => $cdnUrl !== null
    ]);

} catch (Exception $e) {
    error_log('Image enhance error: ' . $e->getMessage());
    errorResponse('Failed to enhance image: ' . $e->getMessage(), 500);
}

/**
 * Upload file to storage (BunnyCDN or local)
 * Returns the CDN/local URL if successful, null otherwise
 */
function uploadToStorage(string $sourceUrl, int $userId): ?string
{
    // Try using the BunnyCDN storage handler plugin
    if (class_exists('BunnyCDNStorageHandler') && BunnyCDNStorageHandler::isConfigured()) {
        $filename = "enhanced/" . $userId . "/img_" . time() . "_" . bin2hex(random_bytes(4)) . ".png";
        $result = BunnyCDNStorageHandler::uploadFromUrl($sourceUrl, $filename);
        if ($result) {
            return $result;
        }
    }

    try {
        // Download file from source
        $fileContent = @file_get_contents($sourceUrl);
        if (!$fileContent) {
            error_log("Failed to download enhanced image from: $sourceUrl");
            return null;
        }

        // Determine extension from URL
        $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
        $uniqueName = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $relativePath = "enhanced/{$userId}/{$uniqueName}";

        // Check for BunnyCDN config from constants
        if (defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE && defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY) {
            $storageUrl = defined('BUNNY_STORAGE_URL') ? BUNNY_STORAGE_URL : 'https://storage.bunnycdn.com';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $storageUrl . '/' . BUNNY_STORAGE_ZONE . '/' . $relativePath,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $fileContent,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'AccessKey: ' . BUNNY_ACCESS_KEY,
                    'Content-Type: application/octet-stream'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $cdnUrl = defined('BUNNY_CDN_URL') ? BUNNY_CDN_URL : '';
                return rtrim($cdnUrl, '/') . '/' . $relativePath;
            }
        }

        // Check for BunnyCDN config from integration_keys
        $result = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
        );
        if ($result && $result['setting_value']) {
            $keys = json_decode($result['setting_value'], true);
            $storageZone = $keys['bunnycdn_storageZone'] ?? '';
            $accessKey = $keys['bunnycdn_accessKey'] ?? '';
            $cdnUrlBase = $keys['bunnycdn_cdnUrl'] ?? '';
            $storageUrl = $keys['bunnycdn_storageUrl'] ?? 'https://storage.bunnycdn.com';

            if (!empty($storageZone) && !empty($accessKey) && !empty($cdnUrlBase)) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $storageUrl . '/' . $storageZone . '/' . $relativePath,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $fileContent,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'AccessKey: ' . $accessKey,
                        'Content-Type: application/octet-stream'
                    ]
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    return rtrim($cdnUrlBase, '/') . '/' . $relativePath;
                }
            }
        }

        // Fallback to local filesystem storage
        $basePath = dirname(dirname(__DIR__)) . '/uploads';
        $dirPath = $basePath . '/enhanced/' . $userId;
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $localPath = $dirPath . '/' . $uniqueName;
        if (file_put_contents($localPath, $fileContent) !== false) {
            $appUrl = defined('APP_URL') ? APP_URL : '';
            return rtrim($appUrl, '/') . '/uploads/enhanced/' . $userId . '/' . $uniqueName;
        }

        return null;

    } catch (Exception $e) {
        error_log('Storage upload error: ' . $e->getMessage());
        return null;
    }
}
