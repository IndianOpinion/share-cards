<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Prints the image tags when no supported SEO plugin takes them.
 */
final class HeadTags
{
    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * Whether this plugin prints its own tags on this request.
     */
    public function shouldEmit(): bool
    {
        $setting = $this->plugin->settings()->string('emit_tags');
        $emit = match ($setting) {
            'off' => false,
            'on' => true,
            default => $this->plugin->activeAdapter() === null,
        };
        return (bool) apply_filters('share_cards_emit_tags', $emit);
    }

    /**
     * `wp_head` callback, priority 5.
     */
    public function print(): void
    {
        if (!$this->shouldEmit()) {
            return;
        }
        $image = $this->plugin->chooser()->forRequest();
        if ($image !== null) {
            echo self::render($image); // phpcs:ignore -- every value is escaped in render().
        }
    }

    /**
     * The eight tags, in order, every value escaped.
     *
     * @param array<string, mixed> $image
     */
    public static function render(array $image): string
    {
        $url = self::absolute((string) ($image['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $alt = (string) ($image['alt'] ?? '');
        $tags = [
            ['property', 'og:image', esc_url($url)],
            ['property', 'og:image:type', esc_attr((string) ($image['type'] ?? 'image/jpeg'))],
            ['property', 'og:image:width', esc_attr((string) (int) ($image['width'] ?? 0))],
            ['property', 'og:image:height', esc_attr((string) (int) ($image['height'] ?? 0))],
            ['property', 'og:image:alt', esc_attr($alt)],
            ['name', 'twitter:card', 'summary_large_image'],
            ['name', 'twitter:image', esc_url($url)],
            ['name', 'twitter:image:alt', esc_attr($alt)],
        ];
        $out = '';
        foreach ($tags as [$attr, $name, $value]) {
            $out .= sprintf('<meta %s="%s" content="%s" />', $attr, $name, $value) . "\n";
        }
        return $out;
    }

    /**
     * Makes a URL absolute, with the scheme of `home_url()`.
     */
    public static function absolute(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $home = home_url('/');
        $scheme = (string) wp_parse_url($home, PHP_URL_SCHEME);
        if (str_starts_with($url, '//')) {
            $url = ($scheme !== '' ? $scheme : 'https') . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $home), '/') . $url;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return $scheme !== '' ? (string) preg_replace('#^https?://#i', $scheme . '://', $url) : $url;
    }
}
