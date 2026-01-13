<?php
/**
 * AIKAFLOW API - Get Storage Configuration Status
 * 
 * GET /api/media/storage-status.php
 * 
 * Returns information about storage configuration:
 * - Whether BunnyCDN is configured
 * - What features are available
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('GET');
$user = requireAuth();

// Check if BunnyCDN is configured
$hasCDN = defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE &&
    defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY;

successResponse([
    'hasCDN' => $hasCDN,
    'storageMode' => $hasCDN ? 'cdn' : 'local',
    'features' => [
        'imageUpload' => true,  // Always available (base64 fallback)
        'videoUpload' => $hasCDN, // Only with CDN
        'audioUpload' => $hasCDN, // Only with CDN
        'persistentStorage' => $hasCDN
    ],
    'warnings' => $hasCDN ? [] : [
        'Images will be sent as base64 to API providers.',
        'Video and audio uploads require CDN configuration.',
        'Results from API providers are temporary and may be deleted.'
    ],
    'recommendation' => $hasCDN ? null :
        'Configure BunnyCDN in Settings → Storage for better performance and persistent file storage.'
]);
