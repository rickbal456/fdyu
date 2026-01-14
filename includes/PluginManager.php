<?php

class PluginManager
{
    private static $plugins = [];
    private static $nodeDefinitions = [];
    private static $storagePlugins = [];

    /**
     * Load all plugins and their node definitions
     */
    public static function loadPlugins()
    {
        if (!empty(self::$plugins))
            return;

        $pluginDir = __DIR__ . '/../plugins';
        if (!is_dir($pluginDir))
            return;

        $dirs = glob($pluginDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $jsonFile = $dir . '/plugin.json';
            if (file_exists($jsonFile)) {
                $pluginData = json_decode(file_get_contents($jsonFile), true);
                if ($pluginData && isset($pluginData['enabled']) && $pluginData['enabled']) {
                    self::$plugins[$pluginData['id']] = $pluginData;

                    // Load node types for node plugins
                    if (isset($pluginData['nodeTypes'])) {
                        foreach ($pluginData['nodeTypes'] as $nodeType) {
                            self::$nodeDefinitions[$nodeType] = $pluginData;
                        }
                    }

                    // Load storage plugins
                    if (($pluginData['type'] ?? 'node') === 'storage') {
                        $handlerFile = $dir . '/handler.php';
                        if (file_exists($handlerFile)) {
                            require_once $handlerFile;
                        }
                        self::$storagePlugins[$pluginData['id']] = $pluginData;
                    }
                }
            }
        }
    }

    /**
     * Check if a plugin is enabled
     */
    public static function isPluginEnabled($pluginId)
    {
        self::loadPlugins();
        return isset(self::$plugins[$pluginId]);
    }

    /**
     * Get all enabled plugins
     * @return array
     */
    public static function getEnabledPlugins(): array
    {
        self::loadPlugins();
        return self::$plugins;
    }

    /**
     * Get storage plugin by ID
     */
    public static function getStoragePlugin($pluginId)
    {
        self::loadPlugins();
        return self::$storagePlugins[$pluginId] ?? null;
    }

    /**
     * Get all storage plugins
     */
    public static function getStoragePlugins()
    {
        self::loadPlugins();
        return self::$storagePlugins;
    }


    /**
     * Get definition for a node type
     */
    public static function getNodeDefinition($nodeType)
    {
        self::loadPlugins();
        return self::$nodeDefinitions[$nodeType] ?? null;
    }

    /**
     * Get all node definitions
     */
    public static function getAllNodeDefinitions()
    {
        self::loadPlugins();
        return self::$nodeDefinitions;
    }

