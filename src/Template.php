<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The resolved layout, colours and fonts for one render.
 */
final class Template
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values, private Fonts $fonts)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $d = Settings::defaults();
        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'padding' => 70,
            'kicker_baseline' => 96,
            'kicker_size' => 30,
            'headline_top' => 150,
            'headline_height' => 340,
            'footer_baseline' => 572,
            'footer_size' => 34,
            'logo_max_height' => 60,
            'footnote_size' => 26,
            'accent_height' => 8,
            'colour_background' => $d['colour_background'],
            'colour_text' => $d['colour_text'],
            'colour_kicker' => $d['colour_kicker'],
            'colour_muted' => $d['colour_muted'],
            'colour_accent' => $d['colour_accent'],
            'size_ladder' => $d['size_ladder'],
            'max_lines' => $d['max_lines'],
            'scrim_opacity' => $d['scrim_opacity'],
            'jpeg_quality' => $d['jpeg_quality'],
            'footer' => $d['footer'],
            'kicker_uppercase' => $d['kicker_uppercase'],
            'backdrop' => $d['backdrop'],
            'logo_id' => 0,
            'site_name' => '',
        ];
    }

    /**
     * Builds a template from settings, then applies `share_cards_template`.
     */
    public static function fromSettings(Settings $settings, Fonts $fonts, string $siteName): self
    {
        $values = self::defaults();
        foreach (['colour_background', 'colour_text', 'colour_kicker', 'colour_muted', 'colour_accent', 'size_ladder', 'max_lines', 'scrim_opacity', 'jpeg_quality', 'footer', 'kicker_uppercase', 'backdrop', 'logo_id'] as $key) {
            $values[$key] = $settings->get($key);
        }
        $values['site_name'] = $siteName;
        $filtered = apply_filters('share_cards_template', $values);
        return self::fromArray(is_array($filtered) ? array_merge($values, $filtered) : $values, $fonts);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values, Fonts $fonts): self
    {
        $values = array_merge(self::defaults(), $values);
        // Output is always 1200 by 630.
        $values['width'] = self::WIDTH;
        $values['height'] = self::HEIGHT;
        foreach (Settings::COLOUR_KEYS as $key) {
            if (!Settings::isColour($values[$key])) {
                $values[$key] = self::defaults()[$key];
            }
        }
        $q = is_numeric($values['jpeg_quality']) ? (int) $values['jpeg_quality'] : 82;
        $values['jpeg_quality'] = max(60, min(95, $q));
        return new self($values, $fonts);
    }

    public function fonts(): Fonts
    {
        return $this->fonts;
    }

    public function int(string $key): int
    {
        $v = $this->values[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }

    public function float(string $key): float
    {
        $v = $this->values[$key] ?? 0;
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public function string(string $key): string
    {
        $v = $this->values[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    /**
     * @return list<int>
     */
    public function ladder(): array
    {
        $v = $this->values['size_ladder'] ?? [];
        $ladder = is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_numeric'))) : [];
        rsort($ladder);
        return $ladder === [] ? [64, 56, 48, 42] : $ladder;
    }

    public function boxWidth(): int
    {
        return self::WIDTH - 2 * $this->int('padding');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
