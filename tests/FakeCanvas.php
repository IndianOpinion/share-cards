<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Canvas;
use IndianOpinion\ShareCards\SourceImage;

/**
 * A canvas that draws nothing and encodes to a size that falls with quality.
 */
final class FakeCanvas implements Canvas
{
    /** @var list<int> */
    public array $qualities = [];

    public function width(string $text, string $fontPath, float $sizePx): float
    {
        return 10.0;
    }

    public function create(int $width, int $height, string $background): void
    {
    }

    public function supports(int $imageType): bool
    {
        return true;
    }

    public function drawBackdrop(SourceImage $source, string $background, float $scrimOpacity): void
    {
    }

    public function drawText(string $text, string $font, float $sizePx, string $colour, int $x, int $baseline): void
    {
    }

    public function drawLayer(string $png, int $x, int $y): void
    {
    }

    public function drawImage(SourceImage $source, int $x, int $y, int $width, int $height): void
    {
    }

    public function fillRect(int $x, int $y, int $width, int $height, string $colour, float $opacity): void
    {
    }

    public function encode(int $quality): string
    {
        $this->qualities[] = $quality;
        return str_repeat('x', $quality === 60 ? 290000 : 400000);
    }

    public function destroy(): void
    {
    }
}
