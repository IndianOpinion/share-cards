<?php

/**
 * Plugin Name:       Share cards
 * Plugin URI:        https://github.com/IndianOpinion/share-cards
 * Description:       Social share cards rendered on the first request and served from disk, with GD or Imagick and a content hash in the URL.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       share-cards
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

register_activation_hook(__FILE__, static function (): void {
    \IndianOpinion\ShareCards\Plugin::instance()->activate();
});
register_deactivation_hook(__FILE__, static function (): void {
    \IndianOpinion\ShareCards\Plugin::instance()->deactivate();
});

\IndianOpinion\ShareCards\Plugin::instance()->boot();
