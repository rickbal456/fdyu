<?php
/**
 * AIKAFLOW API - Ping/Health Check
 * 
 * GET /api/ping.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'pong',
    'timestamp' => time(),
    'version' => '1.0.0'
]);