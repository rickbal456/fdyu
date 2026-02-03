<?php

/**
 * BunnyCDN Storage Plugin Handler
 * 
 * Provides cloud storage functionality via BunnyCDN.
 * This plugin replaces the hardcoded BUNNY_* constants with a plugin-based approach.
 */

class BunnyCDNStorageHandler
{
    private static $config = null;

    /**
     * Get plugin configuration
     * Checks: 1) integration_keys (admin integrations tab), 2) constants (fallback)
     */
    public static function getConfig()
    {
        if (self::$config === null) {
            self::$config = [
                'storageZone' => '',
                'accessKey' => '',
                'storageUrl' => 'https://storage.bunnycdn.com',
                'cdnUrl' => ''
            ];

            // 1. Try to load from integration_keys in site_settings (admin integrations tab)
            if (class_exists('Database')) {
                try {
                    $result = Database::fetchOne(
                        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
                    );
                    if ($result && $result['setting_value']) {
                        $keys = json_decode($result['setting_value'], true);
                        if (is_array($keys)) {
                            // Check for bunnycdn_* format (how admin saves it in integrations tab)
                            self::$config['storageZone'] = $keys['bunnycdn_storageZone'] ?? '';
                            self::$config['accessKey'] = $keys['bunnycdn_accessKey'] ?? '';
                            self::$config['cdnUrl'] = $keys['bunnycdn_cdnUrl'] ?? '';
                            self::$config['storageUrl'] = $keys['bunnycdn_storageUrl'] ?? 'https://storage.bunnycdn.com';
                        }
                    }
                } catch (Exception $e) {
                    error_log('BunnyCDN config load error: ' . $e->getMessage());
                }
            }

            // 2. Fall back to constants if not set in database
            if (empty(self::$config['storageZone']) && defined('BUNNY_STORAGE_ZONE')) {
                self::$config['storageZone'] = BUNNY_STORAGE_ZONE;
            }
            if (empty(self::$config['accessKey']) && defined('BUNNY_ACCESS_KEY')) {
                self::$config['accessKey'] = BUNNY_ACCESS_KEY;
            }
            if (empty(self::$config['cdnUrl']) && defined('BUNNY_CDN_URL')) {
                self::$config['cdnUrl'] = BUNNY_CDN_URL;
            }
            if (self::$config['storageUrl'] === 'https://storage.bunnycdn.com' && defined('BUNNY_STORAGE_URL')) {
                self::$config['storageUrl'] = BUNNY_STORAGE_URL;
            }
        }
        return self::$config;
    }

    /**
     * Check if storage is configured
     */
    public static function isConfigured()
    {
        $config = self::getConfig();
        return !empty($config['storageZone']) && !empty($config['accessKey']) && !empty($config['cdnUrl']);
    }

    /**
     * Upload a file from data URL to BunnyCDN
     */
    public static function uploadDataUrl(string $dataUrl, ?string $filename = null): ?string
    {
        if (!self::isConfigured()) {
            return null;
        }

        // Parse data URL
        if (!preg_match('/^data:([^;]+);base64,(.+)$/', $dataUrl, $matches)) {
            return null;
        }

        $mimeType = $matches[1];
        $data = base64_decode($matches[2]);

        if (!$data) {
            return null;
        }

        return self::uploadData($data, $mimeType, $filename);
    }

    /**
     * Upload raw data to BunnyCDN
     */
    public static function uploadData(string $data, string $mimeType, ?string $filename = null): ?string
    {
        $config = self::getConfig();

        if (!self::isConfigured()) {
            return null;
        }

        // Determine extension
        $extension = self::getExtensionForMimeType($mimeType);
        if ($filename) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext) {
                $extension = $ext;
            }
        }

        // Generate unique path
        $path = 'uploads/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        // Build upload URL
        $url = rtrim($config['storageUrl'], '/') . '/' . $config['storageZone'] . '/' . $path;

        // Upload using cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $config['accessKey'],
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . strlen($data)
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $cdnUrl = self::ensureHttpsProtocol(rtrim($config['cdnUrl'], '/'));
            return $cdnUrl . '/' . $path;
        }

        return null;
    }

    /**
     * Ensure URL has https:// protocol
     */
    private static function ensureHttpsProtocol(string $url): string
    {
        if (empty($url)) {
            return $url;
        }

        // Remove any existing protocol
        $url = preg_replace('#^https?://#i', '', $url);

        // Add https://
        return 'https://' . $url;
    }

    /**
     * Upload a file from URL to BunnyCDN
     */
    public static function uploadFromUrl(string $sourceUrl, ?string $filename = null): ?string
    {
        $config = self::getConfig();

        if (!self::isConfigured()) {
            return null;
        }

        // Download source file
        $fileContent = @file_get_contents($sourceUrl);
        if (!$fileContent) {
            return null;
        }

        // Get mime type from URL or content
        $mimeType = 'application/octet-stream';
        $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'bin';

        // Try to get content type from headers
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $mimeType = trim(substr($header, 13));
                    break;
                }
            }
        }

        // Generate path
        $path = 'uploads/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        // Upload
        $url = rtrim($config['storageUrl'], '/') . '/' . $config['storageZone'] . '/' . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $config['accessKey'],
                'Content-Type: ' . $mimeType
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $cdnUrl = self::ensureHttpsProtocol(rtrim($config['cdnUrl'], '/'));
            return $cdnUrl . '/' . $path;
        }

        return null;
    }

    /**
     * Delete a file from BunnyCDN
     */
    public static function deleteFile(string $path): bool
    {
        $config = self::getConfig();

        if (!self::isConfigured()) {
            return false;
        }

        // Remove CDN URL prefix if present
        $cdnUrl = rtrim($config['cdnUrl'], '/');
        if (strpos($path, $cdnUrl) === 0) {
            $path = substr($path, strlen($cdnUrl) + 1);
        }

        $url = rtrim($config['storageUrl'], '/') . '/' . $config['storageZone'] . '/' . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $config['accessKey']
            ]
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Get extension for mime type
     */
    private static function getExtensionForMimeType(string $mimeType): string
    {
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf'
        ];

        return $mimeTypes[$mimeType] ?? 'bin';
    }
}
