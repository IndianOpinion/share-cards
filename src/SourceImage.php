<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * A local image file that passed every check and may be decoded.
 */
final class SourceImage
{
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly int $type,
        public readonly int $attachmentId = 0,
        public readonly float $focalX = 0.5,
        public readonly float $focalY = 0.5
    ) {
    }
}
