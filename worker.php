<?php

/**
 * AIKAFLOW - Background Task Worker
 * 
 * Processes queued tasks for workflow execution.
 * Run via cron or supervisor:
 *   php worker.php
 * 
 * Or as daemon:
 *   php worker.php --daemon
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/includes/PluginManager.php';

// Configuration
$workerConfig = [
    'sleep_interval' => 2,        // Seconds between checks
    'max_execution_time' => 300,  // Max time per task (seconds)
    'batch_size' => 5,            // Tasks to process per cycle
    'lock_timeout' => 600,        // Lock timeout (seconds)
    'worker_id' => gethostname() . '_' . getmypid()
];

$isDaemon = in_array('--daemon', $argv ?? []);
$running = true;

// Signal handling for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) {
        echo "[" . date('Y-m-d H:i:s') . "] Received SIGTERM, shutting down...\n";
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        echo "[" . date('Y-m-d H:i:s') . "] Received SIGINT, shutting down...\n";
        $running = false;
    });
}

echo "[" . date('Y-m-d H:i:s') . "] Worker started (ID: {$workerConfig['worker_id']})\n";

// Main loop
do {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    try {
        // Clean up stale locks
        cleanupStaleLocks($workerConfig['lock_timeout']);

        // Fetch pending tasks
        $tasks = fetchPendingTasks($workerConfig['batch_size'], $workerConfig['worker_id']);

        if (empty($tasks)) {
            if ($isDaemon) {
                sleep($workerConfig['sleep_interval']);
                continue;
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] No pending tasks\n";
                break;
            }
        }

        foreach ($tasks as $task) {
            if (!$running)
                break;

            echo "[" . date('Y-m-d H:i:s') . "] Processing task #{$task['id']}: {$task['task_type']}\n";

            try {
                processTask($task);
                markTaskCompleted($task['id']);
                echo "[" . date('Y-m-d H:i:s') . "] Task #{$task['id']} completed\n";
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Task #{$task['id']} failed: {$e->getMessage()}\n";
                markTaskFailed($task['id'], $e->getMessage());
            }
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Worker error: {$e->getMessage()}\n";
        error_log('Worker error: ' . $e->getMessage());
        sleep($workerConfig['sleep_interval']);
    }
} while ($isDaemon && $running);

echo "[" . date('Y-m-d H:i:s') . "] Worker stopped\n";

// ============================================
// Worker Functions
// ============================================

/**
 * Fetch pending tasks and lock them
 */
function fetchPendingTasks(int $limit, string $workerId): array
{
    $now = date('Y-m-d H:i:s');

    // Lock tasks atomically
    Database::query(
        "UPDATE task_queue 
         SET status = 'processing', locked_at = ?, locked_by = ?
         WHERE status = 'pending' AND scheduled_at <= ?
         ORDER BY priority DESC, created_at ASC
         LIMIT ?",
        [$now, $workerId, $now, $limit]
    );

    // Fetch locked tasks
    return Database::fetchAll(
        "SELECT * FROM task_queue WHERE status = 'processing' AND locked_by = ?",
        [$workerId]
    );
}

/**
 * Clean up stale locks
 */
function cleanupStaleLocks(int $timeout): void
{
    $threshold = date('Y-m-d H:i:s', time() - $timeout);

    // Reset stale tasks
    Database::query(
        "UPDATE task_queue 
         SET status = 'pending', locked_at = NULL, locked_by = NULL, attempts = attempts + 1
         WHERE status = 'processing' AND locked_at < ?",
        [$threshold]
    );

    // Mark tasks that exceeded max attempts as failed
    Database::query(
        "UPDATE task_queue SET status = 'failed' WHERE attempts >= max_attempts AND status = 'pending'"
    );
}

/**
 * Process a task
 */
function processTask(array $task): void
{
    $payload = json_decode($task['payload'], true);

    switch ($task['task_type']) {
        case 'node_execution':
            processNodeExecution($payload);
            break;

        case 'poll_api_status':
            processPollApiStatus($payload);
            break;

        default:
            throw new Exception("Unknown task type: {$task['task_type']}");
    }
}

/**
 * Process node execution task
 */
