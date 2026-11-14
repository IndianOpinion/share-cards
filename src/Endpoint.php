<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Serves card URLs: warm hits, cold renders under locks and budgets, stale
 * redirects, fallbacks and 404s. Builds a Response; never echoes.
 */
final class Endpoint
{
    public const IMMUTABLE = 'public, max-age=31536000, immutable';
    public const SHORT = 'public, max-age=60';
    public const STALE = 'public, max-age=300';

    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * @param string      $raw         The key from the query var.
     * @param string      $method      HTTP method.
     * @param string|null $ifNoneMatch The If-None-Match request header.
     * @param bool        $previous    True when the request came through the previous prefix.
     */
    public function handle(string $raw, string $method, ?string $ifNoneMatch = null, bool $previous = false): Response
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new Response(405, [
                'Allow' => 'GET, HEAD',
                'Cache-Control' => self::SHORT,
                'Content-Length' => '0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Share-Card' => 'not-allowed',
            ], null, false);
        }
        $head = $method === 'HEAD';

        // Nothing from a malformed key is used for anything.
        $key = Key::tryParse($raw);
        if ($key === null) {
            return $this->notFound();
        }

        if ($previous) {
            return $this->redirect($this->plugin->cards()->url($key), self::STALE, 'moved');
        }

        $subject = $this->plugin->resolver()->resolve($key->type(), $key->id());
        if ($subject === null || !$subject->isPublic) {
            return $this->notFound();
        }

        $reason = $this->plugin->unavailableReason();
        if ($reason !== null) {
            return $this->fallback($key, $subject, $reason);
        }

        $current = $this->plugin->cards()->keyFor($subject);
        if (!$current->equals($key)) {
            // Only current hashes are ever written, so random hashes cannot
            // fill the disk or cost a render.
            return $this->redirect($this->plugin->cards()->url($current), self::STALE, 'stale');
        }

        $cache = $this->plugin->cache();
        $path = $cache->pathFor($key);
        if (is_file($path)) {
            return $this->serve($key, $path, 'hit', $head, $ifNoneMatch);
        }

        return $this->cold($key, $subject, $path, $head, $ifNoneMatch);
    }

    private function cold(Key $key, Subject $subject, string $path, bool $head, ?string $ifNoneMatch): Response
    {
        $settings = $this->plugin->settings();
        $cache = $this->plugin->cache();
        $locks = $this->plugin->locks();
        $cache->ensure();

        $keyLock = $locks->acquire($key->toString(), $settings->int('lock_wait_ms'));
        if ($keyLock === null) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return $this->serve($key, $path, 'hit', $head, $ifNoneMatch);
            }
            return $this->fallback($key, $subject, 'busy');
        }

        try {
            // Another process may have finished while we waited.
            clearstatcache(true, $path);
            if (is_file($path)) {
                return $this->serve($key, $path, 'hit', $head, $ifNoneMatch);
            }
            if ($cache->failureActive($key, $settings->int('failure_ttl'), $this->plugin->clock()->now())) {
                return $this->fallback($key, $subject, 'error');
            }
            if (!$this->plugin->budget()->tryStart()) {
                return $this->fallback($key, $subject, 'budget');
            }
            $slot = $this->plugin->acquireSlot($settings->int('slot_wait_ms'));
            if ($slot === null) {
                return $this->fallback($key, $subject, 'slots');
            }
            try {
                // The render finishes even if the scraper hangs up.
                ignore_user_abort(true);
                $this->plugin->renderToCache($subject, $key);
            } catch (\Throwable $e) {
                $cache->markFailure($key, $this->plugin->clock()->now());
                $this->plugin->logger()->error(sprintf('render failed key=%s class=%s message=%s', $key, get_class($e), $e->getMessage()));
                do_action('share_cards_render_failed', $key->toString(), $e);
                return $this->fallback($key, $subject, 'error');
            } finally {
                $slot->release();
            }
            return $this->serve($key, $path, 'render', $head, $ifNoneMatch);
        } finally {
            $keyLock->release();
        }
    }

    private function serve(Key $key, string $path, string $state, bool $head, ?string $ifNoneMatch): Response
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $etag = '"' . $key->hash() . '-' . $size . '"';
        $headers = [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $size,
            'Cache-Control' => self::IMMUTABLE,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Share-Card' => $state,
        ];
        if ($ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag)) {
            unset($headers['Content-Length'], $headers['Content-Type']);
            return new Response(304, $headers, null, false);
        }
        return new Response(200, $headers, $path, !$head);
    }

    public static function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * The 404 for unknown, non-public and malformed keys. Identical in every
     * case, so nothing about a subject leaks.
     */
    public function notFound(string $state = 'not-found'): Response
    {
        return new Response(404, [
            'Cache-Control' => self::SHORT,
            'Content-Length' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Share-Card' => $state,
        ], null, false);
    }

    private function redirect(string $url, string $cacheControl, string $state): Response
    {
        return new Response(302, [
            'Location' => $url,
            'Cache-Control' => $cacheControl,
            'Content-Length' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Share-Card' => $state,
        ], null, false);
    }

    /**
     * 302 to a real image now, the card next time. Never to an HTML page:
     * with no fallback image, 404.
     */
    public function fallback(Key $key, Subject $subject, string $reason): Response
    {
        $url = apply_filters('share_cards_fallback_url', $this->plugin->chooser()->fallbackUrl($subject), $key->toString(), $reason);
        do_action('share_cards_fallback', $key->toString(), $reason);
        $this->plugin->logger()->info(sprintf('fallback key=%s reason=%s', $key, $reason));
        if (!is_string($url) || $url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return $this->notFound('fallback-' . $reason);
        }
        return $this->redirect($url, self::SHORT, 'fallback-' . $reason);
    }
}
