<?php
/**
 * AIKAFLOW Google OAuth Callback Handler
 * 
 * Handles the OAuth 2.0 callback from Google, exchanges authorization code
 * for access token, fetches user profile, and creates or logs in user.
 */

declare(strict_types=1);

define('AIKAFLOW', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Initialize session
Auth::initSession();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: ../../');
    exit;
}

// Get Google OAuth settings
$googleEnabled = false;
$googleClientId = '';
$googleClientSecret = '';
$whatsappVerificationEnabled = false;

try {
    $rows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('google_auth_enabled', 'google_client_id', 'google_client_secret', 'whatsapp_verification_enabled')");
    foreach ($rows as $row) {
        if ($row['setting_key'] === 'google_auth_enabled')
            $googleEnabled = $row['setting_value'] === '1';
        if ($row['setting_key'] === 'google_client_id')
            $googleClientId = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'google_client_secret')
            $googleClientSecret = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'whatsapp_verification_enabled')
            $whatsappVerificationEnabled = $row['setting_value'] === '1';
    }
} catch (Exception $e) {
    error_log('Google OAuth settings error: ' . $e->getMessage());
    header('Location: ../../login.php?error=oauth_config');
    exit;
}

// Check if Google OAuth is enabled
if (!$googleEnabled || empty($googleClientId) || empty($googleClientSecret)) {
    header('Location: ../../login.php?error=oauth_disabled');
    exit;
}

// Get callback URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname(dirname(dirname($_SERVER['PHP_SELF'])));
$redirectUri = $protocol . '://' . $host . $basePath . '/api/auth/google-callback.php';

// Check for error from Google
if (isset($_GET['error'])) {
    error_log('Google OAuth error: ' . $_GET['error']);
    header('Location: ../../login.php?error=oauth_denied');
    exit;
}

// Check for authorization code
if (!isset($_GET['code'])) {
    // No code - redirect to Google for authorization
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $googleClientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account'
    ]);

    // Store invitation code if provided
    if (isset($_GET['ref']) && !empty($_GET['ref'])) {
        $_SESSION['pending_invitation_code'] = strtoupper(trim($_GET['ref']));
    }

    header('Location: ' . $authUrl);
    exit;
}

// Verify state parameter
if (!isset($_GET['state']) || !isset($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
    error_log('Google OAuth state mismatch');
    header('Location: ../../login.php?error=oauth_state');
    exit;
}
unset($_SESSION['google_oauth_state']);

// Exchange authorization code for access token
$code = $_GET['code'];

$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenData = [
    'code' => $code,
    'client_id' => $googleClientId,
    'client_secret' => $googleClientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$tokenResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log('Google OAuth token exchange failed: ' . $tokenResponse);
    header('Location: ../../login.php?error=oauth_token');
    exit;
}

$tokenResult = json_decode($tokenResponse, true);
if (!isset($tokenResult['access_token'])) {
    error_log('Google OAuth no access token in response');
    header('Location: ../../login.php?error=oauth_token');
    exit;
}

$accessToken = $tokenResult['access_token'];

// Fetch user profile from Google
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
$userResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log('Google OAuth user info fetch failed: ' . $userResponse);
    header('Location: ../../login.php?error=oauth_profile');
    exit;
}

$googleUser = json_decode($userResponse, true);
if (!isset($googleUser['email'])) {
    error_log('Google OAuth no email in user profile');
    header('Location: ../../login.php?error=oauth_email');
    exit;
}

$email = $googleUser['email'];
$googleId = $googleUser['id'] ?? '';
$name = $googleUser['name'] ?? '';

// Check if user exists by email
$existingUser = Database::fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

