<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * GD drawing backend.
 */
final class GdCanvas implements Canvas
{
    private ?\GdImage $image = null;

    private GdMeasurer $measurer;

    public function __construct()
    {
        $this->measurer = new GdMeasurer();
    }

    public static function usable(): bool
    {
        if (!function_exists('gd_info') || !function_exists('imagettftext')) {
            return false;
        }
        $info = gd_info();
        return !empty($info['JPEG Support']) && !empty($info['FreeType Support']);
    }

    public function width(string $text, string $fontPath, float $sizePx): float
    {
        return $this->measurer->width($text, $fontPath, $sizePx);
    }

    public function create(int $width, int $height, string $background): void
    {
        if ($width < 1 || $height < 1) {
            throw new RenderException('The canvas must be at least 1 by 1 pixel.');
        }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RenderException('GD could not allocate the canvas.');
        }
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $this->colour($image, $background, 1.0));
        $this->image = $image;
    }

    public function supports(int $imageType): bool
    {
        $bits = imagetypes();
        return match ($imageType) {
            IMAGETYPE_JPEG => ($bits & IMG_JPG) !== 0,
            IMAGETYPE_PNG => ($bits & IMG_PNG) !== 0,
            IMAGETYPE_GIF => ($bits & IMG_GIF) !== 0,
            IMAGETYPE_WEBP => ($bits & IMG_WEBP) !== 0,
            default => false,
        };
    }

    public function drawBackdrop(SourceImage $source, string $background, float $scrimOpacity): void
    {
        $canvas = $this->canvas();
        $w = imagesx($canvas);
        $h = imagesy($canvas);
        $src = $this->decode($source);
        try {
            [$sx, $sy, $sw, $sh] = Geometry::coverCrop($source->width, $source->height, $w, $h, $source->focalX, $source->focalY);
            $layer = imagecreatetruecolor($w, $h);
            if ($layer === false) {
                throw new SourceImageException('GD could not allocate the backdrop layer.');
            }
            // Transparent areas show the background colour.
            imagefilledrectangle($layer, 0, 0, $w - 1, $h - 1, $this->colour($layer, $background, 1.0));
            imagealphablending($layer, true);
            imagecopyresampled($layer, $src, 0, 0, $sx, $sy, $w, $h, $sw, $sh);

            // Saturation to 65%: mix 35% of a grey copy back in.
            $grey = imagecreatetruecolor($w, $h);
            if ($grey !== false) {
                imagecopy($grey, $layer, 0, 0, 0, 0, $w, $h);
                imagefilter($grey, IMG_FILTER_GRAYSCALE);
                imagecopymerge($layer, $grey, 0, 0, 0, 0, $w, $h, 35);
                imagedestroy($grey);
            }
            // Brightness to 55%: black at 45% opacity.
            imagefilledrectangle($layer, 0, 0, $w - 1, $h - 1, $this->colour($layer, '#000000', 0.45));
            // Scrim of the background colour.
            imagefilledrectangle($layer, 0, 0, $w - 1, $h - 1, $this->colour($layer, $background, $scrimOpacity));

            imagecopy($canvas, $layer, 0, 0, 0, 0, $w, $h);
            imagedestroy($layer);
        } finally {
            imagedestroy($src);
        }
    }

    public function drawText(string $text, string $font, float $sizePx, string $colour, int $x, int $baseline): void
    {
        $canvas = $this->canvas();
        $c = $this->colour($canvas, $colour, 1.0);
        $result = Errors::call(
            static fn () => imagettftext($canvas, GdMeasurer::points($sizePx), 0, $x, $baseline, $c, $font, $text),
            RenderException::class
        );
        if ($result === false) {
            throw new RenderException('GD could not draw text with font ' . basename($font));
        }
    }

    public function drawLayer(string $png, int $x, int $y): void
    {
        $layer = Errors::call(static fn () => imagecreatefromstring($png), RenderException::class);
        if ($layer === false) {
            throw new RenderException('Could not decode the text layer.');
        }
        imagealphablending($this->canvas(), true);
        imagecopy($this->canvas(), $layer, $x, $y, 0, 0, imagesx($layer), imagesy($layer));
        imagedestroy($layer);
    }

    public function drawImage(SourceImage $source, int $x, int $y, int $width, int $height): void
    {
        $src = $this->decode($source);
        imagealphablending($this->canvas(), true);
        imagecopyresampled($this->canvas(), $src, $x, $y, 0, 0, $width, $height, imagesx($src), imagesy($src));
        imagedestroy($src);
    }

    public function fillRect(int $x, int $y, int $width, int $height, string $colour, float $opacity): void
    {
        $canvas = $this->canvas();
        imagefilledrectangle($canvas, $x, $y, $x + $width - 1, $y + $height - 1, $this->colour($canvas, $colour, $opacity));
    }

    public function encode(int $quality): string
    {
        $canvas = $this->canvas();
        imageinterlace($canvas, true);
        ob_start();
        try {
            $ok = imagejpeg($canvas, null, $quality);
        } finally {
            $data = (string) ob_get_clean();
        }
        if (!$ok || $data === '') {
            throw new RenderException('GD could not encode the JPEG.');
        }
        return $data;
    }

    public function destroy(): void
    {
        if ($this->image !== null) {
            imagedestroy($this->image);
            $this->image = null;
        }
    }

    private function canvas(): \GdImage
    {
        if ($this->image === null) {
            throw new RenderException('Canvas not created.');
        }
        return $this->image;
    }

    private function decode(SourceImage $source): \GdImage
    {
        $path = $source->path;
        $fn = match ($source->type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };
        if ($fn === null || !function_exists($fn)) {
            throw new SourceImageException('Unsupported source type for GD.');
        }
        try {
            $image = Errors::call(static fn () => $fn($path), SourceImageException::class);
        } catch (\ValueError $e) {
            throw new SourceImageException($e->getMessage(), 0, $e);
        }
        if (!$image instanceof \GdImage) {
            throw new SourceImageException('GD could not decode the source image.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return $image;
    }

    private function colour(\GdImage $image, string $hex, float $opacity): int
    {
        [$r, $g, $b] = Geometry::rgb($hex);
        $alpha = max(0, min(127, (int) round(127 * (1 - max(0.0, min(1.0, $opacity))))));
        $c = imagecolorallocatealpha($image, $r, $g, $b, $alpha);
        return $c === false ? 0 : $c;
    }
}
