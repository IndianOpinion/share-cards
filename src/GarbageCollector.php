<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Walks the cache directory in batches, resuming where it stopped, and
 * removes what is no longer needed. Touches only files whose names match
 * the plugin's own patterns.
 */
final class GarbageCollector
{
    public const STATE_OPTION = 'share_cards_gc_state';
    public const BATCH = 500;
    public const TEMP_AGE = 3600;
    public const LOCK_AGE = 86400;
    public const CAP_TARGET = 0.9;

    public function __construct(private Plugin $plugin)
    {
    }

    public function batchSize(): int
    {
        return max(1, (int) apply_filters('share_cards_gc_batch', self::BATCH));
    }

    /**
     * Cron entry point: batches until the cycle ends or about 20 seconds pass.
     *
     * @return array{cursor: string, cycle: array<string, mixed>, last: array<string, mixed>|null}
     */
    public function cron(): array
    {
        $started = $this->plugin->clock()->now();
        do {
            $state = $this->runBatch();
        } while ($state['cursor'] !== '' && $this->plugin->clock()->now() - $started < 20);
        return $state;
    }

    /**
     * Processes one batch and saves the cursor.
     *
     * @return array{cursor: string, cycle: array<string, mixed>, last: array<string, mixed>|null}
     */
    public function runBatch(): array
    {
        $state = $this->state();
        [$cursor, $cycle, $done] = $this->batch($state['cursor'], $state['cycle'], false);
        $state['cursor'] = $done ? '' : $cursor;
        if ($done) {
            $cycle['finished'] = (int) $this->plugin->clock()->now();
            $state['last'] = $cycle;
            $state['cycle'] = self::emptyResult();
            $this->plugin->logger()->info(sprintf(
                'gc scanned=%d removed=%s bytes_freed=%d',
                (int) $cycle['scanned'],
                (string) json_encode($cycle['removed']),
                (int) $cycle['bytes']
            ));
        } else {
            $state['cycle'] = $cycle;
        }
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    /**
     * One full collection from the start. With `$dryRun`, reports and deletes
     * nothing, and the saved state is left alone.
     *
     * @return array<string, mixed>
     */
    public function runFull(bool $dryRun = false): array
    {
        $cursor = '';
        $result = self::emptyResult();
        do {
            [$cursor, $result, $done] = $this->batch($cursor, $result, $dryRun);
        } while (!$done);
        $result['finished'] = (int) $this->plugin->clock()->now();
        $result['dry_run'] = $dryRun;
        if (!$dryRun) {
            update_option(self::STATE_OPTION, ['cursor' => '', 'cycle' => self::emptyResult(), 'last' => $result], false);
        }
        return $result;
    }

    /**
     * @return array{cursor: string, cycle: array<string, mixed>, last: array<string, mixed>|null}
     */
    public function state(): array
    {
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        return [
            'cursor' => isset($stored['cursor']) && is_string($stored['cursor']) ? $stored['cursor'] : '',
            'cycle' => isset($stored['cycle']) && is_array($stored['cycle']) ? array_merge(self::emptyResult(), $stored['cycle']) : self::emptyResult(),
            'last' => isset($stored['last']) && is_array($stored['last']) ? $stored['last'] : null,
        ];
    }

    /**
     * @return array{scanned: int, removed: array<string, int>, bytes: int}
     */
    public static function emptyResult(): array
    {
        return [
            'scanned' => 0,
            'removed' => ['stale' => 0, 'gone' => 0, 'temp' => 0, 'marker' => 0, 'lock' => 0, 'cap' => 0],
            'bytes' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{0: string, 1: array<string, mixed>, 2: bool} cursor, result, cycle finished
     */
    private function batch(string $cursor, array $result, bool $dryRun): array
    {
        $cache = $this->plugin->cache();
        $dir = $cache->directory();
        $now = $this->plugin->clock()->now();
        $ttl = $this->plugin->settings()->int('failure_ttl');
        $names = array_values(array_filter(
            $cache->listNames(),
            static fn (string $n): bool => strcmp($n, $cursor) > 0 && Cache::isOwnName($n)
        ));
        $batch = array_slice($names, 0, $this->batchSize());
        $purged = [];
        $current = [];

        foreach ($batch as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $result['scanned']++;
            $mtime = (int) filemtime($path);
            $reason = null;

            if (preg_match(Cache::CARD_PATTERN, $name, $m) === 1) {
                $key = Key::tryParse($m[1]);
                if ($key === null) {
                    continue;
                }
                $stem = $key->stem();
                if (!array_key_exists($stem, $current)) {
                    $subject = $this->plugin->resolver()->resolve($key->type(), $key->id());
                    $current[$stem] = $subject !== null && $subject->isPublic ? $this->plugin->cards()->keyFor($subject)->hash() : null;
                }
                if ($current[$stem] === null) {
                    $reason = 'gone';
                } elseif ($current[$stem] !== $key->hash()) {
                    $reason = 'stale';
                }
                if ($reason !== null) {
                    $purged[] = $key;
                }
            } elseif (preg_match(Cache::TEMP_PATTERN, $name) === 1) {
                if ($mtime + self::TEMP_AGE < $now) {
                    $reason = 'temp';
                }
            } elseif (preg_match(Cache::FAIL_PATTERN, $name) === 1) {
                if ($mtime + $ttl <= $now) {
                    $reason = 'marker';
                }
            }

            if ($reason !== null) {
                $result = $this->remove($path, $reason, $result, $dryRun);
            }
        }

        $done = count($names) <= count($batch);
        $newCursor = $batch === [] ? $cursor : (string) end($batch);

        if ($done) {
            $result = $this->sweepLocks($cache, $now, $result, $dryRun);
            [$result, $capped] = $this->enforceCap($cache, $result, $dryRun);
            $purged = array_merge($purged, $capped);
        }

        if (!$dryRun && $purged !== []) {
            (new Invalidator($this->plugin))->purge($purged, 'gc');
        }
        return [$newCursor, $result, $done];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function remove(string $path, string $reason, array $result, bool $dryRun): array
    {
        $size = (int) filesize($path);
        if ($dryRun || unlink($path)) {
            $result['removed'][$reason]++;
            $result['bytes'] += $size;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function sweepLocks(Cache $cache, float $now, array $result, bool $dryRun): array
    {
        $dir = $cache->lockDirectory();
        if (!is_dir($dir)) {
            return $result;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(Cache::LOCK_PATTERN, $name) !== 1) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_file($path) && (int) filemtime($path) + self::LOCK_AGE < $now) {
                $result['scanned']++;
                $result = $this->remove($path, 'lock', $result, $dryRun);
            }
        }
        return $result;
    }

    /**
     * Deletes the oldest cards until the directory is under 90% of the cap.
     *
     * @param array<string, mixed> $result
     * @return array{0: array<string, mixed>, 1: list<Key>}
     */
    private function enforceCap(Cache $cache, array $result, bool $dryRun): array
    {
        $cap = $this->plugin->settings()->int('cache_max_mb') * 1048576;
        $cards = [];
        $total = 0;
        foreach ($cache->listNames() as $name) {
            $path = $cache->directory() . '/' . $name;
            if (!Cache::isOwnName($name) || !is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $total += $size;
            if (preg_match(Cache::CARD_PATTERN, $name, $m) === 1) {
                $cards[] = ['path' => $path, 'size' => $size, 'mtime' => (int) filemtime($path), 'key' => $m[1]];
            }
        }
        if ($dryRun) {
            // Files a dry run would already have removed still count here.
            $total -= (int) $result['bytes'];
        }
        $removed = [];
        if ($cap <= 0 || $total <= $cap) {
            return [$result, $removed];
        }
        usort($cards, static fn (array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);
        $target = (int) floor($cap * self::CAP_TARGET);
        foreach ($cards as $card) {
            if ($total < $target) {
                break;
            }
            if (!is_file($card['path'])) {
                continue;
            }
            $result = $this->remove($card['path'], 'cap', $result, $dryRun);
            $total -= $card['size'];
            $key = Key::tryParse($card['key']);
            if ($key !== null) {
                $removed[] = $key;
            }
        }
        return [$result, $removed];
    }
}