    /**
     * Load admin-configured API key from database
     * This is called server-side only - keys are never exposed to the browser
     * Integration keys are stored at the SITE level in site_settings table
     */
    private static function loadAdminApiKey($provider, $userId = null)
    {
        // Load from site_settings table (site-level config by admin)
        if (class_exists('Database')) {
            try {
                $setting = Database::fetchOne(
                    "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
                );

                if ($setting && isset($setting['setting_value']) && $setting['setting_value']) {
                    $keys = json_decode($setting['setting_value'], true);
                    if (is_array($keys) && !empty($keys[$provider])) {
                        return $keys[$provider];
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to load admin API key: ' . $e->getMessage());
            }
        }

        return '';
    }


    /**
     * Execute a plugin node
     */
    public static function executeNode($nodeType, $inputData)
    {
        // Debug logging
        $debugLog = dirname(__DIR__) . '/logs/worker_debug.log';
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($debugLog, "[$ts] [PluginManager] executeNode: $nodeType\n", FILE_APPEND);

        $definition = self::getNodeDefinition($nodeType);
        if (!$definition) {
            @file_put_contents($debugLog, "[$ts] [PluginManager] No definition found for: $nodeType\n", FILE_APPEND);
            return ['success' => false, 'error' => "Unknown node type: $nodeType"];
        }

        $execType = $definition['executionType'] ?? 'unknown';
        @file_put_contents($debugLog, "[$ts] [PluginManager] executionType: $execType\n", FILE_APPEND);

        if ($definition['executionType'] === 'local') {
            @file_put_contents($debugLog, "[$ts] [PluginManager] Executing as LOCAL node\n", FILE_APPEND);
            return self::executeLocalNode($nodeType, $inputData, $definition);
        } elseif ($definition['executionType'] === 'api') {
            @file_put_contents($debugLog, "[$ts] [PluginManager] Executing as API node\n", FILE_APPEND);
            return self::executeApiNode($nodeType, $inputData, $definition);
        }

        @file_put_contents($debugLog, "[$ts] [PluginManager] Unsupported execution type: $execType\n", FILE_APPEND);
        return ['success' => false, 'error' => "Unsupported execution type"];
    }

    /**
     * Execute local node logic
     */
    private static function executeLocalNode($nodeType, $inputData, $definition)
    {
        // Handle input nodes common logic
        if (in_array($nodeType, ['image-input', 'video-input', 'audio-input'])) {
            $url = $inputData['url'] ?? null;
            $isBase64 = false;

            // Check for uploaded file with existing URL (already uploaded to CDN)
            if (($inputData['source'] ?? '') === 'upload' && isset($inputData['file']['url']) && !empty($inputData['file']['url'])) {
                $url = $inputData['file']['url'];
            }
            // Check for dataUrl that needs uploading
            elseif (($inputData['source'] ?? '') === 'upload' && isset($inputData['file']['dataUrl'])) {
                // Needs external function or helper to upload
                if (function_exists('uploadDataUrlToCDN')) {
                    $url = uploadDataUrlToCDN($inputData['file']['dataUrl'], $inputData['file']['name'] ?? 'file');
                }

                // Fallback for image input to base64 if upload fails/not available
                if (!$url && $nodeType === 'image-input') {
                    $url = $inputData['file']['dataUrl'];
                    $isBase64 = true;
                } elseif (!$url) {
                    return [
                        'success' => false,
                        'error' => 'Upload failed. Please configure CDN or provide a URL.'
                    ];
                }
            }

            // Map output key based on type
            $outputKey = str_replace('-input', '', $nodeType);

            return [
                'success' => true,
                'resultUrl' => $url,
                'output' => [
                    $outputKey => $url,
                    'isBase64' => $isBase64
                ]
            ];
        }

        if ($nodeType === 'text-input') {
            return [
                'success' => true,
                'output' => ['text' => $inputData['text'] ?? '']
            ];
        }

        return ['success' => true, 'output' => $inputData];
    }

    /**
     * Execute API node using mapping
     */
    private static function executeApiNode($nodeType, $inputData, $definition)
    {
        $debugLog = dirname(__DIR__) . '/logs/worker_debug.log';
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($debugLog, "[$ts] [executeApiNode] START for: $nodeType\n", FILE_APPEND);

        if (!isset($definition['apiConfig']) || !isset($definition['apiMapping'])) {
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Missing API config\n", FILE_APPEND);
            return ['success' => false, 'error' => "Missing API configuration for $nodeType"];
        }

        $apiConfig = $definition['apiConfig'];
        $mapping = $definition['apiMapping'];
        $provider = $apiConfig['provider'] ?? 'custom';
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Provider: $provider\n", FILE_APPEND);

        // Get API key - either from user input or from admin configuration
        $apiKey = $inputData['apiKey'] ?? '';
        @file_put_contents($debugLog, "[$ts] [executeApiNode] User apiKey: " . (empty($apiKey) ? '(empty)' : '(set)') . "\n", FILE_APPEND);

        // Fallback to Admin Key if user key is empty
        if (empty($apiKey)) {
            $adminKey = self::loadAdminApiKey($provider, $inputData['_user_id'] ?? null);
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Admin key lookup for '$provider': " . (empty($adminKey) ? '(NOT FOUND)' : '(found)') . "\n", FILE_APPEND);
            if ($adminKey) {
                $apiKey = $adminKey;
            }
        }

        // Update inputData with the resolved API key
        $inputData['apiKey'] = $apiKey;
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Final apiKey: " . (empty($apiKey) ? '(EMPTY - will fail)' : '(set)') . "\n", FILE_APPEND);

        // Check rate limit if ApiRateLimiter is available and we have an API key
        if (!empty($apiKey) && class_exists('ApiRateLimiter')) {
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Checking rate limit...\n", FILE_APPEND);
            if (!ApiRateLimiter::canProceed($provider, $apiKey)) {
                // At rate limit - queue the request
                @file_put_contents($debugLog, "[$ts] [executeApiNode] Rate limited - queueing request\n", FILE_APPEND);
                $queueId = ApiRateLimiter::enqueue(
                    $provider,
                    $apiKey,
                    $inputData['_workflow_run_id'] ?? null,
                    $inputData['_node_id'] ?? '',
                    $nodeType,
                    $inputData
                );

                return [
                    'success' => true,
                    'status' => 'queued',
                    'queueId' => $queueId,
                    'message' => 'Request queued due to API rate limits. It will be processed automatically when a slot becomes available.'
                ];
            }
        }

        // Inject webhook URL
        if (!isset($inputData['webhook_url'])) {
            $inputData['webhook_url'] = defined('APP_URL') ? APP_URL . '/api/webhook.php?source=' . $provider : '';
        }
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Webhook URL: " . ($inputData['webhook_url'] ?? 'none') . "\n", FILE_APPEND);

        // Map common input port names to expected field names
        // This handles cases where:
        // - Connection uses 'text' port -> maps to 'prompt' field
        // - Connection uses 'image' port -> already matches 'image' field
        if (isset($inputData['text']) && !isset($inputData['prompt'])) {
            $inputData['prompt'] = $inputData['text'];
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Mapped 'text' input to 'prompt' field\n", FILE_APPEND);
        }

        // Prepare request body using mapping
        $requestBody = self::mapData($mapping['request'], $inputData);
        $requestBody = self::processSpecialValues($requestBody);
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Request body prepared, keys: " . implode(', ', array_keys($requestBody)) . "\n", FILE_APPEND);

        // Acquire rate limit slot before making the API call
        $taskId = null;
        if (!empty($apiKey) && class_exists('ApiRateLimiter')) {
            // Generate a temporary task ID (will be replaced by actual task ID from API response)
            $taskId = 'pending_' . uniqid();
            ApiRateLimiter::acquireSlot(
                $provider,
                $apiKey,
                $taskId,
                $inputData['_workflow_run_id'] ?? null,
                $inputData['_node_id'] ?? null
            );
        }

        // Call generic API
        $endpoint = $apiConfig['endpoint'] ?? 'unknown';
        @file_put_contents($debugLog, "[$ts] [executeApiNode] CALLING API: $endpoint\n", FILE_APPEND);
        $response = self::callGenericApi($apiConfig, $requestBody);
        @file_put_contents($debugLog, "[$ts] [executeApiNode] API Response: success=" . ($response['success'] ? 'true' : 'false') . ", error=" . ($response['error'] ?? 'none') . "\n", FILE_APPEND);

        if (!$response['success']) {
            // Release slot on failure
            if ($taskId && class_exists('ApiRateLimiter')) {
                ApiRateLimiter::releaseSlot($provider, $taskId);
            }
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Returning failure\n", FILE_APPEND);
            return $response;
        }

        $apiResponse = $response['data'];
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Raw API response: " . json_encode($apiResponse) . "\n", FILE_APPEND);

        // Map response
        $result = [];
        if (isset($mapping['response'])) {
            $result = self::mapResponse($mapping['response'], $apiResponse);
            @file_put_contents($debugLog, "[$ts] [executeApiNode] Mapped result: " . json_encode($result) . "\n", FILE_APPEND);
        }

        $output = [];
        if (isset($mapping['result'])) {
            $output = self::mapResponse($mapping['result'], $apiResponse);
        }

        // Update the rate limit slot with the actual task ID from API response
        $actualTaskId = $result['taskId'] ?? null;
        @file_put_contents($debugLog, "[$ts] [executeApiNode] Extracted taskId: " . ($actualTaskId ?? 'NULL') . "\n", FILE_APPEND);
        if ($taskId && $actualTaskId && class_exists('ApiRateLimiter')) {
            // Release the temporary slot and create one with the real task ID
            ApiRateLimiter::releaseSlot($provider, $taskId);
            ApiRateLimiter::acquireSlot(
                $provider,
                $apiKey,
                $actualTaskId,
                $inputData['_workflow_run_id'] ?? null,
                $inputData['_node_id'] ?? null
            );
        }

        return [
            'success' => true,
            'taskId' => $actualTaskId,
            'resultUrl' => $output['resultUrl'] ?? null,
            'output' => $output
        ];
    }

    /**
     * Process special values in request body
     */
    private static function processSpecialValues($data)
    {
        if (!is_array($data))
            return $data;
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::processSpecialValues($value);
            }
        }
        return $data;
    }

