<?php
/**
 * AIKAFLOW Admin Upload API
 * 
 * Handles file uploads for logo and favicon
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/../../includes/auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Check authentication and admin status
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!$user || ((int) $user['id'] !== 1 && ($user['role'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check for file
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$type = $_POST['type'] ?? 'logo';

// Validate file type
$allowedTypes = [
    'logo' => ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'],
    'favicon' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml']
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!in_array($mimeType, $allowedTypes[$type] ?? $allowedTypes['logo'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit;
}

// Validate file size (max 2MB)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large (max 2MB)']);
    exit;
}

// Create uploads directory if not exists
$uploadDir = __DIR__ . '/../../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = $type . '_' . time() . '.' . $extension;
$targetPath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Generate URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname(dirname(dirname($_SERVER['PHP_SELF']))); // Go up 3 levels: admin -> api -> aikaflow
$url = $protocol . '://' . $host . $basePath . '/uploads/' . $filename;

echo json_encode([
    'success' => true,
    'url' => $url,
    'filename' => $filename
]);
