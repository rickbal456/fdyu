<?php
/**
 * AIKAFLOW API - Execute Workflow
 * 
 * POST /api/workflows/execute.php
 * Body: { workflowId?, workflowData? }
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

// Detect execution source: api or manual
// API calls use X-API-Key header or api_key query parameter
$executionSource = (!empty($_SERVER['HTTP_X_API_KEY']) || !empty($_GET['api_key'])) ? 'api' : 'manual';

$input = getJsonInput();
$workflowId = isset($input['workflowId']) ? (int) $input['workflowId'] : null;
$workflowData = $input['workflowData'] ?? null;
$flowId = $input['flowId'] ?? null; // For single-flow execution

// Get admin-configured max repeat count
$maxRepeatCount = 100; // Default
$maxRepeatSetting = Database::fetchOne("SELECT setting_value FROM site_settings WHERE setting_key = 'max_repeat_count'");
if ($maxRepeatSetting && $maxRepeatSetting['setting_value']) {
    $maxRepeatCount = max(1, min(1000, (int) $maxRepeatSetting['setting_value']));
}

$repeatCount = isset($input['repeatCount']) ? max(1, min($maxRepeatCount, (int) $input['repeatCount'])) : 1;

// Must provide either workflowId or workflowData
if (!$workflowId && !$workflowData) {
    errorResponse('Either workflowId or workflowData is required');
}

try {
    // Get workflow data
    if ($workflowId) {
        $workflow = Database::fetchOne(
            "SELECT * FROM workflows WHERE id = ? AND user_id = ?",
            [$workflowId, $user['id']]
        );

        if (!$workflow) {
            errorResponse('Workflow not found', 404);
        }

        $workflowData = json_decode($workflow['json_data'], true);
    } else {
        $workflowData = validateWorkflowData($workflowData);
    }

    $nodes = $workflowData['nodes'] ?? [];
    $connections = $workflowData['connections'] ?? [];

    if (empty($nodes)) {
        errorResponse('Workflow has no nodes to execute');
    }

    // Check for repeat setting in Start Flow (manual-trigger) nodes
    foreach ($nodes as $node) {
        // Only check trigger nodes (Start Flow)
        if ($node['type'] === 'manual-trigger') {
            if (isset($node['data']['enableRepeat']) && $node['data']['enableRepeat']) {
                $nodeRepeatCount = (int) ($node['data']['repeatCount'] ?? 1);
                if ($nodeRepeatCount > 1) {
                    $repeatCount = max(1, min($maxRepeatCount, $nodeRepeatCount));
                }
            }
            break; // Use first found Start Flow node
        }
    }

    // Verify user credits (Pre-flight check) - account for all iterations
    $totalCost = 0;
    $nodeCosts = Database::fetchAll("SELECT node_type, cost_per_call FROM node_costs");
    $costMap = [];
    foreach ($nodeCosts as $nc) {
        $costMap[$nc['node_type']] = (float) $nc['cost_per_call'];
    }

    foreach ($nodes as $node) {
        $type = $node['type'];
        if (isset($costMap[$type])) {
            $totalCost += $costMap[$type];
        }
    }

    // Multiply by repeat count for total cost
    $totalCost *= $repeatCount;

    if ($totalCost > 0) {
        $balance = Database::fetchColumn(
            "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger 
             WHERE user_id = ? AND remaining > 0 
             AND (expires_at IS NULL OR expires_at >= CURDATE())",
            [$user['id']]
        );

        if ($balance < $totalCost) {
            errorResponse("Insufficient credits. Required: " . number_format((float) $totalCost) . " (for {$repeatCount} iterations), Available: " . number_format((float) $balance), 402);
        }
    }

    // Create execution records - one for each repeat iteration
    $executionIds = [];
    $firstExecutionId = null;

    for ($i = 0; $i < $repeatCount; $i++) {
        $isFirst = ($i === 0);

        // Store execution metadata including source
        $inputData = [
            'inputs' => $input['inputs'] ?? [],
            '_source' => $executionSource  // 'manual' or 'api'
        ];

        $executionId = Database::insert('workflow_executions', [
            'workflow_id' => $workflowId ?: null,  // Use NULL for unsaved workflows
            'user_id' => $user['id'],
            'status' => 'pending',  // All start as pending, first one will be updated to running
            'input_data' => json_encode($inputData),
            'repeat_count' => 1,  // Each execution is a single iteration now
            'current_iteration' => 1,
            'iteration_outputs' => json_encode([]),
            'started_at' => $isFirst ? date('Y-m-d H:i:s') : null
        ]);

        $executionIds[] = $executionId;
        if ($isFirst) {
            $firstExecutionId = $executionId;
        }

        // If this is a single-flow execution, create a flow execution record
        $flowExecutionId = null;
        if ($flowId) {
            // Find the entry node (trigger node)
            $entryNode = null;
            foreach ($nodes as $node) {
                if ($node['id'] === $flowId) {
                    $entryNode = $node;
                    break;
                }
            }

            $flowName = $entryNode['data']['flowName'] ?? 'Single Flow';
            $priority = $entryNode['data']['priority'] ?? 0;

            $flowExecutionId = Database::insert('flow_executions', [
                'execution_id' => $executionId,
                'flow_id' => $flowId,
                'entry_node_id' => $flowId,
                'flow_name' => $flowName,
                'status' => $isFirst ? 'queued' : 'queued',
                'priority' => $priority,
                'started_at' => $isFirst ? date('Y-m-d H:i:s') : null
            ]);
        }

        // Calculate execution order (topological sort)
        $executionOrder = calculateExecutionOrder($nodes, $connections);

        // Create task records for each node
        foreach ($executionOrder as $index => $node) {
            Database::insert('node_tasks', [
                'execution_id' => $executionId,
                'node_id' => $node['id'],
                'node_type' => $node['type'],
                'status' => 'pending',
                'input_data' => json_encode($node['data'] ?? [])
            ]);
        }

        // Only start the first execution immediately
        if ($isFirst) {
            // Update execution status to running
            Database::update(
                'workflow_executions',
                ['status' => 'running'],
                'id = :id',
                ['id' => $executionId]
            );

            // Update flow execution status to running if applicable
            if ($flowExecutionId) {
                Database::update(
                    'flow_executions',
                    ['status' => 'running'],
                    'id = :id',
                    ['id' => $flowExecutionId]
                );
            }

            // Queue first task for execution
            queueNextTask($executionId);
        }
    }

    successResponse([
        'executionId' => $firstExecutionId,
        'executionIds' => $executionIds,
        'flowExecutionId' => $flowExecutionId ?? null,
        'flowId' => $flowId,
        'status' => 'running',
        'nodeCount' => count($nodes),
        'iterations' => [
            'total' => $repeatCount,
            'current' => 1
        ],
        'message' => $repeatCount > 1
            ? "Started {$repeatCount} workflow executions (" . ($repeatCount - 1) . " queued)"
            : ($flowId ? 'Single flow execution started' : 'Workflow execution started')
    ]);


} catch (Exception $e) {
    error_log('Execute workflow error: ' . $e->getMessage());
    errorResponse('Failed to start workflow execution: ' . $e->getMessage(), 500);
}

/**
 * Calculate execution order using topological sort
 */
