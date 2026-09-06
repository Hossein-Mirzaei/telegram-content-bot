<?php

declare(strict_types=1);

/**
 * tools/setup_webhook.php
 *
 * One-time CLI helper to register (or remove) the Telegram webhook using
 * TELEGRAM_WEBHOOK_URL + TELEGRAM_WEBHOOK_SECRET from .env, so you don't
 * have to build the setWebhook URL by hand.
 *
 * This file lives OUTSIDE public/ on purpose and refuses to run over HTTP —
 * it touches your bot token, so it should only ever be run from the shell:
 *
 *   php tools/setup_webhook.php set      # register the webhook
 *   php tools/setup_webhook.php info     # show current webhook info
 *   php tools/setup_webhook.php delete   # remove the webhook (fall back to cron-only mode)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../bootstrap.php';

use App\App;

$action = $argv[1] ?? 'info';
$app = App::boot();

$webhookUrl = (string) $app->config['telegram']['webhook_url'];
$webhookSecret = (string) $app->config['telegram']['webhook_secret'];

switch ($action) {
    case 'set':
        if ($webhookUrl === '' || $webhookSecret === '') {
            fwrite(STDERR, "TELEGRAM_WEBHOOK_URL and/or TELEGRAM_WEBHOOK_SECRET are not set in .env.\n");
            exit(1);
        }
        $ok = $app->telegram->setWebhook($webhookUrl, $webhookSecret);
        echo $ok ? "Webhook registered: {$webhookUrl}\n" : "Failed to register webhook. Check logs/bot.log.\n";
        exit($ok ? 0 : 1);

    case 'delete':
        $ok = $app->telegram->deleteWebhook();
        echo $ok ? "Webhook removed. The bot will only auto-publish via cron now.\n" : "Failed to remove webhook.\n";
        exit($ok ? 0 : 1);

    case 'info':
    default:
        $info = $app->telegram->getWebhookInfo();
        if ($info === null) {
            fwrite(STDERR, "Could not fetch webhook info (check TELEGRAM_BOT_TOKEN).\n");
            exit(1);
        }
        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
}
