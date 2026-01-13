<?php
/**
 * Share Workflow API
 * Generates a shareable link for a workflow snapshot
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Helper to generate random string
function generateRandomString($length = 16)
{
    return bin2hex(random_bytes($length / 2));
}

try {
    // Check authentication
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $user = Auth::user();
    $userId = $user['id'];
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['workflow'])) {
        throw new Exception('Invalid input data');
    }

    $workflowData = $input['workflow'];
    $workflowId = $input['workflowId'] ?? null;
    $isPublic = isset($input['isPublic']) ? (bool) $input['isPublic'] : true;

    // Encode workflow data
    $jsonData = json_encode($workflowData);
    if ($jsonData === false) {
        throw new Exception('Failed to encode workflow data');
    }

    // Check if we already have a share for this workflow
    $pdo = Database::getInstance();
    $shareId = null;

    try {
        if ($workflowId) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM workflow_shares WHERE user_id = :uid AND workflow_id = :wid");
                $stmt->execute([':uid' => $userId, ':wid' => $workflowId]);
                $shareId = $stmt->fetchColumn();
            } catch (PDOException $e) {
                // Ignore missing column
            }
        }

        if ($shareId) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE workflow_shares 
                SET workflow_data = :data, is_public = :public, created_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':data' => $jsonData,
                ':public' => $isPublic ? 1 : 0,
                ':id' => $shareId
            ]);
        } else {
            // Insert
            $shareId = generateRandomString(16);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO workflow_shares (id, user_id, workflow_id, workflow_data, is_public)
                    VALUES (:id, :user_id, :workflow_id, :workflow_data, :is_public)
                ");
                $stmt->execute([
                    ':id' => $shareId,
                    ':user_id' => $userId,
                    ':workflow_id' => $workflowId,
                    ':workflow_data' => $jsonData,
                    ':is_public' => $isPublic ? 1 : 0
                ]);
            } catch (PDOException $ex) {
                // If workflow_id generic error?
                // If the error is about unknown column, we need to retry without it.
                // But the OUTER catch block (original line 62) handles fallback to 'workflows' table if 'workflow_shares' fails (doesn't exist).
                // If 'workflow_shares' EXISTS but 'workflow_id' column MISSING, we want to try generic insert into 'workflow_shares'.
                if (strpos($ex->getMessage(), "Unknown column") !== false) {
                    $stmt = $pdo->prepare("
                        INSERT INTO workflow_shares (id, user_id, workflow_data, is_public)
                        VALUES (:id, :user_id, :workflow_data, :is_public)
                    ");
                    $stmt->execute([
                        ':id' => $shareId,
                        ':user_id' => $userId,
                        ':workflow_data' => $jsonData,
                        ':is_public' => $isPublic ? 1 : 0
                    ]);
                } else {
                    throw $ex; // Throw to outer catch
                }
            }
        }

    } catch (PDOException $e) {
        // Fallback or rethrow
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            // Resilience: if workflow_shares missing, use workflows
            $stmt = $pdo->prepare("
                INSERT INTO workflows (user_id, name, description, json_data, is_public)
                VALUES (:user_id, :name, :description, :json_data, :is_public)
            ");

            $name = ($workflowData['name'] ?? 'Shared Workflow') . ' (Snapshot ' . date('Y-m-d H:i') . ')';
            $desc = 'Shared via link';

            $stmt->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':description' => $desc,
                ':json_data' => $jsonData,
                ':is_public' => 1
            ]);

            $shareId = $pdo->lastInsertId();
        } else {
            throw $e;
        }
    }

    echo json_encode([
        'success' => true,
        'shareId' => (string) $shareId,
        'isPublic' => $isPublic
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
