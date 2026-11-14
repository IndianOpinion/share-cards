<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Seo;

/**
 * Hands the chosen image to an SEO plugin through that plugin's own public
 * filters, so the page ends up with exactly one og:image set.
 */
interface SeoAdapter
{
    /**
     * Whether the SEO plugin is active on this request.
     */
    public function isActive(): bool;

    /**
     * Hooks the SEO plugin's filters.
     *
     * @param callable(): (array<string, mixed>|null) $imageProvider Returns the
     *        image for the current request (url, width, height, type, alt), or
     *        null to leave the SEO plugin's own choice alone.
     */
    public function register(callable $imageProvider): void;
}
