<?php

/**
 * Public functions for themes.
 */

declare(strict_types=1);

use IndianOpinion\ShareCards\Plugin;

if (!function_exists('share_cards_image_for_request')) {
    /**
     * The image the current request advertises, or null.
     *
     * @return array<string, mixed>|null Keys: url, width, height, type, alt, source.
     */
    function share_cards_image_for_request(): ?array
    {
        return Plugin::instance()->chooser()->forRequest();
    }
}

if (!function_exists('share_cards_url_for_post')) {
    /**
     * The current card URL of a post, or null when it is not eligible.
     */
    function share_cards_url_for_post(int|\WP_Post $post): ?string
    {
        $post = get_post($post);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $plugin = Plugin::instance();
        $card = $plugin->chooser()->card($plugin->resolver()->resolvePost($post));
        return $card === null ? null : (string) $card['url'];
    }
}

if (!function_exists('share_cards_url_for_term')) {
    /**
     * The current card URL of a term, or null when it is not eligible.
     */
    function share_cards_url_for_term(int|\WP_Term $term): ?string
    {
        $term = $term instanceof \WP_Term ? $term : get_term($term);
        if (!$term instanceof \WP_Term) {
            return null;
        }
        $plugin = Plugin::instance();
        $card = $plugin->chooser()->card($plugin->resolver()->resolveTerm($term));
        return $card === null ? null : (string) $card['url'];
    }
}

if (!function_exists('share_cards_url_for')) {
    /**
     * The current card URL of any subject type, or null.
     */
    function share_cards_url_for(string $type, string $id): ?string
    {
        $plugin = Plugin::instance();
        $subject = $plugin->resolver()->resolve($type, $id);
        if ($subject === null) {
            return null;
        }
        $card = $plugin->chooser()->card($subject);
        return $card === null ? null : (string) $card['url'];
    }
}
