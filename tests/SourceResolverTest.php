<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\CardComposer;
use IndianOpinion\ShareCards\Fonts;
use IndianOpinion\ShareCards\SourceResolver;
use IndianOpinion\ShareCards\Template;
use WpStub;

/**
 * T58, T59, T62 and the T69 re-encode step.
 */
final class SourceResolverTest extends TestCase
{
    private function anyType(): \Closure
    {
        return static fn (int $type): bool => true;
    }

    public function testT58SmallestFittingIntermediateIsDecoded(): void
    {
        $this->addAttachment(20, 6000, 3375, false, 'image/jpeg', [
            'large' => ['width' => 2048, 'height' => 1152, 'file' => 'img-20-2048x1152.jpg'],
            '1536x1536' => ['width' => 1536, 'height' => 864, 'file' => 'img-20-1536x864.jpg'],
            'medium' => ['width' => 300, 'height' => 169, 'file' => 'img-20-300x169.jpg'],
            'banner' => ['width' => 1800, 'height' => 400, 'file' => 'img-20-1800x400.jpg'],
        ]);
        Images::write($this->uploads . '/img-20-1536x864.jpg', 1536, 864);
        $source = (new SourceResolver())->resolve(20, $this->anyType());
        self::assertNotNull($source);
        self::assertSame(realpath($this->uploads . '/img-20-1536x864.jpg'), $source->path);
        self::assertSame(1536, $source->width);
    }

    public function testFullFileWhenNoIntermediateFits(): void
    {
        $path = $this->addAttachment(20, 1300, 700, true, 'image/jpeg', ['medium' => ['width' => 300, 'height' => 161, 'file' => 'img-20-300x161.jpg']]);
        self::assertSame(realpath($path), (new SourceResolver())->resolve(20, $this->anyType())?->path);
    }

    public function testT59ByteAndPixelCapsBeforeDecoding(): void
    {
        $path = $this->addAttachment(20, 1600, 900, true);
        $decoded = false;
        $supports = static function (int $type) use (&$decoded): bool {
            $decoded = true;
            return true;
        };
        $bytes = new SourceResolver(1024, 25000000);
        self::assertNull($bytes->resolve(20, $supports));
        self::assertSame('over byte cap', $bytes->lastReason());
        $pixels = new SourceResolver(20971520, 1000000);
        self::assertNull($pixels->resolve(20, $supports));
        self::assertSame('over pixel cap', $pixels->lastReason());
        self::assertFalse($decoded, 'the caps are checked before the decoder is even asked');
        self::assertGreaterThan(1024, filesize($path));
    }

    public function testT62OutsideUploadsOrMissing(): void
    {
        $this->addAttachment(20, 1600, 900, true);
        $outside = $this->tmp . '/outside.jpg';
        Images::write($outside, 1600, 900);
        add_filter('share_cards_backdrop_path', static fn (): string => $outside);
        $r = new SourceResolver();
        self::assertNull($r->resolve(20, $this->anyType()));
        self::assertSame('not local', $r->lastReason());

        remove_all_filters('share_cards_backdrop_path');
        add_filter('share_cards_backdrop_path', fn (): string => $this->uploads . '/../outside.jpg');
        self::assertNull($r->resolve(20, $this->anyType()));

        remove_all_filters('share_cards_backdrop_path');
        add_filter('share_cards_backdrop_path', static fn (): string => 's3://bucket/img.jpg');
        self::assertNull($r->resolve(20, $this->anyType()));

        remove_all_filters('share_cards_backdrop_path');
        $this->addAttachment(21, 1600, 900, false);
        self::assertNull($r->resolve(21, $this->anyType()));
        self::assertSame('not local', $r->lastReason());

        if (function_exists('symlink')) {
            symlink($outside, $this->uploads . '/link.jpg');
            WpStub::$meta[21]['_wp_attached_file'] = 'link.jpg';
            self::assertNull($r->resolve(21, $this->anyType()), 'a link out of uploads is refused');
        }
    }

    public function testUnsupportedTypeForTheCanvas(): void
    {
        $this->addAttachment(20, 1600, 900, true, 'image/webp');
        $r = new SourceResolver();
        self::assertNull($r->resolve(20, static fn (int $t): bool => $t !== IMAGETYPE_WEBP));
        self::assertSame('unsupported', $r->lastReason());
    }

    public function testT69ReencodesUntilUnderTheLimit(): void
    {
        $canvas = new FakeCanvas();
        $fonts = new Fonts('', '', []);
        if (!$fonts->hasBase()) {
            self::markTestSkipped('No system font.');
        }
        $composer = new CardComposer($canvas, null, new SourceResolver());
        $data = $composer->compose($this->subject('x'), Template::fromArray(['jpeg_quality' => 92], $fonts));
        self::assertSame([92, 82, 72, 62, 60], $canvas->qualities);
        self::assertSame(str_repeat('x', 290000), $data);
    }
}
