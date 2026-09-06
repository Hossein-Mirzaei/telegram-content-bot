<?php

/**
 * bootstrap.php
 *
 * Central bootstrap file. Loaded by every entry point (index.php, cron.php, webhook.php).
 * Responsibilities:
 *   - Define filesystem path constants (with optional overrides via env vars,
 *     so data/logs/storage can live outside public_html even if the rest of
 *     the project can't be moved)
 *   - Register a lightweight PSR-4-ish autoloader for the App\ namespace (composer optional)
 *   - Load environment variables from .env (and an optional external secrets file)
 *   - Set the default timezone
 *   - Set strict error reporting to a log file, never to screen
 *
 * This file must be required with require_once from every entry script:
 *   require_once __DIR__ . '/../bootstrap.php';   // adjust relative path as needed
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 1. Fixed path constants (code location - these never move)
// ---------------------------------------------------------------------
define('ROOT_PATH', __DIR__);
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');

// ---------------------------------------------------------------------
// 2. Autoloader
// ---------------------------------------------------------------------
// Prefer composer's autoloader if it exists (run `composer install` / `composer dump-autoload`).
// Otherwise fall back to a manual PSR-4 style autoloader so the project also
// works on hosts where Composer is not available at all.
if (is_file(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = SRC_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// ---------------------------------------------------------------------
// 3. Environment variables (.env + optional external secrets file)
// ---------------------------------------------------------------------
// We deliberately avoid a Composer dependency (vlucas/phpdotenv) to keep the
// project dependency-free and runnable on any shared host. Env.php implements
// a small, safe .env parser.
require_once SRC_PATH . '/Support/Env.php';
App\Support\Env::load(ROOT_PATH . '/.env');

// Optional: a secrets file living completely outside the project
// (BOT_SECRETS_FILE=/home/user/private/secrets.php or .env-style file).
// Values here NEVER override anything already set by the main .env or by
// real server-level environment variables.
App\Support\Env::loadSecretsFile(App\Support\Env::get('BOT_SECRETS_FILE'));

// ---------------------------------------------------------------------
// 4. Movable runtime directories (data / logs / storage)
// ---------------------------------------------------------------------
// By default these live inside the project itself, protected by .htaccess.
// If your host lets you point them somewhere entirely outside public_html
// (recommended), set BOT_DATA_DIR / BOT_LOG_DIR / BOT_STORAGE_DIR in .env.
define('DATA_PATH', rtrim((string) App\Support\Env::get('BOT_DATA_DIR', ROOT_PATH . '/data'), '/'));
define('LOG_PATH', rtrim((string) App\Support\Env::get('BOT_LOG_DIR', ROOT_PATH . '/logs'), '/'));
define('STORAGE_PATH', rtrim((string) App\Support\Env::get('BOT_STORAGE_DIR', ROOT_PATH . '/storage'), '/'));

foreach ([DATA_PATH, LOG_PATH, STORAGE_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ---------------------------------------------------------------------
// 5. Error handling
// ---------------------------------------------------------------------
// Never display raw errors to the outside world (security). Everything is
// logged to logs/php_errors.log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php_errors.log');
error_reporting(E_ALL);

// ---------------------------------------------------------------------
// 6. Timezone
// ---------------------------------------------------------------------
date_default_timezone_set(App\Support\Env::get('APP_TIMEZONE', 'Asia/Tehran'));

// ---------------------------------------------------------------------
// 7. Config loader (returns an array of resolved settings)
// ---------------------------------------------------------------------
require_once CONFIG_PATH . '/config.php';
