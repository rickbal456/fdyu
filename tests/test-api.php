<?php
/**
 * AIKAFLOW - API Test Script
 * 
 * Run: php tests/test-api.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$baseUrl = APP_URL . '/api';
$testResults = [];
$passed = 0;
$failed = 0;

echo "\n=== AIKAFLOW API Tests ===\n\n";

// ============================================
// Helper Functions
// ============================================

function makeRequest(string $url, string $method = 'GET', array $data = [], array $headers = []): array
{
    $ch = curl_init();

    $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    $headers = array_merge($defaultHeaders, $headers);

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method
    ];

    if ($method !== 'GET' && !empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

function test(string $name, callable $testFn): void
{
    global $passed, $failed;

    echo "Testing: {$name}... ";

    try {
        $result = $testFn();
        if ($result === true) {
            echo "✓ PASSED\n";
            $passed++;
        } else {
            echo "✗ FAILED: {$result}\n";
            $failed++;
        }
    } catch (Exception $e) {
        echo "✗ ERROR: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEquals($expected, $actual, string $message = ''): bool|string
{
    if ($expected === $actual) {
        return true;
    }
    return $message ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual);
}

function assertTrue($value, string $message = ''): bool|string
{
    if ($value === true) {
        return true;
    }
    return $message ?: "Expected true but got " . json_encode($value);
}

function assertNotEmpty($value, string $message = ''): bool|string
{
    if (!empty($value)) {
        return true;
    }
    return $message ?: "Expected non-empty value";
}

// ============================================
// Tests
// ============================================

// Test 1: Ping endpoint
test('Ping endpoint', function () use ($baseUrl) {
    $response = makeRequest("{$baseUrl}/ping.php");

    if (!$response['success']) {
        return "Request failed with code {$response['httpCode']}";
    }

    return assertTrue($response['data']['success'] ?? false, "Ping response should be successful");
});

// Test 2: Status endpoint
test('Status endpoint', function () use ($baseUrl) {
    $response = makeRequest("{$baseUrl}/status.php");

    if (!$response['success']) {
        return "Request failed with code {$response['httpCode']}";
    }

    $data = $response['data'];

    if (!isset($data['services']['database'])) {
        return "Database status not found in response";
    }

    return assertEquals('healthy', $data['services']['database']['status'], "Database should be healthy");
});

// Test 3: Database connection
test('Database connection', function () {
    try {
        $result = Database::fetchOne("SELECT 1 as test");
        return assertEquals(1, (int) $result['test']);
    } catch (Exception $e) {
        return "Database error: {$e->getMessage()}";
    }
});

// Test 4: User registration
$testEmail = 'test_' . time() . '@example.com';
$testUsername = 'test_' . time();
$testPassword = 'TestPassword123!';
$testUserId = null;

test('User registration', function () use ($baseUrl, $testEmail, $testUsername, $testPassword, &$testUserId) {
    $response = makeRequest("{$baseUrl}/auth/register.php", 'POST', [
        'email' => $testEmail,
        'username' => $testUsername,
        'password' => $testPassword
    ]);

    if (!$response['success'] && $response['httpCode'] !== 201) {
        return "Registration failed: " . ($response['data']['error'] ?? 'Unknown error');
    }

    $testUserId = $response['data']['user_id'] ?? null;

    return assertTrue($response['data']['success'] ?? false, "Registration should succeed");
});

// Test 5: User login
$sessionCookie = null;

test('User login', function () use ($baseUrl, $testEmail, $testPassword, &$sessionCookie) {
    $ch = curl_init("{$baseUrl}/auth/login.php");

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'email' => $testEmail,
            'password' => $testPassword
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    // Extract session cookie
    if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $matches)) {
        $sessionCookie = $matches[1];
    }

    $data = json_decode($body, true);

    return assertTrue($data['success'] ?? false, "Login should succeed");
});

// Test 6: Auth check
test('Auth check (me endpoint)', function () use ($baseUrl, $sessionCookie) {
    $response = makeRequest(
        "{$baseUrl}/auth/me.php",
        'GET',
        [],
        ["Cookie: {$sessionCookie}"]
    );

    return assertTrue($response['data']['authenticated'] ?? false, "User should be authenticated");
});

// Test 7: Create workflow
$testWorkflowId = null;

test('Create workflow', function () use ($baseUrl, $sessionCookie, &$testWorkflowId) {
    $response = makeRequest(
        "{$baseUrl}/workflows/save.php",
        'POST',
        [
            'name' => 'Test Workflow',
            'description' => 'A test workflow',
            'data' => [
                'nodes' => [
                    [
                        'id' => 'node_1',
                        'type' => 'text-input',
                        'position' => ['x' => 100, 'y' => 100],
                        'data' => ['text' => 'Hello World']
                    ]
                ],
                'connections' => [],
                'canvas' => ['pan' => ['x' => 0, 'y' => 0], 'zoom' => 1]
            ]
        ],
        ["Cookie: {$sessionCookie}"]
    );

    if (!$response['success']) {
        return "Create workflow failed: " . ($response['data']['error'] ?? 'Unknown error');
    }

    $testWorkflowId = $response['data']['workflowId'] ?? null;

    return assertNotEmpty($testWorkflowId, "Workflow ID should be returned");
});

// Test 8: Get workflow
test('Get workflow', function () use ($baseUrl, $sessionCookie, $testWorkflowId) {
    if (!$testWorkflowId) {
        return "No workflow ID from previous test";
    }

    $response = makeRequest(
        "{$baseUrl}/workflows/get.php?id={$testWorkflowId}",
        'GET',
        [],
        ["Cookie: {$sessionCookie}"]
    );

    if (!$response['success']) {
        return "Get workflow failed: " . ($response['data']['error'] ?? 'Unknown error');
    }

    return assertEquals('Test Workflow', $response['data']['workflow']['workflow']['name'] ?? '');
});

// Test 9: List workflows
test('List workflows', function () use ($baseUrl, $sessionCookie) {
    $response = makeRequest(
        "{$baseUrl}/workflows/list.php",
        'GET',
        [],
        ["Cookie: {$sessionCookie}"]
    );

    if (!$response['success']) {
        return "List workflows failed: " . ($response['data']['error'] ?? 'Unknown error');
    }

    return assertTrue(isset($response['data']['workflows']), "Workflows list should be returned");
});

// Test 10: Delete workflow
test('Delete workflow', function () use ($baseUrl, $sessionCookie, $testWorkflowId) {
    if (!$testWorkflowId) {
        return "No workflow ID from previous test";
    }

    $response = makeRequest(
        "{$baseUrl}/workflows/delete.php?id={$testWorkflowId}",
        'DELETE',
        [],
        ["Cookie: {$sessionCookie}"]
    );

    return assertTrue($response['data']['success'] ?? false, "Delete should succeed");
});

// Test 11: Logout
test('Logout', function () use ($baseUrl, $sessionCookie) {
    $response = makeRequest(
        "{$baseUrl}/auth/logout.php",
        'POST',
        [],
        ["Cookie: {$sessionCookie}"]
    );

    return assertTrue($response['data']['success'] ?? false, "Logout should succeed");
});

// Cleanup: Delete test user
test('Cleanup test user', function () use ($testEmail) {
    try {
        Database::delete('users', 'email = :email', ['email' => $testEmail]);
        return true;
    } catch (Exception $e) {
        return "Cleanup failed: {$e->getMessage()}";
    }
});

// ============================================
// Summary
// ============================================

echo "\n=== Test Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total:  " . ($passed + $failed) . "\n";

if ($failed > 0) {
    echo "\n⚠ Some tests failed!\n";
    exit(1);
} else {
    echo "\n✓ All tests passed!\n";
    exit(0);
}