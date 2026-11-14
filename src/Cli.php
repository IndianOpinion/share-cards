<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Manage share cards: warm, status, render, gc, flush, url.
 */
final class Cli
{
    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * Renders cards that do not exist yet.
     *
     * ## OPTIONS
     *
     * [--posts=<n>]
     * : How many of the most recent published posts that would get a card.
     * ---
     * default: 50
     * ---
     *
     * [--terms]
     * : Also every non-empty term in an allowed taxonomy.
     *
     * [--dry-run]
     * : List what would be rendered; write nothing.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function warm(array $args, array $assoc): void
    {
        $limit = isset($assoc['posts']) && is_numeric($assoc['posts']) ? max(0, (int) $assoc['posts']) : 50;
        $dry = !empty($assoc['dry-run']);
        $counts = ['rendered' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->postsNeedingCards($limit) as $post) {
            $this->warmOne($this->plugin->resolver()->resolvePost($post), $dry, $counts);
        }
        if (!empty($assoc['terms'])) {
            $terms = get_terms(['taxonomy' => $this->plugin->resolver()->taxonomies(), 'hide_empty' => true]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                if ($term instanceof \WP_Term && $this->plugin->resolver()->termIsPublic($term)) {
                    $this->warmOne($this->plugin->resolver()->resolveTerm($term), $dry, $counts);
                }
            }
        }
        \WP_CLI::success(sprintf(
            '%s: %d, skipped: %d, failed: %d.',
            $dry ? 'Would render' : 'Rendered',
            $counts['rendered'],
            $counts['skipped'],
            $counts['failed']
        ));
    }

    /**
     * @param array{rendered: int, skipped: int, failed: int} $counts
     */
    private function warmOne(Subject $subject, bool $dry, array &$counts): void
    {
        $key = $this->plugin->cards()->keyFor($subject);
        if ($this->plugin->cache()->exists($key)) {
            $counts['skipped']++;
            return;
        }
        if ($dry) {
            \WP_CLI::log('Would render ' . $this->plugin->cards()->url($key));
            $counts['rendered']++;
            return;
        }
        // Respects max_concurrent; ignores the per-minute budget.
        $slot = $this->plugin->acquireSlot(max(30000, $this->plugin->settings()->int('slot_wait_ms')));
        if ($slot === null) {
            \WP_CLI::warning('No render slot free for ' . $key);
            $counts['failed']++;
            return;
        }
        try {
            $this->plugin->renderToCache($subject, $key);
            \WP_CLI::log('Rendered ' . $this->plugin->cards()->url($key));
            $counts['rendered']++;
        } catch (\Throwable $e) {
            \WP_CLI::warning(sprintf('Failed %s: %s', $key, $e->getMessage()));
            $counts['failed']++;
        } finally {
            $slot->release();
        }
    }

