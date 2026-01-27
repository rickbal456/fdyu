<?php
/**
 * AIKAFLOW API - Webhook Handler
 * 
 * Receives callbacks from external services (RunningHub, etc.)
 * 
 * POST /api/webhook.php?source={source}
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/PluginManager.php';
require_once __DIR__ . '/../includes/ApiRateLimiter.php';
require_once __DIR__ . '/helpers.php';

// Load plugins (including storage handlers)
PluginManager::loadPlugins();

/**
 * Sanitize error messages for user display
 * - Removes provider-specific information
 * - Removes non-English text (Chinese, etc.)
 * - Returns generic user-friendly message
 */
function sanitizeErrorMessage(string $rawError): string
{
    // Log raw error for admin debugging
    // (already done before calling this function)

    // Check for common error patterns and return user-friendly messages
    $patterns = [
        // Network/SSL errors
        '/SSL|SSLError|HTTPS|ConnectionPool|Max retries/i' => 'Connection error. Please try again later.',
        // Rate limiting
        '/rate limit|too many requests|throttl/i' => 'Service busy. Please try again in a few minutes.',
        // Image loading errors
        '/Failed to fetch|load image|LoadImageFromUrl/i' => 'Failed to load input media. Please check your file and try again.',
        // Model/API errors with Chinese text
        '/[\x{4e00}-\x{9fff}]/u' => 'Generation service temporarily unavailable. Please try again later.',
        // Content policy
        '/content policy|moderation|nsfw|inappropriate/i' => 'Content could not be processed. Please adjust your input.',
        // GPU/resource errors
        '/GPU|out of memory|resource|CUDA/i' => 'Service is experiencing high demand. Please try again later.',
        // Timeout
        '/timeout|timed out/i' => 'Request timed out. Please try again.',
        // Generic API errors
        '/APIKEY|API.?KEY/i' => 'Service configuration error. Please contact support.',
    ];

    foreach ($patterns as $pattern => $message) {
        if (preg_match($pattern, $rawError)) {
            return $message;
        }
    }

    // Default generic message
    return 'Generation failed. Please try again later.';
}

// Log all webhook requests
$rawInput = file_get_contents('php://input');
$source = $_GET['source'] ?? 'unknown';