function calculateExecutionOrder(array $nodes, array $connections): array
{
    $nodeMap = [];
    $inDegree = [];
    $adjacency = [];

    // Initialize
    foreach ($nodes as $node) {
        $nodeMap[$node['id']] = $node;
        $inDegree[$node['id']] = 0;
        $adjacency[$node['id']] = [];
    }

    // Build graph
    foreach ($connections as $conn) {
        $fromId = $conn['from']['nodeId'];
        $toId = $conn['to']['nodeId'];

        if (isset($adjacency[$fromId]) && isset($inDegree[$toId])) {
            $adjacency[$fromId][] = $toId;
            $inDegree[$toId]++;
        }
    }

    // Kahn's algorithm
    $queue = [];
    $result = [];

    foreach ($inDegree as $nodeId => $degree) {
        if ($degree === 0) {
            $queue[] = $nodeId;
        }
    }

    while (!empty($queue)) {
        $nodeId = array_shift($queue);
        $result[] = $nodeMap[$nodeId];

        foreach ($adjacency[$nodeId] as $neighbor) {
            $inDegree[$neighbor]--;
            if ($inDegree[$neighbor] === 0) {
                $queue[] = $neighbor;
            }
        }
    }

    return $result;
}

/**
 * Queue next pending task for execution
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
    // Get all task results
    $tasks = Database::fetchAll(
        "SELECT * FROM node_tasks WHERE execution_id = ? ORDER BY id ASC",
        [$executionId]
    );

    $allCompleted = true;
    $finalResultUrl = null;
    $outputs = [];

    foreach ($tasks as $task) {
        if ($task['status'] !== 'completed') {
            $allCompleted = false;
        }

        if ($task['result_url']) {
            $finalResultUrl = $task['result_url'];
            $outputs[$task['node_id']] = $task['result_url'];
        }
    }

    // Update execution record
    Database::update(
        'workflow_executions',
        [
            'status' => $allCompleted ? 'completed' : 'failed',
            'result_url' => $finalResultUrl,
            'output_data' => json_encode($outputs),
            'completed_at' => date('Y-m-d H:i:s')
        ],
        'id = :id',
        ['id' => $executionId]
    );
}