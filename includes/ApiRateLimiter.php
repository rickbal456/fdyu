<?php
/**
 * AIKAFLOW API Rate Limiter
 * 
 * Manages API concurrency limits per API key to prevent exceeding
 * provider rate limits. Queues requests when limits are reached.
 */

class ApiRateLimiter
{
    private static $defaultTimeout = 3600; // 1 hour default timeout

    /**
     * Hash API key for storage (privacy protection)
     */
    public static function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * Get rate limit configuration for a provider
     */
    public static function getLimit(string $provider): array
    {
        $result = Database::fetchOne(
            "SELECT default_max_concurrent, queue_timeout FROM api_rate_limits WHERE provider = ? AND is_active = TRUE",
            [$provider]
        );

        return [
            'max_concurrent' => (int) ($result['default_max_concurrent'] ?? 50),
            'queue_timeout' => (int) ($result['queue_timeout'] ?? self::$defaultTimeout)
        ];
    }

    /**
     * Count active calls for a specific API key
     */
    public static function getActiveCount(string $provider, string $apiKeyHash): int
    {
        // Clean up expired calls first
        self::cleanupExpired($provider, $apiKeyHash);

        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM api_active_calls WHERE provider = ? AND api_key_hash = ?",
            [$provider, $apiKeyHash]
        );
    }

    /**
     * Check if a request can proceed (under limit)
     * @param string $provider Provider name
     * @param string $apiKey API key to check
     * @param int|null $userLimit User-configured limit (0 = unlimited, null = use default)
     */
    public static function canProceed(string $provider, string $apiKey, ?int $userLimit = null): bool
    {
        // If user configured unlimited (0), always allow
        if ($userLimit === 0) {
            return true;
        }

        $apiKeyHash = self::hashApiKey($apiKey);

        // Get effective limit - user configured or system default
        if ($userLimit !== null && $userLimit > 0) {
            $maxConcurrent = $userLimit;
        } else {
            $limit = self::getLimit($provider);
            $maxConcurrent = $limit['max_concurrent'];
        }

        $activeCount = self::getActiveCount($provider, $apiKeyHash);

        return $activeCount < $maxConcurrent;
    }

    /**
     * Acquire a slot for an API call
     * Returns slot ID on success, false if at limit
     */
    public static function acquireSlot(
        string $provider,
        string $apiKey,
        string $taskId,
        ?int $workflowRunId = null,
        ?string $nodeId = null
    ): int|false {
        $apiKeyHash = self::hashApiKey($apiKey);

        if (!self::canProceed($provider, $apiKey)) {
            return false;
        }

        $limit = self::getLimit($provider);
        $expiresAt = date('Y-m-d H:i:s', time() + $limit['queue_timeout']);

        return Database::insert('api_active_calls', [
            'provider' => $provider,
            'api_key_hash' => $apiKeyHash,
            'task_id' => $taskId,
            'workflow_run_id' => $workflowRunId,
            'node_id' => $nodeId,
            'expires_at' => $expiresAt
        ]);
    }

    /**
     * Release a slot when API call completes
     */
    public static function releaseSlot(string $provider, string $taskId): ?string
    {
        // Get the api_key_hash before deleting (needed for queue processing)
        $call = Database::fetchOne(
            "SELECT api_key_hash FROM api_active_calls WHERE provider = ? AND task_id = ?",
            [$provider, $taskId]
        );

        $apiKeyHash = $call['api_key_hash'] ?? null;

        Database::delete('api_active_calls', 'provider = ? AND task_id = ?', [$provider, $taskId]);

        return $apiKeyHash;
    }

    /**
     * Add a request to the queue
     */
    public static function enqueue(
        string $provider,
        string $apiKey,
        ?int $workflowRunId,
        string $nodeId,
        string $nodeType,
        array $inputData,
        int $priority = 0
    ): int {
        $apiKeyHash = self::hashApiKey($apiKey);

        return Database::insert('api_call_queue', [
            'provider' => $provider,
            'api_key_hash' => $apiKeyHash,
            'workflow_run_id' => $workflowRunId,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'input_data' => json_encode($inputData),
            'priority' => $priority,
            'status' => 'pending'
        ]);
    }

    /**
     * Get next queued request for processing
     */
    public static function getNextQueued(string $provider, string $apiKeyHash): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM api_call_queue 
             WHERE provider = ? AND api_key_hash = ? AND status = 'pending' 
             ORDER BY priority ASC, created_at ASC 
             LIMIT 1",
            [$provider, $apiKeyHash]
        );
    }

    /**
     * Process next item in queue (called after slot release)
     * Returns the queue item data if processed, null otherwise
     * @param int|null $userLimit User-configured limit (0 = unlimited, null = use default)
     */
    public static function processQueue(string $provider, string $apiKeyHash, ?int $userLimit = null): ?array
    {
        // If unlimited, always process queue
        if ($userLimit === 0) {
            $queueItem = self::getNextQueued($provider, $apiKeyHash);
            if (!$queueItem) {
                return null; // Queue empty
            }
            Database::update('api_call_queue', ['status' => 'processing', 'processed_at' => date('Y-m-d H:i:s')], 'id = ?', [$queueItem['id']]);
            return $queueItem;
        }

        // Check if we have capacity now
        if ($userLimit !== null && $userLimit > 0) {
            $maxConcurrent = $userLimit;
        } else {
            $limit = self::getLimit($provider);
            $maxConcurrent = $limit['max_concurrent'];
        }

        $activeCount = self::getActiveCount($provider, $apiKeyHash);

        if ($activeCount >= $maxConcurrent) {
            return null; // Still at capacity
        }

        // Get next queued item
        $queueItem = self::getNextQueued($provider, $apiKeyHash);
        if (!$queueItem) {
            return null; // Queue empty
        }

        // Mark as processing
        Database::update('api_call_queue', ['status' => 'processing', 'processed_at' => date('Y-m-d H:i:s')], 'id = ?', [$queueItem['id']]);

        return $queueItem;
    }

    /**
     * Mark queue item as completed
     */
    public static function markQueueCompleted(int $queueId): void
    {
        Database::update('api_call_queue', ['status' => 'completed'], 'id = ?', [$queueId]);
    }

    /**
     * Mark queue item as failed
     */
    public static function markQueueFailed(int $queueId, string $errorMessage): void
    {
        Database::update('api_call_queue', ['status' => 'failed', 'error_message' => $errorMessage], 'id = ?', [$queueId]);
    }

    /**
     * Clean up expired active calls
     */
    public static function cleanupExpired(string $provider, string $apiKeyHash): int
    {
        return Database::delete('api_active_calls', 'provider = ? AND api_key_hash = ? AND expires_at < NOW()', [$provider, $apiKeyHash]);
    }

    /**
     * Get statistics for admin panel
     */
    public static function getStats(?string $provider = null): array
    {
        $stats = [];

        // Get all providers or specific one
        if ($provider) {
            $providers = Database::fetchAll(
                "SELECT * FROM api_rate_limits WHERE provider = ?",
                [$provider]
            );
        } else {
            $providers = Database::fetchAll("SELECT * FROM api_rate_limits WHERE is_active = TRUE");
        }

        foreach ($providers as $p) {
            $providerKey = $p['provider'];

            // Count total active calls for this provider (all API keys)
            $activeTotal = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM api_active_calls WHERE provider = ?",
                [$providerKey]
            );

            // Count pending queue items
            $queuePending = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM api_call_queue WHERE provider = ? AND status = 'pending'",
                [$providerKey]
            );

            // Count unique API keys currently in use
            $uniqueKeys = (int) Database::fetchColumn(
                "SELECT COUNT(DISTINCT api_key_hash) FROM api_active_calls WHERE provider = ?",
                [$providerKey]
            );

            $stats[$providerKey] = [
                'provider' => $providerKey,
                'display_name' => $p['display_name'],
                'max_concurrent' => $p['default_max_concurrent'],
                'queue_timeout' => $p['queue_timeout'],
                'active_calls' => $activeTotal,
                'queue_pending' => $queuePending,
                'unique_api_keys' => $uniqueKeys
            ];
        }

        return $stats;
    }

    /**
     * Update rate limit for a provider (admin function)
     */
    public static function updateLimit(string $provider, int $maxConcurrent, int $queueTimeout): bool
    {
        $affected = Database::update(
            'api_rate_limits',
            ['default_max_concurrent' => $maxConcurrent, 'queue_timeout' => $queueTimeout],
            'provider = ?',
            [$provider]
        );

        return $affected > 0;
    }

    /**
     * Global cleanup - remove all expired calls and old queue entries
     */
    public static function globalCleanup(): array
    {
        // Remove expired active calls
        $expiredCalls = Database::delete('api_active_calls', 'expires_at < NOW()', []);

        // Mark old pending queue items as expired (older than 24 hours)
        $expiredQueue = Database::update(
            'api_call_queue',
            ['status' => 'expired'],
            "status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            []
        );

        // Delete old completed/failed/expired queue entries (older than 7 days)
        $deletedQueue = Database::delete(
            'api_call_queue',
            "status IN ('completed', 'failed', 'expired') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            []
        );

        return [
            'expired_calls_removed' => $expiredCalls,
            'queue_items_expired' => $expiredQueue,
            'old_queue_deleted' => $deletedQueue
        ];
    }
}
