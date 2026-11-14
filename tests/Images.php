<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

/**
 * Test images written with GD, and pixel sampling helpers.
 */
final class Images
{
    /**
     * Writes an image. JPEGs are a left-right red/blue split, so crops can be
     * told apart; PNGs are half transparent.
     */
    public static function write(string $path, int $width, int $height, string $mime = 'image/jpeg', bool $noise = false): void
    {
        if ($mime === 'image/svg+xml') {
            file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"><rect width="100%" height="100%" fill="red"/></svg>');
            return;
        }
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $red = imagecolorallocate($im, 230, 30, 30);
        $blue = imagecolorallocate($im, 30, 30, 230);
        imagefilledrectangle($im, 0, 0, intdiv($width, 2) - 1, $height - 1, $red);
        imagefilledrectangle($im, intdiv($width, 2), 0, $width - 1, $height - 1, $blue);
        if ($noise) {
            mt_srand(42);
            for ($i = 0; $i < $width * $height / 2; $i++) {
                imagesetpixel($im, mt_rand(0, $width - 1), mt_rand(0, $height - 1), mt_rand(0, 0xFFFFFF));
            }
        }
        if ($mime === 'image/png') {
            $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefilledrectangle($im, 0, 0, $width - 1, intdiv($height, 2) - 1, $clear);
            imagepng($im, $path);
        } elseif ($mime === 'image/gif') {
            imagegif($im, $path);
        } elseif ($mime === 'image/webp') {
            imagewebp($im, $path);
        } else {
            imagejpeg($im, $path, 90);
        }
        imagedestroy($im);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function pixel(string $jpeg, int $x, int $y): array
    {
        $im = imagecreatefromstring((string) file_get_contents($jpeg));
        if ($im === false) {
            throw new \RuntimeException('Not an image: ' . $jpeg);
        }
        $c = imagecolorat($im, $x, $y);
        imagedestroy($im);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     */
    public static function close(array $a, array $b, int $tolerance = 12): bool
    {
        return abs($a[0] - $b[0]) <= $tolerance && abs($a[1] - $b[1]) <= $tolerance && abs($a[2] - $b[2]) <= $tolerance;
    }

    /**
     * Counts pixels in a region that differ from a colour by more than the
     * tolerance (ink).
     *
     * @param array{0: int, 1: int, 2: int} $background
     */
    public static function ink(string $jpeg, int $x0, int $y0, int $x1, int $y1, array $background, int $tolerance = 40): int
    {
        $im = imagecreatefromstring((string) file_get_contents($jpeg));
        if ($im === false) {
            throw new \RuntimeException('Not an image.');
        }
        $count = 0;
        for ($y = $y0; $y < $y1; $y += 2) {
            for ($x = $x0; $x < $x1; $x += 2) {
                $c = imagecolorat($im, $x, $y);
                $px = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                if (!self::close($px, $background, $tolerance)) {
                    $count++;
                }
            }
        }
        imagedestroy($im);
        return $count;
    }

    /**
     * Whether a JPEG is progressive (has an SOF2 marker).
     */
    public static function progressive(string $jpeg): bool
    {
        return str_contains((string) file_get_contents($jpeg), "\xFF\xC2");
    }

    public static function hasExif(string $jpeg): bool
    {
        return str_contains((string) file_get_contents($jpeg), "Exif\x00\x00");
    }
}
