<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Seo;

/**
 * Yoast SEO, through its documented Open Graph and X filters:
 *
 * - `wpseo_opengraph_image` (string $url): replaces the og:image URL.
 * - `wpseo_opengraph_image_width`, `wpseo_opengraph_image_height`,
 *   `wpseo_opengraph_image_type`: the matching tags.
 * - `wpseo_add_opengraph_images` (action, receives Yoast's image container
 *   with `add_image( array $image )`): adds our image when Yoast found none,
 *   because the URL filter does nothing when there is no image.
 * - `wpseo_twitter_image` (string $url): the twitter:image URL.
 */
final class YoastAdapter implements SeoAdapter
{
    public function isActive(): bool
    {
        return defined('WPSEO_VERSION');
    }

    public function register(callable $imageProvider): void
    {
        $field = static function (string $key) use ($imageProvider): callable {
            return static function ($value) use ($imageProvider, $key) {
                $image = $imageProvider();
                return is_array($image) && isset($image[$key]) ? $image[$key] : $value;
            };
        };
        add_filter('wpseo_opengraph_image', $field('url'), 20);
        add_filter('wpseo_opengraph_image_width', $field('width'), 20);
        add_filter('wpseo_opengraph_image_height', $field('height'), 20);
        add_filter('wpseo_opengraph_image_type', $field('type'), 20);
        add_filter('wpseo_twitter_image', $field('url'), 20);
        add_action('wpseo_add_opengraph_images', static function ($container) use ($imageProvider): void {
            $image = $imageProvider();
            if (!is_array($image) || !is_object($container) || !method_exists($container, 'add_image')) {
                return;
            }
            if (method_exists($container, 'has_images') && $container->has_images()) {
                return;
            }
            $container->add_image([
                'url' => $image['url'],
                'width' => $image['width'],
                'height' => $image['height'],
                'type' => $image['type'],
                'alt' => $image['alt'],
            ]);
        }, 20);
    }
}
