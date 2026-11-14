<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The one drawing model. Computes the layout in plain PHP and drives a
 * canvas: background, backdrop, kicker, headline, footer, footnote, accent.
 *
 * A field is drawn by the canvas when its scripts are safe for plain text
 * drawing, by the Pango layer when they need shaping and Pango is present,
 * and not at all otherwise. A card with a blank field is fine; a card with
 * broken text is not.
 */
final class CardComposer
{
    public const MAX_BYTES = 307200;
    public const MIN_QUALITY = 60;

    /** @var list<array{field: string, reason: string, scripts: string}> */
    private array $omitted = [];

    private string $backdropNote = '';

    public function __construct(
        private Canvas $canvas,
        private ?PangoTextLayer $pango,
        private SourceResolver $sources
    ) {
    }

    /**
     * Fields left off the last card, and why.
     *
     * @return list<array{field: string, reason: string, scripts: string}>
     */
    public function omitted(): array
    {
        return $this->omitted;
    }

    /**
     * What happened to the backdrop on the last card: '' (drawn or none
     * asked), or a refusal or failure reason.
     */
    public function backdropNote(): string
    {
        return $this->backdropNote;
    }

    /**
     * Draws the card and returns the encoded JPEG.
     *
     * @throws RenderException
     */
    public function compose(Subject $subject, Template $t): string
    {
        $this->omitted = [];
        $this->backdropNote = '';
        $fonts = $t->fonts();
        if (!$fonts->hasBase()) {
            $check = $fonts->check();
            throw new RenderException($check instanceof \WP_Error ? $check->get_error_message() : 'No font found for the Latin script.');
        }

        $w = Template::WIDTH;
        $h = Template::HEIGHT;
        $bg = $t->string('colour_background');
        $pad = $t->int('padding');
        $boxW = $t->boxWidth();

        $this->canvas->create($w, $h, $bg);
        try {
            $this->drawBackdrop($subject, $t);

            // Kicker.
            $kicker = $subject->kicker;
            if ($kicker !== '' && $t->bool('kicker_uppercase')) {
                $kicker = TextLayout::upper($kicker);
            }
            $this->drawLine('kicker', $kicker, true, $t->int('kicker_size'), $t->string('colour_kicker'), $pad, $t->int('kicker_baseline'), $boxW, false, $t);

            // Headline.
            $this->drawHeadline($subject->headline, $t);

            // Footer: logo, site name, or both, bottom left.
            $footer = $t->string('footer');
            $footerBaseline = $t->int('footer_baseline');
            $footerSize = $t->int('footer_size');
            $x = $pad;
            $footerWidth = (int) round($boxW * 0.55);
            if (in_array($footer, ['logo', 'both'], true) && $t->int('logo_id') > 0) {
                $x += $this->drawLogo($t->int('logo_id'), $pad, $footerBaseline, $t);
            }
            if (in_array($footer, ['name', 'both'], true)) {
                $this->drawLine('footer', $t->string('site_name'), true, $footerSize, $t->string('colour_text'), $x, $footerBaseline, max(1, $footerWidth - ($x - $pad)), false, $t);
            }

            // Footnote, right-aligned.
            $this->drawLine('footnote', $subject->footnote, false, $t->int('footnote_size'), $t->string('colour_muted'), $w - $pad, $footerBaseline, (int) round($boxW * 0.4), true, $t);

            // Accent bar along the bottom edge.
            $accent = $t->int('accent_height');
            if ($accent > 0) {
                $this->canvas->fillRect(0, $h - $accent, $w, $accent, $t->string('colour_accent'), 1.0);
            }

            return $this->encode($t->int('jpeg_quality'));
        } finally {
            $this->canvas->destroy();
        }
    }

    private function encode(int $quality): string
    {
        $data = $this->canvas->encode($quality);
        while (strlen($data) > self::MAX_BYTES && $quality > self::MIN_QUALITY) {
            $quality = max(self::MIN_QUALITY, $quality - 10);
            $data = $this->canvas->encode($quality);
        }
        return $data;
    }

