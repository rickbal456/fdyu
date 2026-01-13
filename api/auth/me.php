<?php
/**
 * AIKAFLOW API - Get Current User
 * 
 * GET /api/auth/me.php
 * Returns current authenticated user info
 */

declare(strict_types=1);

define('AIKAFLOW', true);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check session auth first
if (Auth::check()) {
    $user = Auth::user();
    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'created_at' => $user['created_at']
        ]
    ]);
    exit;
}

// Check API key auth
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;

if ($apiKey) {
    $user = Auth::authenticateApiKey($apiKey);
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'auth_method' => 'api_key',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username']
            ]
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'authenticated' => false,
    'error' => 'Not authenticated'
]);