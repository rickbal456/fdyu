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
        case 'runninghub':
            // Check for AI App format (eventData)
            if (isset($payload['eventData'])) {
                $taskId = $payload['taskId'] ?? null;

                // Parse eventData string
                $eventData = is_string($payload['eventData']) ? json_decode($payload['eventData'], true) : $payload['eventData'];

                // Check for error in eventData (code != 0 means error)
                if (isset($eventData['code']) && $eventData['code'] != 0) {
                    $status = 'failed';
                    $error = $eventData['msg'] ?? 'Task failed';

                    // Try to extract more specific error from failedReason
                    if (isset($eventData['data']['failedReason']['exception_message'])) {
                        $error = $eventData['data']['failedReason']['exception_message'];
                    }

                    error_log("[Webhook] RunningHub task failed: code={$eventData['code']}, msg={$error}");
                }
                // Check for TASK_FAIL event
                elseif (($payload['event'] ?? '') === 'TASK_FAIL') {
                    $status = 'failed';
                    $error = $payload['msg'] ?? 'Task failed';
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

        case 'kie':
            $taskId = $payload['task_id'] ?? null;
            $status = $payload['status'] ?? null;
            $resultUrl = $payload['audio_url'] ?? $payload['result_url'] ?? null;
            $error = $payload['error_message'] ?? null;
            break;

        case 'jsoncut':
            $taskId = $payload['task_id'] ?? null;
            $status = $payload['status'] ?? null;
            $resultUrl = $payload['output_url'] ?? null;
            $error = $payload['error'] ?? null;
            break;

        case 'postforme':
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

            // Update task
            Database::update(
                'node_tasks',
                [
                    'status' => $internalStatus,
                    'result_url' => $resultUrl,
                    'error_message' => $error,
                    'completed_at' => in_array($internalStatus, ['completed', 'failed'])
                        ? date('Y-m-d H:i:s')
                        : null
                ],
                'id = :id',
                ['id' => $nodeTask['id']]
            );

            // If completed, upload result to BunnyCDN for persistence
            if ($resultUrl && $internalStatus === 'completed') {
                $cdnUrl = uploadToBunnyCDN($resultUrl, $nodeTask['execution_id'], $nodeTask['node_id']);
                if ($cdnUrl) {
                    Database::update(
                        'node_tasks',
                        ['result_url' => $cdnUrl],
                        'id = :id',
                        ['id' => $nodeTask['id']]
                    );
                }
            }

            // Queue next task if this one completed
            if ($internalStatus === 'completed') {
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
                // Release rate limit slot on failure too
                $apiKeyHash = ApiRateLimiter::releaseSlot($source, $taskId);
                if ($apiKeyHash) {
                    $queuedItem = ApiRateLimiter::processQueue($source, $apiKeyHash);
                    if ($queuedItem) {
                        processQueuedApiCall($queuedItem);
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
    error_log('Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}

/**
 * Queue next task for execution
 */
function queueNextTask(int $executionId): void
{
    require_once __DIR__ . '/workflows/execute.php';
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