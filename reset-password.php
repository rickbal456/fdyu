<?php
/**
 * AIKAFLOW Reset Password Page
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';

// Initialize session
Auth::initSession();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$validToken = false;
$userId = null;

// Validate token
if (!empty($token)) {
    $resetRecord = Database::fetchOne(
        "SELECT prt.*, u.username FROM password_reset_tokens prt 
         JOIN users u ON prt.user_id = u.id 
         WHERE prt.token = ? AND prt.expires_at > NOW()",
        [$token]
    );

    if ($resetRecord) {
        $validToken = true;
        $userId = $resetRecord['user_id'];
    } else {
        $error = 'This password reset link is invalid or has expired.';
    }
} else {
    $error = 'Invalid password reset link.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate CSRF token
    if (!Auth::validateCsrfToken($submittedToken)) {
        $error = 'Session expired. Please try again.';
        Auth::generateCsrfToken();
    } elseif (empty($password) || empty($confirmPassword)) {
        $error = 'Please enter and confirm your new password.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        Database::query("UPDATE users SET password = ? WHERE id = ?", [$hashedPassword, $userId]);

        // Delete the token
        Database::query("DELETE FROM password_reset_tokens WHERE token = ?", [$token]);

        $success = 'Your password has been reset successfully. You can now log in with your new password.';
        $validToken = false; // Hide the form
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
    <title>Reset Password - AIKAFLOW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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

        .password-hint {
            font-size: 12px;
            color: var(--color-dark-400);
            margin-top: 6px;
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <div class="auth-logo-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                    </div>
                    <span class="auth-logo-text">AIKAFLOW</span>
                </div>
                <h1 class="auth-title">Reset Password</h1>
                <p class="auth-subtitle">Enter your new password</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($validToken): ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-group">
                        <label class="form-label" for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-input"
                            placeholder="Enter new password" required autocomplete="new-password">
                        <p class="password-hint">Must be at least 8 characters long</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                            placeholder="Confirm new password" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn-primary">Reset Password</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php" class="auth-link">Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>