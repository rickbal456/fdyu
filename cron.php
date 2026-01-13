<?php
/**
 * AIKAFLOW - Cron Job Handler
 * 
 * Handles scheduled tasks. Add to crontab:
 *   * * * * * php /path/to/aikaflow/cron.php >> /path/to/aikaflow/logs/cron.log 2>&1
 */

declare(strict_types=1);

define('AIKAFLOW', true);
define('CRON_MODE', true);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/api/helpers.php';

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "[{$timestamp}] Cron job started\n";

try {
    // Run all scheduled tasks
    cleanupExpiredSessions();
    cleanupOldExecutions();
    cleanupTempFiles();
    cleanupApiLogs();
    cleanupWebhookLogs();
    processStuckTasks();
    
    $duration = round((microtime(true) - $startTime) * 1000);
    echo "[{$timestamp}] Cron job completed in {$duration}ms\n";
    
} catch (Exception $e) {
    echo "[{$timestamp}] Cron error: {$e->getMessage()}\n";
    error_log("Cron error: {$e->getMessage()}");
    exit(1);
}

// ============================================
// Cleanup Functions
// ============================================

/**
 * Clean up expired sessions
 */
function cleanupExpiredSessions(): void
{
    $threshold = time() - SESSION_LIFETIME;
    
    $deleted = Database::delete(
        'sessions',
        'last_activity < :threshold',
        ['threshold' => $threshold]
    );
    
    if ($deleted > 0) {
        echo "  - Cleaned up {$deleted} expired sessions\n";
    }
}

/**
 * Clean up old workflow executions (older than 30 days)
 */
function cleanupOldExecutions(): void
{
    $threshold = date('Y-m-d H:i:s', strtotime('-30 days'));
    
    // Get executions to delete
    $executions = Database::fetchAll(
        "SELECT id FROM workflow_executions WHERE created_at < ? AND status IN ('completed', 'failed', 'cancelled')",
        [$threshold]
    );
    
    if (empty($executions)) {
        return;
    }
    
    $ids = array_column($executions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Delete node tasks first
    Database::query(
        "DELETE FROM node_tasks WHERE execution_id IN ({$placeholders})",
        $ids
    );
    
    // Delete executions
    Database::query(
        "DELETE FROM workflow_executions WHERE id IN ({$placeholders})",
        $ids
    );
    
    echo "  - Cleaned up " . count($ids) . " old executions\n";
}

/**
 * Clean up temporary files (older than 24 hours)
 */
function cleanupTempFiles(): void
{
    $tempDir = TEMP_PATH;
    $threshold = time() - 86400; // 24 hours
    $count = 0;
    
    if (!is_dir($tempDir)) {
        return;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getMTime() < $threshold) {
            @unlink($file->getPathname());
            $count++;
        }
    }
    
    // Remove empty directories
    $dirIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($dirIterator as $dir) {
        if ($dir->isDir()) {
            @rmdir($dir->getPathname());
        }
    }
    
    if ($count > 0) {
        echo "  - Cleaned up {$count} temporary files\n";
    }
}

/**
 * Clean up old API logs (older than 7 days)
 */
function cleanupApiLogs(): void
{
    $threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
    
    $deleted = Database::delete(
        'api_logs',
        'created_at < :threshold',
        ['threshold' => $threshold]
    );
    
    if ($deleted > 0) {
        echo "  - Cleaned up {$deleted} old API logs\n";
    }
}

/**
 * Clean up old webhook logs (older than 7 days)
 */
function cleanupWebhookLogs(): void
{
    $threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
    
    $deleted = Database::delete(
        'webhook_logs',
        'created_at < :threshold AND processed = 1',
        ['threshold' => $threshold]
    );
    
    if ($deleted > 0) {
        echo "  - Cleaned up {$deleted} old webhook logs\n";
    }
}

/**
 * Process stuck tasks
 */
function processStuckTasks(): void
{
    $threshold = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    
    // Reset stuck processing tasks
    $updated = Database::query(
        "UPDATE task_queue 
         SET status = 'pending', locked_at = NULL, locked_by = NULL, attempts = attempts + 1
         WHERE status = 'processing' AND locked_at < ?",
        [$threshold]
    )->rowCount();
    
    if ($updated > 0) {
        echo "  - Reset {$updated} stuck tasks\n";
    }
    
    // Mark executions with all failed tasks as failed
    Database::query(
        "UPDATE workflow_executions we
         SET status = 'failed', 
             error_message = 'Execution timeout',
             completed_at = NOW()
         WHERE status = 'running'
         AND started_at < ?
         AND NOT EXISTS (
             SELECT 1 FROM node_tasks nt 
             WHERE nt.execution_id = we.id 
             AND nt.status IN ('pending', 'queued', 'processing')
         )",
        [$threshold]
    );
}