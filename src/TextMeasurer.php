<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Measures the advance width of one line of text, as the canvas will draw it.
 */
interface TextMeasurer
{
    /**
     * @param string $text     One line, already normalised.
     * @param string $fontPath Readable font file.
     * @param float  $sizePx   Font size in pixels.
     *
     * @throws RenderException When the font cannot be used.
     */
    public function width(string $text, string $fontPath, float $sizePx): float;
}
