<?php
/**
 * AIKAFLOW - Cleanup Expired Content Cron Job
 * 
 * This script should be run via cron (e.g., daily) to delete expired generated content.
 * 
 * Example cron entry (run daily at 2am):
 * 0 2 * * * php /path/to/aikaflow/api/cron/cleanup-expired-content.php
 * 
 * Or via URL (with secret key):
 * curl "https://yourdomain.com/api/cron/cleanup-expired-content.php?key=YOUR_SECRET_KEY"
 */

declare(strict_types=1);

define('AIKAFLOW', true);

// Allow CLI or authenticated HTTP access
$isCli = php_sapi_name() === 'cli';
$isAuthorized = false;

if (!$isCli) {
    // For HTTP access, require a secret key
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';

    header('Content-Type: application/json');

    // Get cron secret key from settings or use a default check
    $cronKey = $_GET['key'] ?? '';
    $storedKey = '';

    try {
        $result = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'cron_secret_key'"
        );
        $storedKey = $result['setting_value'] ?? '';
    } catch (Exception $e) {
        // Continue checking
    }

    // If no stored key, check for admin API key
    if (empty($storedKey)) {
        // Allow with valid admin API key
        $apiKey = $_GET['api_key'] ?? '';
        if (!empty($apiKey)) {
            try {
                $user = Database::fetchOne(
                    "SELECT id, role FROM users WHERE api_key = ?",
                    [$apiKey]
                );
                if ($user && $user['role'] === 'admin') {
                    $isAuthorized = true;
                }
            } catch (Exception $e) {
                // Not authorized
            }
        }
    } else {
        $isAuthorized = hash_equals($storedKey, $cronKey);
    }

    if (!$isAuthorized) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
} else {
    // CLI access - always authorized
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    $isAuthorized = true;
}

// Load BunnyCDN handler if available
$bunnyHandlerPath = __DIR__ . '/../../plugins/aflow-storage-bunnycdn/handler.php';
if (file_exists($bunnyHandlerPath)) {
    require_once $bunnyHandlerPath;
}

/**
 * Main cleanup function
 */
function cleanupExpiredContent(): array
{
    $stats = [
        'checked' => 0,
        'deleted_db' => 0,
        'deleted_files' => 0,
        'deleted_cdn' => 0,
        'errors' => [],
        'started_at' => date('Y-m-d H:i:s'),
        'completed_at' => null
    ];

    try {
        // Get retention setting
        $retentionResult = Database::fetchOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'content_retention_days'"
        );
        $retentionDays = (int) ($retentionResult['setting_value'] ?? 0);

        if ($retentionDays <= 0) {
            $stats['message'] = 'Content retention is disabled (set to 0 days)';
            $stats['completed_at'] = date('Y-m-d H:i:s');
            return $stats;
        }

        // Find expired content
        // Method 1: Check expires_at column if set
        // Method 2: Calculate from created_at if expires_at is NULL
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $expiredItems = Database::fetchAll(
            "SELECT id, user_id, url, item_type, created_at, expires_at 
             FROM user_gallery 
             WHERE (expires_at IS NOT NULL AND expires_at < NOW())
                OR (expires_at IS NULL AND created_at < ?)
             LIMIT 500",
            [$cutoffDate]
        );

        $stats['checked'] = count($expiredItems);

        foreach ($expiredItems as $item) {
            $url = $item['url'];
            $itemId = $item['id'];

            try {
                // Attempt to delete the actual file
                $fileDeleted = deleteFile($url);

                if ($fileDeleted) {
                    // Determine if it was CDN or local
                    if (strpos($url, 'bunnycdn') !== false || strpos($url, 'b-cdn') !== false) {
                        $stats['deleted_cdn']++;
                    } else {
                        $stats['deleted_files']++;
                    }
                }

                // Delete from database
                Database::delete('user_gallery', 'id = ?', [$itemId]);
                $stats['deleted_db']++;

                // Log deletion
                logDeletion($item);

            } catch (Exception $e) {
                $stats['errors'][] = "Item {$itemId}: " . $e->getMessage();
            }
        }

        // Also clean up media_assets table if exists
        try {
            $expiredAssets = Database::fetchAll(
                "SELECT id, user_id, cdn_url, cdn_path, created_at 
                 FROM media_assets 
                 WHERE created_at < ?
                 LIMIT 500",
                [$cutoffDate]
            );

            foreach ($expiredAssets as $asset) {
                try {
                    deleteFile($asset['cdn_url']);
                    Database::delete('media_assets', 'id = ?', [$asset['id']]);
                    $stats['deleted_cdn']++;
                    $stats['deleted_db']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Asset {$asset['id']}: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            // media_assets table might not exist or have different schema
        }

        $stats['message'] = "Cleanup completed successfully";

    } catch (Exception $e) {
        $stats['errors'][] = 'Fatal error: ' . $e->getMessage();
        $stats['message'] = 'Cleanup failed';
    }

    $stats['completed_at'] = date('Y-m-d H:i:s');
    return $stats;
}

/**
 * Delete a file from storage (local or CDN)
 */
function deleteFile(string $url): bool
{
    if (empty($url)) {
        return false;
    }

    // Check if it's a BunnyCDN URL
    if (class_exists('BunnyCDNStorageHandler') && BunnyCDNStorageHandler::isConfigured()) {
        $config = BunnyCDNStorageHandler::getConfig();
        $cdnUrl = rtrim($config['cdnUrl'], '/');

        if (!empty($cdnUrl) && strpos($url, $cdnUrl) === 0) {
            // It's a BunnyCDN URL
            return BunnyCDNStorageHandler::deleteFile($url);
        }
    }

    // Check if it's a local file
    $uploadsDir = realpath(__DIR__ . '/../../uploads');
    if ($uploadsDir) {
        // Try to resolve local path
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '';

        // Remove leading /uploads/ or similar
        if (strpos($path, '/uploads/') !== false) {
            $relativePath = substr($path, strpos($path, '/uploads/') + 9);
            $localPath = $uploadsDir . '/' . $relativePath;

            if (file_exists($localPath) && is_file($localPath)) {
                return @unlink($localPath);
            }
        }
    }

    // Could not delete (external URL or file not found)
    return false;
}

/**
 * Log deletion for auditing
 */
function logDeletion(array $item): void
{
    $logFile = __DIR__ . '/../../logs/content-cleanup.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logEntry = sprintf(
        "[%s] Deleted: ID=%d, User=%d, Type=%s, URL=%s, Created=%s\n",
        date('Y-m-d H:i:s'),
        $item['id'],
        $item['user_id'],
        $item['item_type'],
        $item['url'],
        $item['created_at']
    );

    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Run cleanup
$result = cleanupExpiredContent();

// Output result
if ($isCli) {
    echo "Content Cleanup Report\n";
    echo "======================\n";
    echo "Started: {$result['started_at']}\n";
    echo "Completed: {$result['completed_at']}\n";
    echo "Items checked: {$result['checked']}\n";
    echo "Database records deleted: {$result['deleted_db']}\n";
    echo "Local files deleted: {$result['deleted_files']}\n";
    echo "CDN files deleted: {$result['deleted_cdn']}\n";
    echo "Message: {$result['message']}\n";

    if (!empty($result['errors'])) {
        echo "\nErrors:\n";
        foreach ($result['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
} else {
    echo json_encode([
        'success' => empty($result['errors']),
        'data' => $result
    ]);
}
