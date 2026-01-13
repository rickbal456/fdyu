<?php
/**
 * AIKAFLOW API Helpers
 * 
 * Common helper functions for API endpoints.
 */

declare(strict_types=1);

if (!defined('AIKAFLOW')) {
    exit('Direct access not allowed');
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function errorResponse(string $message, int $statusCode = 400): void
{
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

/**
 * Send success response
 */
function successResponse(array $data = [], string $message = ''): void
{
    $response = ['success' => true];
    if ($message) {
        $response['message'] = $message;
    }
    jsonResponse(array_merge($response, $data));
}


/**
 * Get JSON input from request body
 */
function getJsonInput(): array
{
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $_POST ?: [];
    }

    return $data ?: [];
}

/**
 * Require authentication
 * Checks both session and API key
 */
function requireAuth(): array
{
    // Check session auth first
    if (Auth::check()) {
        return Auth::user();
    }

    // Check API key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    if ($apiKey) {
        $user = Auth::authenticateApiKey($apiKey);
        if ($user) {
            return $user;
        }
    }

    errorResponse('Authentication required', 401);
    exit;
}

/**
 * Require specific HTTP method
 */
function requireMethod(string|array $methods): void
{
    $methods = is_array($methods) ? $methods : [$methods];

    if (!in_array($_SERVER['REQUEST_METHOD'], $methods)) {
        errorResponse('Method not allowed', 405);
    }
}

/**
 * Validate required fields
 */
function validateRequired(array $data, array $fields): void
{
    $missing = [];

    foreach ($fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        errorResponse('Missing required fields: ' . implode(', ', $missing));
    }
}

/**
 * Sanitize string input
 */
function sanitizeString(?string $input, int $maxLength = 255): string
{
    if ($input === null) {
        return '';
    }

    $input = trim($input);
    $input = strip_tags($input);
    $input = mb_substr($input, 0, $maxLength);

    return $input;
}

/**
 * Validate and sanitize workflow JSON data
 */
function validateWorkflowData(array $data): array
{
    $validated = [
        'version' => $data['version'] ?? '1.0.0',
        'workflow' => [],
        'nodes' => [],
        'connections' => [],
        'canvas' => $data['canvas'] ?? ['pan' => ['x' => 0, 'y' => 0], 'zoom' => 1]
    ];

    // Validate workflow info
    if (isset($data['workflow'])) {
        $validated['workflow'] = [
            'id' => $data['workflow']['id'] ?? null,
            'name' => sanitizeString($data['workflow']['name'] ?? 'Untitled', 255),
            'description' => sanitizeString($data['workflow']['description'] ?? '', 1000),
            'isPublic' => (bool) ($data['workflow']['isPublic'] ?? false)
        ];
    }

    // Validate nodes
    if (isset($data['nodes']) && is_array($data['nodes'])) {
        foreach ($data['nodes'] as $node) {
            if (!isset($node['id'], $node['type'], $node['position'])) {
                continue;
            }

            $validated['nodes'][] = [
                'id' => sanitizeString($node['id'], 50),
                'type' => sanitizeString($node['type'], 50),
                'position' => [
                    'x' => (float) ($node['position']['x'] ?? 0),
                    'y' => (float) ($node['position']['y'] ?? 0)
                ],
                'data' => $node['data'] ?? []
            ];
        }
    }

    // Validate connections
    if (isset($data['connections']) && is_array($data['connections'])) {
        foreach ($data['connections'] as $conn) {
            if (!isset($conn['from'], $conn['to'])) {
                continue;
            }

            $validated['connections'][] = [
                'id' => sanitizeString($conn['id'] ?? '', 50),
                'from' => [
                    'nodeId' => sanitizeString($conn['from']['nodeId'] ?? '', 50),
                    'portId' => sanitizeString($conn['from']['portId'] ?? '', 50),
                    'type' => sanitizeString($conn['from']['type'] ?? '', 20)
                ],
                'to' => [
                    'nodeId' => sanitizeString($conn['to']['nodeId'] ?? '', 50),
                    'portId' => sanitizeString($conn['to']['portId'] ?? '', 50),
                    'type' => sanitizeString($conn['to']['type'] ?? '', 20)
                ]
            ];
        }
    }

    return $validated;
}

/**
 * Log API request
 */
function logApiRequest(?int $userId = null, int $responseCode = 200, int $responseTimeMs = 0): void
{
    try {
        Database::insert('api_logs', [
            'user_id' => $userId,
            'endpoint' => $_SERVER['REQUEST_URI'],
            'method' => $_SERVER['REQUEST_METHOD'],
            'request_data' => json_encode(getJsonInput()),
            'response_code' => $responseCode,
            'response_time_ms' => $responseTimeMs,
            'ip_address' => Auth::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // Silently fail - don't break the request
        error_log('API log error: ' . $e->getMessage());
    }
}

/**
 * Check rate limit
 */
function checkRateLimit(int $userId, int $limit = 100, int $windowSeconds = 60): bool
{
    $key = "rate_limit_{$userId}";
    $now = time();

    // Simple in-memory rate limiting using session
    Auth::initSession();

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    if ($_SESSION[$key]['reset'] < $now) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    $_SESSION[$key]['count']++;

    if ($_SESSION[$key]['count'] > $limit) {
        return false;
    }

    return true;
}

/**
 * Make external HTTP request
 */
function httpRequest(string $url, array $options = []): array
{
    $method = strtoupper($options['method'] ?? 'GET');
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    $timeout = $options['timeout'] ?? 30;

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_map(
                fn($k, $v) => "$k: $v",
                array_keys($headers),
                array_values($headers)
            ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    if ($body !== null) {
        if (is_array($body)) {
            $body = json_encode($body);
            $headers['Content-Type'] = 'application/json';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        return [
            'success' => false,
            'error' => $error,
            'httpCode' => 0
        ];
    }

    $data = json_decode($response, true);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data' => $data ?? $response,
        'raw' => $response
    ];
}