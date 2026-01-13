<?php
/**
 * AIKAFLOW Installation Wizard
 * 
 * Complete installation wizard with license verification, database setup,
 * and admin account creation.
 * 
 * DELETE THIS FILE AFTER INSTALLATION!
 */

declare(strict_types=1);

// Prevent running if already installed
$installLockFile = __DIR__ . '/.installed';
$envFile = __DIR__ . '/.env';

if (file_exists($installLockFile)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Already Installed - AIKAFLOW</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body
        class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen flex items-center justify-center p-4">
        <div class="text-center">
            <div class="w-20 h-20 bg-yellow-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Already Installed</h1>
            <p class="text-gray-400 mb-6">AIKAFLOW has already been installed on this server.</p>
            <p class="text-gray-500 text-sm mb-8">To reinstall, delete the <code
                    class="bg-gray-800 px-2 py-1 rounded">.installed</code> file.</p>
            <a href="login"
                class="inline-block px-6 py-3 bg-gradient-to-r from-purple-600 to-blue-600 text-white font-semibold rounded-lg hover:from-purple-500 hover:to-blue-500 transition-all">
                Go to Login
            </a>
        </div>
    </body>

    </html>
    <?php
    exit;
}

session_start();

// Initialize session data
if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [
        'step' => 1,
        'license_key' => '',
        'license_verified' => false,
        'app_name' => 'AIKAFLOW',
        'app_url' => '',
        'app_debug' => false,
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_name' => 'aikaflow',
        'db_user' => 'root',
        'db_pass' => '',
        'admin_email' => '',
        'admin_username' => '',
        'admin_password' => ''
    ];
}

// Auto-detect app URL
if (empty($_SESSION['install']['app_url'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $_SESSION['install']['app_url'] = rtrim("{$protocol}://{$host}{$path}", '/');
}

$step = (int) ($_GET['step'] ?? $_SESSION['install']['step']);
$error = '';
$success = '';

// License server URL - UPDATE THIS TO YOUR LICENSE SERVER
define('LICENSE_SERVER_URL', 'http://localhost/aikaflow/license_server/api/verify.php');

/**
 * Verify license with server
 */
function verifyLicense(string $licenseKey, string $domain): array
{
    $ch = curl_init(LICENSE_SERVER_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'license_key' => $licenseKey,
            'domain' => $domain,
            'action' => 'activate'
        ]),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false // Set to true in production
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['valid' => false, 'message' => 'Could not connect to license server: ' . $curlError];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['valid' => false, 'message' => 'Invalid response from license server'];
    }

    return $data;
}

/**
 * Test database connection
 */
