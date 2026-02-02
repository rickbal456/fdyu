<?php

/**
 * AIKAFLOW Configuration
 * 
 * This file contains all application configuration constants.
 * For production, use environment variables or a .env file.
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('AIKAFLOW')) {
    define('AIKAFLOW', true);
}

// Define root path first
define('ROOT_PATH', dirname(__DIR__));

/**
 * Load environment variables from .env file
 * 
 * @param string $path Path to .env file
 * @return void
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        error_log("Warning: .env file not found at: $path");
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log("Error: Unable to read .env file at: $path");
        return;
    }

    foreach ($lines as $line) {
        // Skip comments
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        // Parse key=value
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Only set if not already set
            if (!getenv($key) && !isset($_ENV[$key])) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

/**
 * Get environment variable with fallback
 * 
 * @param string $key Variable name
 * @param mixed $default Default value
 * @return mixed
 */
function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    return $default;
}

// Load .env file BEFORE defining any constants that use environment variables
loadEnv(ROOT_PATH . '/.env');

// Application settings
define('APP_NAME', 'AIKAFLOW');
define('APP_VERSION', '1.0.0');
define('APP_URL', env('APP_URL', 'http://localhost'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

// Database configuration
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Session configuration
define('SESSION_NAME', 'aikaflow_session');
define('SESSION_LIFETIME', 30 * 24 * 3600); // 30 days - users stay logged in until manual logout
define('SESSION_SECURE', filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOLEAN));
define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Strict');

// Security settings
define('PASSWORD_MIN_LENGTH', 8);
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour
define('API_RATE_LIMIT', 100); // requests per minute
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// External API URLs (API keys are configured via Integration tab or node settings)
define('RUNNINGHUB_API_URL', 'https://api.runninghub.ai');
define('KIE_API_URL', 'https://api.kie.ai');
define('JSONCUT_API_URL', 'https://api.jsoncut.com');
define('POSTFORME_API_URL', 'https://api.postforme.dev');

// BunnyCDN configuration (optional - configure via .env)
define('BUNNY_STORAGE_ZONE', env('BUNNY_STORAGE_ZONE', ''));
define('BUNNY_ACCESS_KEY', env('BUNNY_ACCESS_KEY', ''));
define('BUNNY_STORAGE_URL', env('BUNNY_STORAGE_URL', 'https://storage.bunnycdn.com'));
define('BUNNY_CDN_URL', env('BUNNY_CDN_URL', ''));


// File upload settings
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/quicktime']);
define('ALLOWED_AUDIO_TYPES', ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm']);

// Workflow settings
define('MAX_NODES_PER_WORKFLOW', 50);
define('MAX_WORKFLOW_EXECUTION_TIME', 3600); // 1 hour
define('POLLING_INTERVAL', 5); // seconds

// Paths
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('API_PATH', ROOT_PATH . '/api');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('TEMP_PATH', ROOT_PATH . '/temp');
define('LOGS_PATH', ROOT_PATH . '/logs');

// Create required directories
foreach ([TEMP_PATH, LOGS_PATH] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("Failed to create directory: $dir");
        }
    }
}

// Error handling
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOGS_PATH . '/php_errors.log');
}

// Timezone
date_default_timezone_set('UTC');

// Debug output (only in debug mode)
if (APP_DEBUG) {
    error_log("AIKAFLOW Config Loaded:");
    error_log("- DB_HOST: " . DB_HOST);
    error_log("- DB_NAME: " . DB_NAME);
    error_log("- DB_USER: " . DB_USER);
    error_log("- APP_URL: " . APP_URL);
}

// ============================================
// LICENSE VERIFICATION
// ============================================
// This runs on every page load but is very fast (local signature check)
// Server verification only happens once per day

// Only verify if Database is available and we're not in install/login context
$skipLicenseCheck = defined('SKIP_LICENSE_CHECK') && SKIP_LICENSE_CHECK;
$isInstaller = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'install.php';
$isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;

if (!$skipLicenseCheck && !$isInstaller && !$isApi && class_exists('Database')) {
    require_once INCLUDES_PATH . '/LicenseVerifier.php';

    // Verify license (fast local check)
    if (!isLicenseValid()) {
        // License is invalid - show error page
        http_response_code(403);
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>License Required - <?= APP_NAME ?></title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>

        <body
            class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen flex items-center justify-center p-4">
            <div class="text-center max-w-md">
                <div class="w-20 h-20 bg-red-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white mb-2">License Required</h1>
                <p class="text-gray-400 mb-6">
                    This installation requires a valid license. The license may have expired,
                    been revoked, or the domain may not match.
                </p>
                <p class="text-gray-500 text-sm">
                    Please contact the administrator or run the installer again.
                </p>
            </div>
        </body>

        </html>
<?php
        exit;
    }
}
