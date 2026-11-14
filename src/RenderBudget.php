<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Counts renders started in the current minute, in a small file in the cache
 * directory. A file behaves the same with or without a persistent object
 * cache, unlike a transient.
 */
final class RenderBudget
{
    public const FILENAME = '.budget';

    private Clock $clock;

    public function __construct(private string $path, private int $perMinute, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Counts one render if the budget allows it.
     *
     * @return bool False when the minute's budget is already spent.
     */
    public function tryStart(): bool
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            // Without a counter file the cache is unwritable, and the caller
            // has already refused to render. Allow, rather than fail closed.
            return true;
        }
        try {
            flock($handle, LOCK_EX);
            [$minute, $count] = $this->parse((string) stream_get_contents($handle));
            $now = $this->minute();
            if ($minute !== $now) {
                $count = 0;
            }
            if ($count >= $this->perMinute) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $now . ' ' . ($count + 1));
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Renders counted in the current minute.
     */
    public function current(): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        [$minute, $count] = $this->parse((string) file_get_contents($this->path));
        return $minute === $this->minute() ? $count : 0;
    }

    private function minute(): int
    {
        return intdiv((int) $this->clock->now(), 60);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parse(string $raw): array
    {
        if (preg_match('/^(\d+) (\d+)$/D', trim($raw), $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, 0];
    }
}
