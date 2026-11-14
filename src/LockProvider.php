<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Hands out named, exclusive, non-blocking locks.
 *
 * Replace the default file provider through the `share_cards_lock_provider`
 * filter on hosts where `flock` is not reliable (for example several web
 * servers sharing one network file system). A provider must:
 *
 * - never block longer than `$waitMs` milliseconds;
 * - release the lock when the holding process dies, or expire it soon after;
 * - treat names as opaque strings of `[a-z0-9-]`, at most 64 characters.
 */
interface LockProvider
{
    /**
     * @param string $name   Lock name, `[a-z0-9-]{1,64}`.
     * @param int    $waitMs How long to keep trying, 0 for one attempt.
     *
     * @return Lock|null The lock, or null when it stayed busy for the whole wait.
     */
    public function acquire(string $name, int $waitMs): ?Lock;
}
