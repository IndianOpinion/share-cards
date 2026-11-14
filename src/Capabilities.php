<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * What this PHP build can do. Checked once, cached in a transient for five
 * minutes, cleared when settings are saved.
 */
final class Capabilities
{
    public const TRANSIENT = 'share_cards_caps';
    public const TTL = 300;

    /**
     * @return array<string, bool>
     */
    public static function detect(string $cacheDirectory): array
    {
        $gd = function_exists('gd_info') ? gd_info() : [];
        return [
            'gd' => $gd !== [],
            'gd_jpeg' => !empty($gd['JPEG Support']),
            'gd_png' => !empty($gd['PNG Support']),
            'gd_webp' => !empty($gd['WebP Support']),
            'gd_freetype' => !empty($gd['FreeType Support']) && function_exists('imagettftext'),
            'imagick' => extension_loaded('imagick') && class_exists(\Imagick::class),
            'imagick_jpeg' => ImagickCanvas::usable(),
            'pango' => PangoTextLayer::isAvailable(),
            'intl' => class_exists(\Normalizer::class),
            'cache_writable' => (new Cache($cacheDirectory))->isWritable(),
        ];
    }

    /**
     * Cached capabilities, after the `share_cards_capabilities` filter.
     *
     * @return array<string, bool>
     */
    public static function get(string $cacheDirectory): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (!is_array($cached) || !isset($cached['gd'], $cached['cache_writable'])) {
            $cached = self::detect($cacheDirectory);
            set_transient(self::TRANSIENT, $cached, self::TTL);
        }
        $filtered = apply_filters('share_cards_capabilities', $cached);
        $out = [];
        foreach (is_array($filtered) ? $filtered : $cached as $k => $v) {
            $out[(string) $k] = (bool) $v;
        }
        return $out;
    }

    /**
     * @param array<string, bool> $caps
     */
    public static function gdUsable(array $caps): bool
    {
        return !empty($caps['gd']) && !empty($caps['gd_jpeg']) && !empty($caps['gd_freetype']);
    }

    /**
     * @param array<string, bool> $caps
     */
    public static function imagickUsable(array $caps): bool
    {
        return !empty($caps['imagick']) && !empty($caps['imagick_jpeg']);
    }

    /**
     * What is missing for any renderer to work, for notices and Site Health.
     *
     * @param array<string, bool> $caps
     * @return list<string>
     */
    public static function missing(array $caps): array
    {
        if (self::gdUsable($caps) || self::imagickUsable($caps)) {
            return [];
        }
        $out = [];
        if (empty($caps['gd'])) {
            $out[] = 'ext-gd';
        } else {
            if (empty($caps['gd_jpeg'])) {
                $out[] = 'JPEG support in GD';
            }
            if (empty($caps['gd_freetype'])) {
                $out[] = 'FreeType support in GD';
            }
        }
        if (empty($caps['imagick'])) {
            $out[] = 'ext-imagick (alternative)';
        }
        return $out;
    }
}
