<?php
/**
 * AIKAFLOW API - Text Enhancement via LLM API
 * 
 * Proxies text enhancement requests to LLM API provider.
 * API key is read from site_settings (admin-configured), never exposed to browser.
 * 
 * POST /api/ai/enhance.php
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $input = getJsonInput();

    $text = trim($input['text'] ?? '');
    $systemPromptId = $input['systemPromptId'] ?? null;
    $customPrompt = trim($input['customPrompt'] ?? '');

    if (empty($text)) {
        errorResponse('Text is required');
    }

    // Get LLM API key from site_settings
    $result = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'integration_keys'"
    );

    $apiKey = '';
    if ($result && $result['setting_value']) {
        $keys = json_decode($result['setting_value'], true);
        $apiKey = $keys['llm'] ?? '';
    }

    if (empty($apiKey)) {
        errorResponse('LLM API key not configured. Please configure it in Administration → Integrations.');
    }

    // Get LLM settings (model, system prompts)
    $llmSettings = Database::fetchOne(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'llm_settings'"
    );

    $model = 'openai/gpt-4o-mini';
    $systemPrompts = [];

    if ($llmSettings && $llmSettings['setting_value']) {
        $settings = json_decode($llmSettings['setting_value'], true);
        $model = $settings['model'] ?? 'openai/gpt-4o-mini';
        $systemPrompts = $settings['systemPrompts'] ?? [];
    }

    // Determine the system prompt to use
    // Priority: 1) Custom prompt from user, 2) System prompt by ID, 3) Default
    $systemPrompt = 'You are a helpful assistant that enhances and improves text prompts for AI image and video generation. Make the prompt more descriptive, detailed, and effective while maintaining the original intent. Return only the enhanced prompt without any explanation.';

    if (!empty($customPrompt)) {
        // User provided their own custom prompt
        $systemPrompt = $customPrompt;
    } elseif ($systemPromptId && !empty($systemPrompts)) {
        // Find the system prompt by ID
        foreach ($systemPrompts as $prompt) {
            if (($prompt['id'] ?? '') === $systemPromptId) {
                $systemPrompt = $prompt['content'] ?? $systemPrompt;
                break;
            }
        }
    }

    // Call LLM API (using OpenRouter-compatible endpoint)
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://fidyu.com'),
            'X-Title: AIKAFLOW'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text]
            ],
            'max_tokens' => 1000
        ]),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $message = $error['error']['message'] ?? 'Failed to enhance text';
        errorResponse($message);
    }

    $data = json_decode($response, true);
    $enhanced = $data['choices'][0]['message']['content'] ?? $text;

    successResponse([
        'enhanced' => $enhanced
    ]);

} catch (Exception $e) {
    error_log('Text enhance error: ' . $e->getMessage());
    errorResponse('Failed to enhance text: ' . $e->getMessage(), 500);
}
