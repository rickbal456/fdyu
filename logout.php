<?php
/**
 * AIKAFLOW Logout Handler
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';

// Perform logout (this destroys the session completely)
Auth::logout();

// Redirect to login page with message
header('Location: login.php?logout=1');
exit;