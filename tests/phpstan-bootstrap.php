<?php

declare(strict_types=1);

// Constants WordPress defines at runtime, for static analysis only.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/tmp/wordpress/wp-content');
}
