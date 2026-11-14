<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\TextLayout;
use IndianOpinion\ShareCards\TextMeasurer;

/**
 * Every grapheme is 0.5 em wide: predictable layout tests.
 */
final class MonoMeasurer implements TextMeasurer
{
    public int $calls = 0;

    public function width(string $text, string $fontPath, float $sizePx): float
    {
        $this->calls++;
        return count(TextLayout::graphemes($text)) * $sizePx * 0.5;
    }
}
