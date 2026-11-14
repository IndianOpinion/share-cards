<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Small pure helpers shared by both canvases.
 */
final class Geometry
{
    /**
     * Source rectangle for a cover crop around a focal point.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height in the source.
     */
    public static function coverCrop(int $srcW, int $srcH, int $dstW, int $dstH, float $fx, float $fy): array
    {
        $scale = max($dstW / max(1, $srcW), $dstH / max(1, $srcH));
        $cropW = max(1, min($srcW, (int) round($dstW / $scale)));
        $cropH = max(1, min($srcH, (int) round($dstH / $scale)));
        $fx = max(0.0, min(1.0, $fx));
        $fy = max(0.0, min(1.0, $fy));
        $x = (int) round($fx * $srcW - $cropW / 2);
        $y = (int) round($fy * $srcH - $cropH / 2);
        $x = max(0, min($srcW - $cropW, $x));
        $y = max(0, min($srcH - $cropH, $y));
        return [$x, $y, $cropW, $cropH];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/D', $hex, $m) !== 1) {
            return [0, 0, 0];
        }
        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }

    /**
     * Memory a decode of this source is estimated to need, in bytes.
     */
    public static function decodeEstimate(int $width, int $height): int
    {
        return $width * $height * 5 + Template::WIDTH * Template::HEIGHT * 5;
    }

    /**
     * Parses a php.ini size such as `128M`. -1 means unlimited.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
