<?php

/**
 * Plugin Name: Share cards: CDN purge
 * Description: Example of reacting to `share_cards_purged`. The plugin never
 *              calls a CDN itself; it tells you which URLs went away.
 */

declare(strict_types=1);

add_action('share_cards_purged', static function (array $urls, string $reason): void {
    // Replace this with your CDN's purge call. Keep it non-blocking: this
    // runs inside `save_post` for edits and unpublishes.
    foreach (array_chunk($urls, 30) as $batch) {
        wp_schedule_single_event(time(), 'my_cdn_purge_urls', [$batch, $reason]);
    }
}, 10, 2);

add_action('my_cdn_purge_urls', static function (array $urls, string $reason): void {
    error_log(sprintf('[my-cdn] would purge %d URLs (%s)', count($urls), $reason));
}, 10, 2);
