<?php
/**
 * AIKAFLOW Email Service
 * 
 * Handles sending emails via SMTP for notifications
 */

declare(strict_types=1);

if (!defined('AIKAFLOW')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';

class EmailService
{
    private static ?array $settings = null;

    /**
     * Load email settings from database
     */
    private static function loadSettings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        self::$settings = [];

        try {
            $rows = Database::fetchAll(
                "SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'email_%'"
            );

            foreach ($rows as $row) {
                self::$settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            error_log('EmailService: Failed to load settings - ' . $e->getMessage());
        }

        return self::$settings;
    }

    /**
     * Check if SMTP is enabled and configured
     */
    public static function isEnabled(): bool
    {
        $settings = self::loadSettings();
        return ($settings['smtp_enabled'] ?? '0') === '1'
            && !empty($settings['smtp_host'])
            && !empty($settings['smtp_username'])
            && !empty($settings['smtp_password']);
    }

    /**
     * Send an email using SMTP
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        if (!self::isEnabled()) {
            error_log('EmailService: SMTP is not enabled or not configured');
            return false;
        }

        $settings = self::loadSettings();

        $host = $settings['smtp_host'] ?? '';
        $port = (int) ($settings['smtp_port'] ?? 587);
        $username = $settings['smtp_username'] ?? '';
        $password = $settings['smtp_password'] ?? '';
        $encryption = $settings['smtp_encryption'] ?? 'tls';
        $fromEmail = $settings['smtp_from_email'] ?? $username;
        $fromName = $settings['smtp_from_name'] ?? 'AIKAFLOW';

        try {
            // Use socket-based SMTP for simplicity (no external dependencies)
            $socket = self::smtpConnect($host, $port, $encryption);

            if (!$socket) {
                error_log('EmailService: Failed to connect to SMTP server');
                return false;
            }

            // SMTP handshake
            self::smtpRead($socket);
            self::smtpCommand($socket, "EHLO " . gethostname());

            // STARTTLS if using TLS
            if ($encryption === 'tls') {
                self::smtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                self::smtpCommand($socket, "EHLO " . gethostname());
            }

            // Authentication
            self::smtpCommand($socket, "AUTH LOGIN");
            self::smtpCommand($socket, base64_encode($username));
            self::smtpCommand($socket, base64_encode($password));

            // Send email
            self::smtpCommand($socket, "MAIL FROM:<{$fromEmail}>");
            self::smtpCommand($socket, "RCPT TO:<{$to}>");
            self::smtpCommand($socket, "DATA");

            // Email headers and body
            $headers = [
                "From: {$fromName} <{$fromEmail}>",
                "To: {$to}",
                "Subject: {$subject}",
                "MIME-Version: 1.0",
                "Content-Type: text/plain; charset=UTF-8",
                "Content-Transfer-Encoding: 8bit",
                "Date: " . date('r'),
                "Message-ID: <" . uniqid() . "@" . gethostname() . ">"
            ];

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            self::smtpCommand($socket, $message);

            // Quit
            self::smtpCommand($socket, "QUIT");
            fclose($socket);

            return true;

        } catch (Exception $e) {
            error_log('EmailService: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Connect to SMTP server
     */
    private static function smtpConnect(string $host, int $port, string $encryption)
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @stream_socket_client(
            "{$prefix}{$host}:{$port}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        return $socket ?: null;
    }

    /**
     * Read response from SMTP server
     */
    private static function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Send command to SMTP server
     */
    private static function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return self::smtpRead($socket);
    }

    /**
     * Replace template variables
     */
    private static function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }

    /**
     * Send email verification email
     */
    public static function sendVerificationEmail(string $to, string $username, string $verificationLink): bool
    {
        $settings = self::loadSettings();

        $subject = $settings['email_verification_subject'] ?? 'Verify your email - AIKAFLOW';
        $body = $settings['email_verification_body'] ?? 'Hello {{username}},\n\nPlease verify your email: {{verification_link}}';

        $body = self::replaceVariables($body, [
            'username' => $username,
            'verification_link' => $verificationLink
        ]);

        // Convert \n to actual newlines
        $body = str_replace('\n', "\n", $body);

        return self::send($to, $subject, $body);
    }

    /**
     * Send welcome email
     */
    public static function sendWelcomeEmail(string $to, string $username, string $loginLink): bool
    {
        $settings = self::loadSettings();

        $subject = $settings['email_welcome_subject'] ?? 'Welcome to AIKAFLOW';
        $body = $settings['email_welcome_body'] ?? 'Hello {{username}},\n\nWelcome to AIKAFLOW!\n\nLogin at: {{login_link}}';

        $body = self::replaceVariables($body, [
            'username' => $username,
            'login_link' => $loginLink
        ]);

        $body = str_replace('\n', "\n", $body);

        return self::send($to, $subject, $body);
    }

    /**
     * Send forgot password email
     */
    public static function sendForgotPasswordEmail(string $to, string $username, string $resetLink): bool
    {
        $settings = self::loadSettings();

        $subject = $settings['email_forgot_password_subject'] ?? 'Reset your password - AIKAFLOW';
        $body = $settings['email_forgot_password_body'] ?? 'Hello {{username}},\n\nReset your password: {{reset_link}}';

        $body = self::replaceVariables($body, [
            'username' => $username,
            'reset_link' => $resetLink
        ]);

        $body = str_replace('\n', "\n", $body);

        return self::send($to, $subject, $body);
    }
}
