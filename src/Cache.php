<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The card directory: paths, atomic writes, variants and failure markers.
 *
 * Every file name is rebuilt from a validated key. Only files whose names
 * match the plugin's own patterns are ever deleted.
 */
final class Cache
{
    public const CARD_PATTERN = '/^([a-z][a-z0-9]{0,15}-[1-9][0-9]{0,19}-[0-9a-f]{10})\.jpg$/D';
    public const FAIL_PATTERN = '/^([a-z][a-z0-9]{0,15}-[1-9][0-9]{0,19}-[0-9a-f]{10})\.fail$/D';
    public const TEMP_PATTERN = '/^\.([a-z][a-z0-9]{0,15}-[1-9][0-9]{0,19}-[0-9a-f]{10}|preview(?:-[a-z_]+)?)\.[0-9a-f]{8,32}\.tmp$/D';
    public const PREVIEW_PATTERN = '/^\.preview(?:-[a-z_]+)?\.jpg$/D';
    public const LOCK_PATTERN = '/^[a-z0-9-]{1,64}\.lock$/D';
    public const LOCK_DIR = '.locks';

    public function __construct(private string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Where cards live for the current site.
     *
     * `{uploads}/share-cards`, unless the uploads directory is a stream
     * wrapper (object storage), where `flock` and `rename` are unreliable; then
     * `WP_CONTENT_DIR/cache/share-cards`. Filterable.
     */
    public static function defaultDirectory(): string
    {
        $uploads = wp_upload_dir(null, false);
        $base = is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir']) ? $uploads['basedir'] : '';
        if ($base === '' || str_contains($base, '://')) {
            $dir = rtrim((string) WP_CONTENT_DIR, '/') . '/cache/share-cards';
        } else {
            $dir = rtrim($base, '/') . '/share-cards';
        }
        $filtered = apply_filters('share_cards_cache_dir', $dir);
        return is_string($filtered) && $filtered !== '' ? rtrim($filtered, '/') : $dir;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function lockDirectory(): string
    {
        return $this->directory . '/' . self::LOCK_DIR;
    }

    /**
     * Creates the directory (0755) with an empty index.html.
     */
    public function ensure(): bool
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
                return false;
            }
            chmod($this->directory, 0755);
        }
        $index = $this->directory . '/index.html';
        if (!is_file($index) && is_writable($this->directory)) {
            file_put_contents($index, '');
        }
        return true;
    }

    public function isWritable(): bool
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }
        // Not created yet: writable if the nearest existing parent is.
        $parent = dirname($this->directory);
        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        return is_writable($parent);
    }

    /**
     * Absolute path of a card. Checked to sit inside the real cache directory.
     *
     * @throws InvalidKeyException Never for a parsed key; kept as a guard.
     */
    public function pathFor(Key $key): string
    {
        $name = $key->filename();
        if (preg_match(self::CARD_PATTERN, $name) !== 1) {
            throw new InvalidKeyException('Malformed card key.');
        }
        $real = realpath($this->directory);
        $base = $real === false ? $this->directory : $real;
        return $base . '/' . $name;
    }

    public function exists(Key $key): bool
    {
        return is_file($this->pathFor($key));
    }

    /**
     * Writes a card atomically: the callback writes a temporary file, which
     * is then renamed into place. No reader ever sees a half-written card.
     *
     * @param callable(string): void $write Receives the temporary path.
     *
     * @return string The final path.
     */
    public function writeAtomic(Key $key, callable $write): string
    {
        return $this->writeNamed($key->toString(), $this->pathFor($key), $write);
    }

    /**
     * Writes the settings preview file, outside the key space.
     *
     * @param callable(string): void $write
     */
    public function writePreview(string $script, callable $write): string
    {
        $suffix = $script === '' ? '' : '-' . strtolower((string) preg_replace('/[^A-Za-z_]/', '', $script));
        $this->ensure();
        $final = $this->directory . '/.preview' . $suffix . '.jpg';
        return $this->writeNamed('preview' . $suffix, $final, $write);
    }

    /**
     * @param callable(string): void $write
     */
    private function writeNamed(string $stem, string $final, callable $write): string
    {
        $temp = dirname($final) . '/.' . $stem . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $write($temp);
            if (!is_file($temp) || filesize($temp) === 0) {
                throw new RenderException('The renderer wrote no file.');
            }
            chmod($temp, 0644);
            if (!rename($temp, $final)) {
                throw new RenderException('Could not move the card into place.');
            }
        } catch (\Throwable $e) {
            if (is_file($temp)) {
                unlink($temp);
            }
            throw $e;
        }
        return $final;
    }

    /**
     * Card files of every variant of one subject, `{type}-{id}-*.jpg`.
     *
     * @return list<Key>
     */
    public function variants(string $type, string $id): array
    {
        $out = [];
        $prefix = $type . '-' . $id . '-';
        foreach ($this->listNames() as $name) {
            if (!str_starts_with($name, $prefix) || preg_match(self::CARD_PATTERN, $name, $m) !== 1) {
                continue;
            }
            $key = Key::tryParse($m[1]);
            if ($key !== null && $key->type() === $type && $key->id() === $id) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Deletes every variant of a subject except `$keep`, with its markers.
     *
     * @return list<Key> The keys whose card files were removed.
     */
    public function deleteVariants(string $type, string $id, ?Key $keep = null): array
    {
        $removed = [];
        foreach ($this->variants($type, $id) as $key) {
            if ($keep !== null && $key->equals($keep)) {
                continue;
            }
            if ($this->delete($key)) {
                $removed[] = $key;
            }
        }
        return $removed;
    }

    public function delete(Key $key): bool
    {
        $path = $this->pathFor($key);
        $this->clearFailure($key);
        return is_file($path) && unlink($path);
    }

    public function failurePath(Key $key): string
    {
        return dirname($this->pathFor($key)) . '/' . $key->toString() . '.fail';
    }

    public function markFailure(Key $key, ?float $now = null): void
    {
        if (is_dir($this->directory)) {
            touch($this->failurePath($key), (int) ($now ?? time()));
        }
    }

    public function failureActive(Key $key, int $ttl, float $now): bool
    {
        $path = $this->failurePath($key);
        if (!is_file($path)) {
            return false;
        }
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        return $mtime !== false && $mtime + $ttl > $now;
    }

    public function clearFailure(Key $key): void
    {
        $path = $this->failurePath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * File names in the cache directory, sorted, without `.` and `..`.
     *
     * @return list<string>
     */
    public function listNames(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $names = scandir($this->directory);
        if ($names === false) {
            return [];
        }
        return array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
    }

    /**
     * Whether a name is one the plugin writes in the cache directory.
     */
    public static function isOwnName(string $name): bool
    {
        return $name === 'index.html'
            || $name === RenderBudget::FILENAME
            || preg_match(self::CARD_PATTERN, $name) === 1
            || preg_match(self::FAIL_PATTERN, $name) === 1
            || preg_match(self::TEMP_PATTERN, $name) === 1
            || preg_match(self::PREVIEW_PATTERN, $name) === 1;
    }
}
