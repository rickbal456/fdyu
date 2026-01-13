<?php
/**
 * AIKAFLOW API - File Upload
 * 
 * POST /api/media/upload.php
 * Form data: file, folder?
 * 
 * Upload behavior:
 * - If BunnyCDN is configured: Upload to CDN and return URL
 * - If no CDN: 
 *   - Images: Return base64 data URL (for sending to API providers)
 *   - Video/Audio: Reject upload (too large for base64)
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('POST');
$user = requireAuth();

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];

    $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    errorResponse($errors[$errorCode] ?? 'Upload failed');
}

$file = $_FILES['file'];
$folder = sanitizeString($_POST['folder'] ?? 'uploads', 50);

// Check if BunnyCDN is configured
$hasCDN = defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE &&
    defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY;

// Validate file size
if ($file['size'] > MAX_UPLOAD_SIZE) {
    $maxSizeMB = round(MAX_UPLOAD_SIZE / 1024 / 1024, 1);
    errorResponse('File exceeds maximum size of ' . $maxSizeMB . ' MB');
}


// Validate file type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

$allowedTypes = array_merge(
    ALLOWED_IMAGE_TYPES,
    ALLOWED_VIDEO_TYPES,
    ALLOWED_AUDIO_TYPES
);

if (!in_array($mimeType, $allowedTypes)) {
    errorResponse('File type not allowed: ' . $mimeType);
}

// Determine file type category
$fileType = 'other';
if (in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
    $fileType = 'image';
} elseif (in_array($mimeType, ALLOWED_VIDEO_TYPES)) {
    $fileType = 'video';
} elseif (in_array($mimeType, ALLOWED_AUDIO_TYPES)) {
    $fileType = 'audio';
}

// If no CDN and trying to upload video/audio, reject
if (!$hasCDN && in_array($fileType, ['video', 'audio'])) {
    errorResponse(
        'Video and audio uploads require BunnyCDN storage. ' .
        'Please configure your BunnyCDN API key in Settings → Storage, or provide a URL instead.',
        400
    );
}

try {
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
    $filename = $user['id'] . '/' . $folder . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

    if ($hasCDN) {
        // Upload to BunnyCDN
        $uploadUrl = BUNNY_STORAGE_URL . '/' . BUNNY_STORAGE_ZONE . '/' . $filename;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => file_get_contents($file['tmp_name']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . BUNNY_ACCESS_KEY,
                'Content-Type: ' . $mimeType
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new Exception('BunnyCDN upload failed: ' . $response);
        }

        $cdnUrl = BUNNY_CDN_URL . '/' . $filename;
        $storageMode = 'cdn';

    } else {
        // No CDN - handle based on file type
        if ($fileType === 'image') {
            // For images without CDN: Convert to base64
            $imageData = file_get_contents($file['tmp_name']);
            $base64 = base64_encode($imageData);
            $cdnUrl = 'data:' . $mimeType . ';base64,' . $base64;
            $storageMode = 'base64';

            // Also save locally for preview
            $localPath = TEMP_PATH . '/' . $filename;
            $localDir = dirname($localPath);

            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            move_uploaded_file($file['tmp_name'], $localPath);
            $previewUrl = APP_URL . '/temp/' . $filename;

        } else {
            // This shouldn't happen due to earlier check, but just in case
            throw new Exception('File type not supported without CDN storage');
        }
    }

    // Save to database
    $mediaId = Database::insert('media_assets', [
        'user_id' => $user['id'],
        'filename' => $filename,
        'original_filename' => $file['name'],
        'file_type' => $fileType,
        'file_size' => $file['size'],
        'cdn_url' => $storageMode === 'cdn' ? $cdnUrl : ($previewUrl ?? null),
        'cdn_path' => $filename,
        'metadata' => json_encode([
            'mime_type' => $mimeType,
            'original_name' => $file['name'],
            'storage_mode' => $storageMode,
            'base64_url' => $storageMode === 'base64' ? $cdnUrl : null
        ])
    ]);

    // Build response
    $responseData = [
        'mediaId' => $mediaId,
        'url' => $storageMode === 'cdn' ? $cdnUrl : ($previewUrl ?? $cdnUrl),
        'filename' => $file['name'],
        'fileType' => $fileType,
        'fileSize' => $file['size'],
        'mimeType' => $mimeType,
        'storageMode' => $storageMode
    ];

    // Include base64 for API usage if no CDN
    if ($storageMode === 'base64') {
        $responseData['base64Url'] = $cdnUrl;
        $responseData['previewUrl'] = $previewUrl ?? null;
        $responseData['warning'] = 'No CDN configured. Image will be sent as base64 to API providers.';
    }

    successResponse($responseData);

} catch (Exception $e) {
    error_log('File upload error: ' . $e->getMessage());
    errorResponse('Upload failed: ' . $e->getMessage(), 500);
}