<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Support\Logger;

/**
 * TelegramService
 *
 * Thin wrapper around the Telegram Bot API using cURL directly (no external
 * SDK dependency). Handles sending posts to the channel and basic bot
 * operations (getMe, setWebhook, deleteWebhook, sendMessage for command
 * replies).
 */
final class TelegramService
{
    private string $token;
    private string $channelId;
    private string $apiBase;
    private string $parseMode;
    private Logger $logger;

    public function __construct(string $token, string $channelId, string $apiBase, string $parseMode, Logger $logger)
    {
        $this->token = $token;
        $this->channelId = $channelId;
        $this->apiBase = rtrim($apiBase, '/');
        $this->parseMode = $parseMode;
        $this->logger = $logger;
    }

    /**
     * Publish a post to the configured channel.
     * Returns the Telegram message_id on success, or null on failure.
     */
    public function publishToChannel(string $text): ?int
    {
        $response = $this->call('sendMessage', [
            'chat_id' => $this->channelId,
            'text' => $text,
            'parse_mode' => $this->parseMode,
            'disable_web_page_preview' => false,
        ]);

        if ($response === null || empty($response['ok'])) {
            $this->logger->error('Failed to publish post to Telegram channel', [
                'response' => $response,
            ]);
            return null;
        }

        return $response['result']['message_id'] ?? null;
    }

    /**
     * Send a private reply to a chat (used for bot commands).
     */
    public function sendMessage(int|string $chatId, string $text, ?array $extra = []): bool
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $this->parseMode,
            'disable_web_page_preview' => true,
        ], $extra ?? []);

        $response = $this->call('sendMessage', $payload);
        return $response !== null && !empty($response['ok']);
    }

    public function getMe(): ?array
    {
        $response = $this->call('getMe', []);
        if ($response === null || empty($response['ok'])) {
            return null;
        }
        return $response['result'] ?? null;
    }

    public function setWebhook(string $url, string $secretToken): bool
    {
        $response = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => json_encode(['message', 'channel_post']),
        ]);
        return $response !== null && !empty($response['ok']);
    }

    public function deleteWebhook(): bool
    {
        $response = $this->call('deleteWebhook', []);
        return $response !== null && !empty($response['ok']);
    }

    public function getWebhookInfo(): ?array
    {
        $response = $this->call('getWebhookInfo', []);
        if ($response === null || empty($response['ok'])) {
            return null;
        }
        return $response['result'] ?? null;
    }

    /**
     * Low-level API call helper. Never throws; returns null on any failure
     * (network error, timeout, invalid JSON, etc.) so callers can handle
     * failures gracefully instead of crashing.
     */
    private function call(string $method, array $params): ?array
    {
        if ($this->token === '' || $this->token === null) {
            $this->logger->error('Telegram bot token is not configured');
            return null;
        }

        $url = $this->apiBase . $this->token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            $this->logger->error('Telegram API cURL error', [
                'method' => $method,
                'curl_errno' => $errNo,
                'curl_error' => $errMsg,
            ]);
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->error('Telegram API returned invalid JSON', [
                'method' => $method,
                'raw' => substr((string) $raw, 0, 500),
            ]);
            return null;
        }

        return $decoded;
    }
}
