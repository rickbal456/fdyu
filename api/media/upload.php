<?php
/**
 * AIKAFLOW API - File Upload
 * 
 * POST /api/media/upload.php
 * Form data: file, folder?
 * 
 * Upload behavior:
 * - If BunnyCDN is configured (plugin or constants): Upload to CDN and return URL
 * - If no CDN: Save to local uploads folder (NOT base64 to prevent database bloat)
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

// Check if BunnyCDN is configured (constants, plugin handler, or site_settings)
$hasCDN = false;
$bunnyConfig = null;

// First check constants
if (
    defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE &&
    defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY
) {
    $hasCDN = true;
    $bunnyConfig = [
        'storageZone' => BUNNY_STORAGE_ZONE,
        'accessKey' => BUNNY_ACCESS_KEY,
        'storageUrl' => defined('BUNNY_STORAGE_URL') ? BUNNY_STORAGE_URL : 'https://storage.bunnycdn.com',
        'cdnUrl' => defined('BUNNY_CDN_URL') ? BUNNY_CDN_URL : ''
    ];
}

// Try to load plugin handler
if (!$hasCDN) {
    $handlerPath = __DIR__ . '/../../plugins/aflow-storage-bunnycdn/handler.php';
    if (file_exists($handlerPath)) {
        require_once $handlerPath;
    }

    if (class_exists('BunnyCDNStorageHandler')) {
        $config = BunnyCDNStorageHandler::getConfig();
        if (!empty($config['storageZone']) && !empty($config['accessKey']) && !empty($config['cdnUrl'])) {
            $hasCDN = true;
            $bunnyConfig = $config;
        }
    }
}

// Check integration_keys for BunnyCDN settings (format: bunnycdn_storageZone, bunnycdn_accessKey, etc.)
if (!$hasCDN) {
    try {
        $result = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
        );
        if ($result && $result['setting_value']) {
            $keys = json_decode($result['setting_value'], true);
            // Check for bunnycdn_* format
            $storageZone = $keys['bunnycdn_storageZone'] ?? '';
            $accessKey = $keys['bunnycdn_accessKey'] ?? '';
            $cdnUrl = $keys['bunnycdn_cdnUrl'] ?? '';
            $storageUrl = $keys['bunnycdn_storageUrl'] ?? 'https://storage.bunnycdn.com';

            if (!empty($storageZone) && !empty($accessKey) && !empty($cdnUrl)) {
                $hasCDN = true;
                $bunnyConfig = [
                    'storageZone' => $storageZone,
                    'accessKey' => $accessKey,
                    'storageUrl' => $storageUrl,
                    'cdnUrl' => $cdnUrl
                ];
            }
        }
    } catch (Exception $e) {
        error_log('BunnyCDN config check failed: ' . $e->getMessage());
    }
}

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

try {
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
    $uniqueName = bin2hex(random_bytes(16)) . '.' . $extension;
    $relativePath = $user['id'] . '/' . $folder . '/' . $uniqueName;

    if ($hasCDN && $bunnyConfig) {
        // Upload to BunnyCDN
        $uploadUrl = rtrim($bunnyConfig['storageUrl'], '/') . '/' . $bunnyConfig['storageZone'] . '/' . $relativePath;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => file_get_contents($file['tmp_name']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $bunnyConfig['accessKey'],
                'Content-Type: ' . $mimeType
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new Exception('BunnyCDN upload failed: ' . $response);
        }

        $cdnUrl = rtrim($bunnyConfig['cdnUrl'], '/') . '/' . $relativePath;
        $storageMode = 'cdn';

    } else {
        // No CDN - save to local uploads folder (NOT base64!)
        $uploadsDir = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/uploads' : __DIR__ . '/../../uploads';
        $localDir = $uploadsDir . '/' . $user['id'] . '/' . $folder;

        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $localPath = $localDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $localPath)) {
            throw new Exception('Failed to save file to uploads folder');
        }

        // Build URL to the uploaded file
        $cdnUrl = (defined('APP_URL') ? APP_URL : '') . '/uploads/' . $relativePath;
        $storageMode = 'local';
    }

    // Save to database
    $mediaId = Database::insert('media_assets', [
        'user_id' => $user['id'],
        'filename' => $uniqueName,
        'original_filename' => $file['name'],
        'file_type' => $fileType,
        'file_size' => $file['size'],
        'cdn_url' => $cdnUrl,
        'cdn_path' => $relativePath,
        'metadata' => json_encode([
            'mime_type' => $mimeType,
            'original_name' => $file['name'],
            'storage_mode' => $storageMode
        ])
    ]);

    // Build response
    $responseData = [
        'mediaId' => $mediaId,
        'url' => $cdnUrl,
        'filename' => $file['name'],
        'fileType' => $fileType,
        'fileSize' => $file['size'],
        'mimeType' => $mimeType,
        'storageMode' => $storageMode
    ];

    // Add a note if using local storage
    if ($storageMode === 'local') {
        $responseData['note'] = 'File saved to local storage. Configure BunnyCDN for better performance.';
    }

    successResponse($responseData);

} catch (Exception $e) {
    error_log('File upload error: ' . $e->getMessage());
    errorResponse('Upload failed: ' . $e->getMessage(), 500);
}