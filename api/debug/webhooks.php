<?php
/**
 * AIKAFLOW - Debug Webhook Logs
 * 
 * GET /api/debug/webhooks.php
 * Shows recent webhook logs for debugging
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

try {
    // Get recent webhook logs
    $webhooks = Database::fetchAll(
        "SELECT id, source, external_id, payload, processed, created_at 
         FROM webhook_logs 
         ORDER BY id DESC 
         LIMIT 20"
    );

    // Parse payloads
    $parsed = array_map(function ($wh) {
        $payload = json_decode($wh['payload'], true);
        return [
            'id' => $wh['id'],
            'source' => $wh['source'],
            'external_id' => $wh['external_id'],
            'processed' => (bool) $wh['processed'],
            'created_at' => $wh['created_at'],
            'payload_summary' => [
                'event' => $payload['event'] ?? null,
                'taskId' => $payload['taskId'] ?? $payload['task_id'] ?? null,
                'status' => $payload['status'] ?? null,
                'has_eventData' => isset($payload['eventData']),
                'has_data' => isset($payload['data'])
            ],
            'raw_payload' => $payload
        ];
    }, $webhooks);

    // Get node_tasks waiting for webhook
    $waitingTasks = Database::fetchAll(
        "SELECT nt.id, nt.node_id, nt.node_type, nt.status, nt.external_task_id, nt.created_at,
                we.id as execution_id, we.status as execution_status
         FROM node_tasks nt
         JOIN workflow_executions we ON we.id = nt.execution_id
         WHERE nt.status = 'processing' AND nt.external_task_id IS NOT NULL
         ORDER BY nt.id DESC
         LIMIT 10"
    );

    echo json_encode([
        'success' => true,
        'recent_webhooks' => $parsed,
        'tasks_waiting_for_webhook' => $waitingTasks,
        'info' => 'Check raw_payload for full webhook data'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
