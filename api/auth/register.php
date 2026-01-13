<?php
/**
 * AIKAFLOW API - Register Endpoint
 * 
 * POST /api/auth/register.php
 * Body: { "email": "...", "username": "...", "password": "..." }
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
    $input = $_POST;
}

$email = trim($input['email'] ?? '');
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

// Validate required fields
$errors = [];

if (empty($email)) {
    $errors['email'] = 'Email is required';
}

if (empty($username)) {
    $errors['username'] = 'Username is required';
}

if (empty($password)) {
    $errors['password'] = 'Password is required';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

$result = Auth::register($email, $username, $password);

if ($result['success']) {
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'user_id' => $result['user_id']
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $result['error']
    ]);
}