<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Seo;

/**
 * Rank Math, through its documented Open Graph filters:
 *
 * - `rank_math/opengraph/{$network}/image` (string $url), for `facebook`
 *   and `twitter`: replaces the image URL.
 * - `rank_math/opengraph/{$network}/{$og_property}` (string $content): the
 *   generic per-tag filter, used for `og_image_width`, `og_image_height`,
 *   `og_image_type` and `og_image_alt` on the `facebook` network, and
 *   `twitter_image_alt` on `twitter`.
 */
final class RankMathAdapter implements SeoAdapter
{
    public function isActive(): bool
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath', false);
    }

    public function register(callable $imageProvider): void
    {
        $field = static function (string $key) use ($imageProvider): callable {
            return static function ($value) use ($imageProvider, $key) {
                $image = $imageProvider();
                return is_array($image) && isset($image[$key]) ? $image[$key] : $value;
            };
        };
        add_filter('rank_math/opengraph/facebook/image', $field('url'), 20);
        add_filter('rank_math/opengraph/twitter/image', $field('url'), 20);
        add_filter('rank_math/opengraph/facebook/og_image_width', $field('width'), 20);
        add_filter('rank_math/opengraph/facebook/og_image_height', $field('height'), 20);
        add_filter('rank_math/opengraph/facebook/og_image_type', $field('type'), 20);
        add_filter('rank_math/opengraph/facebook/og_image_alt', $field('alt'), 20);
        add_filter('rank_math/opengraph/twitter/twitter_image_alt', $field('alt'), 20);
    }
}
