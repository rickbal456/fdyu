<?php
/**
 * AIKAFLOW API - OAuth Callback Handler
 * 
 * Handles the OAuth callback from social platforms after user authorizes.
 * This page is displayed to the user in the popup window.
 * 
 * Endpoint: /api/social/callback.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';

// Get callback parameters
$success = $_GET['success'] ?? $_GET['status'] ?? '';
$error = $_GET['error'] ?? $_GET['error_description'] ?? '';
$platform = $_GET['platform'] ?? '';

// Determine success/failure
$isSuccess = $success === 'true' || $success === '1' || (!empty($success) && empty($error));

// Build message
if ($isSuccess) {
    $title = 'Account Connected!';
    $message = 'Your ' . ucfirst($platform ?: 'social media') . ' account has been successfully connected.';
    $iconClass = 'text-green-400';
    $icon = 'check-circle';
    $bgClass = 'bg-green-500/20';
} else {
    $title = 'Connection Failed';
    $message = $error ?: 'Failed to connect your social media account. Please try again.';
    $iconClass = 'text-red-400';
    $icon = 'x-circle';
    $bgClass = 'bg-red-500/20';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - AIKAFLOW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-gray-900/80 backdrop-blur rounded-2xl border border-gray-800 p-8 max-w-md w-full text-center shadow-2xl">
        <div class="w-16 h-16 rounded-full <?php echo $bgClass; ?> flex items-center justify-center mx-auto mb-4">
            <i data-lucide="<?php echo $icon; ?>" class="w-8 h-8 <?php echo $iconClass; ?>"></i>
        </div>
        
        <h1 class="text-xl font-bold text-white mb-2"><?php echo htmlspecialchars($title); ?></h1>
        <p class="text-gray-400 mb-6"><?php echo htmlspecialchars($message); ?></p>
        
        <p class="text-gray-500 text-sm mb-4">This window will close automatically...</p>
        
        <button onclick="window.close()" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg transition-colors">
            Close Window
        </button>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // Auto-close after 3 seconds if successful
        <?php if ($isSuccess): ?>
        setTimeout(() => {
            window.close();
        }, 3000);
        <?php endif; ?>

        // Notify parent window if available
        if (window.opener) {
            try {
                window.opener.postMessage({
                    type: 'social-oauth-callback',
                    success: <?php echo $isSuccess ? 'true' : 'false'; ?>,
                    platform: '<?php echo addslashes($platform); ?>',
                    error: '<?php echo addslashes($error); ?>'
                }, '*');
            } catch (e) {
                console.log('Could not notify parent window');
            }
        }
    </script>
</body>
</html>
