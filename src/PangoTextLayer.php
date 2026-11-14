<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The shaping text layer: Pango through Imagick's `pango:` pseudo-format.
 *
 * Pango does the reordering, joining and conjunct formation that complex
 * scripts need, and wraps by word, falling back to characters. The result
 * is a transparent PNG that either canvas composites.
 *
 * Text is escaped twice: ImageMagick decodes one level of entities before
 * Pango parses the markup, so text escaped once would reach Pango with a
 * bare `&` or `<`, Pango would reject the markup and the text would vanish.
 *
 * ImageMagick also expands its own escapes in the `pango:` text before
 * Pango sees it: `%` escapes (`%m`, `%[fx:...]`, `%[option:...]`) and
 * backslash escapes (`\n`). Every `%` and `\` is doubled, so a headline
 * is always drawn as written and never evaluated.
 */
final class PangoTextLayer
{
    public const MAX_ATTEMPTS = 6;

    public static function isAvailable(): bool
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return \Imagick::queryFormats('PANGO') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Escapes text for the Pango path: once for Pango, once for ImageMagick.
     */
    public static function escape(string $text): string
    {
        $once = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return htmlspecialchars($once, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Doubles ImageMagick's escape characters, so the `pango:` coder reads
     * the markup literally.
     */
    public static function literal(string $markup): string
    {
        return str_replace(['\\', '%'], ['\\\\', '%%'], $markup);
    }

    /**
     * Lays out a wrapped paragraph at the largest ladder size whose height
     * fits; else truncates by graphemes with an ellipsis and retries. At most
     * six attempts in total.
     *
     * @param list<int> $ladder
     */
    public function paragraph(string $text, string $font, array $ladder, int $width, int $height, string $colour, bool $bold): ?PangoResult
    {
        $family = FontInfo::familyName($font);
        if ($family === '' || $ladder === []) {
            return null;
        }
        rsort($ladder);
        $attempts = 0;
        foreach (array_slice($ladder, 0, self::MAX_ATTEMPTS) as $size) {
            $attempts++;
            $result = $this->draw($text, $family, $size, $width, $colour, $bold, false);
            if ($result->height <= $height) {
                return $result;
            }
        }
        $size = $ladder[count($ladder) - 1];
        $graphemes = TextLayout::graphemes($text);
        while (++$attempts <= self::MAX_ATTEMPTS && count($graphemes) > 1) {
            $graphemes = array_slice($graphemes, 0, max(1, (int) floor(count($graphemes) * 0.7)));
            $candidate = rtrim(implode('', $graphemes)) . TextLayout::ELLIPSIS;
            $result = $this->draw($candidate, $family, $size, $width, $colour, $bold, false);
            if ($result->height <= $height) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Lays out one line at a fixed size, ellipsised at `$maxWidth`. The layer
     * is only as wide as the text.
     */
    public function line(string $text, string $font, int $size, int $maxWidth, string $colour, bool $bold): ?PangoResult
    {
        $family = FontInfo::familyName($font);
        if ($family === '') {
            return null;
        }
        $result = $this->draw($text, $family, $size, 0, $colour, $bold, true);
        if ($result->width > $maxWidth) {
            $result = $this->draw($text, $family, $size, $maxWidth, $colour, $bold, true);
        }
        return $result;
    }

    private function draw(string $text, string $family, int $sizePx, int $width, string $colour, bool $bold, bool $singleLine): PangoResult
    {
        // Pango sizes are points at 96 dpi, in 1024ths.
        $size = (int) round($sizePx * 0.75 * 1024);
        $markup = sprintf(
            '<span font_family="%s" font_weight="%s" size="%d" foreground="%s">%s</span>',
            self::escape($family),
            $bold ? 'bold' : 'normal',
            $size,
            Settings::isColour($colour) ? $colour : '#ffffff',
            self::escape($text)
        );
        $im = new \Imagick();
        try {
            $im->setBackgroundColor(new \ImagickPixel('transparent'));
            $im->setOption('pango:auto-dir', 'false');
            // "left" is the natural start edge: right-to-left text, once
            // Pango detects it, ends up right-aligned.
            $im->setOption('pango:align', 'left');
            if ($singleLine) {
                $im->setOption('pango:single-paragraph', 'true');
                $im->setOption('pango:ellipsize', 'end');
            } else {
                $im->setOption('pango:wrap', 'word-char');
            }
            if ($width > 0) {
                $im->setSize($width, 0);
            }
            $im->readImage('pango:' . self::literal($markup));
            $im->setImageFormat('png');
            return new PangoResult($im->getImageBlob(), $im->getImageWidth(), $im->getImageHeight(), $sizePx);
        } catch (\ImagickException $e) {
            throw new RenderException('The Pango text layer failed.', 0, $e);
        } finally {
            $im->clear();
            $im->destroy();
        }
    }
}
