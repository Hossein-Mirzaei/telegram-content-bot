<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lock
 *
 * File-based mutual-exclusion lock used to prevent two overlapping cron runs
 * from both publishing a post at the same time. Uses flock() for atomicity
 * and also stores a PID + timestamp so a stale lock (from a crashed process)
 * can be detected and released automatically after a timeout.
 */
final class Lock
{
    private string $lockFile;
    /** @var resource|null */
    private $handle = null;
    private int $staleAfterSeconds;

    public function __construct(string $lockFile, int $staleAfterSeconds = 900)
    {
        $this->lockFile = $lockFile;
        $this->staleAfterSeconds = $staleAfterSeconds;
    }

    /**
     * Attempt to acquire the lock. Returns true on success, false if another
     * process currently holds a valid (non-stale) lock.
     */
    public function acquire(): bool
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->handle = @fopen($this->lockFile, 'c+');
        if ($this->handle === false) {
            // If we cannot even open the lock file, fail closed (do not run).
            return false;
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            // Someone else holds the lock. Check whether it looks stale.
            if ($this->isStale()) {
                // Force-release a stale lock and try once more.
                @fclose($this->handle);
                @unlink($this->lockFile);
                $this->handle = @fopen($this->lockFile, 'c+');
                if ($this->handle === false || !flock($this->handle, LOCK_EX | LOCK_NB)) {
                    return false;
                }
            } else {
                fclose($this->handle);
                $this->handle = null;
                return false;
            }
        }

        ftruncate($this->handle, 0);
        rewind($this->handle);
        fwrite($this->handle, json_encode([
            'pid' => getmypid(),
            'started_at' => date('c'),
        ]));
        fflush($this->handle);

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    private function isStale(): bool
    {
        if (!is_file($this->lockFile)) {
            return false;
        }
        $mtime = @filemtime($this->lockFile);
        if ($mtime === false) {
            return false;
        }
        return (time() - $mtime) > $this->staleAfterSeconds;
    }
}
