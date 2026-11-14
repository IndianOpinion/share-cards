<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * A held lock. Releasing twice is harmless.
 */
interface Lock
{
    public function release(): void;
}