function processNodeExecution(array $payload): void
{
    $executionId = $payload['execution_id'];
    $taskId = $payload['task_id'];
    $nodeId = $payload['node_id'];
    $nodeType = $payload['node_type'];

    // Get node task
    $nodeTask = Database::fetchOne(
        "SELECT * FROM node_tasks WHERE id = ?",
        [$taskId]
    );

    if (!$nodeTask) {
        throw new Exception("Node task not found: {$taskId}");
    }

    // Prevent duplicate execution - skip if already has external task ID or is in final state
    if (!empty($nodeTask['external_task_id'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Skipping node {$nodeId} - already has external_task_id: {$nodeTask['external_task_id']}\n";
        return;
    }

    if (in_array($nodeTask['status'], ['completed', 'failed'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Skipping node {$nodeId} - already in final state: {$nodeTask['status']}\n";
        return;
    }

    // Also check if node is already processing (to prevent race conditions)
    if ($nodeTask['status'] === 'processing' && !empty($nodeTask['started_at'])) {
        $startedAt = strtotime($nodeTask['started_at']);
        // If started less than 5 minutes ago, skip (might still be running)
        if (time() - $startedAt < 300) {
            echo "[" . date('Y-m-d H:i:s') . "] Skipping node {$nodeId} - already processing (started at {$nodeTask['started_at']})\n";
            return;
        }
    }

    // Update status to processing
    Database::update(
        'node_tasks',
        ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s')],
        'id = :id',
        ['id' => $taskId]
    );

    // Get input data
    $inputData = json_decode($nodeTask['input_data'], true) ?: [];

    // Get inputs from connected nodes
    $connectedInputs = getNodeInputs($executionId, $nodeId);
    $inputData = array_merge($inputData, $connectedInputs);

    // Debug logging
    $debugLog = __DIR__ . '/logs/worker_debug.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($debugLog, "[$ts] [processNodeExecution] Node: $nodeId ($nodeType)\n", FILE_APPEND);
    @file_put_contents($debugLog, "[$ts] [processNodeExecution] Connected inputs: " . json_encode($connectedInputs) . "\n", FILE_APPEND);
    @file_put_contents($debugLog, "[$ts] [processNodeExecution] Merged inputData keys: " . implode(', ', array_keys($inputData)) . "\n", FILE_APPEND);

    // Credit Deduction
    $cost = (float) Database::fetchColumn("SELECT cost_per_call FROM node_costs WHERE node_type = ?", [$nodeType]);

    if ($cost > 0) {
        $execution = Database::fetchOne("SELECT user_id FROM workflow_executions WHERE id = ?", [$executionId]);
        $userId = $execution['user_id'];

        // Check balance
        $balance = (float) Database::fetchColumn(
            "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
             WHERE user_id = ? AND remaining > 0 
             AND (expires_at IS NULL OR expires_at >= CURDATE())",
            [$userId]
        );

        if ($balance < $cost) {
            throw new Exception("Insufficient credits. Cost: {$cost}, Available: {$balance}");
        }

        // Deduct credits (FIFO)
        $remainingToDeduct = $cost;
        $ledgers = Database::fetchAll(
            "SELECT * FROM credit_ledger 
             WHERE user_id = ? AND remaining > 0 
             AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY expires_at ASC, id ASC",
            [$userId]
        );

        foreach ($ledgers as $ledger) {
            if ($remainingToDeduct <= 0)
                break;

            $deduct = min($remainingToDeduct, $ledger['remaining']);

            Database::query(
                "UPDATE credit_ledger SET remaining = remaining - ? WHERE id = ?",
                [$deduct, $ledger['id']]
            );

            $remainingToDeduct -= $deduct;
        }

        // Log transaction
        $newBalance = $balance - $cost;
        Database::insert('credit_transactions', [
            'user_id' => $userId,
            'type' => 'usage',
            'amount' => -$cost,
            'balance_after' => $newBalance,
            'description' => "Node execution: {$nodeType}",
            'reference_id' => "task_{$taskId}"
        ]);
    }

    // Execute node based on type
    $result = executeNode($nodeType, $inputData);

    if ($result['success']) {
        // Update node task with result
        Database::update(
            'node_tasks',
            [
                'status' => 'completed',
                'external_task_id' => $result['taskId'] ?? null,
                'result_url' => $result['resultUrl'] ?? null,
                'output_data' => json_encode($result['output'] ?? []),
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $taskId]
        );

        // If async task, queue polling (webhooks are unreliable)
        // Exception: social-post nodes complete immediately since Postforme doesn't send webhooks for post results
        if (isset($result['taskId']) && !isset($result['resultUrl']) && $nodeType !== 'social-post') {
            // Task is async - start polling for status
            Database::update(
                'node_tasks',
                ['status' => 'processing'],
                'id = :id',
                ['id' => $taskId]
            );

            // Queue polling task
            Database::insert('task_queue', [
                'task_type' => 'poll_api_status',
                'payload' => json_encode([
                    'node_task_id' => $taskId,
                    'execution_id' => $executionId,
                    'external_task_id' => $result['taskId'],
                    'provider' => $inputData['_provider'] ?? 'rhub',
                    'api_key' => $inputData['_resolved_api_key'] ?? null,
                    'poll_count' => 0,
                    'max_polls' => 60  // 10 minutes max (60 * 10s)
                ]),
                'status' => 'pending',
                'scheduled_at' => date('Y-m-d H:i:s', strtotime('+10 seconds')),
                'priority' => 5,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] Queued polling task for external_task_id: {$result['taskId']}\n";
            return;
        }

        // Queue next task
        queueNextPendingTask($executionId);
    } else {
        throw new Exception($result['error'] ?? 'Node execution failed');
    }
}

/**
 * Process poll API status task
 * Polls external API to check task status and update workflow
 */
function processPollApiStatus(array $payload): void
{
    $nodeTaskId = $payload['node_task_id'];
    $executionId = $payload['execution_id'];
    $externalTaskId = $payload['external_task_id'];
    $provider = $payload['provider'] ?? 'rhub';
    $apiKey = $payload['api_key'] ?? null;
    $pollCount = $payload['poll_count'] ?? 0;
    $maxPolls = $payload['max_polls'] ?? 60;

    $debugLog = __DIR__ . '/logs/worker_debug.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($debugLog, "[$ts] [Poll] Checking status for task: $externalTaskId (poll $pollCount/$maxPolls)\n", FILE_APPEND);

    // Check if node task still exists and is processing
    $nodeTask = Database::fetchOne("SELECT * FROM node_tasks WHERE id = ?", [$nodeTaskId]);
    if (!$nodeTask) {
        @file_put_contents($debugLog, "[$ts] [Poll] Node task not found, skipping\n", FILE_APPEND);
        return;
    }

    if ($nodeTask['status'] !== 'processing') {
        @file_put_contents($debugLog, "[$ts] [Poll] Node task already {$nodeTask['status']}, skipping\n", FILE_APPEND);
        return;
    }

    // Check if task is stale (started more than 1 hour ago)
    $startedAt = strtotime($nodeTask['started_at'] ?? 'now');
    $staleThreshold = 3600; // 1 hour
    if (time() - $startedAt > $staleThreshold) {
        $error = "Task is stale: started more than 1 hour ago and never completed. External task ID may be invalid.";
        @file_put_contents($debugLog, "[$ts] [Poll] $error\n", FILE_APPEND);

        Database::update(
            'node_tasks',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $nodeTaskId]
        );

        Database::update(
            'workflow_executions',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $executionId]
        );

        echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId marked as stale/failed\n";
        return;
    }

    // Get API key if not provided
    if (!$apiKey) {
        $apiKey = PluginManager::resolveApiKey($provider);
    }

    if (!$apiKey) {
        throw new Exception("No API key available for provider: $provider");
    }

    // Query task status from provider
    $result = queryExternalTaskStatus($provider, $externalTaskId, $apiKey);
    @file_put_contents($debugLog, "[$ts] [Poll] Query result: " . json_encode($result) . "\n", FILE_APPEND);

    // Handle error status (treat as failed)
    if ($result['status'] === 'error') {
        $error = $result['error'] ?? 'API query returned error status';
        @file_put_contents($debugLog, "[$ts] [Poll] Error status received: $error\n", FILE_APPEND);

        // If it's a temporary error and we haven't exceeded max polls, retry
        if ($pollCount < $maxPolls && strpos($error, 'cURL') !== false) {
            // Re-queue for retry
            Database::insert('task_queue', [
                'task_type' => 'poll_api_status',
                'payload' => json_encode([
                    'node_task_id' => $nodeTaskId,
                    'execution_id' => $executionId,
                    'external_task_id' => $externalTaskId,
                    'provider' => $provider,
                    'api_key' => $apiKey,
                    'poll_count' => $pollCount + 1,
                    'max_polls' => $maxPolls
                ]),
                'status' => 'pending',
                'scheduled_at' => date('Y-m-d H:i:s', strtotime('+30 seconds')),
                'priority' => 5,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo "[" . date('Y-m-d H:i:s') . "] Poll: Temporary error, will retry in 30s: $error\n";
            return;
        }

        // Non-recoverable error, mark as failed
        Database::update(
            'node_tasks',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $nodeTaskId]
        );

        Database::update(
            'workflow_executions',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $executionId]
        );

        echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId failed with error: $error\n";
        return;
    }

    if ($result['status'] === 'SUCCESS' || $result['status'] === 'completed') {
        // Task completed successfully
        $resultUrl = $result['resultUrl'] ?? null;

        // Upload to CDN if needed
        if ($resultUrl) {
            $filename = 'video_' . time() . '_' . uniqid() . '.mp4';
            $uploadedUrl = uploadDataUrlToCDN($resultUrl, $filename);
            if ($uploadedUrl) {
                $resultUrl = $uploadedUrl;
            }
        }

        Database::update(
            'node_tasks',
            [
                'status' => 'completed',
                'result_url' => $resultUrl,
                'output_data' => json_encode(['video' => $resultUrl]),
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $nodeTaskId]
        );

        echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId completed with result: $resultUrl\n";

        // Queue next task
        queueNextPendingTask($executionId);
    } elseif ($result['status'] === 'FAILED' || $result['status'] === 'failed') {
        // Task failed
        $error = $result['error'] ?? 'External API task failed';

        Database::update(
            'node_tasks',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $nodeTaskId]
        );

        Database::update(
            'workflow_executions',
            [
                'status' => 'failed',
                'error_message' => $error,
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $executionId]
        );

        echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId failed: $error\n";
    } else {
        // Still running - queue another poll if not exceeded max
        if ($pollCount >= $maxPolls) {
            $error = "Polling timeout: Task did not complete within " . ($maxPolls * 10) . " seconds";

            Database::update(
                'node_tasks',
                [
                    'status' => 'failed',
                    'error_message' => $error,
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $nodeTaskId]
            );

            Database::update(
                'workflow_executions',
                [
                    'status' => 'failed',
                    'error_message' => $error,
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $executionId]
            );

            echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId timed out\n";
        } else {
            // Re-queue polling
            Database::insert('task_queue', [
                'task_type' => 'poll_api_status',
                'payload' => json_encode([
                    'node_task_id' => $nodeTaskId,
                    'execution_id' => $executionId,
                    'external_task_id' => $externalTaskId,
                    'provider' => $provider,
                    'api_key' => $apiKey,
                    'poll_count' => $pollCount + 1,
                    'max_polls' => $maxPolls
                ]),
                'status' => 'pending',
                'scheduled_at' => date('Y-m-d H:i:s', strtotime('+10 seconds')),
                'priority' => 5,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] Poll: Task $externalTaskId still running, re-queued poll " . ($pollCount + 1) . "\n";
        }
    }
}

/**
 * Query external task status from provider API
 */
function queryExternalTaskStatus(string $provider, string $taskId, string $apiKey): array
{
    $debugLog = __DIR__ . '/logs/worker_debug.log';
    $ts = date('Y-m-d H:i:s');

    switch ($provider) {
        case 'rhub':
            // RunningHub task query endpoint - from official docs: https://www.runninghub.ai/openapi/v2/query
            $url = 'https://www.runninghub.ai/openapi/v2/query';
            @file_put_contents($debugLog, "[$ts] [QueryStatus] Calling URL: $url with taskId: $taskId\n", FILE_APPEND);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['taskId' => $taskId]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            @file_put_contents($debugLog, "[$ts] [QueryStatus] HTTP $httpCode: $response\n", FILE_APPEND);

            // Handle cURL errors
            if ($curlError) {
                @file_put_contents($debugLog, "[$ts] [QueryStatus] cURL Error: $curlError\n", FILE_APPEND);
                return ['status' => 'error', 'error' => "cURL error: $curlError"];
            }

            if ($httpCode !== 200) {
                // Treat 4xx/5xx as potential expired task
                if ($httpCode >= 400) {
                    @file_put_contents($debugLog, "[$ts] [QueryStatus] Task may be expired (HTTP $httpCode)\n", FILE_APPEND);
                    return ['status' => 'FAILED', 'error' => "Task not found or expired (HTTP $httpCode)"];
                }
                return ['status' => 'error', 'error' => "HTTP $httpCode"];
            }

            $data = json_decode($response, true);
            if (!$data) {
                return ['status' => 'error', 'error' => 'Invalid JSON response'];
            }

            // Handle RunningHub API error format (code != 0 means error)
            if (isset($data['code']) && $data['code'] != 0) {
                $errorMsg = $data['msg'] ?? 'Unknown API error';
                @file_put_contents($debugLog, "[$ts] [QueryStatus] API Error code {$data['code']}: $errorMsg\n", FILE_APPEND);

                // Common error codes that indicate task doesn't exist
                if (in_array($data['code'], [404, 40001, 40002, 50001])) {
                    return ['status' => 'FAILED', 'error' => "Task not found: $errorMsg"];
                }
                return ['status' => 'FAILED', 'error' => "API error (code {$data['code']}): $errorMsg"];
            }

            // Handle data wrapper format from RunningHub
            $taskData = $data['data'] ?? $data;

            // Map RunningHub status
            $status = $taskData['status'] ?? $data['status'] ?? 'UNKNOWN';
            $resultUrl = null;

            // Try multiple result formats
            if (isset($taskData['results']) && is_array($taskData['results']) && !empty($taskData['results'])) {
                $resultUrl = $taskData['results'][0]['url'] ?? null;
            } elseif (isset($data['results']) && is_array($data['results']) && !empty($data['results'])) {
                $resultUrl = $data['results'][0]['url'] ?? null;
            } elseif (isset($taskData['output']['video'])) {
                $resultUrl = $taskData['output']['video'];
            } elseif (isset($taskData['resultUrl'])) {
                $resultUrl = $taskData['resultUrl'];
            }

            @file_put_contents($debugLog, "[$ts] [QueryStatus] Parsed status: $status, resultUrl: " . ($resultUrl ?? 'null') . "\n", FILE_APPEND);

            return [
                'status' => $status,
                'resultUrl' => $resultUrl,
                'error' => $taskData['errorMessage'] ?? $data['errorMessage'] ?? $data['msg'] ?? null
            ];

        default:
            return ['status' => 'error', 'error' => "Unknown provider: $provider"];
    }
}


/**
 * Get inputs from connected nodes
 */
function getNodeInputs(int $executionId, string $nodeId): array
{
    $debugLog = __DIR__ . '/logs/worker_debug.log';
    $ts = date('Y-m-d H:i:s');

    @file_put_contents($debugLog, "[$ts] [getNodeInputs] START - executionId: $executionId, nodeId: $nodeId\n", FILE_APPEND);

    // Get workflow data to find connections
    // First try workflow_json_data (stored directly), then fall back to JOIN with workflows table
    $execution = Database::fetchOne(
        "SELECT we.*, w.json_data as workflow_joined_data
         FROM workflow_executions we
         LEFT JOIN workflows w ON w.id = we.workflow_id
         WHERE we.id = ?",
        [$executionId]
    );

    // Prefer workflow_json_data (directly stored), then workflow_joined_data (from JOIN)
    $jsonData = $execution['workflow_json_data'] ?? $execution['workflow_joined_data'] ?? null;

    @file_put_contents($debugLog, "[$ts] [getNodeInputs] workflow_id: " . ($execution['workflow_id'] ?? 'NULL') . "\n", FILE_APPEND);
    @file_put_contents($debugLog, "[$ts] [getNodeInputs] workflow_json_data exists: " . (!empty($execution['workflow_json_data']) ? 'YES' : 'NO') . "\n", FILE_APPEND);
    @file_put_contents($debugLog, "[$ts] [getNodeInputs] workflow_joined_data exists: " . (!empty($execution['workflow_joined_data']) ? 'YES' : 'NO') . "\n", FILE_APPEND);
    @file_put_contents($debugLog, "[$ts] [getNodeInputs] Final jsonData exists: " . (!empty($jsonData) ? 'YES' : 'NO') . "\n", FILE_APPEND);

    if (!$execution || !$jsonData) {
        @file_put_contents($debugLog, "[$ts] [getNodeInputs] RETURNING EMPTY - no execution or json_data\n", FILE_APPEND);
        return [];
    }

    $workflowData = json_decode($jsonData, true);
    $connections = $workflowData['connections'] ?? [];

    @file_put_contents($debugLog, "[$ts] [getNodeInputs] Total connections in workflow: " . count($connections) . "\n", FILE_APPEND);

    $inputs = [];

    foreach ($connections as $conn) {
        @file_put_contents($debugLog, "[$ts] [getNodeInputs] Connection: {$conn['from']['nodeId']} -> {$conn['to']['nodeId']}\n", FILE_APPEND);

        if ($conn['to']['nodeId'] === $nodeId) {
            @file_put_contents($debugLog, "[$ts] [getNodeInputs] MATCH FOUND - looking for source: {$conn['from']['nodeId']}\n", FILE_APPEND);

            // Get output from source node
            $sourceTask = Database::fetchOne(
                "SELECT result_url, output_data FROM node_tasks 
                 WHERE execution_id = ? AND node_id = ? AND status = 'completed'",
                [$executionId, $conn['from']['nodeId']]
            );

            @file_put_contents($debugLog, "[$ts] [getNodeInputs] Source task found: " . ($sourceTask ? 'YES' : 'NO') . "\n", FILE_APPEND);

            if ($sourceTask) {
                $inputKey = $conn['to']['portId'];
                @file_put_contents($debugLog, "[$ts] [getNodeInputs] result_url: " . ($sourceTask['result_url'] ?? 'NULL') . "\n", FILE_APPEND);
                @file_put_contents($debugLog, "[$ts] [getNodeInputs] output_data: " . ($sourceTask['output_data'] ?? 'NULL') . "\n", FILE_APPEND);

                if ($sourceTask['result_url']) {
                    $inputs[$inputKey] = $sourceTask['result_url'];
                    @file_put_contents($debugLog, "[$ts] [getNodeInputs] Assigned result_url to '$inputKey'\n", FILE_APPEND);
                } elseif ($sourceTask['output_data']) {
                    $outputData = json_decode($sourceTask['output_data'], true);
                    $fromPort = $conn['from']['portId'];
                    $inputs[$inputKey] = $outputData[$fromPort] ?? null;
                    @file_put_contents($debugLog, "[$ts] [getNodeInputs] Assigned output_data[$fromPort] to '$inputKey': " . ($inputs[$inputKey] ?? 'NULL') . "\n", FILE_APPEND);
                }
            }
        }
    }

    @file_put_contents($debugLog, "[$ts] [getNodeInputs] FINAL inputs: " . json_encode($inputs) . "\n", FILE_APPEND);
    return $inputs;
}

/**
 * Execute a node
 */
function executeNode(string $nodeType, array $inputData): array
{
    // Debug: write to dedicated log file
    $debugLog = __DIR__ . '/logs/worker_debug.log';
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($debugLog, "[$ts] executeNode called for: $nodeType\n", FILE_APPEND);

    // Try Plugin Manager first
    $pluginResult = PluginManager::executeNode($nodeType, $inputData);

    @file_put_contents($debugLog, "[$ts] PluginManager result: " . json_encode([
        'success' => $pluginResult['success'] ?? false,
        'error' => $pluginResult['error'] ?? null,
        'taskId' => $pluginResult['taskId'] ?? null
    ]) . "\n", FILE_APPEND);

    if ($pluginResult['success']) {
        @file_put_contents($debugLog, "[$ts] Returning success from PluginManager\n", FILE_APPEND);
        return $pluginResult;
    }

    // If error is NOT "Unknown node type", it means plugin execution failed, so return error
    if (isset($pluginResult['error']) && strpos($pluginResult['error'], 'Unknown node type') === false) {
        @file_put_contents($debugLog, "[$ts] Returning plugin error: {$pluginResult['error']}\n", FILE_APPEND);
        return $pluginResult;
    }

    // Internal/Utility nodes
    switch ($nodeType) {
        // Utility nodes
        case 'delay':
            $duration = (int) ($inputData['duration'] ?? 5);
            sleep(min($duration, 60)); // Max 60 seconds
            return [
                'success' => true,
                'output' => $inputData
            ];

        case 'condition':
            $input = $inputData['input'] ?? null;
            $condition = $inputData['condition'] ?? 'exists';
            $value = $inputData['value'] ?? '';

            $result = false;
            switch ($condition) {
                case 'exists':
                    $result = !empty($input);
                    break;
                case 'empty':
                    $result = empty($input);
                    break;
                case 'contains':
                    $result = is_string($input) && str_contains($input, $value);
                    break;
                case 'equals':
                    $result = $input == $value;
                    break;
                default:
                    $result = !empty($input);
            }

            return [
                'success' => true,
                'output' => [
                    'true' => $result ? $input : null,
                    'false' => !$result ? $input : null,
                    'result' => $result
                ]
            ];

        case 'start-flow':
        case 'manual-trigger':
        case 'flow-merge':
            return [
                'success' => true,
                'output' => $inputData
            ];

        default:
            return [
                'success' => false,
                'error' => "Unknown node type: {$nodeType}"
            ];
    }
}

/**
 * Upload data URL to CDN or local storage
 * Priority: BunnyCDN plugin -> Constants-based CDN -> Local filesystem
 */
function uploadDataUrlToCDN(string $dataUrl, string $filename): ?string
{
    // Try using the BunnyCDN storage handler plugin
    if (class_exists('BunnyCDNStorageHandler') && BunnyCDNStorageHandler::isConfigured()) {
        $result = BunnyCDNStorageHandler::uploadDataUrl($dataUrl, $filename);
        if ($result)
            return $result;
    }

    // Parse data URL first (needed for all fallbacks)
    if (!preg_match('/^data:([^;]+);base64,(.+)$/', $dataUrl, $matches)) {
        return null;
    }

    $mimeType = $matches[1];
    $data = base64_decode($matches[2]);

    if (!$data) {
        return null;
    }

    // Generate file extension
    $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
    if (!$extension || $extension === 'bin') {
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav'
        ];
        if (isset($mimeTypes[$mimeType])) {
            $extension = $mimeTypes[$mimeType];
        }
    }

    $uniqueName = bin2hex(random_bytes(16)) . '.' . $extension;
    $datePath = date('Y/m/d');

    // Try constants-based BunnyCDN approach
    if (defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE && defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY) {
        $path = 'uploads/' . $datePath . '/' . $uniqueName;
        $storageUrl = defined('BUNNY_STORAGE_URL') ? BUNNY_STORAGE_URL : 'https://storage.bunnycdn.com';
        $url = rtrim($storageUrl, '/') . '/' . BUNNY_STORAGE_ZONE . '/' . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . BUNNY_ACCESS_KEY,
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . strlen($data)
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $cdnUrl = defined('BUNNY_CDN_URL') ? BUNNY_CDN_URL : '';
            // Ensure https:// protocol
            if (!empty($cdnUrl) && !preg_match('#^https?://#i', $cdnUrl)) {
                $cdnUrl = 'https://' . $cdnUrl;
            }
            return rtrim($cdnUrl, '/') . '/' . $path;
        }
    }

    // Fallback to local filesystem storage
    $uploadDir = __DIR__ . '/uploads/' . $datePath;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $localPath = $uploadDir . '/' . $uniqueName;
    if (file_put_contents($localPath, $data) !== false) {
        // Return URL to the uploaded file
        $appUrl = defined('APP_URL') ? APP_URL : '';
        return rtrim($appUrl, '/') . '/uploads/' . $datePath . '/' . $uniqueName;
    }

    return null;
}


/**
 * Queue next pending task
 */
function queueNextPendingTask(int $executionId): void
{
    // Find next pending task
    $task = Database::fetchOne(
        "SELECT * FROM node_tasks WHERE execution_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1",
        [$executionId]
    );

    if (!$task) {
        // Check if all tasks are complete
        $incomplete = Database::fetchColumn(
            "SELECT COUNT(*) FROM node_tasks WHERE execution_id = ? AND status NOT IN ('completed', 'failed')",
            [$executionId]
        );

        if ($incomplete == 0) {
            // Finalize execution
            finalizeExecution($executionId);
        }
        return;
    }

    // Check if task is already queued to prevent duplicates
    $existingQueue = Database::fetchOne(
        "SELECT id FROM task_queue WHERE payload LIKE ? AND status IN ('pending', 'processing')",
        ['%"task_id":' . $task['id'] . '%']
    );

    if ($existingQueue) {
        echo "[" . date('Y-m-d H:i:s') . "] Task {$task['id']} already in queue, skipping\n";
        return;
    }

    // Add to queue
    Database::insert('task_queue', [
        'task_type' => 'node_execution',
        'payload' => json_encode([
            'execution_id' => $executionId,
            'task_id' => $task['id'],
            'node_id' => $task['node_id'],
            'node_type' => $task['node_type']
        ]),
        'priority' => 1,
        'status' => 'pending'
    ]);

    // Update task status
    Database::update(
        'node_tasks',
        ['status' => 'queued'],
        'id = :id',
        ['id' => $task['id']]
    );
}

/**
 * Finalize execution (or start next iteration)
 */
function finalizeExecution(int $executionId): void
{
    // Get execution details including repeat info
    $execution = Database::fetchOne(
        "SELECT * FROM workflow_executions WHERE id = ?",
        [$executionId]
    );

    if (!$execution) {
        echo "[" . date('Y-m-d H:i:s') . "] Execution not found: {$executionId}\n";
        return;
    }

    $repeatCount = (int) ($execution['repeat_count'] ?? 1);
    $currentIteration = (int) ($execution['current_iteration'] ?? 1);
    $iterationOutputs = json_decode($execution['iteration_outputs'] ?? '[]', true) ?: [];

    $tasks = Database::fetchAll(
        "SELECT * FROM node_tasks WHERE execution_id = ? ORDER BY id DESC",
        [$executionId]
    );

    $allCompleted = true;
    $finalResultUrl = null;
    $outputs = [];

    // Check if CDN is configured
    $hasCDN = defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE &&
        defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY;

    foreach ($tasks as $task) {
        if ($task['status'] !== 'completed') {
            $allCompleted = false;
        }

        if ($task['result_url']) {
            $finalResultUrl = $task['result_url'];
        }

        $outputs[$task['node_id']] = [
            'status' => $task['status'],
            'resultUrl' => $task['result_url']
        ];
    }

    // Store this iteration's output
    $iterationOutputs[] = [
        'iteration' => $currentIteration,
        'result_url' => $finalResultUrl,
        'outputs' => $outputs,
        'completed_at' => date('Y-m-d H:i:s')
    ];

    // Check if we need more iterations
    if ($allCompleted && $currentIteration < $repeatCount) {
        // More iterations needed - start next iteration
        echo "[" . date('Y-m-d H:i:s') . "] Iteration {$currentIteration}/{$repeatCount} complete, starting next...\n";

        // Update execution with new iteration number and accumulated outputs
        Database::update(
            'workflow_executions',
            [
                'current_iteration' => $currentIteration + 1,
                'iteration_outputs' => json_encode($iterationOutputs),
                'status' => 'running'
            ],
            'id = :id',
            ['id' => $executionId]
        );

        // Clone node_tasks for next iteration (reset status to pending)
        foreach ($tasks as $task) {
            // Get original input data (node config data, not computed inputs)
            $inputData = json_decode($task['input_data'], true) ?: [];

            Database::insert('node_tasks', [
                'execution_id' => $executionId,
                'node_id' => $task['node_id'],
                'node_type' => $task['node_type'],
                'status' => 'pending',
                'input_data' => json_encode($inputData)
            ]);
        }

        // Delete old completed tasks to avoid confusion
        Database::delete('node_tasks', 'execution_id = ? AND status IN (\'completed\', \'failed\')', [$executionId]);

        // Queue next task
        queueNextPendingTask($executionId);

        return;
    }

    // All iterations complete - finalize
    echo "[" . date('Y-m-d H:i:s') . "] All {$repeatCount} iteration(s) complete\n";

    // Prepare result metadata
    $resultMetadata = [
        'storage_mode' => $hasCDN ? 'cdn' : 'direct',
        'is_temporary' => !$hasCDN,
        'total_iterations' => $repeatCount,
        'completed_iterations' => count($iterationOutputs)
    ];

    // Add warning for temporary files
    if (!$hasCDN && $finalResultUrl) {
        $resultMetadata['warning'] = 'This result is stored on the API provider\'s temporary storage and may be deleted. Please download immediately.';
        $resultMetadata['download_urgency'] = 'high';
    }

    // Collect all result URLs for easy access
    $allResultUrls = [];
    foreach ($iterationOutputs as $io) {
        if (!empty($io['result_url'])) {
            $allResultUrls[] = $io['result_url'];
        }
    }

    Database::update(
        'workflow_executions',
        [
            'status' => $allCompleted ? 'completed' : 'failed',
            'result_url' => $finalResultUrl, // Last result URL for backward compatibility
            'output_data' => json_encode([
                'nodes' => $outputs,
                'metadata' => $resultMetadata,
                'all_results' => $allResultUrls
            ]),
            'iteration_outputs' => json_encode($iterationOutputs),
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $executionId]
    );

    // Add all outputs to user gallery
    if (!empty($allResultUrls) && $execution['user_id']) {
        foreach ($iterationOutputs as $io) {
            if (!empty($io['result_url'])) {
                try {
                    // Determine item type from URL
                    $itemType = 'video'; // Default
                    $url = $io['result_url'];
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url)) {
                        $itemType = 'image';
                    } elseif (preg_match('/\.(mp3|wav|ogg|m4a)$/i', $url)) {
                        $itemType = 'audio';
                    }

                    Database::insert('user_gallery', [
                        'user_id' => $execution['user_id'],
                        'workflow_id' => $execution['workflow_id'],
                        'item_type' => $itemType,
                        'url' => $io['result_url'],
                        'metadata' => json_encode([
                            'execution_id' => $executionId,
                            'iteration' => $io['iteration']
                        ])
                    ]);
                } catch (Exception $e) {
                    // Silently fail gallery insert
                    error_log('Gallery insert failed: ' . $e->getMessage());
                }
            }
        }
    }
}


