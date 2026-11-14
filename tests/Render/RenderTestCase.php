<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests\Render;

use IndianOpinion\ShareCards\Subject;
use IndianOpinion\ShareCards\Tests\Images;
use IndianOpinion\ShareCards\Tests\TestCase;

abstract class RenderTestCase extends TestCase
{
    /** Default background #1f2937. */
    public const BG = [31, 41, 55];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not installed.');
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function useRenderer(string $renderer, bool $pango, array $settings = []): void
    {
        if ($renderer === 'imagick' && !\IndianOpinion\ShareCards\ImagickCanvas::usable()) {
            self::markTestSkipped('Imagick is not installed.');
        }
        if ($pango && !\IndianOpinion\ShareCards\PangoTextLayer::isAvailable()) {
            self::markTestSkipped('ImageMagick has no Pango format here.');
        }
        if (!$pango) {
            add_filter('share_cards_capabilities', static fn (array $c): array => array_merge($c, ['pango' => false]));
        }
        $this->settings(array_merge(['renderer' => $renderer, 'log_level' => 'debug'], $settings));
    }

    protected function render(Subject $subject): string
    {
        $out = $this->tmp . '/card-' . bin2hex(random_bytes(3)) . '.jpg';
        $this->plugin->renderTo($subject, $out);
        $info = getimagesize($out);
        self::assertIsArray($info);
        self::assertSame([1200, 630, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        return $out;
    }

    protected function requireFont(string $path): string
    {
        if (!is_readable($path)) {
            self::markTestSkipped(basename($path) . ' is not installed.');
        }
        return $path;
    }

    /** Ink in the headline box (150 to 490, 70 to 1130). */
    protected function headlineInk(string $jpeg, int $x0 = 70, int $x1 = 1130): int
    {
        return Images::ink($jpeg, $x0, 150, $x1, 490, self::BG, 60);
    }
}