    /**
     * @return list<\WP_Post>
     */
    private function postsNeedingCards(int $limit): array
    {
        $out = [];
        $page = 1;
        while (count($out) < $limit) {
            $posts = get_posts([
                'post_type' => $this->plugin->resolver()->postTypes(),
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'paged' => $page++,
                'orderby' => 'date',
                'order' => 'DESC',
                'has_password' => false,
                'no_found_rows' => true,
            ]);
            if (!is_array($posts) || $posts === []) {
                break;
            }
            foreach ($posts as $post) {
                if (!$post instanceof \WP_Post) {
                    continue;
                }
                $image = $this->plugin->chooser()->forPost($post);
                if (is_array($image) && ($image['source'] ?? '') === 'card') {
                    $out[] = $post;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Prints renderer, capabilities, fonts, cache directory and gc state.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $caps = $this->plugin->capabilities();
        $yes = static fn (bool $b): string => $b ? 'yes' : 'no';
        $renderer = $this->plugin->renderer();
        $fonts = $this->plugin->fonts();
        $cache = $this->plugin->cache();

        \WP_CLI::log('Renderer: ' . ($renderer === null ? 'none (missing: ' . implode(', ', Capabilities::missing($caps)) . ')' : $renderer->name()));
        \WP_CLI::log(sprintf(
            'GD: %s (JPEG %s, PNG %s, WebP %s, FreeType %s)',
            $yes(!empty($caps['gd'])),
            $yes(!empty($caps['gd_jpeg'])),
            $yes(!empty($caps['gd_png'])),
            $yes(!empty($caps['gd_webp'])),
            $yes(!empty($caps['gd_freetype']))
        ));
        \WP_CLI::log('Imagick: ' . $yes(!empty($caps['imagick']) && !empty($caps['imagick_jpeg'])));
        \WP_CLI::log('Pango: ' . $yes(!empty($caps['pango'])));
        \WP_CLI::log(sprintf('Font regular: %s (%s)', $fonts->regular() === '' ? 'none' : $fonts->regular(), $fonts->regular() === '' ? 'missing' : 'readable'));
        \WP_CLI::log(sprintf('Font bold: %s (%s)', $fonts->bold() === '' ? 'none' : $fonts->bold(), $fonts->bold() === '' ? 'missing' : 'readable'));
        foreach ($fonts->scripts() as $script => $path) {
            \WP_CLI::log(sprintf('Font %s: %s (readable)', $script, $path));
        }
        foreach ($fonts->missing() as $path) {
            \WP_CLI::log(sprintf('Font missing: %s (not readable)', $path));
        }
        $check = $fonts->check();
        if ($check instanceof \WP_Error) {
            \WP_CLI::log('Font error: ' . $check->get_error_message());
        }

        $files = 0;
        $bytes = 0;
        foreach ($cache->listNames() as $name) {
            if (preg_match(Cache::CARD_PATTERN, $name) === 1) {
                $files++;
                $bytes += (int) filesize($cache->directory() . '/' . $name);
            }
        }
        \WP_CLI::log(sprintf('Cache directory: %s (%s)', $cache->directory(), $cache->isWritable() ? 'writable' : 'not writable'));
        \WP_CLI::log(sprintf('Cards: %d files, %s', $files, size_format($bytes) ?: '0 B'));
        \WP_CLI::log('Rewrite rule: ' . ($this->plugin->rewrite()->rulePresent() ? 'present' : 'missing'));
        $last = $this->plugin->gc()->state()['last'];
        \WP_CLI::log('Last garbage collection: ' . ($last === null ? 'never' : self::describeGc($last)));
    }

    /**
     * Renders one card, ignoring locks and budget.
     *
     * ## OPTIONS
     *
     * <target>
     * : `post:<id>`, `term:<id>` or `<type>:<id>`.
     *
     * [--force]
     * : Render even if the card exists.
     *
     * [--out=<file>]
     * : Write here instead; the cache is left untouched.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function render(array $args, array $assoc): void
    {
        $subject = $this->subjectFromTarget($args[0] ?? '');
        $started = microtime(true);
        $out = isset($assoc['out']) && is_string($assoc['out']) ? $assoc['out'] : '';
        if ($out !== '') {
            $this->plugin->renderTo($subject, $out);
            \WP_CLI::success(sprintf('Wrote %s in %d ms, peak memory %s.', $out, (int) round((microtime(true) - $started) * 1000), size_format(memory_get_peak_usage(true))));
            return;
        }
        $key = $this->plugin->cards()->keyFor($subject);
        if ($this->plugin->cache()->exists($key) && empty($assoc['force'])) {
            \WP_CLI::success('Card exists: ' . $this->plugin->cards()->url($key) . ' (use --force to render again)');
            return;
        }
        $result = $this->plugin->renderToCache($subject, $key);
        \WP_CLI::success(sprintf('Rendered %s in %d ms, %d bytes, peak memory %s.', $this->plugin->cards()->url($key), $result['ms'], $result['bytes'], size_format(memory_get_peak_usage(true))));
    }

    /**
     * Runs one full garbage collection.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be removed; delete nothing.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function gc(array $args, array $assoc): void
    {
        $result = $this->plugin->gc()->runFull(!empty($assoc['dry-run']));
        \WP_CLI::success((!empty($assoc['dry-run']) ? 'Dry run: ' : '') . self::describeGc($result));
    }

    /**
     * Removes every cached card, temporary file, marker and lock.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Confirm.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function flush(array $args, array $assoc): void
    {
        if (empty($assoc['yes'])) {
            \WP_CLI::error('This deletes every cached card. Run again with --yes to confirm.');
            return;
        }
        $cache = $this->plugin->cache();
        $keys = [];
        foreach ($cache->listNames() as $name) {
            if (preg_match(Cache::CARD_PATTERN, $name, $m) === 1) {
                $key = Key::tryParse($m[1]);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }
        $removed = Plugin::removeOwnFiles($cache, false);
        (new Invalidator($this->plugin))->purge($keys, 'flush');
        \WP_CLI::success(sprintf('Removed %d files.', $removed));
    }

    /**
     * Prints the current card URL and the image the page would advertise.
     *
     * ## OPTIONS
     *
     * <target>
     * : `post:<id>`, `term:<id>` or `<type>:<id>`.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function url(array $args, array $assoc): void
    {
        $target = $args[0] ?? '';
        $subject = $this->subjectFromTarget($target);
        \WP_CLI::log('Card URL: ' . $this->plugin->cards()->urlForSubject($subject));
        $chooser = $this->plugin->chooser();
        $image = match ($subject->type) {
            'post' => ($post = get_post((int) $subject->id)) instanceof \WP_Post ? $chooser->forPost($post) : null,
            'term' => ($term = get_term((int) $subject->id)) instanceof \WP_Term ? $chooser->forTerm($term) : null,
            default => $chooser->card($subject),
        };
        \WP_CLI::log($image === null
            ? 'Advertised image: none'
            : sprintf('Advertised image: %s (%dx%d, %s)', (string) $image['url'], (int) $image['width'], (int) $image['height'], (string) $image['source']));
    }

    private function subjectFromTarget(string $target): Subject
    {
        if (preg_match('/^(' . Key::TYPE_PATTERN . '):(' . Key::ID_PATTERN . ')$/D', $target, $m) !== 1) {
            \WP_CLI::error('Target must look like post:<id>, term:<id> or <type>:<id>.');
            throw new \InvalidArgumentException('Bad target.');
        }
        $subject = $this->plugin->resolver()->resolve($m[1], $m[2]);
        if ($subject === null || !$subject->isPublic) {
            \WP_CLI::error(sprintf('No public subject %s.', $target));
            throw new \InvalidArgumentException('No subject.');
        }
        return $subject;
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function describeGc(array $r): string
    {
        $removed = is_array($r['removed'] ?? null) ? $r['removed'] : [];
        $parts = [];
        foreach ($removed as $reason => $n) {
            $parts[] = $reason . ' ' . (int) $n;
        }
        $when = isset($r['finished']) ? gmdate('Y-m-d H:i', (int) $r['finished']) . ' UTC, ' : '';
        return sprintf('%sscanned %d, removed: %s, freed %d bytes', $when, (int) ($r['scanned'] ?? 0), implode(', ', $parts), (int) ($r['bytes'] ?? 0));
    }
}