try {
    // Log webhook
    $logId = Database::insert('webhook_logs', [
        'source' => $source,
        'external_id' => $_GET['task_id'] ?? null,
        'payload' => $rawInput,
        'processed' => 0
    ]);

    // Parse payload
    $payload = json_decode($rawInput, true);

    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Extract task info based on source
    $taskId = null;
    $status = null;
    $resultUrl = null;
    $error = null;

    switch ($source) {
        case 'rhub':
        case 'runninghub': // Backward compatibility - external API sends this
            // Check for AI App format (eventData)
            if (isset($payload['eventData'])) {
                $taskId = $payload['taskId'] ?? null;

                // Parse eventData string
                $eventData = is_string($payload['eventData']) ? json_decode($payload['eventData'], true) : $payload['eventData'];

                // Check for error in eventData (code != 0 means error)
                if (isset($eventData['code']) && $eventData['code'] != 0) {
                    $status = 'failed';
                    $rawError = $eventData['msg'] ?? 'Task failed';

                    // Try to extract more specific error from failedReason
                    if (isset($eventData['data']['failedReason']['exception_message'])) {
                        $rawError = $eventData['data']['failedReason']['exception_message'];
                    }

                    // Log raw error for debugging but sanitize for user display
                    error_log("[Webhook] RunningHub task failed: code={$eventData['code']}, raw_msg={$rawError}");
                    $error = sanitizeErrorMessage($rawError);
                }
                // Check for TASK_FAIL event
                elseif (($payload['event'] ?? '') === 'TASK_FAIL') {
                    $status = 'failed';
                    $rawError = $payload['msg'] ?? 'Task failed';
                    error_log("[Webhook] RunningHub TASK_FAIL: raw_msg={$rawError}");
                    $error = sanitizeErrorMessage($rawError);
                }
                // Success case - TASK_END with code 0 or no code
                elseif (($payload['event'] ?? '') === 'TASK_END') {
                    $status = 'completed';

                    // Look for video file in data array
                    if (isset($eventData['data']) && is_array($eventData['data'])) {
                        foreach ($eventData['data'] as $item) {
                            if (is_array($item) && isset($item['fileUrl'])) {
                                if (($item['fileType'] ?? '') === 'mp4') {
                                    $resultUrl = $item['fileUrl'];
                                    break;
                                }
                            }
                        }
                        // Fallback to first file with fileUrl
                        if (!$resultUrl) {
                            foreach ($eventData['data'] as $item) {
                                if (is_array($item) && isset($item['fileUrl'])) {
                                    $resultUrl = $item['fileUrl'];
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $status = 'processing';
                }

            } else {
                // Standard/Legacy format
                $taskId = $payload['task_id'] ?? $payload['id'] ?? null;
                $status = $payload['status'] ?? null;
                $resultUrl = $payload['result_url'] ?? $payload['output_url'] ?? null;
                $error = $payload['error'] ?? null;
            }
            break;

        case 'kapi':
        case 'kie':
            // KIE.AI webhook format:
            // {
            //   "code": 200,
            //   "data": {
            //     "taskId": "...",
            //     "state": "success" | "fail" | "waiting",
            //     "resultJson": "{\"resultUrls\":[\"https://...\"]}"
            //   }
            // }
            $data = $payload['data'] ?? [];
            $taskId = $data['taskId'] ?? null;

            // Map KIE.AI state to our internal status
            $kieState = $data['state'] ?? '';
            if ($kieState === 'success') {
                $status = 'completed';
            } elseif ($kieState === 'fail') {
                $status = 'failed';
                $error = $data['failMsg'] ?? 'Task failed';
            } else {
                $status = 'processing';
            }

            // Extract result URL from resultJson
            if (isset($data['resultJson'])) {
                $resultData = is_string($data['resultJson'])
                    ? json_decode($data['resultJson'], true)
                    : $data['resultJson'];

                if (isset($resultData['resultUrls']) && is_array($resultData['resultUrls']) && count($resultData['resultUrls']) > 0) {
                    $resultUrl = $resultData['resultUrls'][0];
                }
            }
            break;

        case 'jcut':
        case 'jsoncut': // Backward compatibility - external API sends this
            $taskId = $payload['task_id'] ?? null;
            $status = $payload['status'] ?? null;
            $resultUrl = $payload['output_url'] ?? null;
            $error = $payload['error'] ?? null;
            break;

        case 'sapi':
        case 'postforme': // Backward compatibility - external API sends this
            // Postforme webhook for social post results
            // Verify webhook signature if secret is stored
            $webhookSecret = '';
            try {
                $secretRow = Database::fetchOne("SELECT value FROM site_settings WHERE `key` = 'postforme_webhook_secret'");
                $webhookSecret = $secretRow['value'] ?? '';
            } catch (Exception $e) {
                // Ignore
            }

            // Get signature from header (Postforme uses this header)
            $receivedSignature = $_SERVER['HTTP_POST_FOR_ME_WEBHOOK_SECRET'] ?? '';
            if ($webhookSecret && $receivedSignature && $receivedSignature !== $webhookSecret) {
                error_log('Postforme webhook signature mismatch');
                // Continue processing anyway for now, but log the mismatch
            }

            // Extract event data
            $eventType = $payload['event_type'] ?? $payload['type'] ?? '';
            $eventData = $payload['data'] ?? $payload;

            if ($eventType === 'social.post.result.created' || isset($eventData['post_id'])) {
                // Post result created - get post ID and result
                $taskId = $eventData['post_id'] ?? $eventData['social_post_id'] ?? $eventData['id'] ?? null;
                $postSuccess = $eventData['success'] ?? null;

                if ($postSuccess === true || $postSuccess === 'true') {
                    $status = 'completed';
                } elseif ($postSuccess === false || $postSuccess === 'false') {
                    $status = 'failed';
                } else {
                    $status = $eventData['status'] ?? 'completed';
                }

                // Get platform URL from result
                $platformData = $eventData['platform_data'] ?? [];
                $resultUrl = $platformData['url'] ?? $platformData['post_url'] ?? null;

                // Get error if failed
                $errorData = $eventData['error'] ?? [];
                $error = is_array($errorData) ? ($errorData['message'] ?? json_encode($errorData)) : $errorData;

            } elseif ($eventType === 'social.account.created' || $eventType === 'social.account.updated') {
                // Account event - no task to update, just log
                error_log('Postforme account event: ' . $eventType);
                $taskId = null;
            }
            break;
    }

    if ($taskId) {
        error_log("[Webhook] Looking for node_task with external_task_id: $taskId");

        // Find matching node task
        $nodeTask = Database::fetchOne(
            "SELECT * FROM node_tasks WHERE external_task_id = ?",
            [$taskId]
        );

        if ($nodeTask) {
            error_log("[Webhook] Found node_task id={$nodeTask['id']}, current status={$nodeTask['status']}");

            // Map external status to internal status
            $internalStatus = 'processing';
            if (in_array($status, ['completed', 'success', 'done'])) {
                $internalStatus = 'completed';
            } elseif (in_array($status, ['failed', 'error'])) {
                $internalStatus = 'failed';
            }
            error_log("[Webhook] Status mapping: external=$status -> internal=$internalStatus");

            // Skip if task is already completed or failed (prevent duplicate processing)
            if (in_array($nodeTask['status'], ['completed', 'failed'])) {
                error_log("[Webhook] Skipping - task already in final state: {$nodeTask['status']}");

                // Mark webhook as processed and return success
                Database::update('webhook_logs', ['processed' => 1], 'id = :id', ['id' => $logId]);

                echo json_encode(['success' => true, 'message' => 'Already processed']);
                exit;
            }

            // If task completed successfully with a result URL, handle BunnyCDN upload first
            // This ensures the frontend gets the CDN URL when BunnyCDN is enabled
            $finalResultUrl = $resultUrl;
            $finalStatus = $internalStatus;

            if ($resultUrl && $internalStatus === 'completed') {
                // Check if this is a generation/editing node that should use CDN
                $nodeType = $nodeTask['node_type'];

                // Try to upload to BunnyCDN - if configured
                $cdnUrl = uploadToBunnyCDN($resultUrl, $nodeTask['execution_id'], $nodeTask['node_id']);
                if ($cdnUrl) {
                    $finalResultUrl = $cdnUrl; // Use CDN URL instead of original
                    error_log("[Webhook] CDN upload successful for node {$nodeTask['node_id']}: $cdnUrl");
                } else {
                    // CDN upload failed or not configured, use original URL
                    error_log("[Webhook] CDN upload skipped/failed for node {$nodeTask['node_id']}, using original URL");
                }
            }

            // Update task with final URL and status
            Database::update(
                'node_tasks',
                [
                    'status' => $finalStatus,
                    'result_url' => $finalResultUrl,
                    'error_message' => $error,
                    'completed_at' => in_array($finalStatus, ['completed', 'failed'])
                        ? date('Y-m-d H:i:s')
                        : null
                ],
                'id = :id',
                ['id' => $nodeTask['id']]
            );

            // Queue next task if this one completed
            if ($finalStatus === 'completed') {
                // Release rate limit slot and process queue
                $apiKeyHash = ApiRateLimiter::releaseSlot($source, $taskId);
                if ($apiKeyHash) {
                    // Try to process next queued request for this API key
                    $queuedItem = ApiRateLimiter::processQueue($source, $apiKeyHash);
                    if ($queuedItem) {
                        // Execute the queued item
                        processQueuedApiCall($queuedItem);
                    }
                }

                queueNextTask($nodeTask['execution_id']);
            } elseif ($internalStatus === 'failed') {
                error_log("[Webhook] Processing failure for node_task id={$nodeTask['id']}, execution_id={$nodeTask['execution_id']}");

                // Release rate limit slot on failure too
                $apiKeyHash = ApiRateLimiter::releaseSlot($source, $taskId);
                if ($apiKeyHash) {
                    $queuedItem = ApiRateLimiter::processQueue($source, $apiKeyHash);
                    if ($queuedItem) {
                        processQueuedApiCall($queuedItem);
                    }
                }

                // Refund credits for failed generation
                $execution = Database::fetchOne(
                    "SELECT user_id FROM workflow_executions WHERE id = ?",
                    [$nodeTask['execution_id']]
                );

                if ($execution) {
                    $userId = $execution['user_id'];
                    $nodeType = $nodeTask['node_type'];

                    // Get cost for this node type
                    $cost = (float) Database::fetchColumn(
                        "SELECT cost_per_call FROM node_costs WHERE node_type = ?",
                        [$nodeType]
                    );

                    if ($cost > 0) {
                        // Add refund to credit ledger using 'adjustment' source (allowed in ENUM)
                        // Note: 'adjustment' is used because 'refund' is not in the source ENUM
                        try {
                            Database::insert('credit_ledger', [
                                'user_id' => $userId,
                                'credits' => $cost,
                                'remaining' => $cost,
                                'source' => 'adjustment'
                            ]);
                            error_log("[Webhook] Refunded {$cost} credits to user {$userId} for failed node {$nodeType}");
                        } catch (Exception $refundError) {
                            error_log("[Webhook] Failed to refund credits: " . $refundError->getMessage());
                        }
                    }
                }

                // Mark execution as failed
                Database::update(
                    'workflow_executions',
                    [
                        'status' => 'failed',
                        'error_message' => $error ?? 'Node execution failed',
                        'completed_at' => date('Y-m-d H:i:s')
                    ],
                    'id = :id',
                    ['id' => $nodeTask['execution_id']]
                );
                error_log("[Webhook] Updated workflow_executions id={$nodeTask['execution_id']} to status=failed");

                // Start next queued execution (for repeat workflows)
                // Even if this one failed, continue with the next one in queue
                startNextQueuedExecution($nodeTask['execution_id']);
            }
        } else {
            error_log("[Webhook] No node_task found for external_task_id: $taskId");
        }
    }

    // Mark webhook as processed
    Database::update(
        'webhook_logs',
        ['processed' => 1],
        'id = :id',
        ['id' => $logId]
    );

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Webhook error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    error_log('Webhook trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'debug' => $e->getMessage()]);
}

/**
 * Start next queued workflow execution (for repeat workflows)
 * Called when a workflow execution completes or fails
 */
function startNextQueuedExecution(int $executionId): void
{
    // Get the execution details to find related queued executions
    $execution = Database::fetchOne(
        "SELECT user_id, workflow_id FROM workflow_executions WHERE id = ?",
        [$executionId]
    );

    if (!$execution) {
        return;
    }

    // Find next pending execution that hasn't started yet (queued)
    // Pending executions with started_at = null are waiting to be started
    $whereClause = "status = 'pending' AND started_at IS NULL";
    $params = [];

    if ($execution['workflow_id']) {
        $whereClause .= " AND workflow_id = :workflow_id";
        $params['workflow_id'] = $execution['workflow_id'];
    } else {
        $whereClause .= " AND user_id = :user_id AND workflow_id IS NULL";
        $params['user_id'] = $execution['user_id'];
    }

    $nextExecution = Database::fetchOne(
        "SELECT id FROM workflow_executions WHERE {$whereClause} ORDER BY id ASC LIMIT 1",
        $params
    );

    if ($nextExecution) {
        $nextId = $nextExecution['id'];
        error_log("[Webhook] Starting next queued execution: $nextId (after execution $executionId)");

        // Update status to running
        Database::update(
            'workflow_executions',
            [
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            ['id' => $nextId]
        );

        // Queue first task for the next execution
        queueNextTask($nextId);
    } else {
        error_log("[Webhook] No more queued executions found after execution $executionId");
    }
}

/**
 * Queue next task for execution
 */
function queueNextTask(int $executionId): void
{
    // Find next pending task
    $task = Database::fetchOne(
        "SELECT * FROM node_tasks WHERE execution_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1",
        [$executionId]
    );

    if (!$task) {
        // No more tasks - check if all completed
        $pending = Database::fetchColumn(
            "SELECT COUNT(*) FROM node_tasks WHERE execution_id = ? AND status NOT IN ('completed', 'failed')",
            [$executionId]
        );

        if ($pending == 0) {
            // All done - mark execution as complete
            finalizeExecution($executionId);
        }
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
 * Finalize workflow execution
 */
function finalizeExecution(int $executionId): void
{
    // Get execution details including source
    $execution = Database::fetchOne(
        "SELECT user_id, workflow_id, input_data FROM workflow_executions WHERE id = ?",
        [$executionId]
    );

    if (!$execution) {
        error_log("[Webhook] Cannot finalize - execution not found: $executionId");
        return;
    }

    // Get execution source from input_data
    $inputData = json_decode($execution['input_data'] ?? '{}', true);
    $source = $inputData['_source'] ?? 'manual';

    // Get all task results
    $tasks = Database::fetchAll(
        "SELECT * FROM node_tasks WHERE execution_id = ? ORDER BY id ASC",
        [$executionId]
    );

    $allCompleted = true;
    $finalResultUrl = null;
    $outputs = [];
    $allResults = [];

    foreach ($tasks as $task) {
        if ($task['status'] !== 'completed') {
            $allCompleted = false;
        }

        if ($task['result_url']) {
            $finalResultUrl = $task['result_url'];
            $outputs[$task['node_id']] = $task['result_url'];
            $allResults[] = [
                'node_id' => $task['node_id'],
                'node_type' => $task['node_type'],
                'url' => $task['result_url']
            ];

            // Add to gallery if completed successfully
            if ($task['status'] === 'completed') {
                try {
                    // Determine media type based on URL
                    $url = $task['result_url'];
                    $itemType = 'image'; // default
                    if (preg_match('/\.(mp4|webm|mov|avi)$/i', $url)) {
                        $itemType = 'video';
                    } elseif (preg_match('/\.(mp3|wav|ogg|m4a)$/i', $url)) {
                        $itemType = 'audio';
                    }

                    // Check for duplicates
                    $existing = Database::fetchOne(
                        "SELECT id FROM user_gallery WHERE user_id = ? AND url = ?",
                        [$execution['user_id'], $url]
                    );

                    if (!$existing) {
                        Database::insert('user_gallery', [
                            'user_id' => $execution['user_id'],
                            'workflow_id' => $execution['workflow_id'],
                            'item_type' => $itemType,
                            'url' => $url,
                            'node_id' => $task['node_id'],
                            'node_type' => $task['node_type'],
                            'source' => $source,
                            'metadata' => json_encode(['execution_id' => $executionId])
                        ]);
                    }
                } catch (Exception $galleryError) {
                    error_log("[Webhook] Failed to add to gallery: " . $galleryError->getMessage());
                }
            }
        }
    }

    // Update execution record
    Database::update(
        'workflow_executions',
        [
            'status' => $allCompleted ? 'completed' : 'failed',
            'result_url' => $finalResultUrl,
            'output_data' => json_encode(['outputs' => $outputs, 'all_results' => $allResults]),
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $executionId]
    );

    error_log("[Webhook] Execution $executionId finalized with status: " . ($allCompleted ? 'completed' : 'failed'));

    // Start next queued execution (for repeat workflows)
    startNextQueuedExecution($executionId);
}

/**
 * Upload file to storage (BunnyCDN or local)
 * Priority: BunnyCDN plugin -> Constants-based CDN -> Local filesystem
 */
function uploadToBunnyCDN(string $sourceUrl, int $executionId, string $nodeId): ?string
{
    // Try using the BunnyCDN storage handler plugin
    if (class_exists('BunnyCDNStorageHandler') && BunnyCDNStorageHandler::isConfigured()) {
        $result = BunnyCDNStorageHandler::uploadFromUrl($sourceUrl, "exec_{$executionId}/{$nodeId}_" . time());
        if ($result)
            return $result;
    }

    try {
        // Download file from source first (needed for all fallbacks)
        $fileContent = @file_get_contents($sourceUrl);
        if (!$fileContent) {
            error_log("Failed to download from source: $sourceUrl");
            return null;
        }

        // Determine extension
        $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';
        $filename = "exec_{$executionId}/{$nodeId}_" . time() . ".{$extension}";

        // Try constants-based BunnyCDN approach
        if (defined('BUNNY_STORAGE_ZONE') && BUNNY_STORAGE_ZONE && defined('BUNNY_ACCESS_KEY') && BUNNY_ACCESS_KEY) {
            $storageUrl = defined('BUNNY_STORAGE_URL') ? BUNNY_STORAGE_URL : 'https://storage.bunnycdn.com';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $storageUrl . '/' . BUNNY_STORAGE_ZONE . '/' . $filename,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $fileContent,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'AccessKey: ' . BUNNY_ACCESS_KEY,
                    'Content-Type: application/octet-stream'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $cdnUrl = defined('BUNNY_CDN_URL') ? BUNNY_CDN_URL : '';
                return rtrim($cdnUrl, '/') . '/' . $filename;
            }
        }

        // Fallback to local filesystem storage
        $basePath = dirname(__DIR__) . '/generated';
        $dirPath = $basePath . '/exec_' . $executionId;
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $localFilename = $nodeId . '_' . time() . '.' . $extension;
        $localPath = $dirPath . '/' . $localFilename;

        if (file_put_contents($localPath, $fileContent) !== false) {
            $appUrl = defined('APP_URL') ? APP_URL : '';
            return rtrim($appUrl, '/') . '/generated/exec_' . $executionId . '/' . $localFilename;
        }

        return null;

    } catch (Exception $e) {
        error_log('Storage upload error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Process a queued API call (triggered when a slot becomes available)
 */
function processQueuedApiCall(array $queueItem): void
{
    try {
        // Decode the input data
        $inputData = is_string($queueItem['input_data'])
            ? json_decode($queueItem['input_data'], true)
            : $queueItem['input_data'];

        if (!$inputData) {
            ApiRateLimiter::markQueueFailed($queueItem['id'], 'Invalid input data');
            return;
        }

        // Execute the node
        $result = PluginManager::executeNode($queueItem['node_type'], $inputData);

        if ($result['success'] ?? false) {
            ApiRateLimiter::markQueueCompleted($queueItem['id']);

            // If this was part of a workflow, update the node task
            if ($queueItem['workflow_run_id']) {
                Database::update(
                    'node_tasks',
                    [
                        'status' => ($result['status'] ?? '') === 'queued' ? 'queued' : 'processing',
                        'external_task_id' => $result['taskId'] ?? null
                    ],
                    'execution_id = :exec_id AND node_id = :node_id',
                    ['exec_id' => $queueItem['workflow_run_id'], 'node_id' => $queueItem['node_id']]
                );
            }
        } else {
            ApiRateLimiter::markQueueFailed($queueItem['id'], $result['error'] ?? 'Execution failed');
        }

    } catch (Exception $e) {
        error_log('Queue processing error: ' . $e->getMessage());
        ApiRateLimiter::markQueueFailed($queueItem['id'], $e->getMessage());
    }
}