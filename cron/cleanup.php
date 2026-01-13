<?php
/**
 * AIKAFLOW - Auto Cleanup Cron Script
 * 
 * Run this script periodically (e.g., daily via cron/task scheduler) to clean up old executions.
 * 
 * Usage:
 *   php cron/cleanup.php
 * 
 * Or via crontab:
 *   0 3 * * * php /path/to/aikaflow/cron/cleanup.php >> /path/to/logs/cleanup.log 2>&1
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Configuration
$CLEANUP_ABORTED_DAYS = 7;      // Delete aborted/cancelled executions older than 7 days
$CLEANUP_FAILED_DAYS = 14;      // Delete failed executions older than 14 days
$CLEANUP_COMPLETED_DAYS = 30;   // Delete completed executions older than 30 days

echo "[" . date('Y-m-d H:i:s') . "] Starting auto-cleanup...\n";

try {
    $totalDeleted = 0;

    // 1. Clean up old aborted/cancelled executions
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$CLEANUP_ABORTED_DAYS} days"));
    $stmt = Database::query(
        "DELETE FROM workflow_executions WHERE status = 'cancelled' AND created_at < ?",
        [$cutoff]
    );
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  - Deleted {$deleted} aborted executions older than {$CLEANUP_ABORTED_DAYS} days\n";

    // 2. Clean up old failed executions
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$CLEANUP_FAILED_DAYS} days"));
    $stmt = Database::query(
        "DELETE FROM workflow_executions WHERE status = 'failed' AND created_at < ?",
        [$cutoff]
    );
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  - Deleted {$deleted} failed executions older than {$CLEANUP_FAILED_DAYS} days\n";

    // 3. Clean up old completed executions
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$CLEANUP_COMPLETED_DAYS} days"));
    $stmt = Database::query(
        "DELETE FROM workflow_executions WHERE status = 'completed' AND completed_at < ?",
        [$cutoff]
    );
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  - Deleted {$deleted} completed executions older than {$CLEANUP_COMPLETED_DAYS} days\n";

    // 4. Clean up orphaned node_tasks (should be handled by CASCADE but just in case)
    $stmt = Database::query(
        "DELETE nt FROM node_tasks nt 
         LEFT JOIN workflow_executions we ON nt.execution_id = we.id 
         WHERE we.id IS NULL"
    );
    $orphanedTasks = $stmt->rowCount();
    if ($orphanedTasks > 0) {
        echo "  - Deleted {$orphanedTasks} orphaned node tasks\n";
    }

    // 5. Clean up orphaned flow_executions
    $stmt = Database::query(
        "DELETE fe FROM flow_executions fe 
         LEFT JOIN workflow_executions we ON fe.execution_id = we.id 
         WHERE we.id IS NULL"
    );
    $orphanedFlows = $stmt->rowCount();
    if ($orphanedFlows > 0) {
        echo "  - Deleted {$orphanedFlows} orphaned flow executions\n";
    }

    // 6. Clean up old task queue entries
    $cutoff = date('Y-m-d H:i:s', strtotime("-7 days"));
    $stmt = Database::query(
        "DELETE FROM task_queue WHERE status IN ('completed', 'failed') AND created_at < ?",
        [$cutoff]
    );
    $queueDeleted = $stmt->rowCount();
    if ($queueDeleted > 0) {
        echo "  - Deleted {$queueDeleted} old task queue entries\n";
    }

    // 7. Clean up expired CSRF tokens
    $stmt = Database::query(
        "DELETE FROM csrf_tokens WHERE expires_at < NOW()"
    );
    $csrfDeleted = $stmt->rowCount();
    if ($csrfDeleted > 0) {
        echo "  - Deleted {$csrfDeleted} expired CSRF tokens\n";
    }

    // 8. Clean up old API logs (keep last 30 days)
    $cutoff = date('Y-m-d H:i:s', strtotime("-30 days"));
    $stmt = Database::query(
        "DELETE FROM api_logs WHERE created_at < ?",
        [$cutoff]
    );
    $logsDeleted = $stmt->rowCount();
    if ($logsDeleted > 0) {
        echo "  - Deleted {$logsDeleted} old API log entries\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete. Total executions deleted: {$totalDeleted}\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
