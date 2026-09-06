<?php

declare(strict_types=1);

/**
 * public/webhook.php
 *
 * Telegram webhook receiver. Only needed if you want the bot to respond to
 * commands (/status, /generate, ...) in real time instead of / in addition
 * to the cron-based auto-publishing in cron.php.
 *
 * Set this as your webhook URL, e.g.:
 *   https://hoseiin-28.ir/bot/webhook.php
 *
 * Security:
 *   - Telegram calls setWebhook with a "secret_token"; Telegram then sends
 *     that same value back in the "X-Telegram-Bot-Api-Secret-Token" header
 *     on every request. We verify it before processing anything.
 *   - Only POST requests are accepted.
 *   - All administrative commands are additionally restricted to
 *     TELEGRAM_ADMIN_ID inside CommandHandler.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\App;
use App\Telegram\CommandHandler;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$app = App::boot();

$expectedSecret = (string) $app->config['telegram']['webhook_secret'];
$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($expectedSecret === '' || !hash_equals($expectedSecret, (string) $providedSecret)) {
    $app->logger->warning('webhook.php: rejected request with invalid or missing secret token');
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(200); // Acknowledge to Telegram even on empty body; nothing to do.
    exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    $app->logger->warning('webhook.php: received invalid JSON payload');
    http_response_code(200);
    exit;
}

try {
    $handler = new CommandHandler($app);
    $handler->handleUpdate($update);
} catch (\Throwable $e) {
    $app->logger->error('webhook.php: unhandled exception while processing update', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

// Always respond 200 quickly so Telegram doesn't retry/backoff unnecessarily.
http_response_code(200);
echo 'ok';
