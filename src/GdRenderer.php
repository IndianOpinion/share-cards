<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * GD as the canvas. Needs JPEG and FreeType support. Never shapes text by
 * itself; with Pango present, complex scripts go through the Pango layer.
 */
final class GdRenderer extends CanvasRenderer
{
    protected function base(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return GdCanvas::usable();
    }

    protected function createCanvas(): Canvas
    {
        return new GdCanvas();
    }
}
