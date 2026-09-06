<?php

declare(strict_types=1);

namespace App\AI;

use App\Support\Logger;

/**
 * HuggingFaceService
 *
 * PHP port of the Python snippet:
 *
 *   client = InferenceClient(api_key=os.environ["HF_TOKEN"])
 *   completion = client.chat.completions.create(model=..., messages=[...])
 *
 * The huggingface_hub Python client's chat.completions.create() call is an
 * OpenAI-compatible wrapper around Hugging Face's router endpoint:
 *   POST https://router.huggingface.co/v1/chat/completions
 *
 * This class re-implements that single HTTP call in plain PHP with cURL, so
 * no external SDK or Composer package is required. The API key is injected
 * via the constructor (sourced from config/.env) and is never hardcoded.
 */
final class HuggingFaceService
{
    private string $token;
    private string $model;
    private string $endpoint;
    private int $timeout;
    private int $maxTokens;
    private float $temperature;
    private Logger $logger;

    public function __construct(
        string $token,
        string $model,
        string $endpoint,
        int $timeout,
        int $maxTokens,
        float $temperature,
        Logger $logger
    ) {
        $this->token = $token;
        $this->model = $model;
        $this->endpoint = $endpoint;
        $this->timeout = $timeout;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->logger = $logger;
    }

    /**
     * Run a chat completion.
     *
     * @param array $messages e.g. [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']]
     * @return string|null The assistant's raw text response, or null on failure.
     */
    public function chatCompletion(array $messages): ?string
    {
        if (empty($this->token)) {
            $this->logger->error('HF_TOKEN is not configured');
            return null;
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            $this->logger->error('Hugging Face API cURL error', [
                'curl_errno' => $errNo,
                'curl_error' => $errMsg,
            ]);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('Hugging Face API returned non-2xx status', [
                'http_code' => $httpCode,
                'body' => substr((string) $raw, 0, 800),
            ]);
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->error('Hugging Face API returned invalid JSON', [
                'raw' => substr((string) $raw, 0, 500),
            ]);
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $this->logger->error('Hugging Face API response missing message content', [
                'decoded' => $decoded,
            ]);
            return null;
        }

        return $content;
    }
}
