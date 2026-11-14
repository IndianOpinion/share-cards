<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Clock;

/**
 * A clock that only moves when told, or when something sleeps.
 */
final class FakeClock implements Clock
{
    /** @var (callable(int): void)|null */
    public $onSleep = null;

    public int $sleeps = 0;

    public function __construct(public float $time)
    {
    }

    public function now(): float
    {
        return $this->time;
    }

    public function sleepMs(int $ms): void
    {
        $this->sleeps++;
        $this->time += $ms / 1000;
        if ($this->onSleep !== null) {
            ($this->onSleep)($this->sleeps);
        }
    }
}
