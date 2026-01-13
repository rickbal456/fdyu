<?php
/**
 * AIKAFLOW - Invitation Code Migration
 * 
 * Adds invitation_code, referred_by, referred_at columns to users table.
 * Run this script once to set up the database schema.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

// Check if this is being run from CLI or has admin access
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../../includes/auth.php';
    Auth::initSession();

    $user = Auth::user();
    if (!$user || ((int) $user['id'] !== 1 && ($user['role'] ?? '') !== 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

$results = [];

try {
    // Check if invitation_code column exists
    $checkColumn = Database::fetchOne(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'invitation_code'"
    );

    if (!$checkColumn) {
        // Add invitation_code column
        Database::query("ALTER TABLE users ADD COLUMN invitation_code VARCHAR(12) NULL UNIQUE");
        $results[] = "Added invitation_code column";

        // Generate invitation codes for existing users
        $users = Database::fetchAll("SELECT id FROM users WHERE invitation_code IS NULL");
        foreach ($users as $user) {
            $code = strtoupper(substr(md5($user['id'] . uniqid() . time()), 0, 8));
            Database::query("UPDATE users SET invitation_code = ? WHERE id = ?", [$code, $user['id']]);
        }
        $results[] = "Generated invitation codes for " . count($users) . " existing users";
    } else {
        $results[] = "invitation_code column already exists";
    }

    // Check if referred_by column exists
    $checkColumn = Database::fetchOne(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'referred_by'"
    );

    if (!$checkColumn) {
        Database::query("ALTER TABLE users ADD COLUMN referred_by INT NULL");
        Database::query("ALTER TABLE users ADD COLUMN referred_at DATETIME NULL");
        $results[] = "Added referred_by and referred_at columns";
    } else {
        $results[] = "referred_by column already exists";
    }

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
