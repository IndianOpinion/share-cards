<?php

/**
 * Plugin Name: Share cards (must-use loader)
 * Description: Loads share-cards from wp-content/plugins as a must-use plugin.
 *
 * Copy this file to wp-content/mu-plugins/share-cards-loader.php. Must-use
 * plugins get no activation hook, so defaults are used until the settings
 * page is saved, the cron event is scheduled on the first request, and the
 * rewrite rule is flushed once on the first admin page load.
 */

declare(strict_types=1);

$shareCards = WP_CONTENT_DIR . '/plugins/share-cards/share-cards.php';
if (is_readable($shareCards)) {
    require_once $shareCards;
}
