<?php

declare(strict_types=1);

$content = sys_get_temp_dir() . '/share-cards-tests-' . getmypid() . '/wp-content';
if (!is_dir($content)) {
    mkdir($content, 0755, true);
}
define('ABSPATH', dirname($content) . '/');
define('WP_CONTENT_DIR', $content);

require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/wordpress.php';
require dirname(__DIR__) . '/vendor/autoload.php';

register_shutdown_function(static function () use ($content): void {
    \IndianOpinion\ShareCards\Tests\TestCase::removeTree(dirname($content));
});
