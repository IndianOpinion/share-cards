<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Imagick as the canvas: decode, compose and encode. Same layout as GD.
 */
final class ImagickRenderer extends CanvasRenderer
{
    protected function base(): string
    {
        return 'imagick';
    }

    public function isAvailable(): bool
    {
        return ImagickCanvas::usable();
    }

    protected function createCanvas(): Canvas
    {
        return new ImagickCanvas();
    }
}
