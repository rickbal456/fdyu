<?php
/**
 * AIKAFLOW API - Server Status
 * 
 * GET /api/status.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$status = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'version' => APP_VERSION,
    'services' => []
];

// Check database
try {
    $start = microtime(true);
    Database::query("SELECT 1");
    $dbLatency = round((microtime(true) - $start) * 1000);

    $status['services']['database'] = [
        'status' => 'healthy',
        'latency_ms' => $dbLatency
    ];
} catch (Exception $e) {
    $status['services']['database'] = [
        'status' => 'unhealthy',
        'error' => 'Connection failed'
    ];
}

// Check external APIs (basic connectivity)
$externalApis = [
    'rhub' => RUNNINGHUB_API_URL,
    'kie' => KIE_API_URL,
    'jcut' => JSONCUT_API_URL
];

foreach ($externalApis as $name => $url) {
    if (!$url) {
        $status['services'][$name] = ['status' => 'not_configured'];
        continue;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_NOBODY => true
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $latency = round((microtime(true) - $start) * 1000);
    curl_close($ch);

    $status['services'][$name] = [
        'status' => $httpCode > 0 ? 'reachable' : 'unreachable',
        'latency_ms' => $latency
    ];
}

// Check BunnyCDN
if (BUNNY_STORAGE_ZONE && BUNNY_ACCESS_KEY) {
    $status['services']['bunnycdn'] = ['status' => 'configured'];
} else {
    $status['services']['bunnycdn'] = ['status' => 'not_configured'];
}

// System info
$status['system'] = [
    'php_version' => PHP_VERSION,
    'memory_usage' => memory_get_usage(true),
    'memory_peak' => memory_get_peak_usage(true)
];

echo json_encode($status, JSON_PRETTY_PRINT);