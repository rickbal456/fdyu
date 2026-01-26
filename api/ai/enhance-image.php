<?php
/**
 * AIKAFLOW API - Image Enhancement via OpenRouter (Flux model)
 * 
 * Enhances images using OpenRouter's image-to-image capabilities.
 * Uses the same LLM API key as text enhancement.
 * Returns result instantly (no webhook needed).
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

// Load plugins (for storage handler)
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

    if (empty($imageUrl)) {
        errorResponse('Image URL is required');
    }

    if (empty($prompt)) {
        errorResponse('Enhancement prompt is required');
    }

    // Get LLM API key from site_settings (same as text enhancement)
    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );

    $apiKey = '';
    if ($result && $result['setting_value']) {
        $keys = json_decode($result['setting_value'], true);
        $apiKey = $keys['llm'] ?? $keys['openrouter'] ?? '';
    }

    if (empty($apiKey)) {
        errorResponse('LLM API key not configured. Please configure it in Administration → Integrations.');
    }

    // Download the image and convert to base64
    $imageContent = @file_get_contents($imageUrl);
    if (!$imageContent) {
        errorResponse('Failed to download image from URL');
    }

    // Detect image type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageContent);
    $base64Image = base64_encode($imageContent);
    $dataUri = "data:{$mimeType};base64,{$base64Image}";

    // Call OpenRouter API with Flux model for image-to-image
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://fidyu.com'),
            'X-Title: AIKAFLOW'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'black-forest-labs/flux-1.1-pro',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUri
                            ]
                        ]
                    ]
                ]
            ]
        ]),
        CURLOPT_TIMEOUT => 120 // Allow up to 2 minutes for image generation
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('Image enhance cURL error: ' . $curlError);
        errorResponse('Network error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $message = $error['error']['message'] ?? 'Failed to enhance image';
        error_log('Image enhance API error: ' . $response);
        errorResponse($message);
    }

    $data = json_decode($response, true);

    // Extract the generated image URL from response
    $enhancedUrl = null;

    // OpenRouter returns the image in the message content
    if (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];

        // If content is an array (multimodal response)
        if (is_array($content)) {
            foreach ($content as $part) {
                if (isset($part['type']) && $part['type'] === 'image_url') {
                    $enhancedUrl = $part['image_url']['url'] ?? null;
                    break;
                }
            }
        } elseif (is_string($content)) {
            // Check if it's a URL or base64
            if (filter_var($content, FILTER_VALIDATE_URL)) {
                $enhancedUrl = $content;
            } elseif (preg_match('/^data:image\//', $content)) {
                $enhancedUrl = $content;
            }
        }
    }

    // Also check for image in the response data directly
    if (!$enhancedUrl && isset($data['data'][0]['url'])) {
        $enhancedUrl = $data['data'][0]['url'];
    }

    if (!$enhancedUrl && isset($data['data'][0]['b64_json'])) {
        $enhancedUrl = 'data:image/png;base64,' . $data['data'][0]['b64_json'];
    }

    if (!$enhancedUrl) {
        error_log('Image enhance - no image in response: ' . $response);
        errorResponse('No enhanced image returned from API');
    }

    // If it's a base64 image, save it to storage
    if (preg_match('/^data:image\/(\w+);base64,/', $enhancedUrl, $matches)) {
        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $enhancedUrl);
        $imageData = base64_decode($base64Data);

        // Try to save to BunnyCDN or local storage
        $filename = 'enhanced_' . $user['id'] . '_' . time() . '.' . $extension;
        $savedUrl = saveEnhancedImage($imageData, $filename, $user['id']);

        if ($savedUrl) {
            $enhancedUrl = $savedUrl;
        }
    }

    successResponse([
        'enhanced' => $enhancedUrl
    ]);

} catch (Exception $e) {
    error_log('Image enhance error: ' . $e->getMessage());
    errorResponse('Failed to enhance image: ' . $e->getMessage(), 500);
}

/**
 * Save enhanced image to BunnyCDN or local storage
 */
function saveEnhancedImage(string $imageData, string $filename, int $userId): ?string
{
    // Try BunnyCDN plugin first
    if (class_exists('BunnyCDNStorageHandler') && BunnyCDNStorageHandler::isConfigured()) {
        // Detect mime type from data
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData) ?: 'image/png';
        $result = BunnyCDNStorageHandler::uploadData($imageData, $mimeType, "enhanced/{$userId}/{$filename}");
        if ($result)
            return $result;
    }

    // Try constants-based BunnyCDN
    if (defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE && defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY) {
        $storageUrl = defined('BUNNY_STORAGE_URL') ? BUNNY_STORAGE_URL : 'https://storage.bunnycdn.com';
        $path = "enhanced/{$userId}/{$filename}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $storageUrl . '/' . BUNNY_STORAGE_ZONE . '/' . $path,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $imageData,
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
            return rtrim($cdnUrl, '/') . '/' . $path;
        }
    }

    // Fallback to local storage
    $uploadDir = __DIR__ . '/../../uploads/enhanced/' . $userId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $localPath = $uploadDir . '/' . $filename;
    if (file_put_contents($localPath, $imageData)) {
        $appUrl = defined('APP_URL') ? APP_URL : '';
        return rtrim($appUrl, '/') . '/uploads/enhanced/' . $userId . '/' . $filename;
    }

    return null;
}
