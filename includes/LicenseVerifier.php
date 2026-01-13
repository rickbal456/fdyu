<?php
/**
 * AIKAFLOW License Verification
 * 
 * This file handles local license verification using digital signatures.
 * It verifies the license on every page load (instant, no server call)
 * and periodically validates with the license server (daily).
 */

declare(strict_types=1);

// Public key for signature verification
// This key can only VERIFY signatures, not create them
// The private key is kept secret on the license server
define('LICENSE_PUBLIC_KEY', <<<'KEY'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0Z3VS5JJcpE5KzQf9r8h
LOGmPH9uxXfkHKFQYHMz8xvZKPOG5q8k9w7Dx4M0dBfA3rS7KlR2qP1eG5T8xNwY
jN9vK0LCbEV1hYzP2wqRQOF3mKqL8mE0ZnNjVhKvY2eJyLB7tA3T5sZ9HLhBdGKn
OQF0VRsMLb9T3bN0OpTz3x4wRlKCvB3J0B1mHTPVwEvYC2MsLB1h9DJ4Q6NxPHpM
xQZ3LZ0FpDsKEVE0PI5F4T3E8JqwoxGvY7ELqMnB9H7vPkM8EGCTQxzkv4S3LqXE
2qTqBN2L8KZU5pZ9RqQ3V4BCMFBWvN5EQp8Y3YWVxH2G1fXjKBD0pT3E0BkC7Q9V
4wIDAQAB
-----END PUBLIC KEY-----
KEY);

// License server URL for periodic verification
define('LICENSE_VERIFY_URL', 'https://flow.aikademi.id/api/verify.php');

// How often to verify with server (in seconds)
define('LICENSE_CHECK_INTERVAL', 86400); // 24 hours

class LicenseVerifier
{
    private static ?array $licenseData = null;
    private static bool $verified = false;

    /**
     * Verify the license (called on every page load)
     * This is FAST - no server call, just local signature verification
     */
    public static function verify(): bool
    {
        // Skip if already verified this request
        if (self::$verified) {
            return true;
        }

        try {
            // Get license data from database
            $token = self::getSetting('license_token');
            $signature = self::getSetting('license_signature');

            if (empty($token) || empty($signature)) {
                return false;
            }

            // Verify signature locally (no server call)
            $valid = self::verifySignature($token, $signature);
            if (!$valid) {
                error_log('License: Invalid signature');
                return false;
            }

            // Decode token
            $tokenJson = base64_decode($token);
            $tokenData = json_decode($tokenJson, true);

            if (!$tokenData) {
                error_log('License: Invalid token data');
                return false;
            }

            // Check domain matches
            $currentDomain = self::normalizeDomain($_SERVER['HTTP_HOST'] ?? '');
            $licensedDomain = $tokenData['domain'] ?? '';

            if ($currentDomain !== $licensedDomain) {
                error_log("License: Domain mismatch. Expected: {$licensedDomain}, Got: {$currentDomain}");
                return false;
            }

            // Check expiration
            if (!empty($tokenData['expires_at'])) {
                if (strtotime($tokenData['expires_at']) < time()) {
                    error_log('License: Token expired');
                    return false;
                }
            }

            self::$licenseData = $tokenData;
            self::$verified = true;

            // Periodic server verification (async, doesn't block page load)
            self::periodicServerCheck();

            return true;

        } catch (Exception $e) {
            error_log('License verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify signature using public key
     */
    private static function verifySignature(string $token, string $signature): bool
    {
        $signatureRaw = base64_decode($signature);
        $valid = openssl_verify($token, $signatureRaw, LICENSE_PUBLIC_KEY, OPENSSL_ALGO_SHA256);
        return $valid === 1;
    }

    /**
     * Periodic server check (runs once per day)
     */
    private static function periodicServerCheck(): void
    {
        $lastCheck = self::getSetting('license_last_verified');
        $lastCheckTime = $lastCheck ? strtotime($lastCheck) : 0;

        if (time() - $lastCheckTime < LICENSE_CHECK_INTERVAL) {
            return; // Not time yet
        }

        // Run server check in background (non-blocking)
        // This updates the token and checks for revocation
        try {
            $licenseKey = self::getSetting('license_key');
            $domain = self::normalizeDomain($_SERVER['HTTP_HOST'] ?? '');

            if (empty($licenseKey)) {
                return;
            }

            $ch = curl_init(LICENSE_VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'license_key' => $licenseKey,
                    'domain' => $domain,
                    'action' => 'verify'
                ]),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $data = json_decode($response, true);

                if ($data && isset($data['valid'])) {
                    // Update last verified timestamp
                    self::updateSetting('license_last_verified', date('Y-m-d H:i:s'));

                    // Update token if provided (refreshes expiration)
                    if (!empty($data['token']) && !empty($data['token_signature'])) {
                        self::updateSetting('license_token', $data['token']);
                        self::updateSetting('license_signature', $data['token_signature']);
                    }

                    // If license was revoked, clear the token
                    if (!$data['valid']) {
                        error_log('License: Server verification failed - ' . ($data['message'] ?? 'Unknown'));
                        self::updateSetting('license_token', '');
                        self::updateSetting('license_signature', '');
                    }
                }
            }
        } catch (Exception $e) {
            error_log('License server check error: ' . $e->getMessage());
        }
    }

    /**
     * Get license data (if verified)
     */
    public static function getLicenseData(): ?array
    {
        if (!self::$verified) {
            self::verify();
        }
        return self::$licenseData;
    }

    /**
     * Normalize domain
     */
    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);
        $domain = preg_replace('/:\d+$/', '', $domain);
        return $domain;
    }

    /**
     * Get setting from database
     */
    private static function getSetting(string $key): ?string
    {
        static $settings = null;

        if ($settings === null) {
            try {
                $settings = [];
                $result = Database::fetchAll(
                    "SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'license_%'"
                );
                foreach ($result as $row) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                return null;
            }
        }

        return $settings[$key] ?? null;
    }

    /**
     * Update setting in database
     */
    private static function updateSetting(string $key, string $value): void
    {
        try {
            Database::query(
                "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$key, $value]
            );
        } catch (Exception $e) {
            error_log('Failed to update license setting: ' . $e->getMessage());
        }
    }
}

/**
 * Check if license is valid
 * Call this function to verify the license
 */
function isLicenseValid(): bool
{
    return LicenseVerifier::verify();
}

/**
 * Get license info
 */
function getLicenseInfo(): ?array
{
    return LicenseVerifier::getLicenseData();
}