function testDatabaseConnection(string $host, string $port, string $name, string $user, string $pass): array
{
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // Check if database exists
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($name));
        $exists = $stmt->fetchColumn();

        if (!$exists) {
            // Try to create the database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // Connect to the database
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        return ['success' => true, 'message' => 'Connection successful'];

    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Generate .env file content
 */
function generateEnvContent(array $data): string
{
    return <<<ENV
# AIKAFLOW Environment Configuration
# Generated by Installation Wizard

# Application
APP_NAME={$data['app_name']}
APP_URL={$data['app_url']}
APP_DEBUG={$data['app_debug']}

# License
LICENSE_KEY={$data['license_key']}

# Database
DB_HOST={$data['db_host']}
DB_PORT={$data['db_port']}
DB_NAME={$data['db_name']}
DB_USER={$data['db_user']}
DB_PASS={$data['db_pass']}

# Session
SESSION_SECURE=false
ENV;
}

/**
 * Run database installation
 */
function installDatabase(array $data): array
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $data['db_host'],
            $data['db_port'],
            $data['db_name']
        );

        $pdo = new PDO($dsn, $data['db_user'], $data['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Read and execute database.sql
        $sqlFile = __DIR__ . '/database.sql';
        if (!file_exists($sqlFile)) {
            return ['success' => false, 'message' => 'database.sql not found'];
        }

        $sql = file_get_contents($sqlFile);

        // Remove comments and split by semicolon
        $sql = preg_replace('/--.*$/m', '', $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );

        $tableCount = 0;
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $pdo->exec($statement);
                    if (stripos($statement, 'CREATE TABLE') !== false) {
                        $tableCount++;
                    }
                } catch (PDOException $e) {
                    // Ignore "already exists" errors
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
        }

        // Create admin user
        $adminEmail = $data['admin_email'];
        $adminUser = $data['admin_username'];
        $adminPass = password_hash($data['admin_password'], PASSWORD_ARGON2ID);
        $apiKey = bin2hex(random_bytes(32));

        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$adminEmail, $adminUser]);

        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO users (email, username, password_hash, api_key, role, is_verified) VALUES (?, ?, ?, ?, 'admin', 1)");
            $stmt->execute([$adminEmail, $adminUser, $adminPass, $apiKey]);
        }

        // Store license in site_settings
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$data['license_key']]);

        // Store signed token and signature for local verification
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('license_token', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$data['license_token'] ?? '']);
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('license_signature', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$data['license_signature'] ?? '']);
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('license_last_verified', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([date('Y-m-d H:i:s')]);

        // Update site title
        $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'site_title'")
            ->execute([$data['app_name']]);

        return ['success' => true, 'message' => "Database installed successfully ({$tableCount} tables)"];

    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Handle AJAX requests
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'verify_license':
            $licenseKey = trim($_POST['license_key'] ?? '');
            $domain = parse_url($_SESSION['install']['app_url'], PHP_URL_HOST) ?: $_SERVER['HTTP_HOST'];

            $result = verifyLicense($licenseKey, $domain);

            if ($result['valid']) {
                $_SESSION['install']['license_key'] = $licenseKey;
                $_SESSION['install']['license_verified'] = true;
                // Store signed token for local verification
                $_SESSION['install']['license_token'] = $result['token'] ?? '';
                $_SESSION['install']['license_signature'] = $result['token_signature'] ?? '';
            }

            echo json_encode($result);
            exit;

        case 'test_database':
            $result = testDatabaseConnection(
                $_POST['db_host'] ?? 'localhost',
                $_POST['db_port'] ?? '3306',
                $_POST['db_name'] ?? '',
                $_POST['db_user'] ?? '',
                $_POST['db_pass'] ?? ''
            );
            echo json_encode($result);
            exit;
    }

    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_license':
            if ($_SESSION['install']['license_verified']) {
                $_SESSION['install']['step'] = 3;
                header('Location: install.php?step=3');
                exit;
            } else {
                $error = 'Please verify your license first';
            }
            break;

        case 'save_app':
            $_SESSION['install']['app_name'] = trim($_POST['app_name'] ?? 'AIKAFLOW');
            $_SESSION['install']['app_url'] = rtrim(trim($_POST['app_url'] ?? ''), '/');
            $_SESSION['install']['app_debug'] = isset($_POST['app_debug']) ? 'true' : 'false';
            $_SESSION['install']['step'] = 4;
            header('Location: install.php?step=4');
            exit;

        case 'save_database':
            $_SESSION['install']['db_host'] = trim($_POST['db_host'] ?? 'localhost');
            $_SESSION['install']['db_port'] = trim($_POST['db_port'] ?? '3306');
            $_SESSION['install']['db_name'] = trim($_POST['db_name'] ?? '');
            $_SESSION['install']['db_user'] = trim($_POST['db_user'] ?? '');
            $_SESSION['install']['db_pass'] = $_POST['db_pass'] ?? '';

            // Test connection
            $result = testDatabaseConnection(
                $_SESSION['install']['db_host'],
                $_SESSION['install']['db_port'],
                $_SESSION['install']['db_name'],
                $_SESSION['install']['db_user'],
                $_SESSION['install']['db_pass']
            );

            if ($result['success']) {
                $_SESSION['install']['step'] = 5;
                header('Location: install.php?step=5');
                exit;
            } else {
                $error = $result['message'];
            }
            break;

        case 'save_admin':
            $email = trim($_POST['admin_email'] ?? '');
            $username = trim($_POST['admin_username'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $confirmPassword = $_POST['admin_password_confirm'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } elseif (strlen($username) < 3) {
                $error = 'Username must be at least 3 characters';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match';
            } else {
                $_SESSION['install']['admin_email'] = $email;
                $_SESSION['install']['admin_username'] = $username;
                $_SESSION['install']['admin_password'] = $password;
                $_SESSION['install']['step'] = 6;
                header('Location: install.php?step=6');
                exit;
            }
            break;

        case 'install':
            // Generate .env file
            $envContent = generateEnvContent($_SESSION['install']);
            if (file_put_contents($envFile, $envContent) === false) {
                $error = 'Could not write .env file. Check directory permissions.';
                break;
            }
            chmod($envFile, 0600);

            // Install database
            $result = installDatabase($_SESSION['install']);
            if (!$result['success']) {
                $error = $result['message'];
                break;
            }

            // Create lock file
            file_put_contents($installLockFile, date('Y-m-d H:i:s'));

            $_SESSION['install']['step'] = 7;
            header('Location: install.php?step=7');
            exit;
    }
}

