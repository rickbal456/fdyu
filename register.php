<?php
/**
 * AIKAFLOW Registration Page
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
$termsOfService = '';
$privacyPolicy = '';

try {
    $rows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('hcaptcha_enabled', 'hcaptcha_site_key', 'hcaptcha_secret_key', 'terms_of_service', 'privacy_policy')");
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'hcaptcha_enabled')
            $hcaptchaEnabled = $row['setting_value'] === '1';
        if ($row['setting_key'] === 'hcaptcha_site_key')
            $hcaptchaSiteKey = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'hcaptcha_secret_key')
            $hcaptchaSecretKey = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'terms_of_service')
            $termsOfService = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'privacy_policy')
            $privacyPolicy = $row['setting_value'] ?? '';
    }
    // Load logo, favicon, and site title
    $brandRows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('logo_url_dark', 'logo_url_light', 'favicon_url', 'site_title', 'google_auth_enabled', 'invitation_enabled', 'invitation_referrer_credits', 'invitation_referee_credits', 'whatsapp_verification_enabled')");
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
        if ($row['setting_key'] === 'invitation_enabled')
            $invitationEnabled = $row['setting_value'] === '1';
        if ($row['setting_key'] === 'invitation_referrer_credits')
            $referrerCredits = (int) $row['setting_value'];
        if ($row['setting_key'] === 'invitation_referee_credits')
            $refereeCredits = (int) $row['setting_value'];
        if ($row['setting_key'] === 'whatsapp_verification_enabled')
            $whatsappVerificationEnabled = $row['setting_value'] === '1';
    }
} catch (Exception $e) {
    // Ignore
}
$logoUrlDark = $logoUrlDark ?? '';
$logoUrlLight = $logoUrlLight ?? '';
$faviconUrl = $faviconUrl ?? '';
$siteTitle = $siteTitle ?? 'AIKAFLOW';
$googleAuthEnabled = $googleAuthEnabled ?? false;
$invitationEnabled = $invitationEnabled ?? false;
$whatsappVerificationEnabled = $whatsappVerificationEnabled ?? false;
$referrerCredits = $referrerCredits ?? 50;
$refereeCredits = $refereeCredits ?? 50;

$error = '';
$errors = [];
$formData = [
    'email' => '',
    'username' => '',
    'whatsapp_phone' => ''
];

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    // Get form data
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['username'] = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $agreeTerms = isset($_POST['agree_terms']);

    // Validate CSRF token
    if (!Auth::validateCsrfToken($submittedToken)) {
        $error = 'Session expired. Please try again.';
        Auth::generateCsrfToken();
    } else {
        // Validation
        if (empty($formData['email'])) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }

        if (empty($formData['username'])) {
            $errors['username'] = 'Username is required.';
        } elseif (strlen($formData['username']) < 3 || strlen($formData['username']) > 50) {
            $errors['username'] = 'Username must be 3-50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $formData['username'])) {
            $errors['username'] = 'Username can only contain letters, numbers, and underscores.';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if (!$agreeTerms) {
            $errors['agree_terms'] = 'You must agree to the terms and conditions.';
        }

        // WhatsApp phone validation (always mandatory)
        $formData['whatsapp_phone'] = trim($_POST['whatsapp_phone'] ?? '');
        if (empty($formData['whatsapp_phone'])) {
            $errors['whatsapp_phone'] = 'WhatsApp number is required.';
        } elseif (!preg_match('/^\+?[0-9]{10,15}$/', $formData['whatsapp_phone'])) {
            $errors['whatsapp_phone'] = 'Invalid phone number format.';
        } else {
            // Check if phone is already registered
            $existingPhone = Database::fetchOne(
                "SELECT id FROM users WHERE whatsapp_phone = ?",
                [$formData['whatsapp_phone']]
            );
            if ($existingPhone) {
                $errors['whatsapp_phone'] = 'This WhatsApp number is already registered.';
            }
        }

        // If WhatsApp verification is enabled, check if phone is verified
        if ($whatsappVerificationEnabled && empty($errors['whatsapp_phone'])) {
            if (
                !isset($_SESSION['whatsapp_verified']) ||
                $_SESSION['whatsapp_verified']['phone'] !== $formData['whatsapp_phone']
            ) {
                $errors['whatsapp_phone'] = 'Please verify your WhatsApp number before registering.';
            }
        }

        // Verify hCaptcha if enabled
        if ($hcaptchaEnabled && !empty($hcaptchaSecretKey) && empty($errors)) {
            $hcaptchaResponse = $_POST['h-captcha-response'] ?? '';
            if (empty($hcaptchaResponse)) {
                $errors['captcha'] = 'Please complete the captcha verification.';
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
                        $errors['captcha'] = 'Captcha verification failed. Please try again.';
                    }
                }
            }
        }

        // If no errors, attempt registration
        if (empty($errors)) {
            $result = Auth::register($formData['email'], $formData['username'], $password);

            if ($result['success']) {
                // Grant welcome credits
                try {
                    $welcomeCredits = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_welcome_amount'");

                    if ($welcomeCredits && intval($welcomeCredits) > 0) {
                        $amount = intval($welcomeCredits);
                        $expiryDays = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'") ?: 365;
                        $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

                        Database::insert('credit_ledger', [
                            'user_id' => $result['user_id'],
                            'credits' => $amount,
                            'remaining' => $amount,
                            'source' => 'welcome',
                            'expires_at' => $expiresAt
                        ]);

                        Database::insert('credit_transactions', [
                            'user_id' => $result['user_id'],
                            'type' => 'welcome',
                            'amount' => $amount,
                            'balance_after' => $amount,
                            'description' => 'Welcome bonus credits',
                            'reference_id' => 'welcome_' . $result['user_id']
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('Failed to grant welcome credits: ' . $e->getMessage());
                }

                // Save WhatsApp phone number if provided
                if (!empty($formData['whatsapp_phone'])) {
                    try {
                        Database::update(
                            'users',
                            ['whatsapp_phone' => $formData['whatsapp_phone']],
                            'id = :id',
                            ['id' => $result['user_id']]
                        );
                        // Clear verification session data
                        unset($_SESSION['whatsapp_verified']);
                    } catch (Exception $e) {
                        error_log('Failed to save WhatsApp phone: ' . $e->getMessage());
                    }
                }

                // Process invitation code if provided
                $invitationCode = strtoupper(trim($_POST['invitation_code'] ?? ''));
                if ($invitationEnabled && !empty($invitationCode)) {
                    try {
                        // Find referrer by invitation code
                        $referrer = Database::fetchOne(
                            "SELECT id FROM users WHERE invitation_code = ? AND id != ?",
                            [$invitationCode, $result['user_id']]
                        );

                        if ($referrer) {
                            // Record referral
                            Database::query(
                                "UPDATE users SET referred_by = ?, referred_at = NOW() WHERE id = ?",
                                [$referrer['id'], $result['user_id']]
                            );

                            $expiryDays = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'") ?: 365;
                            $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

                            // Grant credits to new user (referee)
                            if ($refereeCredits > 0) {
                                Database::insert('credit_ledger', [
                                    'user_id' => $result['user_id'],
                                    'credits' => $refereeCredits,
                                    'remaining' => $refereeCredits,
                                    'source' => 'referral_bonus',
                                    'expires_at' => $expiresAt
                                ]);
                                Database::insert('credit_transactions', [
                                    'user_id' => $result['user_id'],
                                    'type' => 'referral_bonus',
                                    'amount' => $refereeCredits,
                                    'balance_after' => 0,
                                    'description' => 'Referral bonus credits',
                                    'reference_id' => 'referral_bonus_' . $referrer['id']
                                ]);
                            }

                            // Grant credits to referrer
                            if ($referrerCredits > 0) {
                                Database::insert('credit_ledger', [
                                    'user_id' => $referrer['id'],
                                    'credits' => $referrerCredits,
                                    'remaining' => $referrerCredits,
                                    'source' => 'referral_reward',
                                    'expires_at' => $expiresAt
                                ]);
                                Database::insert('credit_transactions', [
                                    'user_id' => $referrer['id'],
                                    'type' => 'referral_reward',
                                    'amount' => $referrerCredits,
                                    'balance_after' => 0,
                                    'description' => 'Referral reward',
                                    'reference_id' => 'referral_reward_' . $result['user_id']
                                ]);
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Failed to process invitation code: ' . $e->getMessage());
                    }
                }

                // Check if email verification is enabled
                $verificationEnabled = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'email_verification_enabled'") === '1';

                if ($verificationEnabled) {
                    // Generate token
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    Database::update(
                        'users',
                        ['verification_token' => $token, 'verification_token_expires_at' => $expires],
                        'id = :id',
                        ['id' => $result['user_id']]
                    );

                    // Send email
                    require_once __DIR__ . '/includes/email.php';
                    $link = APP_URL . '/verify-email.php?token=' . $token;

                    if (EmailService::sendVerificationEmail($formData['email'], $formData['username'], $link)) {
                        $successMessage = 'Registration successful! Please check your email to verify your account.';
                        header('Location: login?verified=pending');
                    } else {
                        // If email fails, log error but let them login (or handle as needed)
                        error_log('Failed to send verification email to ' . $formData['email']);
                        $successMessage = 'Registration successful! (Email verification failed, please contact support)';
                        header('Location: login?registered=1');
                    }
                    exit;
                }

                $successMessage = $result['message'];
                header('Location: login?registered=1');
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

// Get CSRF token
$csrfToken = Auth::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?= htmlspecialchars($siteTitle) ?></title>
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
    <!-- intl-tel-input for phone number -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.css">
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/intlTelInput.min.js"></script>
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

        /* intl-tel-input dark theme overrides */
        .iti {
            width: 100%;
        }

        .iti__tel-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 87px !important;
            background-color: rgb(31, 41, 55) !important;
            border: 1px solid rgb(75, 85, 99) !important;
            border-radius: 0.5rem !important;
            color: white !important;
        }

        .iti__tel-input:focus {
            outline: none !important;
            ring: 2px !important;
            ring-color: rgb(147, 51, 234) !important;
            border-color: transparent !important;
        }

        .iti__tel-input::placeholder {
            color: rgb(107, 114, 128) !important;
        }

        .iti__country-container {
            padding-left: 0 !important;
        }

        .iti__dropdown-content {
            background-color: rgb(31, 41, 55) !important;
            border: 1px solid rgb(75, 85, 99) !important;
            color: white !important;
        }

        .iti__search-input {
            background-color: rgb(55, 65, 81) !important;
            color: white !important;
            border-color: rgb(75, 85, 99) !important;
        }

        .iti__country {
            padding: 8px 10px !important;
        }

        .iti__country:hover,
        .iti__country--highlight {
            background-color: rgb(55, 65, 81) !important;
        }

        .iti__dial-code {
            color: rgb(156, 163, 175) !important;
        }

        .iti__selected-dial-code {
            color: rgb(209 213 219 / var(--tw-text-opacity, 1)) !important;
        }

        .h-captcha iframe {
            width: 100% !important;
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

        <!-- Register Card -->
        <div class="glass rounded-2xl p-8">
            <h2 class="text-xl font-semibold text-white mb-6">Create your account</h2>

            <!-- General Error Message -->
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

            <!-- Registration Form -->
            <form method="POST" action="" class="space-y-5" id="register-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-2">
                        Email Address
                    </label>
                    <input type="email" id="email" name="email" required autocomplete="email"
                        class="w-full px-4 py-3 bg-dark-800 border <?= isset($errors['email']) ? 'border-red-500' : 'border-dark-600' ?> rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                        placeholder="you@example.com" value="<?= htmlspecialchars($formData['email']) ?>">
                    <?php if (isset($errors['email'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Username Field -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-300 mb-2">
                        Username
                    </label>
                    <input type="text" id="username" name="username" required autocomplete="username"
                        pattern="[a-zA-Z0-9_]+" minlength="3" maxlength="50"
                        class="w-full px-4 py-3 bg-dark-800 border <?= isset($errors['username']) ? 'border-red-500' : 'border-dark-600' ?> rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                        placeholder="Choose a username" value="<?= htmlspecialchars($formData['username']) ?>">
                    <?php if (isset($errors['username'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['username']) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500">3-50 characters, letters, numbers, and underscores only</p>
                    <?php endif; ?>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-2">
                        Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required autocomplete="new-password"
                            minlength="<?= PASSWORD_MIN_LENGTH ?>"
                            class="w-full px-4 py-3 bg-dark-800 border <?= isset($errors['password']) ? 'border-red-500' : 'border-dark-600' ?> rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all pr-12"
                            placeholder="Create a strong password">
                        <button type="button" onclick="togglePassword('password')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white transition-colors"
                            tabindex="-1">
                            <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                </path>
                            </svg>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['password']) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500">Minimum <?= PASSWORD_MIN_LENGTH ?> characters</p>
                    <?php endif; ?>

                    <!-- Password Strength Indicator -->
                    <div class="mt-2">
                        <div class="h-1 bg-dark-700 rounded-full overflow-hidden">
                            <div id="password-strength" class="h-full w-0 transition-all duration-300"></div>
                        </div>
                        <p id="password-strength-text" class="text-xs mt-1 text-gray-500"></p>
                    </div>
                </div>

                <!-- Confirm Password Field -->
                <div>
                    <label for="password_confirm" class="block text-sm font-medium text-gray-300 mb-2">
                        Confirm Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password_confirm" name="password_confirm" required
                            autocomplete="new-password"
                            class="w-full px-4 py-3 bg-dark-800 border <?= isset($errors['password_confirm']) ? 'border-red-500' : 'border-dark-600' ?> rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all pr-12"
                            placeholder="Confirm your password">
                        <button type="button" onclick="togglePassword('password_confirm')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white transition-colors"
                            tabindex="-1">
                            <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                </path>
                            </svg>
                        </button>
                    </div>
                    <?php if (isset($errors['password_confirm'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['password_confirm']) ?></p>
                    <?php endif; ?>
                    <p id="password-match" class="mt-1 text-xs hidden"></p>
                </div>

                <!-- WhatsApp Phone Field -->
                <div>
                    <label for="whatsapp_phone" class="block text-sm font-medium text-gray-300 mb-2">
                        WhatsApp Number <span class="text-red-400">*</span>
                    </label>
                    <div class="flex gap-2 items-start">
                        <div class="flex-1">
                            <input type="tel" id="whatsapp_phone" name="whatsapp_phone" required class="w-full"
                                value="<?= htmlspecialchars($formData['whatsapp_phone']) ?>">
                            <input type="hidden" id="whatsapp_phone_full" name="whatsapp_phone_full" value="">
                        </div>
                        <?php if ($whatsappVerificationEnabled): ?>
                            <button type="button" id="btn-send-wa-code"
                                class="px-4 py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                                Send Code
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($errors['whatsapp_phone'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['whatsapp_phone']) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500">Select your country and enter your phone number</p>
                    <?php endif; ?>

                    <?php if ($whatsappVerificationEnabled): ?>
                        <!-- OTP Verification Section (hidden by default) -->
                        <div id="wa-otp-section" class="mt-3 hidden">
                            <div class="flex gap-2">
                                <input type="text" id="wa_otp_code" maxlength="6"
                                    class="flex-1 px-4 py-3 bg-dark-800 border border-dark-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all text-center tracking-widest font-mono text-lg"
                                    placeholder="Enter 6-digit code">
                                <button type="button" id="btn-verify-wa-code"
                                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition-colors whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                                    Verify
                                </button>
                            </div>
                            <p id="wa-otp-status" class="mt-1 text-xs text-gray-500">Enter the verification code sent to
                                your WhatsApp</p>
                        </div>
                        <!-- Verified Badge (hidden by default) -->
                        <div id="wa-verified-badge" class="mt-2 hidden">
                            <span
                                class="inline-flex items-center gap-1 px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 13l4 4L19 7"></path>
                                </svg>
                                Verified
                            </span>
                        </div>
                        <input type="hidden" id="whatsapp_verified" name="whatsapp_verified" value="0">
                    <?php endif; ?>
                </div>

                <?php if ($invitationEnabled): ?>
                    <!-- Invitation Code -->
                    <div>
                        <label for="invitation_code" class="block text-sm font-medium text-gray-300 mb-2">
                            Invitation Code <span class="text-gray-500">(optional)</span>
                        </label>
                        <input type="text" id="invitation_code" name="invitation_code"
                            value="<?= htmlspecialchars($_GET['ref'] ?? '') ?>"
                            class="w-full px-4 py-3 bg-dark-800 border border-dark-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors uppercase"
                            placeholder="Enter invitation code" maxlength="12">
                        <p class="mt-1 text-xs text-gray-500">Have a friend's invitation code? Enter it to get bonus
                            credits!</p>
                    </div>
                <?php endif; ?>

                <!-- Terms Agreement -->
                <div>
                    <label class="flex items-start cursor-pointer">
                        <input type="checkbox" name="agree_terms" required
                            class="w-4 h-4 mt-1 rounded border-dark-600 bg-dark-800 text-purple-500 focus:ring-purple-500 focus:ring-offset-0 cursor-pointer">
                        <span class="ml-2 text-sm text-gray-400">
                            I agree to the
                            <a href="javascript:void(0)" onclick="showTermsModal()"
                                class="text-purple-400 hover:text-purple-300">Terms of Service</a>
                            and
                            <a href="javascript:void(0)" onclick="showPrivacyModal()"
                                class="text-purple-400 hover:text-purple-300">Privacy Policy</a>
                        </span>
                    </label>
                    <?php if (isset($errors['agree_terms'])): ?>
                        <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['agree_terms']) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($hcaptchaEnabled && !empty($hcaptchaSiteKey)): ?>
                    <!-- hCaptcha -->
                    <div>
                        <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hcaptchaSiteKey) ?>" data-theme="dark">
                        </div>
                        <?php if (isset($errors['captcha'])): ?>
                            <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['captcha']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Submit Button -->
                <button type="submit"
                    class="w-full py-3 px-4 gradient-bg text-white font-semibold rounded-lg hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                    id="submit-btn">
                    Create Account
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
                <!-- Google Sign Up -->
                <a href="api/auth/google-callback.php"
                    class="w-full flex items-center justify-center gap-3 py-3 px-4 bg-white text-gray-800 font-semibold rounded-lg hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all mb-6">
                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                        <path fill="#4285F4"
                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                        <path fill="#34A853"
                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                        <path fill="#FBBC05"
                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                        <path fill="#EA4335"
                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                    </svg>
                    Sign up with Google
                </a>
            <?php endif; ?>

            <!-- Login Link -->
            <p class="text-center text-gray-400">
                Already have an account?
                <a href="login" class="text-purple-400 hover:text-purple-300 font-medium transition-colors">
                    Sign in
                </a>
            </p>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-500 text-sm mt-8">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($siteTitle) ?>. All rights reserved.
        </p>
    </div>

    <script>
        function togglePassword(inputId) {
            const passwordInput = document.getElementById(inputId);
            const eyeIcon = passwordInput.parentElement.querySelector('.eye-icon');

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

        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('password-strength');
        const strengthText = document.getElementById('password-strength-text');

        passwordInput.addEventListener('input', function () {
            const password = this.value;
            let strength = 0;

            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 15;
            if (/[a-z]/.test(password)) strength += 15;
            if (/[A-Z]/.test(password)) strength += 15;
            if (/[0-9]/.test(password)) strength += 15;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 15;

            strengthBar.style.width = strength + '%';

            if (strength < 30) {
                strengthBar.className = 'h-full transition-all duration-300 bg-red-500';
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-xs mt-1 text-red-400';
            } else if (strength < 60) {
                strengthBar.className = 'h-full transition-all duration-300 bg-yellow-500';
                strengthText.textContent = 'Fair password';
                strengthText.className = 'text-xs mt-1 text-yellow-400';
            } else if (strength < 80) {
                strengthBar.className = 'h-full transition-all duration-300 bg-blue-500';
                strengthText.textContent = 'Good password';
                strengthText.className = 'text-xs mt-1 text-blue-400';
            } else {
                strengthBar.className = 'h-full transition-all duration-300 bg-green-500';
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-xs mt-1 text-green-400';
            }
        });

        // Password match checker
        const confirmInput = document.getElementById('password_confirm');
        const matchText = document.getElementById('password-match');

        function checkMatch() {
            const password = passwordInput.value;
            const confirm = confirmInput.value;

            if (confirm.length === 0) {
                matchText.classList.add('hidden');
                return;
            }

            matchText.classList.remove('hidden');

            if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.className = 'mt-1 text-xs text-green-400';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.className = 'mt-1 text-xs text-red-400';
            }
        }

        confirmInput.addEventListener('input', checkMatch);
        passwordInput.addEventListener('input', checkMatch);

        // Terms and Privacy Modal Functions
        function showTermsModal() {
            document.getElementById('terms-modal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function showPrivacyModal() {
            document.getElementById('privacy-modal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modal on backdrop click
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', function (e) {
                if (e.target === this) {
                    closeModal(this.parentElement.id);
                }
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.legal-modal:not(.hidden)').forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });

        // WhatsApp Verification
        (function () {
            const sendBtn = document.getElementById('btn-send-wa-code');
            const verifyBtn = document.getElementById('btn-verify-wa-code');
            const phoneInput = document.getElementById('whatsapp_phone');
            const phoneFullInput = document.getElementById('whatsapp_phone_full');
            const otpSection = document.getElementById('wa-otp-section');
            const otpInput = document.getElementById('wa_otp_code');
            const otpStatus = document.getElementById('wa-otp-status');
            const verifiedBadge = document.getElementById('wa-verified-badge');
            const verifiedHidden = document.getElementById('whatsapp_verified');

            if (!phoneInput) return; // No phone input found

            // Initialize intl-tel-input
            let iti = null;
            if (typeof intlTelInput !== 'undefined') {
                iti = intlTelInput(phoneInput, {
                    initialCountry: "auto",
                    geoIpLookup: function (callback) {
                        fetch("https://ipapi.co/json")
                            .then(res => res.json())
                            .then(data => callback(data.country_code))
                            .catch(() => callback("ID")); // Default to Indonesia
                    },
                    separateDialCode: true,
                    utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/utils.js",
                    preferredCountries: ["id", "sa", "my", "sg"],
                    nationalMode: false,
                    formatOnDisplay: true
                });
            }

            // Update hidden field with full number before form submit
            const form = document.getElementById('register-form');
            form?.addEventListener('submit', function () {
                if (iti) {
                    phoneFullInput.value = iti.getNumber();
                    phoneInput.value = iti.getNumber();
                }
            });

            // Helper to get full phone number
            function getFullPhone() {
                if (iti) {
                    return iti.getNumber();
                }
                return phoneInput.value.trim();
            }

            if (!sendBtn) return; // WhatsApp verification not enabled

            let cooldownTimer = null;
            let isVerified = false;

            // Send verification code
            sendBtn.addEventListener('click', async function () {
                const phone = getFullPhone();

                if (!phone) {
                    showOtpError('Please enter your WhatsApp number');
                    return;
                }

                if (iti && !iti.isValidNumber()) {
                    showOtpError('Please enter a valid phone number');
                    return;
                }

                sendBtn.disabled = true;
                sendBtn.textContent = 'Sending...';

                try {
                    const response = await fetch('api/auth/send-whatsapp-code.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ phone })
                    });

                    const data = await response.json();

                    if (data.success) {
                        otpSection.classList.remove('hidden');
                        otpInput.value = '';
                        otpInput.focus();
                        showOtpSuccess('Verification code sent to your WhatsApp');
                        startCooldown(60);
                        phoneInput.disabled = true;
                        if (iti) iti.setDisabled(true);
                    } else {
                        showOtpError(data.error || 'Failed to send code');
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send Code';
                    }
                } catch (error) {
                    console.error('Send code error:', error);
                    showOtpError('Failed to send code. Please try again.');
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send Code';
                }
            });

            // Verify code
            verifyBtn?.addEventListener('click', async function () {
                const code = otpInput.value.trim();
                const phone = getFullPhone();

                if (!code || code.length !== 6) {
                    showOtpError('Please enter the 6-digit code');
                    return;
                }

                verifyBtn.disabled = true;
                verifyBtn.textContent = 'Verifying...';

                try {
                    const response = await fetch('api/auth/verify-whatsapp-code.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code, phone })
                    });

                    const data = await response.json();

                    if (data.success) {
                        isVerified = true;
                        otpSection.classList.add('hidden');
                        verifiedBadge.classList.remove('hidden');
                        sendBtn.classList.add('hidden');
                        verifiedHidden.value = '1';
                        showOtpSuccess('WhatsApp number verified!');
                    } else {
                        showOtpError(data.error || 'Invalid code');
                        verifyBtn.disabled = false;
                        verifyBtn.textContent = 'Verify';
                    }
                } catch (error) {
                    console.error('Verify code error:', error);
                    showOtpError('Verification failed. Please try again.');
                    verifyBtn.disabled = false;
                    verifyBtn.textContent = 'Verify';
                }
            });

            // Allow only digits in OTP input
            otpInput?.addEventListener('input', function (e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            // Reset verification if phone changes
            phoneInput?.addEventListener('countrychange', function () {
                if (isVerified) {
                    resetVerification();
                }
            });

            phoneInput?.addEventListener('input', function () {
                if (isVerified) {
                    resetVerification();
                }
            });

            function resetVerification() {
                isVerified = false;
                verifiedBadge?.classList.add('hidden');
                sendBtn?.classList.remove('hidden');
                if (verifiedHidden) verifiedHidden.value = '0';
                phoneInput.disabled = false;
                if (iti) iti.setDisabled(false);
            }

            function startCooldown(seconds) {
                let remaining = seconds;
                sendBtn.disabled = true;

                cooldownTimer = setInterval(() => {
                    remaining--;
                    sendBtn.textContent = `Resend (${remaining}s)`;

                    if (remaining <= 0) {
                        clearInterval(cooldownTimer);
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Resend Code';
                    }
                }, 1000);
            }

            function showOtpError(msg) {
                if (otpStatus) {
                    otpStatus.textContent = msg;
                    otpStatus.className = 'mt-1 text-xs text-red-400';
                }
            }

            function showOtpSuccess(msg) {
                if (otpStatus) {
                    otpStatus.textContent = msg;
                    otpStatus.className = 'mt-1 text-xs text-green-400';
                }
            }
        })();
    </script>

    <!-- Terms of Service Modal -->
    <div id="terms-modal" class="legal-modal hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-backdrop absolute inset-0 bg-black bg-opacity-75"></div>
        <div
            class="relative bg-dark-900 rounded-2xl shadow-2xl max-w-3xl w-full max-h-[80vh] overflow-hidden border border-dark-700">
            <div class="flex items-center justify-between p-6 border-b border-dark-700">
                <h2 class="text-2xl font-bold text-dark-50">Terms of Service</h2>
                <button onclick="closeModal('terms-modal')" class="text-gray-400 hover:text-white transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[calc(80vh-120px)] text-gray-300">
                <?php if (!empty($termsOfService)): ?>
                    <?php
                    // Simple markdown-like rendering
                    // First convert literal \n to actual newlines
                    $content = str_replace('\n', "\n", $termsOfService);
                    $content = htmlspecialchars($content);
                    $content = preg_replace('/^# (.+)$/m', '<h1 class="text-lg font-bold mb-2 mt-3 text-white">$1</h1>', $content);
                    $content = preg_replace('/^## (.+)$/m', '<h2 class="text-base font-semibold mb-2 mt-3 text-gray-100">$1</h2>', $content);
                    $content = preg_replace('/^### (.+)$/m', '<h3 class="text-sm font-medium mb-1 mt-2 text-gray-200">$1</h3>', $content);
                    $content = nl2br($content);
                    echo $content;
                    ?>
                <?php else: ?>
                    <p class="text-gray-400">No Terms of Service have been configured yet.</p>
                <?php endif; ?>
            </div>
            <div class="p-6 border-t border-dark-700">
                <button onclick="closeModal('terms-modal')"
                    class="w-full py-3 px-4 bg-gradient-to-r from-purple-500 to-pink-500 text-white font-semibold rounded-lg hover:opacity-90 transition-all">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div id="privacy-modal" class="legal-modal hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-backdrop absolute inset-0 bg-black bg-opacity-75"></div>
        <div
            class="relative bg-dark-900 rounded-2xl shadow-2xl max-w-3xl w-full max-h-[80vh] overflow-hidden border border-dark-700">
            <div class="flex items-center justify-between p-6 border-b border-dark-700">
                <h2 class="text-2xl font-bold text-dark-50">Privacy Policy</h2>
                <button onclick="closeModal('privacy-modal')" class="text-gray-400 hover:text-white transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[calc(80vh-120px)] text-gray-300">
                <?php if (!empty($privacyPolicy)): ?>
                    <?php
                    // Simple markdown-like rendering
                    // First convert literal \n to actual newlines
                    $content = str_replace('\n', "\n", $privacyPolicy);
                    $content = htmlspecialchars($content);
                    $content = preg_replace('/^# (.+)$/m', '<h1 class="text-lg font-bold mb-2 mt-3 text-white">$1</h1>', $content);
                    $content = preg_replace('/^## (.+)$/m', '<h2 class="text-base font-semibold mb-2 mt-3 text-gray-100">$1</h2>', $content);
                    $content = preg_replace('/^### (.+)$/m', '<h3 class="text-sm font-medium mb-1 mt-2 text-gray-200">$1</h3>', $content);
                    $content = nl2br($content);
                    echo $content;
                    ?>
                <?php else: ?>
                    <p class="text-gray-400">No Privacy Policy has been configured yet.</p>
                <?php endif; ?>
            </div>
            <div class="p-6 border-t border-dark-700">
                <button onclick="closeModal('privacy-modal')"
                    class="w-full py-3 px-4 bg-gradient-to-r from-purple-500 to-pink-500 text-white font-semibold rounded-lg hover:opacity-90 transition-all">
                    Close
                </button>
            </div>
        </div>
    </div>
</body>

</html>