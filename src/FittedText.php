<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Result of the fit loop: one size and the lines drawn at that size.
 */
final class FittedText
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly int $size,
        public readonly array $lines,
        public readonly float $lineHeight,
        public readonly bool $truncated
    ) {
    }
}
