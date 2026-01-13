<?php
/**
 * AIKAFLOW Authentication System
 * 
 * Handles user authentication, sessions, and security.
 */

declare(strict_types=1);

if (!defined('AIKAFLOW')) {
    define('AIKAFLOW', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Authentication class
 */
class Auth
{
    private static ?array $currentUser = null;
    private static bool $sessionStarted = false;

    /**
     * Initialize session with secure settings
     */
    public static function initSession(): void
    {
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;
            return;
        }

        // Configure session settings
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', SESSION_SAMESITE);

        if (SESSION_SECURE) {
            ini_set('session.cookie_secure', '1');
        }

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => SESSION_SECURE,
            'httponly' => SESSION_HTTPONLY,
            'samesite' => SESSION_SAMESITE
        ]);

        session_start();
        self::$sessionStarted = true;

        // Regenerate session ID periodically for security
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) {
            // Regenerate session ID every 30 minutes
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    /**
     * Register a new user
     */
    public static function register(string $email, string $username, string $password): array
    {
        // Validate email
        $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        // Validate username
        $username = trim($username);
        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['success' => false, 'error' => 'Username must be 3-50 characters.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return ['success' => false, 'error' => 'Username can only contain letters, numbers, and underscores.'];
        }

        // Validate password
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'];
        }

        // Check if email already exists
        $existing = Database::fetchOne(
            "SELECT id FROM users WHERE email = ? OR username = ?",
            [$email, $username]
        );

        if ($existing) {
            return ['success' => false, 'error' => 'Email or username already registered.'];
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);

        // Generate API key
        $apiKey = bin2hex(random_bytes(32));

        // Insert user
        try {
            $userId = Database::insert('users', [
                'email' => $email,
                'username' => $username,
                'password_hash' => $passwordHash,
                'api_key' => $apiKey,
                'is_active' => 1
            ]);

            return [
                'success' => true,
                'user_id' => $userId,
                'message' => 'Registration successful! You can now log in.'
            ];
        } catch (Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Registration failed. Please try again.'];
        }
    }

    /**
     * Authenticate user login
     */
    public static function login(string $emailOrUsername, string $password, bool $remember = false): array
    {
        self::initSession();

        $emailOrUsername = trim($emailOrUsername);

        if (empty($emailOrUsername) || empty($password)) {
            return ['success' => false, 'error' => 'Email/username and password are required.'];
        }

        // Check for too many login attempts
        if (self::isLockedOut($emailOrUsername)) {
            return ['success' => false, 'error' => 'Too many login attempts. Please try again later.'];
        }

        // Find user by email or username
        $user = Database::fetchOne(
            "SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1",
            [$emailOrUsername, $emailOrUsername]
        );

        if (!$user) {
            self::recordFailedLogin($emailOrUsername);
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Check password
        if (password_verify($password, $user['password_hash'])) {
            // Check verification status if enabled
            try {
                $verificationEnabled = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'email_verification_enabled'") === '1';

                if ($verificationEnabled && empty($user['is_verified']) && !empty($user['verification_token'])) {
                    return ['success' => false, 'error' => 'Please verify your email address before logging in.'];
                }
            } catch (Exception $e) {
                // Ignore, proceed if settings fail to load
            }

            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Set session data
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['ip_address'] = self::getClientIP();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // Update last login
            Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);

            // Clear failed login attempts
            self::clearFailedLogins($emailOrUsername);

            // Store session in database
            self::storeSession($user['id']);

            self::$currentUser = $user;

            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username']
                ],
                'message' => 'Login successful!'
            ];
        } else {
            self::recordFailedLogin($emailOrUsername);
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Check if password needs rehashing
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            Database::update('users', ['password_hash' => $newHash], 'id = :id', ['id' => $user['id']]);
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = self::getClientIP();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Update last login
        Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);

        // Clear failed login attempts
        self::clearFailedLogins($emailOrUsername);

        // Store session in database
        self::storeSession($user['id']);

        self::$currentUser = $user;

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username']
            ],
            'message' => 'Login successful!'
        ];
    }

    /**
     * Logout current user
     */
    public static function logout(): void
    {
        self::initSession();

        // Remove session from database
        if (isset($_SESSION['user_id'])) {
            try {
                Database::delete('sessions', 'id = :id', ['id' => session_id()]);
            } catch (Exception $e) {
                // Ignore errors
            }
        }

        // Clear session data
        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Destroy session
        session_destroy();

        self::$currentUser = null;
        self::$sessionStarted = false;
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool
    {
        self::initSession();

        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }

        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Session timeout check removed - users stay logged in until manual logout
        // The browser session cookie will handle session expiry on browser close
        // Session ID regeneration (in initSession) still provides security

        return true;
    }

    /**
     * Get current authenticated user
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        self::$currentUser = Database::fetchOne(
            "SELECT id, email, username, api_key, created_at, last_login FROM users WHERE id = ? AND is_active = 1",
            [$_SESSION['user_id']]
        );

        return self::$currentUser;
    }

    /**
     * Get current user ID
     */
    public static function userId(): ?int
    {
        return self::check() ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Require authentication - redirect if not logged in
     */
    public static function requireLogin(string $redirectTo = 'login.php'): void
    {
        if (!self::check()) {
            // Only save the redirect URL if it's not a static asset or API call
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $excludedPatterns = [
                '/favicon\.ico/i',
                '/\.(js|css|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|eot)(\?|$)/i',
                '/^\/api\//i',
                '/\.php$/i'
            ];

            $shouldSaveRedirect = true;
            foreach ($excludedPatterns as $pattern) {
                if (preg_match($pattern, $requestUri)) {
                    $shouldSaveRedirect = false;
                    break;
                }
            }

            if ($shouldSaveRedirect && !isset($_SESSION['redirect_after_login'])) {
                $_SESSION['redirect_after_login'] = $requestUri;
            }

            header('Location: ' . $redirectTo);
            exit;
        }
    }

    /**
     * Redirect if already logged in
     */
    public static function redirectIfLoggedIn(string $redirectTo = 'index.php'): void
    {
        if (self::check()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    /**
     * Authenticate via API key
     */
    public static function authenticateApiKey(string $apiKey): ?array
    {
        if (empty($apiKey)) {
            return null;
        }

        $user = Database::fetchOne(
            "SELECT id, email, username, api_key FROM users WHERE api_key = ? AND is_active = 1",
            [$apiKey]
        );

        return $user ?: null;
    }

    /**
     * Generate new API key for user
     */
    public static function regenerateApiKey(int $userId): ?string
    {
        $newApiKey = bin2hex(random_bytes(32));

        $affected = Database::update(
            'users',
            ['api_key' => $newApiKey],
            'id = :id',
            ['id' => $userId]
        );

        return $affected > 0 ? $newApiKey : null;
    }

    /**
     * Validate CSRF token
     * Note: This does NOT invalidate the token on validation, 
     * allowing multiple form submissions (e.g., failed login attempts)
     */
    public static function validateCsrfToken(?string $token): bool
    {
        self::initSession();

        if (empty($token)) {
            error_log('CSRF validation failed: empty token provided');
            return false;
        }

        if (empty($_SESSION['csrf_token'])) {
            error_log('CSRF validation failed: no session token');
            return false;
        }

        // Check if token has expired
        $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
        if ((time() - $tokenTime) > CSRF_TOKEN_LIFETIME) {
            error_log('CSRF validation failed: token expired');
            // Don't unset - let the page regenerate
            return false;
        }

        // Use hash_equals for timing-safe comparison
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            error_log('CSRF validation failed: token mismatch');
            return false;
        }

        return true;
    }

    /**
     * Get current CSRF token or generate new one
     */
    public static function getCsrfToken(): string
    {
        self::initSession();

        // If token exists and is not expired, return it
        if (!empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token_time'])) {
            if ((time() - $_SESSION['csrf_token_time']) < CSRF_TOKEN_LIFETIME) {
                return $_SESSION['csrf_token'];
            }
        }

        // Generate new token
        return self::generateCsrfToken();
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        self::initSession();

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();

        return $token;
    }

    /**
     * Get CSRF token input field HTML
     */
    public static function csrfField(): string
    {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Store session in database
     */
    public static function storeSession(int $userId): void
    {
        try {
            $sessionId = session_id();
            $ip = self::getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // Delete old session if exists
            Database::delete('sessions', 'id = :id', ['id' => $sessionId]);

            // Insert new session
            Database::query(
                "INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$sessionId, $userId, $ip, $userAgent, '', time()]
            );
        } catch (Exception $e) {
            // Log but don't fail
            error_log('Session store error: ' . $e->getMessage());
        }
    }

    /**
     * Record failed login attempt
     */
    private static function recordFailedLogin(string $identifier): void
    {
        $key = 'failed_login_' . md5($identifier . self::getClientIP());

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        }

        $_SESSION[$key]['count']++;
        $_SESSION[$key]['last_attempt'] = time();
    }

    /**
     * Check if user is locked out due to failed attempts
     */
    private static function isLockedOut(string $identifier): bool
    {
        $key = 'failed_login_' . md5($identifier . self::getClientIP());

        if (!isset($_SESSION[$key])) {
            return false;
        }

        $data = $_SESSION[$key];

        // Reset if lockout period has passed
        if (
            isset($data['last_attempt']) &&
            (time() - $data['last_attempt']) > LOGIN_LOCKOUT_TIME
        ) {
            unset($_SESSION[$key]);
            return false;
        }

        return $data['count'] >= MAX_LOGIN_ATTEMPTS;
    }

    /**
     * Clear failed login attempts
     */
    public static function clearFailedLogins(string $identifier): void
    {
        $key = 'failed_login_' . md5($identifier . self::getClientIP());
        unset($_SESSION[$key]);
    }

    /**
     * Get client IP address
     */
    public static function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Change user password
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'];
        }

        $user = Database::fetchOne("SELECT password_hash FROM users WHERE id = ?", [$userId]);

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        Database::update('users', ['password_hash' => $newHash], 'id = :id', ['id' => $userId]);

        return ['success' => true, 'message' => 'Password changed successfully.'];
    }
}

/**
 * Helper functions for templates
 */
function auth_check(): bool
{
    return Auth::check();
}

function auth_user(): ?array
{
    return Auth::user();
}

function auth_user_id(): ?int
{
    return Auth::userId();
}

function csrf_field(): string
{
    return Auth::csrfField();
}

function csrf_token(): string
{
    return Auth::generateCsrfToken();
}