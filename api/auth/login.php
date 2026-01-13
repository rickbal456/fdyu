<?php
/**
 * AIKAFLOW API - Login Endpoint
 * 
 * POST /api/auth/login.php
 * Body: { "email": "...", "password": "..." }
 */

declare(strict_types=1);

define('AIKAFLOW', true);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Try form data
    $input = $_POST;
}

$emailOrUsername = $input['email'] ?? $input['username'] ?? '';
$password = $input['password'] ?? '';

if (empty($emailOrUsername) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and password are required']);
    exit;
}

$result = Auth::login($emailOrUsername, $password);

if ($result['success']) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => $result['user'],
        'redirect' => 'index.php'
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $result['error']
    ]);
}