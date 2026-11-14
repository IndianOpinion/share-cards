<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Text measurement with GD's FreeType bounding box.
 */
final class GdMeasurer implements TextMeasurer
{
    /** @var array<string, float> */
    private array $memo = [];

    /** GD takes sizes in points at 96 dpi. */
    public static function points(float $px): float
    {
        return $px * 0.75;
    }

    public function width(string $text, string $fontPath, float $sizePx): float
    {
        if ($text === '') {
            return 0.0;
        }
        $memoKey = $fontPath . "\0" . $sizePx . "\0" . $text;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        if (!Fonts::usable($fontPath)) {
            throw new RenderException('Font file is not readable: ' . basename($fontPath));
        }
        $box = Errors::call(static fn () => imagettfbbox(self::points($sizePx), 0, $fontPath, $text), RenderException::class);
        if (!is_array($box)) {
            throw new RenderException('Could not measure text with font ' . basename($fontPath));
        }
        $width = (float) (max($box[2], $box[4]) - min($box[0], $box[6]));
        if (count($this->memo) > 2000) {
            $this->memo = [];
        }
        return $this->memo[$memoKey] = $width;
    }
}
