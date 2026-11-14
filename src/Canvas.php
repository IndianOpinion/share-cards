<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The thin drawing backend behind a renderer. Layout is computed once, in
 * CardComposer; a canvas only measures, draws and encodes.
 */
interface Canvas extends TextMeasurer
{
    public function create(int $width, int $height, string $background): void;

    /**
     * Whether this canvas can decode the given IMAGETYPE_* constant.
     */
    public function supports(int $imageType): bool;

    /**
     * Cover-crops the source to the canvas around its focal point, composites
     * transparency over the background, sets brightness to 55% and
     * saturation to 65%, then lays a scrim of the background colour.
     *
     * @throws SourceImageException When decoding fails.
     */
    public function drawBackdrop(SourceImage $source, string $background, float $scrimOpacity): void;

    /**
     * Draws one line of text with its baseline at `$baseline`.
     *
     * @throws RenderException
     */
    public function drawText(string $text, string $font, float $sizePx, string $colour, int $x, int $baseline): void;

    /**
     * Composites a transparent PNG (a Pango text layer) at its top-left corner.
     *
     * @throws RenderException
     */
    public function drawLayer(string $png, int $x, int $y): void;

    /**
     * Draws an image with alpha, scaled into the given box.
     *
     * @throws SourceImageException
     */
    public function drawImage(SourceImage $source, int $x, int $y, int $width, int $height): void;

    public function fillRect(int $x, int $y, int $width, int $height, string $colour, float $opacity): void;

    /**
     * Encodes a progressive JPEG with no metadata.
     *
     * @throws RenderException
     */
    public function encode(int $quality): string;

    public function destroy(): void;
}
