<?php

/**
 * Loads the plugin without Composer.
 */

declare(strict_types=1);

if (!class_exists(\IndianOpinion\ShareCards\Plugin::class, false)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\ShareCards\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

require_once __DIR__ . '/src/functions.php';
