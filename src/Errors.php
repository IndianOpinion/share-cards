<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Turns PHP warnings raised by image functions into typed exceptions, so
 * nothing fails silently and nothing needs `@`.
 */
final class Errors
{
    /**
     * @template T
     * @param callable(): T $fn
     * @param class-string<\Throwable> $exception
     * @return T
     */
    public static function call(callable $fn, string $exception)
    {
        set_error_handler(static function (int $severity, string $message) use ($exception): bool {
            throw new $exception($message);
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_DEPRECATED);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
