<?php
/**
 * AIKAFLOW Plugin API - Upload and install plugin from ZIP
 */

// Suppress PHP errors from being output as HTML
error_reporting(0);
ini_set('display_errors', 0);

define('AIKAFLOW', true);

// Set JSON header early
header('Content-Type: application/json');

// Custom error handler to return JSON errors
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Authentication error: ' . $e->getMessage()]);
    exit;
}



// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check for file upload
if (!isset($_FILES['plugin']) || $_FILES['plugin']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];

    $errorCode = $_FILES['plugin']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error';

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$uploadedFile = $_FILES['plugin'];
$pluginsDir = dirname(dirname(__DIR__)) . '/plugins';
$tempDir = dirname(dirname(__DIR__)) . '/temp';

// Validate file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
finfo_close($finfo);

// Also check extension
$extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

if ($extension !== 'zip' && $mimeType !== 'application/zip' && $mimeType !== 'application/x-zip-compressed') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only ZIP files are allowed']);
    exit;
}

// Create temp directory if needed
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Generate unique temp path
$tempZipPath = $tempDir . '/plugin_' . uniqid() . '.zip';

// Move uploaded file
if (!move_uploaded_file($uploadedFile['tmp_name'], $tempZipPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

try {
    // Open ZIP file
    $zip = new ZipArchive();
    if ($zip->open($tempZipPath) !== true) {
        throw new Exception('Failed to open ZIP file');
    }

    // Find plugin.json in the ZIP
    $manifestFound = false;
    $pluginRootDir = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);

        // Check if plugin.json exists (might be in root or in a subdirectory)
        if (basename($filename) === 'plugin.json') {
            $manifestFound = true;
            $pluginRootDir = dirname($filename);
            if ($pluginRootDir === '.') {
                $pluginRootDir = '';
            }
            break;
        }
    }

    if (!$manifestFound) {
        $zip->close();
        throw new Exception('Invalid plugin: plugin.json not found');
    }

    // Read and validate manifest
    $manifestPath = $pluginRootDir ? $pluginRootDir . '/plugin.json' : 'plugin.json';
    $manifestContent = $zip->getFromName($manifestPath);

    if (!$manifestContent) {
        $zip->close();
        throw new Exception('Failed to read plugin.json');
    }

    $manifest = json_decode($manifestContent, true);

    if (!$manifest || !isset($manifest['id']) || !isset($manifest['name'])) {
        $zip->close();
        throw new Exception('Invalid plugin.json: missing required fields (id, name)');
    }

    // Sanitize plugin ID (only allow alphanumeric, dashes, underscores)
    $pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $manifest['id']);

    if (empty($pluginId)) {
        $zip->close();
        throw new Exception('Invalid plugin ID');
    }

    $targetDir = $pluginsDir . '/' . $pluginId;

    // Check if plugin already exists
    $isUpdate = is_dir($targetDir);

    // Create plugins directory if needed
    if (!is_dir($pluginsDir)) {
        mkdir($pluginsDir, 0755, true);
    }

    // Remove existing plugin if updating
    if ($isUpdate) {
        // Backup enabled state
        $existingManifest = $targetDir . '/plugin.json';
        $wasEnabled = true;
        if (file_exists($existingManifest)) {
            $existing = json_decode(file_get_contents($existingManifest), true);
            $wasEnabled = $existing['enabled'] ?? true;
        }

        // Remove old files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($targetDir);
    }

    // Create plugin directory
    mkdir($targetDir, 0755, true);

    // Extract files
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);

        // Skip directories and files outside plugin root
        if (substr($filename, -1) === '/') {
            continue;
        }

        // Calculate relative path
        if ($pluginRootDir) {
            if (strpos($filename, $pluginRootDir . '/') !== 0) {
                continue;
            }
            $relativePath = substr($filename, strlen($pluginRootDir) + 1);
        } else {
            $relativePath = $filename;
        }

        // Skip hidden files and potentially dangerous files
        if (strpos($relativePath, '.') === 0 || strpos($relativePath, '..') !== false) {
            continue;
        }

        // Create directory structure
        $targetPath = $targetDir . '/' . $relativePath;
        $targetSubDir = dirname($targetPath);

        if (!is_dir($targetSubDir)) {
            mkdir($targetSubDir, 0755, true);
        }

        // Extract file
        $content = $zip->getFromIndex($i);
        file_put_contents($targetPath, $content);
    }

    $zip->close();

    // Restore enabled state if updating
    if ($isUpdate && isset($wasEnabled)) {
        $newManifest = json_decode(file_get_contents($targetDir . '/plugin.json'), true);
        $newManifest['enabled'] = $wasEnabled;
        file_put_contents($targetDir . '/plugin.json', json_encode($newManifest, JSON_PRETTY_PRINT));
    }

    // Clean up temp file
    unlink($tempZipPath);

    echo json_encode([
        'success' => true,
        'message' => $isUpdate ? 'Plugin updated successfully' : 'Plugin installed successfully',
        'plugin' => [
            'id' => $pluginId,
            'name' => $manifest['name'],
            'version' => $manifest['version'] ?? '1.0.0',
            'isUpdate' => $isUpdate
        ]
    ]);

} catch (Exception $e) {
    // Clean up temp file
    if (file_exists($tempZipPath)) {
        unlink($tempZipPath);
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
