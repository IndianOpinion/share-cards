<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Draws one card to a file.
 */
interface Renderer
{
    /**
     * `gd`, `imagick`, `gd+pango` or `imagick+pango`. Part of the fingerprint,
     * because output differs between engines.
     */
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * Whether complex scripts (Devanagari, Arabic, Thai and so on) can be drawn.
     */
    public function canShape(): bool;

    /**
     * Renders a 1200 by 630 progressive JPEG to `$dest`.
     *
     * @throws RenderException When the card cannot be drawn at all.
     */
    public function render(Subject $s, Template $t, string $dest): void;
}
