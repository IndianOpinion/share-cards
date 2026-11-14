<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Imagick drawing backend. Every Imagick object is cleared and destroyed
 * explicitly; reference counting alone does not reliably free its memory.
 */
final class ImagickCanvas implements Canvas
{
    private ?\Imagick $image = null;

    /** @var array<string, float> */
    private array $memo = [];

    private ?\Imagick $probe = null;

    public static function usable(): bool
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return \Imagick::queryFormats('JPEG') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function width(string $text, string $fontPath, float $sizePx): float
    {
        if ($text === '') {
            return 0.0;
        }
        $memoKey = $fontPath . "\0" . $sizePx . "\0" . $text;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        if (!Fonts::usable($fontPath)) {
            throw new RenderException('Font file is not readable: ' . basename($fontPath));
        }
        if ($this->probe === null) {
            $this->probe = new \Imagick();
            $this->probe->newImage(1, 1, new \ImagickPixel('transparent'));
        }
        $draw = $this->draw($fontPath, $sizePx, '#000000');
        try {
            $metrics = $this->probe->queryFontMetrics($draw, self::safe($text));
        } catch (\ImagickException $e) {
            throw new RenderException('Could not measure text with font ' . basename($fontPath), 0, $e);
        } finally {
            $draw->clear();
            $draw->destroy();
        }
        if (count($this->memo) > 2000) {
            $this->memo = [];
        }
        return $this->memo[$memoKey] = (float) $metrics['textWidth'];
    }

    public function create(int $width, int $height, string $background): void
    {
        try {
            $image = new \Imagick();
            $image->newImage($width, $height, new \ImagickPixel($background));
            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->setImageFormat('png');
            $this->image = $image;
        } catch (\ImagickException $e) {
            throw new RenderException('Imagick could not allocate the canvas.', 0, $e);
        }
    }