// Check system requirements
function checkRequirements(): array
{
    $requirements = [];

    $requirements['php_version'] = [
        'name' => 'PHP Version',
        'required' => '8.1.0',
        'current' => PHP_VERSION,
        'passed' => version_compare(PHP_VERSION, '8.1.0', '>=')
    ];

    $extensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'mbstring'];
    foreach ($extensions as $ext) {
        $requirements['ext_' . $ext] = [
            'name' => "Extension: {$ext}",
            'required' => 'Installed',
            'current' => extension_loaded($ext) ? 'Installed' : 'Missing',
            'passed' => extension_loaded($ext)
        ];
    }

    $requirements['writable_root'] = [
        'name' => 'Root Directory Writable',
        'required' => 'Yes',
        'current' => is_writable(__DIR__) ? 'Yes' : 'No',
        'passed' => is_writable(__DIR__)
    ];

    return $requirements;
}

$requirements = checkRequirements();
$allPassed = !in_array(false, array_column($requirements, 'passed'));

$steps = [
    1 => 'Welcome',
    2 => 'License',
    3 => 'Application',
    4 => 'Database',
    5 => 'Admin',
    6 => 'Install',
    7 => 'Complete'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - AIKAFLOW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .step-item.active .step-number {
            background: linear-gradient(135deg, #9333ea, #3b82f6);
            color: white;
        }

        .step-item.completed .step-number {
            background: #22c55e;
            color: white;
        }

        .step-item.active .step-label {
            color: white;
        }

        .step-item.completed .step-label {
            color: #22c55e;
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen text-white">
    <div class="min-h-screen flex flex-col items-center justify-center p-4">
        <!-- Logo -->
        <div class="mb-8 text-center">
            <div
                class="w-20 h-20 bg-gradient-to-r from-purple-500 to-blue-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i data-lucide="workflow" class="w-10 h-10 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold">AIKAFLOW</h1>
            <p class="text-gray-400">Installation Wizard</p>
        </div>

        <!-- Step Indicator -->
        <?php if ($step < 7): ?>
            <div class="flex items-center gap-2 mb-8">
                <?php foreach ($steps as $num => $label):
                    if ($num == 7)
                        continue; ?>
                    <div
                        class="step-item flex items-center gap-2 <?= $num === $step ? 'active' : ($num < $step ? 'completed' : '') ?>">
                        <div
                            class="step-number w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold <?= $num === $step ? '' : ($num < $step ? '' : 'bg-gray-700 text-gray-400') ?>">
                            <?php if ($num < $step): ?>
                                <i data-lucide="check" class="w-4 h-4"></i>
                            <?php else: ?>
                                <?= $num ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($num < 6): ?>
                            <div class="w-8 h-0.5 <?= $num < $step ? 'bg-green-500' : 'bg-gray-700' ?>"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Main Card -->
        <div
            class="w-full max-w-xl bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-8 shadow-xl fade-in">

            <?php if ($error): ?>
                <div
                    class="bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <!-- Step 1: Welcome -->
                <div class="text-center mb-8">
                    <h2 class="text-xl font-semibold mb-2">Welcome to AIKAFLOW</h2>
                    <p class="text-gray-400">Let's get you set up in just a few minutes.</p>
                </div>

                <h3 class="font-medium mb-4">System Requirements</h3>
                <div class="space-y-2 mb-8">
                    <?php foreach ($requirements as $req): ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-700">
                            <span class="text-sm"><?= $req['name'] ?></span>
                            <span
                                class="<?= $req['passed'] ? 'text-green-400' : 'text-red-400' ?> text-sm flex items-center gap-1">
                                <?php if ($req['passed']): ?>
                                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                                <?php else: ?>
                                    <i data-lucide="x-circle" class="w-4 h-4"></i>
                                <?php endif; ?>
                                <?= $req['current'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($allPassed): ?>
                    <a href="?step=2"
                        class="block w-full py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg text-center transition-all">
                        Continue <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                    </a>
                <?php else: ?>
                    <div class="text-center text-red-400">
                        <p>Please fix the requirements above before continuing.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($step === 2): ?>
                <!-- Step 2: License -->
                <h2 class="text-xl font-semibold mb-2">License Verification</h2>
                <p class="text-gray-400 mb-6">Enter your license key to activate AIKAFLOW.</p>

                <form method="POST" id="license-form">
                    <input type="hidden" name="action" value="save_license">

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-300 mb-2">License Key</label>
                        <div class="flex gap-2">
                            <input type="text" name="license_key" id="license_key" required
                                value="<?= htmlspecialchars($_SESSION['install']['license_key']) ?>"
                                class="flex-1 px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 font-mono"
                                placeholder="AIKA-XXXX-XXXX-XXXX-XXXX">
                            <button type="button" id="verify-btn" onclick="verifyLicense()"
                                class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                                <span id="verify-text">Verify</span>
                                <span id="verify-loading" class="hidden">
                                    <i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i>
                                </span>
                            </button>
                        </div>
                        <div id="license-status" class="mt-2 text-sm hidden"></div>
                    </div>

                    <div class="flex gap-3">
                        <a href="?step=1" class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </a>
                        <button type="submit" id="continue-btn" <?= $_SESSION['install']['license_verified'] ? '' : 'disabled' ?>
                            class="flex-1 py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                            Continue <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                        </button>
                    </div>
                </form>

                <script>
                    async function verifyLicense() {
                        const key = document.getElementById('license_key').value.trim();
                        if (!key) {
                            showStatus('Please enter a license key', false);
                            return;
                        }

                        const btn = document.getElementById('verify-btn');
                        const textEl = document.getElementById('verify-text');
                        const loadingEl = document.getElementById('verify-loading');

                        btn.disabled = true;
                        textEl.classList.add('hidden');
                        loadingEl.classList.remove('hidden');

                        try {
                            const formData = new FormData();
                            formData.append('ajax', '1');
                            formData.append('action', 'verify_license');
                            formData.append('license_key', key);

                            const response = await fetch('install.php', {
                                method: 'POST',
                                body: formData
                            });

                            const data = await response.json();

                            if (data.valid) {
                                showStatus('✓ ' + data.message, true);
                                document.getElementById('continue-btn').disabled = false;
                            } else {
                                showStatus('✗ ' + data.message, false);
                                document.getElementById('continue-btn').disabled = true;
                            }
                        } catch (e) {
                            showStatus('Error connecting to license server', false);
                        } finally {
                            btn.disabled = false;
                            textEl.classList.remove('hidden');
                            loadingEl.classList.add('hidden');
                        }
                    }

                    function showStatus(message, success) {
                        const el = document.getElementById('license-status');
                        el.className = 'mt-2 text-sm ' + (success ? 'text-green-400' : 'text-red-400');
                        el.textContent = message;
                        el.classList.remove('hidden');
                    }
                </script>

            <?php elseif ($step === 3): ?>
                <!-- Step 3: Application Settings -->
                <h2 class="text-xl font-semibold mb-2">Application Settings</h2>
                <p class="text-gray-400 mb-6">Configure your application details.</p>

                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="save_app">

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Application Name</label>
                        <input type="text" name="app_name" required
                            value="<?= htmlspecialchars($_SESSION['install']['app_name']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Application URL</label>
                        <input type="url" name="app_url" required
                            value="<?= htmlspecialchars($_SESSION['install']['app_url']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <p class="text-xs text-gray-500 mt-1">The full URL where AIKAFLOW is installed</p>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" name="app_debug" id="app_debug" <?= $_SESSION['install']['app_debug'] === 'true' ? 'checked' : '' ?>
                            class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-purple-600 focus:ring-purple-500">
                        <label for="app_debug" class="ml-2 text-sm text-gray-300">Enable Debug Mode</label>
                    </div>

                    <div class="flex gap-3">
                        <a href="?step=2" class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg transition-all">
                            Continue <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step === 4): ?>
                <!-- Step 4: Database -->
                <h2 class="text-xl font-semibold mb-2">Database Configuration</h2>
                <p class="text-gray-400 mb-6">Configure your MySQL database connection.</p>

                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="save_database">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Host</label>
                            <input type="text" name="db_host" required
                                value="<?= htmlspecialchars($_SESSION['install']['db_host']) ?>"
                                class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Port</label>
                            <input type="text" name="db_port" required
                                value="<?= htmlspecialchars($_SESSION['install']['db_port']) ?>"
                                class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                        <input type="text" name="db_name" required
                            value="<?= htmlspecialchars($_SESSION['install']['db_name']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <p class="text-xs text-gray-500 mt-1">Will be created if it doesn't exist</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                        <input type="text" name="db_user" required
                            value="<?= htmlspecialchars($_SESSION['install']['db_user']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                        <input type="password" name="db_pass"
                            value="<?= htmlspecialchars($_SESSION['install']['db_pass']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <button type="button" onclick="testDatabase()"
                        class="w-full py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                        <i data-lucide="plug" class="w-4 h-4 inline mr-2"></i> Test Connection
                    </button>
                    <div id="db-status" class="text-sm hidden"></div>

                    <div class="flex gap-3">
                        <a href="?step=3" class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg transition-all">
                            Continue <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                        </button>
                    </div>
                </form>

                <script>
                    async function testDatabase() {
                        const formData = new FormData();
                        formData.append('ajax', '1');
                        formData.append('action', 'test_database');
                        formData.append('db_host', document.querySelector('[name="db_host"]').value);
                        formData.append('db_port', document.querySelector('[name="db_port"]').value);
                        formData.append('db_name', document.querySelector('[name="db_name"]').value);
                        formData.append('db_user', document.querySelector('[name="db_user"]').value);
                        formData.append('db_pass', document.querySelector('[name="db_pass"]').value);

                        const statusEl = document.getElementById('db-status');
                        statusEl.textContent = 'Testing...';
                        statusEl.className = 'text-sm text-gray-400';
                        statusEl.classList.remove('hidden');

                        try {
                            const response = await fetch('install.php', { method: 'POST', body: formData });
                            const data = await response.json();

                            statusEl.textContent = data.success ? '✓ ' + data.message : '✗ ' + data.message;
                            statusEl.className = 'text-sm ' + (data.success ? 'text-green-400' : 'text-red-400');
                        } catch (e) {
                            statusEl.textContent = '✗ Connection failed';
                            statusEl.className = 'text-sm text-red-400';
                        }
                    }
                </script>

            <?php elseif ($step === 5): ?>
                <!-- Step 5: Admin Account -->
                <h2 class="text-xl font-semibold mb-2">Admin Account</h2>
                <p class="text-gray-400 mb-6">Create your administrator account.</p>

                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="save_admin">

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Email Address</label>
                        <input type="email" name="admin_email" required
                            value="<?= htmlspecialchars($_SESSION['install']['admin_email']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                        <input type="text" name="admin_username" required minlength="3"
                            value="<?= htmlspecialchars($_SESSION['install']['admin_username']) ?>"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                        <input type="password" name="admin_password" required minlength="8"
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Confirm Password</label>
                        <input type="password" name="admin_password_confirm" required
                            class="w-full px-4 py-3 bg-gray-800/50 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                    </div>

                    <div class="flex gap-3">
                        <a href="?step=4" class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg transition-all">
                            Continue <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step === 6): ?>
                <!-- Step 6: Install -->
                <div class="text-center mb-8">
                    <div class="w-16 h-16 bg-purple-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="rocket" class="w-8 h-8 text-purple-400"></i>
                    </div>
                    <h2 class="text-xl font-semibold mb-2">Ready to Install</h2>
                    <p class="text-gray-400">Review your settings and click Install to complete setup.</p>
                </div>

                <div class="bg-gray-800/50 rounded-lg p-4 mb-6 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">App Name:</span>
                        <span><?= htmlspecialchars($_SESSION['install']['app_name']) ?></span>
                    </div>
                    <div class="flex justify-between"><span class="text-gray-400">App URL:</span>
                        <span><?= htmlspecialchars($_SESSION['install']['app_url']) ?></span>
                    </div>
                    <div class="flex justify-between"><span class="text-gray-400">Database:</span>
                        <span><?= htmlspecialchars($_SESSION['install']['db_name']) ?></span>
                    </div>
                    <div class="flex justify-between"><span class="text-gray-400">Admin:</span>
                        <span><?= htmlspecialchars($_SESSION['install']['admin_username']) ?></span>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="install">
                    <div class="flex gap-3">
                        <a href="?step=5" class="px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-500 hover:to-emerald-500 text-white font-semibold rounded-lg transition-all">
                            <i data-lucide="rocket" class="w-4 h-4 inline mr-2"></i> Install AIKAFLOW
                        </button>
                    </div>
                </form>

            <?php elseif ($step === 7): ?>
                <!-- Step 7: Complete -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="check-circle" class="w-10 h-10 text-green-400"></i>
                    </div>
                    <h2 class="text-2xl font-bold mb-2">Installation Complete!</h2>
                    <p class="text-gray-400 mb-8">AIKAFLOW has been successfully installed.</p>

                    <div
                        class="bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 px-4 py-3 rounded-lg mb-6 text-sm">
                        <i data-lucide="alert-triangle" class="w-4 h-4 inline mr-2"></i>
                        For security, please delete <code class="bg-gray-800 px-1 rounded">install.php</code> from your
                        server.
                    </div>

                    <div class="bg-gray-800/50 rounded-lg p-4 mb-6 text-left">
                        <h3 class="font-medium mb-2">Your Admin Credentials:</h3>
                        <div class="text-sm space-y-1 text-gray-300">
                            <div><span class="text-gray-500">Email:</span>
                                <?= htmlspecialchars($_SESSION['install']['admin_email']) ?></div>
                            <div><span class="text-gray-500">Username:</span>
                                <?= htmlspecialchars($_SESSION['install']['admin_username']) ?></div>
                        </div>
                    </div>

                    <a href="login.php"
                        class="inline-block w-full py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-semibold rounded-lg text-center transition-all">
                        Go to Login <i data-lucide="arrow-right" class="w-4 h-4 inline ml-1"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-gray-500 text-sm mt-8">AIKAFLOW v1.0.0</p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>