/**
 * Mark queue task as completed
 */
function markTaskCompleted(int $taskId): void
{
    Database::update(
        'task_queue',
        ['status' => 'completed', 'locked_at' => null, 'locked_by' => null],
        'id = :id',
        ['id' => $taskId]
    );
}

/**
 * Mark queue task as failed
 */
function markTaskFailed(int $taskId, string $error): void
{
    $task = Database::fetchOne("SELECT * FROM task_queue WHERE id = ?", [$taskId]);

    if ($task && $task['attempts'] < $task['max_attempts']) {
        // Retry
        Database::update(
            'task_queue',
            [
                'status' => 'pending',
                'locked_at' => null,
                'locked_by' => null,
                'scheduled_at' => date('Y-m-d H:i:s', time() + 30) // Retry in 30 seconds
            ],
            'id = :id',
            ['id' => $taskId]
        );
    } else {
        // Max attempts reached
        Database::update(
            'task_queue',
            ['status' => 'failed', 'locked_at' => null, 'locked_by' => null],
            'id = :id',
            ['id' => $taskId]
        );

        // Mark node task as failed
        $payload = json_decode($task['payload'], true);
        if (isset($payload['task_id'])) {
            Database::update(
                'node_tasks',
                [
                    'status' => 'failed',
                    'error_message' => $error,
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $payload['task_id']]
            );

            // Mark execution as failed
            Database::update(
                'workflow_executions',
                [
                    'status' => 'failed',
                    'error_message' => $error,
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $payload['execution_id']]
            );
        }
    }
}
