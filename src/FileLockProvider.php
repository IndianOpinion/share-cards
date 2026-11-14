<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Locks as files under `{cache}/.locks/`, taken with `flock`. The operating
 * system drops the lock when the process dies, so a crash cannot wedge a key.
 */
final class FileLockProvider implements LockProvider
{
    public const POLL_MS = 50;

    private Clock $clock;

    public function __construct(private string $directory, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function acquire(string $name, int $waitMs): ?Lock
    {
        if (preg_match('/^[a-z0-9-]{1,64}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid lock name.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return null;
        }
        $path = $this->directory . '/' . $name . '.lock';
        $deadline = $this->clock->now() + max(0, $waitMs) / 1000;
        while (true) {
            $handle = fopen($path, 'c');
            if ($handle === false) {
                return null;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                touch($path);
                return new FileLock($handle);
            }
            fclose($handle);
            if ($this->clock->now() >= $deadline) {
                return null;
            }
            $this->clock->sleepMs(self::POLL_MS);
        }
    }
}
