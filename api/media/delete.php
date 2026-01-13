<?php
/**
 * AIKAFLOW API - Delete Media Asset
 * 
 * DELETE /api/media/delete.php?id={id}
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

requireMethod(['DELETE', 'POST']);
$user = requireAuth();

$mediaId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$mediaId) {
    errorResponse('Media ID is required');
}

try {
    // Get asset
    $asset = Database::fetchOne(
        "SELECT * FROM media_assets WHERE id = ? AND user_id = ?",
        [$mediaId, $user['id']]
    );
    
    if (!$asset) {
        errorResponse('Media not found', 404);
    }
    
    // Delete from BunnyCDN
    if (BUNNY_STORAGE_ZONE && BUNNY_ACCESS_KEY && $asset['cdn_path']) {
        $deleteUrl = BUNNY_STORAGE_URL . '/' . BUNNY_STORAGE_ZONE . '/' . $asset['cdn_path'];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $deleteUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . BUNNY_ACCESS_KEY
            ]
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    // Delete from database
    Database::delete('media_assets', 'id = :id', ['id' => $mediaId]);
    
    successResponse(['message' => 'Media deleted successfully']);
    
} catch (Exception $e) {
    error_log('Delete media error: ' . $e->getMessage());
    errorResponse('Failed to delete media', 500);
}