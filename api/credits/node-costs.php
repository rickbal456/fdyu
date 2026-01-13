<?php
/**
 * AIKAFLOW API - Node Costs (Public Read)
 * 
 * Returns node costs for display purposes (read-only, no auth required for logged-in users)
 */

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Require login but not admin
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

try {
    $costs = Database::fetchAll("SELECT node_type, cost_per_call, description FROM node_costs ORDER BY node_type");
    successResponse(['node_costs' => $costs]);
} catch (Exception $e) {
    errorResponse('Failed to fetch node costs', 500);
}
