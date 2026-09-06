<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Env
 *
 * A tiny, dependency-free .env file loader. Reads KEY=VALUE lines and exposes
 * them via getenv()/$_ENV as well as through Env::get(). Lines starting with
 * # are treated as comments. Values may be wrapped in quotes.
 *
 * We intentionally avoid pulling in vlucas/phpdotenv to keep the project
 * free of Composer dependencies so it can run on any shared host, even
 * without SSH/Composer access.
 */
final class Env
{
    /** @var array<string,bool> already-loaded file paths, so calling load() twice on the same file is a no-op */
    private static array $loadedFiles = [];

    public static function load(string $path): void
    {
        if (isset(self::$loadedFiles[$path])) {
            return;
        }
        self::$loadedFiles[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            // No .env file found. This is not fatal here - real deployments
            // may set environment variables directly at the web-server /
            // php-fpm level instead of using a .env file. We simply proceed
            // and rely on getenv() below.
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes if present.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key === '') {
                continue;
            }

            // Do not overwrite variables that were already set at the OS/webserver level.
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
            $_ENV[$key] = $_ENV[$key] ?? $value;
        }
    }

    /**
     * Loads an optional "secrets" file that can live completely outside the
     * project (e.g. BOT_SECRETS_FILE=/home/user/private/secrets.php).
     * Two formats are supported:
     *   - a ".php" file that `return`s an associative array of KEY => VALUE
     *   - a plain ".env"-style file (same format as the main .env)
     * Values already set (from the real environment or the main .env) are
     * never overwritten, so the main .env always wins over this file, and
     * real server-level env vars always win over both.
     */
    public static function loadSecretsFile(?string $path): void
    {
        if ($path === null || $path === '' || !is_file($path) || !is_readable($path)) {
            return;
        }

        if (str_ends_with(strtolower($path), '.php')) {
            if (isset(self::$loadedFiles[$path])) {
                return;
            }
            self::$loadedFiles[$path] = true;

            $data = include $path;
            if (!is_array($data)) {
                return;
            }
            foreach ($data as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (getenv($key) === false) {
                    putenv($key . '=' . (string) $value);
                }
                $_ENV[$key] = $_ENV[$key] ?? (string) $value;
            }
            return;
        }

        self::load($path);
    }

    /**
     * Parses a comma-separated env value into a trimmed, non-empty string array.
     */
    public static function list(string $key, string $default = ''): array
    {
        $raw = (string) self::get($key, $default);
        if (trim($raw) === '') {
            return [];
        }
        $items = array_map('trim', explode(',', $raw));
        return array_values(array_filter($items, static fn ($v) => $v !== ''));
    }

    /**
     * Parses a pipe-separated env value into a trimmed, non-empty string array.
     * Used for CONTENT_BANNED_PHRASES where phrases themselves may contain commas.
     */
    public static function pipeList(string $key, string $default = ''): array
    {
        $raw = (string) self::get($key, $default);
        if (trim($raw) === '') {
            return [];
        }
        $items = array_map('trim', explode('|', $raw));
        return array_values(array_filter($items, static fn ($v) => $v !== ''));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (float) $value;
    }
}
