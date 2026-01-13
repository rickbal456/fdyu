<?php
/**
 * AIKAFLOW Login Page
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';

// Initialize session first
Auth::initSession();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: ./');
    exit;
}

// Load hCaptcha settings
$hcaptchaEnabled = false;
$hcaptchaSiteKey = '';
$hcaptchaSecretKey = '';
try {
    $rows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('hcaptcha_enabled', 'hcaptcha_site_key', 'hcaptcha_secret_key')");
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'hcaptcha_enabled')
            $hcaptchaEnabled = $row['setting_value'] === '1';
        if ($row['setting_key'] === 'hcaptcha_site_key')
            $hcaptchaSiteKey = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'hcaptcha_secret_key')
            $hcaptchaSecretKey = $row['setting_value'] ?? '';
    }
    // Load logo, favicon, and site title
    $brandRows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('logo_url_dark', 'logo_url_light', 'favicon_url', 'site_title', 'google_auth_enabled')");
    foreach ($brandRows as $row) {
        if ($row['setting_key'] === 'logo_url_dark')
            $logoUrlDark = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'logo_url_light')
            $logoUrlLight = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'favicon_url')
            $faviconUrl = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'site_title')
            $siteTitle = $row['setting_value'] ?? 'AIKAFLOW';
        if ($row['setting_key'] === 'google_auth_enabled')
            $googleAuthEnabled = $row['setting_value'] === '1';
    }
} catch (Exception $e) {
    // Ignore
}
$logoUrlDark = $logoUrlDark ?? '';
$logoUrlLight = $logoUrlLight ?? '';
$faviconUrl = $faviconUrl ?? '';
$siteTitle = $siteTitle ?? 'AIKAFLOW';
$googleAuthEnabled = $googleAuthEnabled ?? false;

$error = '';
$success = '';
$emailValue = '';

// Check for messages from query string
if (isset($_GET['registered'])) {
    $success = 'Registration successful! Please log in.';
}

if (isset($_GET['logout'])) {
    $success = 'You have been logged out successfully.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $emailValue = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Validate CSRF token
    if (!Auth::validateCsrfToken($submittedToken)) {
        $error = 'Session expired. Please try again.';
        // Regenerate token for next attempt
        Auth::generateCsrfToken();
    } elseif (empty($emailValue) || empty($password)) {
        $error = 'Please enter your email/username and password.';
    } else {
        // Verify hCaptcha if enabled
        $captchaValid = true;
        if ($hcaptchaEnabled && !empty($hcaptchaSecretKey)) {
            $hcaptchaResponse = $_POST['h-captcha-response'] ?? '';
            if (empty($hcaptchaResponse)) {
                $captchaValid = false;
                $error = 'Please complete the captcha verification.';
            } else {
                $verifyUrl = 'https://hcaptcha.com/siteverify';
                $data = [
                    'secret' => $hcaptchaSecretKey,
                    'response' => $hcaptchaResponse
                ];
                $options = [
                    'http' => [
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method' => 'POST',
                        'content' => http_build_query($data)
                    ]
                ];
                $context = stream_context_create($options);
                $result = @file_get_contents($verifyUrl, false, $context);
                if ($result) {
                    $responseData = json_decode($result, true);
                    if (!($responseData['success'] ?? false)) {
                        $captchaValid = false;
                        $error = 'Captcha verification failed. Please try again.';
                    }
                }
            }
        }

        if ($captchaValid) {
            $result = Auth::login($emailValue, $password, $remember);

            if ($result['success']) {
                // Redirect to originally requested page or dashboard
                $redirect = $_SESSION['redirect_after_login'] ?? './';
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

// Get CSRF token (reuse existing or generate new)
$csrfToken = Auth::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($siteTitle) ?></title>
    <?php if (!empty($faviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            50: '#f9fafb',
                            100: '#f3f4f6',
                            200: '#e5e7eb',
                            300: '#d1d5db',
                            400: '#9ca3af',
                            500: '#6b7280',
                            600: '#4b5563',
                            700: '#374151',
                            800: '#1f2937',
                            900: '#111827',
                            950: '#0d1117'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($hcaptchaEnabled && !empty($hcaptchaSiteKey)): ?>
        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .glass {
            background: rgba(17, 24, 39, 0.8);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>

<body class="bg-dark-950 min-h-screen flex items-center justify-center p-4">
    <!-- Background decoration -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div
            class="absolute -top-40 -right-40 w-80 h-80 bg-purple-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse">
        </div>
        <div
            class="absolute -bottom-40 -left-40 w-80 h-80 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse">
        </div>
    </div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <?php if (!empty($logoUrlDark) || !empty($logoUrlLight)): ?>
                <?php if (!empty($logoUrlDark)): ?>
                    <img src="<?= htmlspecialchars($logoUrlDark) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                        class="h-16 mx-auto mb-4">
                <?php elseif (!empty($logoUrlLight)): ?>
                    <img src="<?= htmlspecialchars($logoUrlLight) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                        class="h-16 mx-auto mb-4">
                <?php endif; ?>
            <?php else: ?>
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl gradient-bg mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z">
                        </path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-white"><?= htmlspecialchars($siteTitle) ?></h1>
            <?php endif; ?>
        </div>

        <!-- Login Card -->
        <div class="glass rounded-2xl p-8">
            <h2 class="text-xl font-semibold text-white mb-6">Welcome back</h2>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div
                    class="bg-red-500/10 border border-red-500/50 text-red-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div
                    class="bg-green-500/10 border border-green-500/50 text-green-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <!-- Email/Username Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-2">
                        Email or Username
                    </label>
                    <input type="text" id="email" name="email" required autocomplete="username"
                        class="w-full px-4 py-3 bg-dark-800 border border-dark-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                        placeholder="Enter your email or username" value="<?= htmlspecialchars($emailValue) ?>">
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-2">
                        Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            class="w-full px-4 py-3 bg-dark-800 border border-dark-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all pr-12"
                            placeholder="Enter your password">
                        <button type="button" onclick="togglePassword()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white transition-colors"
                            tabindex="-1">
                            <svg id="eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                </path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="flex items-center justify-between">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember"
                            class="w-4 h-4 rounded border-dark-600 bg-dark-800 text-purple-500 focus:ring-purple-500 focus:ring-offset-0 cursor-pointer">
                        <span class="ml-2 text-sm text-gray-400">Remember me</span>
                    </label>
                    <a href="forgot-password" class="text-sm text-purple-400 hover:text-purple-300 transition-colors">
                        Forgot password?
                    </a>
                </div>

                <?php if ($hcaptchaEnabled && !empty($hcaptchaSiteKey)): ?>
                    <!-- hCaptcha -->
                    <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hcaptchaSiteKey) ?>" data-theme="dark"></div>
                <?php endif; ?>

                <!-- Submit Button -->
                <button type="submit"
                    class="w-full py-3 px-4 gradient-bg text-white font-semibold rounded-lg hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all">
                    Sign In
                </button>
            </form>

            <!-- Divider -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-dark-600"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-dark-900 text-gray-500">or</span>
                </div>
            </div>

            <?php if ($googleAuthEnabled): ?>
            <!-- Google Sign In -->
            <a href="api/auth/google-callback.php"
                class="w-full flex items-center justify-center gap-3 py-3 px-4 bg-white text-gray-800 font-semibold rounded-lg hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all mb-6">
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Sign in with Google
            </a>
            <?php endif; ?>

            <!-- Register Link -->
            <p class="text-center text-gray-400">
                Don't have an account?
                <a href="register" class="text-purple-400 hover:text-purple-300 font-medium transition-colors">
                    Sign up
                </a>
            </p>
        </div>

        <p class="text-center text-gray-500 text-sm mt-8">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($siteTitle) ?>. All rights reserved.
        </p>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                `;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                `;
            }
        }
    </script>
</body>

</html>