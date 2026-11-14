<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * A rendered Pango text layer: a transparent PNG and its size.
 */
final class PangoResult
{
    public function __construct(
        public readonly string $png,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size
    ) {
    }
}
