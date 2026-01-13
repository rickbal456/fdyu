<?php
/**
 * AIKAFLOW Email Verification
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/includes/auth.php';

Auth::initSession();

// Get token
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Invalid verification link.');
}

try {
    // Find user by token
    $user = Database::fetchOne(
        "SELECT * FROM users WHERE verification_token = ?",
        [$token]
    );

    if (!$user) {
        $message = "Invalid or expired verification link.";
        $status = "error";
    } else {
        // Check expiration
        $expiresAt = strtotime($user['verification_token_expires_at']);
        if (time() > $expiresAt) {
            $message = "Verification link has expired. Please login to request a new one.";
            $status = "error";
        } else {
            // Verify user
            Database::update(
                'users',
                [
                    'is_verified' => 1,
                    'verification_token' => null,
                    'verification_token_expires_at' => null
                ],
                'id = :id',
                ['id' => $user['id']]
            );

            $message = "Email successfully verified! You can now access your account.";
            $status = "success";

            // If not logged in, maybe verify grants auto login provided it was same browser session? 
            // For security, usually better to ask for login, but for UX auto login is nice.
            // Let's redirect to login for now to be safe and consistent.
        }
    }

} catch (Exception $e) {
    error_log("Verification error: " . $e->getMessage());
    $message = "An error occurred during verification.";
    $status = "error";
}

// Render simple page
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - AIKAFLOW</title>
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
                            950: '#030712',
                        },
                        primary: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                            950: '#1e1b4b',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-dark-950 min-h-screen flex items-center justify-center p-4 text-dark-100">
    <div class="max-w-md w-full bg-dark-900 border border-dark-800 rounded-xl p-8 text-center shadow-xl">
        <div class="mb-6 flex justify-center">
            <?php if ($status === 'success'): ?>
                <div class="w-16 h-16 bg-green-500/10 rounded-full flex items-center justify-center text-green-500">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                        <polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                </div>
            <?php else: ?>
                <div class="w-16 h-16 bg-red-500/10 rounded-full flex items-center justify-center text-red-500">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                </div>
            <?php endif; ?>
        </div>

        <h1 class="text-2xl font-bold mb-2">
            <?php echo $status === 'success' ? 'Verification Successful' : 'Verification Failed'; ?></h1>
        <p class="text-dark-400 mb-8"><?php echo htmlspecialchars($message); ?></p>

        <a href="login.php"
            class="inline-block px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition-colors">
            Go to Login
        </a>
    </div>
</body>

</html>