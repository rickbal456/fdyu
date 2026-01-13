<?php
/**
 * AIKAFLOW API - Logout Endpoint
 * 
 * POST /api/auth/logout.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/auth.php';

// Allow POST or GET for logout
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

Auth::logout();

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully'
]);