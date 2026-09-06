<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logger
 *
 * Minimal, dependency-free file logger. Writes to logs/bot.log using
 * LOCK_EX so concurrent cron runs don't corrupt the file. Logging must
 * NEVER throw — a broken logger should never crash the bot.
 *
 * Respects a minimum LOG_LEVEL (debug < info < warning < error) so
 * production deployments can set LOG_LEVEL=info or LOG_LEVEL=warning to
 * keep the log file small, while debugging locally with LOG_LEVEL=debug.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    private string $logFile;
    private int $minLevel;

    public function __construct(string $logFile, string $minLevel = 'info')
    {
        $this->logFile = $logFile;
        $this->minLevel = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['info'];
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        try {
            if ((self::LEVELS[$level] ?? 1) < $this->minLevel) {
                return;
            }

            $dir = dirname($this->logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $line = sprintf(
                '[%s] %s: %s%s%s',
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $message,
                $context !== [] ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '',
                PHP_EOL
            );

            @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging must never break the application. Silently ignore.
        }
    }
}