    /**
     * Call generic API based on config
     */
    private static function callGenericApi($apiConfig, $data)
    {
        $provider = $apiConfig['provider'];

        // Resolve credentials and base URL
        $baseUrl = '';
        $apiKey = '';
        $headerName = 'Authorization';
        $headerValuePrefix = 'Bearer ';

        switch ($provider) {
            case 'jsoncut':
                $baseUrl = defined('JSONCUT_API_URL') ? JSONCUT_API_URL : 'https://api.jsoncut.com';
                break;
            case 'runninghub':
                $baseUrl = defined('RUNNINGHUB_API_URL') ? RUNNINGHUB_API_URL : 'https://api.runninghub.ai';
                break;
            case 'kie':
                $baseUrl = defined('KIE_API_URL') ? KIE_API_URL : 'https://api.kie.ai';
                break;
            case 'postforme':
                $baseUrl = defined('POSTFORME_API_URL') ? POSTFORME_API_URL : 'https://api.postforme.dev';
                break;
            default:
                return ['success' => false, 'error' => "Unknown provider: $provider"];
        }

        // API key must be provided via node configuration (inputData['apiKey'])
        $apiKey = $data['apiKey'] ?? '';
        if (empty($apiKey)) {
            return ['success' => false, 'error' => "API key not provided. Please configure it in the Integration tab or node settings."];
        }

        $endpoint = $apiConfig['endpoint'] ?? '';

        // Fallback or legacy operation to endpoint mapping
        if (empty($endpoint) && isset($apiConfig['operation'])) {
            // Basic mapping for known operations if endpoint is missing
            $endpoint = '/v1/' . $apiConfig['operation'];
        }

        // Handle absolute URL in endpoint
        if (preg_match('/^https?:\/\//', $endpoint)) {
            $url = $endpoint;
        } else {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        }

        // Add webhook URL if not present
        if (!isset($data['webhook_url'])) {
            $data['webhook_url'] = APP_URL . '/api/webhook.php?source=' . $provider;
        }

        // Use httpRequest helper if available
        if (function_exists('httpRequest')) {
            $response = httpRequest($url, [
                'method' => 'POST',
                'headers' => [
                    $headerName => $headerValuePrefix . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'body' => $data,
                'timeout' => 120
            ]);

            // Check for API-level errors (provider-specific)
            if ($response['success'] && isset($response['data'])) {
                $apiData = $response['data'];

                // RunningHub returns code != 0 on error
                if ($provider === 'runninghub' && isset($apiData['code']) && $apiData['code'] != 0) {
                    $errorMsg = $apiData['msg'] ?? 'API error';
                    // Try to extract a cleaner error message
                    if (is_string($errorMsg) && strpos($errorMsg, 'required_input_missing') !== false) {
                        $errorMsg = 'Required input is missing. Please check all inputs are connected.';
                    }
                    return [
                        'success' => false,
                        'error' => "RunningHub API error (code {$apiData['code']}): $errorMsg",
                        'data' => $apiData
                    ];
                }
            }

            return $response;
        }

        return ['success' => false, 'error' => 'HTTP client not available'];
    }


    /**
     * Map data using Mustache-like syntax {{var}} or JSONPath-like $.var
     */
    private static function mapData($template, $data)
    {
        $result = [];
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::mapData($value, $data);
            } else {
                // Check for {{variable}} syntax
                if (preg_match('/\{\{(.*?)\}\}/', $value, $matches)) {
                    $path = trim($matches[1]);
                    $val = self::getValueByPath($data, $path);
                    // If the value is the only thing, use the type, otherwise replace string
                    if ($value === $matches[0]) {
                        $result[$key] = $val;
                    } else {
                        $result[$key] = str_replace($matches[0], $val, $value);
                    }
                } else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * Map response using JSONPath-like syntax $.var
     */
    private static function mapResponse($template, $data)
    {
        $result = [];
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::mapResponse($value, $data);
            } else {
                if (strpos($value, '$.') === 0) {
                    $path = substr($value, 2); // Remove $.
                    // Use simple path traversal without stripping prefixes
                    $result[$key] = self::getValueByPathSimple($data, $path);
                } else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * Simple dot-notation path traversal (no prefix stripping)
     */
    private static function getValueByPathSimple($data, $path)
    {
        $parts = explode('.', $path);
        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * Get value from array using dot notation
     * Supports inputs.var and data.var inputs
     */
    private static function getValueByPath($data, $path)
    {
        // Handle special prefixes for inputs mapping
        // inputs.x -> $data['x']  (legacy/simplification)
        // data.x -> $data['x']

        // But actually the inputData passed to executeNode usually has flattened structure or specific keys
        // Let's assume inputData contains merged inputs and data

        $parts = explode('.', $path);

        // If first part is 'inputs' or 'data', skip it if the root data is merged
        if ($parts[0] === 'inputs' || $parts[0] === 'data') {
            array_shift($parts);
        }

        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null; // Or empty string?
            }
        }

        return $current;
    }
}
