<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Wall clock and sleep, replaceable in tests.
 */
interface Clock
{
    /** Seconds since the epoch, with fractions. */
    public function now(): float;

    public function sleepMs(int $ms): void;
}
