<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
