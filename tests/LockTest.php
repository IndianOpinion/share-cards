<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\FileLockProvider;

/**
 * The file lock provider.
 */
final class LockTest extends TestCase
{
    public function testExclusiveAndReleased(): void
    {
        $locks = new FileLockProvider($this->tmp . '/locks', $this->clock);
        $a = $locks->acquire('post-1-0123456789', 0);
        self::assertNotNull($a);
        self::assertNull($locks->acquire('post-1-0123456789', 0));
        self::assertNotNull($locks->acquire('post-2-0123456789', 0));
        $a->release();
        $a->release();
        self::assertNotNull($locks->acquire('post-1-0123456789', 0));
    }

    public function testWaitsAndPolls(): void
    {
        $locks = new FileLockProvider($this->tmp . '/locks', $this->clock);
        $held = $locks->acquire('k', 0);
        $start = $this->clock->now();
        self::assertNull($locks->acquire('k', 500));
        self::assertEqualsWithDelta(0.5, $this->clock->now() - $start, 0.051);
        self::assertContains($this->clock->sleeps, [10, 11]);
        $held?->release();
    }

    public function testRejectsUnsafeNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileLockProvider($this->tmp . '/locks', $this->clock))->acquire('../x', 0);
    }

    public function testDroppedHandleReleases(): void
    {
        $locks = new FileLockProvider($this->tmp . '/locks', $this->clock);
        (static function () use ($locks): void {
            $locks->acquire('k', 0);
        })();
        self::assertNotNull($locks->acquire('k', 0));
    }
}