    public function supports(int $imageType): bool
    {
        $format = match ($imageType) {
            IMAGETYPE_JPEG => 'JPEG',
            IMAGETYPE_PNG => 'PNG',
            IMAGETYPE_GIF => 'GIF',
            IMAGETYPE_WEBP => 'WEBP',
            default => '',
        };
        if ($format === '') {
            return false;
        }
        try {
            return \Imagick::queryFormats($format) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function drawBackdrop(SourceImage $source, string $background, float $scrimOpacity): void
    {
        $canvas = $this->canvas();
        $w = $canvas->getImageWidth();
        $h = $canvas->getImageHeight();
        $src = $this->decode($source, $background);
        try {
            [$sx, $sy, $sw, $sh] = Geometry::coverCrop($src->getImageWidth(), $src->getImageHeight(), $w, $h, $source->focalX, $source->focalY);
            $src->cropImage($sw, $sh, $sx, $sy);
            $src->setImagePage(0, 0, 0, 0);
            $src->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
            $src->modulateImage(55, 65, 100);
            $canvas->compositeImage($src, \Imagick::COMPOSITE_OVER, 0, 0);
        } catch (\ImagickException $e) {
            throw new SourceImageException('Imagick could not process the backdrop.', 0, $e);
        } finally {
            $src->clear();
            $src->destroy();
        }
        $this->fillRect(0, 0, $w, $h, $background, $scrimOpacity);
    }

    public function drawText(string $text, string $font, float $sizePx, string $colour, int $x, int $baseline): void
    {
        $draw = $this->draw($font, $sizePx, $colour);
        try {
            $this->canvas()->annotateImage($draw, $x, $baseline, 0, self::safe($text));
        } catch (\ImagickException $e) {
            throw new RenderException('Imagick could not draw text with font ' . basename($font), 0, $e);
        } finally {
            $draw->clear();
            $draw->destroy();
        }
    }

    public function drawLayer(string $png, int $x, int $y): void
    {
        $layer = new \Imagick();
        try {
            $layer->setFormat('png');
            $layer->readImageBlob($png);
            $this->canvas()->compositeImage($layer, \Imagick::COMPOSITE_OVER, $x, $y);
        } catch (\ImagickException $e) {
            throw new RenderException('Could not composite the text layer.', 0, $e);
        } finally {
            $layer->clear();
            $layer->destroy();
        }
    }

    public function drawImage(SourceImage $source, int $x, int $y, int $width, int $height): void
    {
        $src = $this->decode($source, null);
        try {
            $src->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            $this->canvas()->compositeImage($src, \Imagick::COMPOSITE_OVER, $x, $y);
        } catch (\ImagickException $e) {
            throw new SourceImageException('Imagick could not draw the image.', 0, $e);
        } finally {
            $src->clear();
            $src->destroy();
        }
    }

    public function fillRect(int $x, int $y, int $width, int $height, string $colour, float $opacity): void
    {
        $draw = new \ImagickDraw();
        try {
            $draw->setFillColor(new \ImagickPixel($colour));
            $draw->setFillOpacity(max(0.0, min(1.0, $opacity)));
            $draw->rectangle($x, $y, $x + $width - 1, $y + $height - 1);
            $this->canvas()->drawImage($draw);
        } catch (\ImagickException $e) {
            throw new RenderException('Imagick could not fill a rectangle.', 0, $e);
        } finally {
            $draw->clear();
            $draw->destroy();
        }
    }

    public function encode(int $quality): string
    {
        $out = clone $this->canvas();
        try {
            $out->setImageFormat('jpeg');
            $out->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $out->setImageCompressionQuality($quality);
            $out->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            $out->setImageInterlaceScheme(\Imagick::INTERLACE_PLANE);
            $out->stripImage();
            return $out->getImageBlob();
        } catch (\ImagickException $e) {
            throw new RenderException('Imagick could not encode the JPEG.', 0, $e);
        } finally {
            $out->clear();
            $out->destroy();
        }
    }

    public function destroy(): void
    {
        foreach ([$this->image, $this->probe] as $im) {
            if ($im !== null) {
                $im->clear();
                $im->destroy();
            }
        }
        $this->image = null;
        $this->probe = null;
    }

    private function canvas(): \Imagick
    {
        if ($this->image === null) {
            throw new RenderException('Canvas not created.');
        }
        return $this->image;
    }

    private function draw(string $font, float $sizePx, string $colour): \ImagickDraw
    {
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setFontSize($sizePx);
        $draw->setFillColor(new \ImagickPixel($colour));
        $draw->setTextAntialias(true);
        return $draw;
    }

    /**
     * A leading `@` asks some ImageMagick builds to read text from a file.
     * A word joiner in front keeps the text literal and draws nothing.
     */
    private static function safe(string $text): string
    {
        return str_starts_with($text, '@') ? "\u{2060}" . $text : $text;
    }

    /**
     * Reads the first frame of a source with its coder fixed by type, so a
     * file can never be read as a script format (SVG, MVG, MSL).
     */
    private function decode(SourceImage $source, ?string $background): \Imagick
    {
        $format = match ($source->type) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => '',
        };
        if ($format === '') {
            throw new SourceImageException('Unsupported source type for Imagick.');
        }
        $blob = file_get_contents($source->path);
        if ($blob === false || $blob === '') {
            throw new SourceImageException('Could not read the source image.');
        }
        $all = new \Imagick();
        try {
            $all->setFormat($format);
            $all->readImageBlob($blob, $format . ':source');
            $all->setFirstIterator();
            $frame = $all->getImage();
        } catch (\ImagickException $e) {
            $all->clear();
            $all->destroy();
            throw new SourceImageException('Imagick could not decode the source image.', 0, $e);
        }
        $all->clear();
        $all->destroy();
        try {
            $frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            if ($background !== null && $frame->getImageAlphaChannel()) {
                $frame->setImageBackgroundColor(new \ImagickPixel($background));
                $flat = $frame->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $frame->clear();
                $frame->destroy();
                $frame = $flat;
            }
            $frame->setImagePage(0, 0, 0, 0);
        } catch (\ImagickException $e) {
            $frame->clear();
            $frame->destroy();
            throw new SourceImageException('Imagick could not prepare the source image.', 0, $e);
        }
        return $frame;
    }
}
