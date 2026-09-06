<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Storage\JsonStorage;

/**
 * Scheduler
 *
 * Decides whether enough time has passed since the last published post
 * (default: 8 hours) so that cron.php can be invoked frequently (e.g. every
 * 15 minutes) without ever double-posting. State is persisted in
 * data/state.json:
 *
 *   {
 *     "last_post_at": "2026-09-04 08:00:00",
 *     "next_retry_at": null,
 *     "auto_publish_paused": false,
 *     "last_error": null
 *   }
 *
 * On generation/publish failure, a short backoff (POST_RETRY_AFTER_MINUTES)
 * is recorded so cron.php doesn't call the AI/search APIs again on every
 * single 15-minute tick while something is broken — it waits out the
 * backoff window, then tries again, independent of the normal 8-hour cycle.
 */
final class Scheduler
{
    public function __construct(
        private readonly JsonStorage $stateStorage,
        private readonly int $intervalHours = 8,
        private readonly int $retryAfterMinutes = 45,
    ) {
    }

    public function shouldPostNow(): bool
    {
        $state = $this->stateStorage->read();

        if (!$this->intervalElapsed($state)) {
            return false;
        }

        if (!$this->retryBackoffElapsed($state)) {
            return false;
        }

        return true;
    }

    private function intervalElapsed(array $state): bool
    {
        $lastPostAt = $state['last_post_at'] ?? null;
        if (empty($lastPostAt)) {
            return true; // never posted before
        }

        $last = strtotime((string) $lastPostAt);
        if ($last === false) {
            return true; // corrupted timestamp -> allow posting, will be fixed on next write
        }

        return time() >= ($last + ($this->intervalHours * 3600));
    }

    private function retryBackoffElapsed(array $state): bool
    {
        $nextRetryAt = $state['next_retry_at'] ?? null;
        if (empty($nextRetryAt)) {
            return true;
        }

        $next = strtotime((string) $nextRetryAt);
        if ($next === false) {
            return true;
        }

        return time() >= $next;
    }

    public function nextPostAt(): string
    {
        $state = $this->stateStorage->read();
        $lastPostAt = $state['last_post_at'] ?? null;

        $candidates = [];

        if (!empty($lastPostAt)) {
            $last = strtotime((string) $lastPostAt);
            if ($last !== false) {
                $candidates[] = $last + ($this->intervalHours * 3600);
            }
        }

        if (!empty($state['next_retry_at'])) {
            $retry = strtotime((string) $state['next_retry_at']);
            if ($retry !== false) {
                $candidates[] = $retry;
            }
        }

        if (empty($candidates)) {
            return 'now';
        }

        return date('Y-m-d H:i:s', max($candidates));
    }

    public function recordPublished(string $timestamp): void
    {
        $state = $this->stateStorage->read();
        $state['last_post_at'] = $timestamp;
        $state['next_retry_at'] = null; // success clears any pending backoff
        $this->stateStorage->write($state);
    }

    public function scheduleRetryBackoff(): void
    {
        $state = $this->stateStorage->read();
        $state['next_retry_at'] = date('Y-m-d H:i:s', time() + ($this->retryAfterMinutes * 60));
        $this->stateStorage->write($state);
    }

    public function recordLastError(string $message): void
    {
        $state = $this->stateStorage->read();
        $state['last_error'] = [
            'message' => $message,
            'at' => date('Y-m-d H:i:s'),
        ];
        $this->stateStorage->write($state);
    }

    public function getState(): array
    {
        return $this->stateStorage->read();
    }

    public function setState(array $state): void
    {
        $this->stateStorage->write($state);
    }
}
