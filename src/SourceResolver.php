<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Turns an attachment ID into a local image file that is safe to decode, or
 * refuses with a reason. Nothing here decodes pixels: every check uses the
 * file size and the image header.
 */
final class SourceResolver
{
    private const SAFETY_MARGIN = 16777216;

    /** @var callable(): int */
    private $memoryAvailable;

    private string $reason = '';

    /**
     * @param callable(): int|null $memoryAvailable Bytes free for a decode.
     */
    public function __construct(
        private int $maxBytes = 20971520,
        private int $maxPixels = 25000000,
        ?callable $memoryAvailable = null
    ) {
        $this->memoryAvailable = $memoryAvailable ?? static function (): int {
            $limit = Geometry::iniBytes((string) ini_get('memory_limit'));
            if ($limit < 0) {
                return PHP_INT_MAX;
            }
            return $limit - memory_get_usage(true) - self::SAFETY_MARGIN;
        };
    }

    public function lastReason(): string
    {
        return $this->reason;
    }

    /**
     * The file to decode for a backdrop: the smallest intermediate size at
     * least 1200 wide and 630 high, else the full file.
     */
    public static function backdropFile(int $attachmentId): string
    {
        $file = (string) get_attached_file($attachmentId, true);
        if ($file === '') {
            return '';
        }
        $meta = wp_get_attachment_metadata($attachmentId);
        $best = null;
        if (is_array($meta) && isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!is_array($size) || !isset($size['file'], $size['width'], $size['height'])) {
                    continue;
                }
                $w = (int) $size['width'];
                $h = (int) $size['height'];
                if ($w < Template::WIDTH || $h < Template::HEIGHT) {
                    continue;
                }
                if ($best === null || $w * $h < $best[0]) {
                    $best = [$w * $h, (string) $size['file']];
                }
            }
        }
        if ($best !== null && basename($best[1]) === $best[1]) {
            return dirname($file) . '/' . $best[1];
        }
        return $file;
    }

    /**
     * @param callable(int): bool $supports Whether the canvas decodes an IMAGETYPE_*.
     * @param bool $backdrop True for a backdrop (intermediate size choice, focal point).
     */
    public function resolve(int $attachmentId, callable $supports, bool $backdrop = true): ?SourceImage
    {
        $this->reason = '';
        if ($attachmentId <= 0) {
            $this->reason = 'none';
            return null;
        }
        $path = $backdrop ? self::backdropFile($attachmentId) : (string) get_attached_file($attachmentId, true);
        if ($backdrop) {
            $filtered = apply_filters('share_cards_backdrop_path', $path, $attachmentId);
            $path = is_string($filtered) ? $filtered : '';
        }
        if ($path === '' || str_contains($path, '://')) {
            $this->reason = 'not local';
            return null;
        }

        // C62: inside the uploads directory, after resolving links, and present.
        $real = realpath($path);
        $uploads = wp_upload_dir(null, false);
        $base = is_array($uploads) && is_string($uploads['basedir'] ?? null) ? realpath($uploads['basedir']) : false;
        if ($real === false || $base === false || !str_starts_with($real, rtrim($base, '/') . '/') || !is_file($real)) {
            $this->reason = 'not local';
            return null;
        }

        // C59: byte cap before anything else reads the file.
        $bytes = filesize($real);
        if ($bytes === false || $bytes > $this->maxBytes) {
            $this->reason = 'over byte cap';
            return null;
        }

        try {
            $info = Errors::call(static fn () => getimagesize($real), SourceImageException::class);
        } catch (SourceImageException) {
            $this->reason = 'unsupported';
            return null;
        }
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            $this->reason = 'unsupported';
            return null;
        }
        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($width * $height > $this->maxPixels) {
            $this->reason = 'over pixel cap';
            return null;
        }

        // C60: types we decode, when the canvas can; never CMYK JPEG.
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true) || !$supports($type)) {
            $this->reason = 'unsupported';
            return null;
        }
        if ($type === IMAGETYPE_JPEG && isset($info['channels']) && (int) $info['channels'] === 4) {
            $this->reason = 'cmyk';
            return null;
        }

        // C61: a JPEG cut short decodes without any warning in GD, as a grey
        // lower half. Refuse a JPEG with no end-of-image marker near its end.
        if ($type === IMAGETYPE_JPEG && !self::hasJpegEnd($real, $bytes)) {
            $this->reason = 'truncated';
            return null;
        }

        // C65: memory headroom.
        if (Geometry::decodeEstimate($width, $height) > ($this->memoryAvailable)()) {
            $this->reason = 'memory';
            return null;
        }

        $fx = 0.5;
        $fy = 0.5;
        if ($backdrop) {
            $focal = apply_filters('share_cards_focal_point', [0.5, 0.5], $attachmentId);
            if (is_array($focal) && isset($focal[0], $focal[1]) && is_numeric($focal[0]) && is_numeric($focal[1])) {
                $fx = max(0.0, min(1.0, (float) $focal[0]));
                $fy = max(0.0, min(1.0, (float) $focal[1]));
            }
        }
        return new SourceImage($real, $width, $height, $type, $attachmentId, $fx, $fy);
    }

    private static function hasJpegEnd(string $path, int $bytes): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            fseek($handle, max(0, $bytes - 64));
            $tail = (string) fread($handle, 64);
        } finally {
            fclose($handle);
        }
        return str_contains($tail, "\xFF\xD9");
    }
}
