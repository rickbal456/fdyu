<?php
/**
 * AIKAFLOW Forgot Password Page
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';

// Initialize session
Auth::initSession();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: index.php');
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
    $brandRows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('logo_url_dark', 'logo_url_light', 'favicon_url', 'site_title')");
    foreach ($brandRows as $row) {
        if ($row['setting_key'] === 'logo_url_dark')
            $logoUrlDark = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'logo_url_light')
            $logoUrlLight = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'favicon_url')
            $faviconUrl = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'site_title')
            $siteTitle = $row['setting_value'] ?? 'AIKAFLOW';
    }
} catch (Exception $e) {
    // Ignore
}
$logoUrlDark = $logoUrlDark ?? '';
$logoUrlLight = $logoUrlLight ?? '';
$faviconUrl = $faviconUrl ?? '';
$siteTitle = $siteTitle ?? 'AIKAFLOW';

$error = '';
$success = '';
$emailValue = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $emailValue = trim($_POST['email'] ?? '');

    // Validate CSRF token
    if (!Auth::validateCsrfToken($submittedToken)) {
        $error = 'Session expired. Please try again.';
        Auth::generateCsrfToken();
    } elseif (empty($emailValue)) {
        $error = 'Please enter your email address.';
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
            // Find user by email
            $user = Database::fetchOne("SELECT id, username, email FROM users WHERE email = ?", [$emailValue]);

            if ($user) {
                // Generate token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Delete any existing tokens for this user
                Database::query("DELETE FROM password_reset_tokens WHERE user_id = ?", [$user['id']]);

                // Insert new token
                Database::query(
                    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                    [$user['id'], $token, $expiresAt]
                );

                // Send email
                $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/reset-password.php?token=' . $token;

                $emailSent = EmailService::sendForgotPasswordEmail($user['email'], $user['username'], $resetLink);

                if ($emailSent) {
                    $success = 'If an account exists with that email, you will receive a password reset link shortly.';
                } else {
                    $success = 'If an account exists with that email, you will receive a password reset link shortly.';
                }
            } else {
                // Don't reveal if email exists or not
                $success = 'If an account exists with that email, you will receive a password reset link shortly.';
            }
        }
    }
}

// Generate CSRF token
$csrfToken = Auth::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= htmlspecialchars($siteTitle) ?></title>
    <?php if (!empty($faviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <?php if ($hcaptchaEnabled && !empty($hcaptchaSiteKey)): ?>
        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php endif; ?>

    <style>
        :root {
            --color-dark-950: #0a0a0f;
            --color-dark-900: #12121a;
            --color-dark-800: #1a1a24;
            --color-dark-700: #252530;
            --color-dark-600: #32323e;
            --color-dark-500: #4a4a58;
            --color-dark-400: #6b6b7b;
            --color-dark-300: #9494a3;
            --color-dark-200: #b8b8c5;
            --color-dark-100: #d4d4dd;
            --color-dark-50: #ededf2;
            --color-primary-500: #8b5cf6;
            --color-primary-600: #7c3aed;
            --color-primary-400: #a78bfa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--color-dark-950) 0%, #1a1025 50%, var(--color-dark-950) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-card {
            background: var(--color-dark-900);
            border: 1px solid var(--color-dark-700);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .auth-logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--color-primary-500), #a855f7);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-logo-icon svg {
            width: 24px;
            height: 24px;
            color: white;
        }

        .auth-logo-text {
            font-size: 24px;
            font-weight: 700;
            color: var(--color-dark-50);
        }

        .auth-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--color-dark-50);
            margin-bottom: 8px;
        }

        .auth-subtitle {
            color: var(--color-dark-400);
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--color-dark-200);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: var(--color-dark-800);
            border: 1px solid var(--color-dark-600);
            border-radius: 8px;
            color: var(--color-dark-50);
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--color-primary-500);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        .form-input::placeholder {
            color: var(--color-dark-500);
        }

        .btn-primary {
            width: 100%;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--color-primary-500), #a855f7);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .auth-link {
            color: var(--color-primary-400);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .auth-link:hover {
            color: var(--color-primary-500);
        }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--color-dark-400);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
        }

        .h-captcha {
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <?php if (!empty($logoUrlDark) || !empty($logoUrlLight)): ?>
                        <?php if (!empty($logoUrlDark)): ?>
                            <img src="<?= htmlspecialchars($logoUrlDark) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                                style="height: 48px;">
                        <?php elseif (!empty($logoUrlLight)): ?>
                            <img src="<?= htmlspecialchars($logoUrlLight) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                                style="height: 48px;">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="auth-logo-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                            </svg>
                        </div>
                        <span class="auth-logo-text"><?= htmlspecialchars($siteTitle) ?></span>
                    <?php endif; ?>
                </div>
                <h1 class="auth-title">Forgot Password</h1>
                <p class="auth-subtitle">Enter your email to receive a reset link</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="you@example.com"
                            value="<?= htmlspecialchars($emailValue) ?>" required autocomplete="email">
                    </div>

                    <?php if ($hcaptchaEnabled && !empty($hcaptchaSiteKey)): ?>
                        <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hcaptchaSiteKey) ?>"></div>
                    <?php endif; ?>

                    <button type="submit" class="btn-primary">Send Reset Link</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php" class="auth-link">Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>