if ($existingUser) {
    // User exists - log them in
    $userId = (int) $existingUser['id'];

    // Update Google ID if not set
    if (empty($existingUser['google_id']) && !empty($googleId)) {
        Database::update('users', ['google_id' => $googleId], 'id = :id', ['id' => $userId]);
    }

    // Create session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $existingUser['email'];
    $_SESSION['user_username'] = $existingUser['username'];
    $_SESSION['login_time'] = time();

    // Store session in database
    Auth::storeSession($userId);

    // Clear any failed login attempts
    Auth::clearFailedLogins($email);

    // Check if WhatsApp verification is required and user doesn't have a verified number
    if ($whatsappVerificationEnabled && empty($existingUser['whatsapp_phone'])) {
        header('Location: ../../verify-whatsapp.php');
        exit;
    }

    header('Location: ../../');
    exit;
} else {
    // New user - create account
    // Generate username from email or name
    $baseUsername = !empty($name) ? preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', strtolower($name))) : explode('@', $email)[0];
    $username = $baseUsername;
    $counter = 1;

    // Ensure unique username
    while (Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username])) {
        $username = $baseUsername . $counter;
        $counter++;
    }

    // Generate random password (user won't need it for Google login)
    $randomPassword = bin2hex(random_bytes(16));
    $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

    // Create user
    $userId = Database::insert('users', [
        'email' => $email,
        'username' => $username,
        'password' => $passwordHash,
        'google_id' => $googleId,
        'email_verified_at' => date('Y-m-d H:i:s'), // Google emails are verified
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Grant welcome credits
    try {
        $welcomeCredits = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_welcome_amount'");

        if ($welcomeCredits && intval($welcomeCredits) > 0) {
            $amount = intval($welcomeCredits);
            $expiryDays = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'") ?: 365;
            $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

            Database::insert('credit_ledger', [
                'user_id' => $userId,
                'credits' => $amount,
                'remaining' => $amount,
                'source' => 'welcome',
                'expires_at' => $expiresAt
            ]);

            Database::insert('credit_transactions', [
                'user_id' => $userId,
                'type' => 'welcome',
                'amount' => $amount,
                'balance_after' => $amount,
                'description' => 'Welcome bonus credits',
                'reference_id' => 'welcome_' . $userId
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to grant welcome credits for Google user: ' . $e->getMessage());
    }

    // Process invitation code if stored in session
    if (isset($_SESSION['pending_invitation_code'])) {
        $invitationCode = $_SESSION['pending_invitation_code'];
        unset($_SESSION['pending_invitation_code']);

        try {
            // Get invitation settings
            $invRows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('invitation_enabled', 'invitation_referrer_credits', 'invitation_referee_credits')");
            $invEnabled = false;
            $refCredits = 50;
            $newCredits = 50;
            foreach ($invRows as $row) {
                if ($row['setting_key'] === 'invitation_enabled')
                    $invEnabled = $row['setting_value'] === '1';
                if ($row['setting_key'] === 'invitation_referrer_credits')
                    $refCredits = (int) $row['setting_value'];
                if ($row['setting_key'] === 'invitation_referee_credits')
                    $newCredits = (int) $row['setting_value'];
            }

            if ($invEnabled && !empty($invitationCode)) {
                $referrer = Database::fetchOne("SELECT id FROM users WHERE invitation_code = ? AND id != ?", [$invitationCode, $userId]);

                if ($referrer) {
                    Database::query("UPDATE users SET referred_by = ?, referred_at = NOW() WHERE id = ?", [$referrer['id'], $userId]);

                    $expiryDays = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'") ?: 365;
                    $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

                    if ($newCredits > 0) {
                        Database::insert('credit_ledger', ['user_id' => $userId, 'credits' => $newCredits, 'remaining' => $newCredits, 'source' => 'referral_bonus', 'expires_at' => $expiresAt]);
                        Database::insert('credit_transactions', ['user_id' => $userId, 'type' => 'referral_bonus', 'amount' => $newCredits, 'balance_after' => 0, 'description' => 'Referral bonus', 'reference_id' => 'referral_bonus_' . $referrer['id']]);
                    }
                    if ($refCredits > 0) {
                        Database::insert('credit_ledger', ['user_id' => $referrer['id'], 'credits' => $refCredits, 'remaining' => $refCredits, 'source' => 'referral_reward', 'expires_at' => $expiresAt]);
                        Database::insert('credit_transactions', ['user_id' => $referrer['id'], 'type' => 'referral_reward', 'amount' => $refCredits, 'balance_after' => 0, 'description' => 'Referral reward', 'reference_id' => 'referral_reward_' . $userId]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Failed to process Google OAuth referral: ' . $e->getMessage());
        }
    }

    // Create session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_username'] = $username;
    $_SESSION['login_time'] = time();

    // Store session in database
    Auth::storeSession($userId);

    // New users always need WhatsApp verification if enabled (they don't have a number yet)
    if ($whatsappVerificationEnabled) {
        header('Location: ../../verify-whatsapp.php');
        exit;
    }

    header('Location: ../../');
    exit;
}