    private function drawBackdrop(Subject $subject, Template $t): void
    {
        if ($subject->backdropId <= 0 || !$t->bool('backdrop')) {
            return;
        }
        $source = $this->sources->resolve($subject->backdropId, fn (int $type): bool => $this->canvas->supports($type), true);
        if ($source === null) {
            $reason = $this->sources->lastReason();
            $this->backdropNote = $reason === 'truncated'
                ? 'decode failed for attachment ' . $subject->backdropId . ': truncated file'
                : $reason;
            return;
        }
        try {
            $this->canvas->drawBackdrop($source, $t->string('colour_background'), $t->float('scrim_opacity'));
        } catch (SourceImageException $e) {
            // A bad source never kills the card.
            $this->backdropNote = 'decode failed for attachment ' . $source->attachmentId . ': ' . $e->getMessage();
        }
    }

    private function drawHeadline(string $text, Template $t): void
    {
        if ($text === '') {
            return;
        }
        $font = $this->fontFor('headline', $text, true, $t);
        if ($font === null) {
            return;
        }
        $pad = $t->int('padding');
        $top = $t->int('headline_top');
        $boxH = $t->int('headline_height');
        $boxW = $t->boxWidth();
        $colour = $t->string('colour_text');

        if ($this->needsShaping($text)) {
            if ($this->pango === null) {
                $this->omit('headline', 'no shaping', $text);
                return;
            }
            $layer = $this->pango->paragraph($text, $font, $t->ladder(), $boxW, $boxH, $colour, true);
            if ($layer === null) {
                $this->omit('headline', 'does not fit', $text);
                return;
            }
            $this->canvas->drawLayer($layer->png, $pad, $top);
            return;
        }

        $fit = (new TextLayout($this->canvas))->fit($text, $font, $t->ladder(), $boxW, $boxH, $t->int('max_lines'));
        foreach ($fit->lines as $i => $line) {
            $baseline = (int) round($top + $fit->size + $i * $fit->lineHeight);
            $this->canvas->drawText($line, $font, $fit->size, $colour, $pad, $baseline);
        }
    }

    /**
     * Draws one single-line field. With `$alignRight`, `$x` is the right edge.
     */
    private function drawLine(string $field, string $text, bool $bold, int $size, string $colour, int $x, int $baseline, int $maxWidth, bool $alignRight, Template $t): void
    {
        if ($text === '' || $size <= 0) {
            return;
        }
        $font = $this->fontFor($field, $text, $bold, $t);
        if ($font === null) {
            return;
        }
        if ($this->needsShaping($text)) {
            if ($this->pango === null) {
                $this->omit($field, 'no shaping', $text);
                return;
            }
            $layer = $this->pango->line($text, $font, $size, $maxWidth, $colour, $bold);
            if ($layer === null) {
                $this->omit($field, 'no font family', $text);
                return;
            }
            // Pango's box starts a little above the ascender.
            $top = (int) round($baseline - $size * 1.05);
            $left = $alignRight ? $x - $layer->width : $x;
            $this->canvas->drawLayer($layer->png, $left, $top);
            return;
        }
        $line = (new TextLayout($this->canvas))->fitLine($text, $font, $size, $maxWidth);
        $left = $alignRight ? (int) round($x - $this->canvas->width($line, $font, $size)) : $x;
        $this->canvas->drawText($line, $font, $size, $colour, $left, $baseline);
    }

    /**
     * Draws the logo, bottom-aligned near the footer baseline. Returns the
     * horizontal space it took, including a gap.
     */
    private function drawLogo(int $logoId, int $x, int $baseline, Template $t): int
    {
        $source = $this->sources->resolve($logoId, fn (int $type): bool => $this->canvas->supports($type), false);
        if ($source === null) {
            return 0;
        }
        $maxH = max(1, $t->int('logo_max_height'));
        $height = min($maxH, $source->height);
        $width = max(1, (int) round($source->width * $height / $source->height));
        $width = min($width, (int) round($t->boxWidth() * 0.4));
        $height = max(1, (int) round($source->height * $width / $source->width));
        try {
            $this->canvas->drawImage($source, $x, $baseline - $height + 8, $width, $height);
        } catch (SourceImageException) {
            return 0;
        }
        return $width + 20;
    }

    private function fontFor(string $field, string $text, bool $bold, Template $t): ?string
    {
        $font = $t->fonts()->forText($text, $bold);
        if ($font === null) {
            $this->omit($field, 'no font', $text);
        }
        return $font;
    }

    private function needsShaping(string $text): bool
    {
        return (bool) apply_filters('share_cards_needs_shaping', TextLayout::needsShaping($text), $text);
    }

    private function omit(string $field, string $reason, string $text): void
    {
        $this->omitted[] = ['field' => $field, 'reason' => $reason, 'scripts' => implode(',', TextLayout::scripts($text))];
    }
}
