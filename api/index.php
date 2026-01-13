<?php
/**
 * AIKAFLOW API Router
 * 
 * Simple router for API endpoints.
 * In production, consider using .htaccess for cleaner URLs.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// CORS headers for API
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

// Simple response helper (may already be defined in helpers.php)
if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse(string $message, int $statusCode = 400): void
    {
        jsonResponse(['success' => false, 'error' => $message], $statusCode);
    }
}

if (!function_exists('successResponse')) {
    function successResponse(array $data = [], string $message = ''): void
    {
        $response = ['success' => true];
        if ($message) {
            $response['message'] = $message;
        }
        jsonResponse(array_merge($response, $data));
    }
}
