<?php
/**
 * AIKAFLOW API - Admin Users Management
 * 
 * GET    /api/admin/users.php - List all users
 * POST   /api/admin/users.php - Create new user
 * PUT    /api/admin/users.php - Update user
 * DELETE /api/admin/users.php - Delete user
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

// Check if user is admin (id=1 or role=admin)
function isAdmin(array $user): bool
{
    return (int) $user['id'] === 1 || ($user['role'] ?? '') === 'admin';
}

if (!isAdmin($user)) {
    errorResponse('Access denied. Admin privileges required.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // List users with pagination and search
        try {
            $search = trim($_GET['search'] ?? '');
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // Build query with search
            $params = [];
            $whereClause = '';
            if (!empty($search)) {
                $whereClause = "WHERE username LIKE ? OR email LIKE ?";
                $searchTerm = "%{$search}%";
                $params = [$searchTerm, $searchTerm];
            }

            // Get total count
            $countSql = "SELECT COUNT(*) FROM users {$whereClause}";
            $total = (int) Database::fetchColumn($countSql, $params);

            // Get paginated users
            $sql = "SELECT id, email, username, role, is_active, created_at, last_login 
                    FROM users {$whereClause} ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}";
            $users = Database::fetchAll($sql, $params);

            successResponse([
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'totalPages' => ceil($total / $limit),
                    'hasMore' => ($page * $limit) < $total
                ]
            ]);
        } catch (Exception $e) {
            error_log('Admin list users error: ' . $e->getMessage());
            errorResponse('Failed to fetch users', 500);
        }
        break;

    case 'POST':
        // Create new user
        $input = getJsonInput();

        $email = trim($input['email'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'user';

        // Validate
        if (empty($email) || empty($username) || empty($password)) {
            errorResponse('Email, username, and password are required');
        }

        if (!in_array($role, ['admin', 'user'])) {
            $role = 'user';
        }

        try {
            $result = Auth::register($email, $username, $password);

            if ($result['success']) {
                // Update role if admin
                if ($role === 'admin') {
                    Database::query(
                        "UPDATE users SET role = 'admin' WHERE id = ?",
                        [$result['user_id']]
                    );
                }

                successResponse([
                    'message' => 'User created successfully',
                    'user_id' => $result['user_id']
                ]);
            } else {
                errorResponse($result['error']);
            }
        } catch (Exception $e) {
            error_log('Admin create user error: ' . $e->getMessage());
            errorResponse('Failed to create user', 500);
        }
        break;

    case 'PUT':
        // Update user
        $input = getJsonInput();

        $userId = (int) ($input['id'] ?? 0);
        if ($userId <= 0) {
            errorResponse('User ID is required');
        }

        // Prevent modifying super admin (id=1) role/status unless by themselves
        if ($userId === 1 && (int) $user['id'] !== 1) {
            errorResponse('Cannot modify super administrator', 403);
        }

        $updates = [];

        if (isset($input['email'])) {
            $email = filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL);
            if (!$email) {
                errorResponse('Invalid email address');
            }
            $updates['email'] = $email;
        }

        if (isset($input['username'])) {
            $username = trim($input['username']);
            if (strlen($username) < 3) {
                errorResponse('Username must be at least 3 characters');
            }
            $updates['username'] = $username;
        }

        if (isset($input['role']) && in_array($input['role'], ['admin', 'user'])) {
            // Cannot change super admin's role
            if ($userId !== 1) {
                $updates['role'] = $input['role'];
            }
        }

        if (isset($input['is_active'])) {
            // Cannot deactivate super admin
            if ($userId !== 1) {
                $updates['is_active'] = $input['is_active'] ? 1 : 0;
            }
        }

        if (isset($input['password']) && !empty($input['password'])) {
            if (strlen($input['password']) < PASSWORD_MIN_LENGTH) {
                errorResponse('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
            }
            $updates['password_hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            successResponse(['message' => 'No changes to save']);
        }

        try {
            Database::update('users', $updates, 'id = :id', ['id' => $userId]);
            successResponse(['message' => 'User updated successfully']);
        } catch (Exception $e) {
            error_log('Admin update user error: ' . $e->getMessage());
            errorResponse('Failed to update user', 500);
        }
        break;

    case 'DELETE':
        // Delete user
        $input = getJsonInput();
        $userId = (int) ($input['id'] ?? 0);

        if ($userId <= 0) {
            errorResponse('User ID is required');
        }

        // Cannot delete super admin
        if ($userId === 1) {
            errorResponse('Cannot delete super administrator', 403);
        }

        // Cannot delete yourself
        if ($userId === (int) $user['id']) {
            errorResponse('Cannot delete your own account', 403);
        }

        try {
            Database::query("DELETE FROM users WHERE id = ?", [$userId]);
            successResponse(['message' => 'User deleted successfully']);
        } catch (Exception $e) {
            error_log('Admin delete user error: ' . $e->getMessage());
            errorResponse('Failed to delete user', 500);
        }
        break;

    default:
        errorResponse('Method not allowed', 405);
}
