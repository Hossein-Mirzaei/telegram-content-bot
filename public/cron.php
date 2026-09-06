<?php

declare(strict_types=1);

/**
 * public/cron.php
 *
 * Entry point for the hosting cron job. Designed to be called frequently
 * (every 15 minutes, or hourly) — it is safe to call this as often as you
 * like: it only actually generates + publishes a post when
 * Scheduler::shouldPostNow() says at least POST_INTERVAL_HOURS have passed
 * since the last publish, AND acquires a file lock so two overlapping runs
 * can never both publish.
 *
 * Suggested cron entry (every 15 minutes):
 *   php-cli:    * /15 * * * * /usr/bin/php /path/to/bot/public/cron.php >> /path/to/bot/logs/cron_stdout.log 2>&1
 *   URL-based:  * /15 * * * * curl -s "https://hoseiin-28.ir/telegram-content-bot/cron.php?key=YOUR_CRON_SECRET" > /dev/null
 *
 * If your host only supports URL-based cron ("wget"/"curl" jobs), the
 * optional ?key= check below (backed by CRON_SECRET in .env) adds a minimal
 * layer of protection so random visitors can't trigger content generation
 * by hitting the URL.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\App;
use App\Support\Env;
use App\Support\Lock;

// --- Optional protection for URL-based (non-CLI) cron triggers ------------
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $cronSecret = Env::get('CRON_SECRET');
    $providedToken = $_GET['key'] ?? '';
    if ($cronSecret !== null && $cronSecret !== '' && !hash_equals((string) $cronSecret, (string) $providedToken)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$app = App::boot();
$lock = new Lock($app->config['paths']['lock_file'], (int) $app->config['schedule']['lock_stale_after_seconds']);

if (!$lock->acquire()) {
    $app->logger->info('cron.php: another run is already in progress, exiting.');
    if (!$isCli) {
        echo "Already running.\n";
    }
    exit;
}

try {
    $state = $app->scheduler->getState();
    if (!empty($state['auto_publish_paused'])) {
        $app->logger->info('cron.php: auto-publish is paused via /stop, skipping.');
        if (!$isCli) {
            echo "Paused.\n";
        }
        exit;
    }

    if (!$app->scheduler->shouldPostNow()) {
        $app->logger->debug('cron.php: not time to post yet.', [
            'next_post_at' => $app->scheduler->nextPostAt(),
        ]);
        if (!$isCli) {
            echo "Not time yet. Next post at: " . $app->scheduler->nextPostAt() . "\n";
        }
        exit;
    }

    $autoMode = (bool) $app->config['app']['auto_mode'];
    $result = $app->generateAndMaybePublish($autoMode);

    if ($result['success']) {
        $app->logger->info('cron.php: pipeline finished successfully.', [
            'message' => $result['message'],
        ]);
    } else {
        $app->logger->error('cron.php: pipeline failed.', [
            'message' => $result['message'],
        ]);
    }

    if (!$isCli) {
        echo $result['message'] . "\n";
    }
} catch (\Throwable $e) {
    $app->logger->error('cron.php: unhandled exception', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    if (!$isCli) {
        http_response_code(500);
        echo "Internal error.\n";
    }
} finally {
    $lock->release();
}
