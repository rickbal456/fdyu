<?php
/**
 * AIKAFLOW API - Regenerate API Key
 * 
 * POST /api/user/regenerate-api-key.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod('POST');
$user = requireAuth();

try {
    // Generate new API key
    $newApiKey = Auth::regenerateApiKey($user['id']);
    
    if (!$newApiKey) {
        errorResponse('Failed to regenerate API key', 500);
    }
    
    successResponse([
        'apiKey' => $newApiKey,
        'message' => 'API key regenerated successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Regenerate API key error: ' . $e->getMessage());
    errorResponse('Failed to regenerate API key', 500);
}