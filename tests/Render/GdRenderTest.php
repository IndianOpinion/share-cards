<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests\Render;

use IndianOpinion\ShareCards\Fingerprint;
use IndianOpinion\ShareCards\RenderException;
use IndianOpinion\ShareCards\Subject;
use IndianOpinion\ShareCards\Tests\Images;
use PHPUnit\Framework\Attributes\DataProvider;
use WpStub;

/**
 * T4 to T6, T26, T48 to T57, T60 to T65, T69: drawing, on both canvases.
 */
final class GdRenderTest extends RenderTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function renderers(): array
    {
        return ['gd' => ['gd'], 'imagick' => ['imagick']];
    }

    #[DataProvider('renderers')]
    public function testT4NoBackdropCornerIsBackground(string $renderer): void
    {
        $this->useRenderer($renderer, false);
        $out = $this->render($this->subject('A Latin headline', 'Politics', 'Note'));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG), 'corner is the background');
        self::assertGreaterThan(200, $this->headlineInk($out), 'headline drawn');
        // Accent bar along the bottom.
        self::assertTrue(Images::close(Images::pixel($out, 600, 627), [96, 165, 250], 20));
    }

    #[DataProvider('renderers')]
    public function testT5GenericImageCardHasNoBackdrop(string $renderer): void
    {
        $this->useRenderer($renderer, false, ['generic_image_ids' => [20]]);
        $post = $this->addPost(10, 'Generic');
        $this->addAttachment(20, 1200, 675, true);
        $this->setFeatured(10, 20);
        $out = $this->render($this->plugin->resolver()->resolvePost($post));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));
        self::assertTrue(Images::close(Images::pixel($out, 1190, 5), self::BG));
    }

    #[DataProvider('renderers')]
    public function testT6BackdropIsDrawnAndDarkened(string $renderer): void
    {
        $this->useRenderer($renderer, false, ['mode' => 'always']);
        $post = $this->addPost(10, 'With a backdrop');
        $this->addAttachment(20, 1600, 900, true);
        $this->setFeatured(10, 20);
        $out = $this->render($this->plugin->resolver()->resolvePost($post));
        $left = Images::pixel($out, 5, 5);
        $right = Images::pixel($out, 1190, 5);
        self::assertFalse(Images::close($left, self::BG, 6));
        self::assertGreaterThan($left[2], $left[0], 'left half is red');
        self::assertGreaterThan($right[0], $right[2], 'right half is blue');
        // Darkened: nowhere near the source's 230.
        self::assertLessThan(120, $left[0]);
    }

    public function testT26CorruptBackdropRendersWithoutIt(): void
    {
        $this->useRenderer('gd', false);
        $path = $this->addAttachment(20, 1600, 900, true);
        $data = (string) file_get_contents($path);
        file_put_contents($path, substr($data, 0, (int) (strlen($data) / 3)));
        $this->addPost(10, 'Corrupt');
        $this->setFeatured(10, 20);
        WpStub::$options['permalink_structure'] = '/%postname%/';
        $key = $this->keyForPost(10);
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame('render', $r->header('X-Share-Card'));
        $out = $this->cacheDir() . '/' . $key->filename();
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));
        self::assertCount(1, $this->logsMatching('attachment=20'));
    }

    public function testT61TruncatedJpegIsSkippedOnImagickToo(): void
    {
        $this->useRenderer('imagick', false);
        $path = $this->addAttachment(20, 1600, 900, true);
        $data = (string) file_get_contents($path);
        file_put_contents($path, substr($data, 0, 200));
        $out = $this->render($this->subject('Corrupt', '', '', 20));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));
    }

    public function testT48HindiThroughPango(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf');
        $this->useRenderer('gd', true, ['font_scripts' => ['Devanagari' => $font]]);
        self::assertSame('gd+pango', $this->plugin->rendererKind());
        $input = $this->plugin->fingerprint()->input($this->subject('x'), '');
        self::assertSame('gd+pango', $input['renderer']);

        $out = $this->render($this->subject('भारत में आज बहुत बारिश हुई और नदियाँ उफान पर हैं'));
        self::assertGreaterThan(500, $this->headlineInk($out));
        // Fits the box: nothing drawn below it except the footer.
        self::assertSame(0, Images::ink($out, 70, 495, 1130, 520, self::BG, 60));
        self::assertSame([], $this->logsMatching('field omitted'));
    }

    public function testT48PangoMarkupIsEscapedTwice(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf');
        $this->useRenderer('gd', true, ['font_scripts' => ['Devanagari' => $font]]);
        $out = $this->render($this->subject('टॉम & जेरी <3 <b>'));
        self::assertGreaterThan(200, $this->headlineInk($out));
    }

    public function testPangoTextIsNeverInterpretedByImageMagick(): void
    {
        if (!\IndianOpinion\ShareCards\PangoTextLayer::isAvailable()) {
            self::markTestSkipped('ImageMagick has no Pango format here.');
        }
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf');
        $pango = new \IndianOpinion\ShareCards\PangoTextLayer();
        $line = static fn (string $text): int => (int) $pango->line($text, $font, 30, 1100, '#ffffff', false)?->width;
        $height = static fn (string $text): int => (int) $pango->paragraph($text, $font, [30], 1100, 600, '#ffffff', false)?->height;

        // `%[fx:1+1]` would be evaluated to "2", `%m` would become "PANGO".
        self::assertGreaterThan(3 * $line('2'), $line('%[fx:1+1]'));
        self::assertNotSame($line('PANGO'), $line('%m'));
        self::assertGreaterThan($line('%m'), $line('%%m'));
        // A literal backslash-n stays on one line.
        self::assertSame($height('ab'), $height('a\\nb'));
        self::assertSame("50%% \\\\n", \IndianOpinion\ShareCards\PangoTextLayer::literal('50% \\n'));
    }

    public function testT49HindiWithoutShapingIsOmitted(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf');
        $this->useRenderer('gd', false, ['font_scripts' => ['Devanagari' => $font]]);
        self::assertSame('gd', $this->plugin->rendererKind());
        $out = $this->render($this->subject('भारत में आज बहुत बारिश हुई', 'Politics'));
        self::assertSame(0, $this->headlineInk($out));
        self::assertGreaterThan(0, Images::ink($out, 70, 60, 400, 100, self::BG, 60), 'kicker still drawn');
        $omitted = $this->logsMatching('field omitted');
        self::assertCount(1, $omitted);
        self::assertStringContainsString('field=headline', $omitted[0]);
        self::assertStringContainsString('Devanagari', $omitted[0]);
    }

    public function testT50ArabicRightAlignedWithShaping(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf');
        $this->useRenderer('gd', true, ['font_scripts' => ['Arabic' => $font]]);
        $out = $this->render($this->subject('مرحبا بالعالم'));
        $left = $this->headlineInk($out, 70, 600);
        $right = $this->headlineInk($out, 600, 1130);
        self::assertGreaterThan(100, $right);
        self::assertSame(0, $left, 'ink only on the right of the box');
    }

    public function testT50ArabicOmittedWithoutShaping(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf');
        $this->useRenderer('gd', false, ['font_scripts' => ['Arabic' => $font]]);
        $out = $this->render($this->subject('مرحبا بالعالم'));
        self::assertSame(0, $this->headlineInk($out));
    }

    public function testT51JapaneseWithAndWithoutAMappedFont(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/fonts-japanese-gothic.ttf');
        $text = '日本の首都は東京です。新しいニュースをお届けします。';
        $this->useRenderer('gd', false, ['font_scripts' => ['Han' => $font, 'Hiragana' => $font, 'Katakana' => $font]]);
        $out = $this->render($this->subject($text));
        self::assertGreaterThan(300, $this->headlineInk($out));

        $this->useRenderer('gd', false, ['font_scripts' => []]);
        $out = $this->render($this->subject($text));
        self::assertSame(0, $this->headlineInk($out));
    }

    public function testT52ThaiWithoutShapingIsOmitted(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf');
        $this->useRenderer('gd', false, ['font_scripts' => ['Thai' => $font]]);
        $out = $this->render($this->subject('ข่าววันนี้ฝนตกหนัก'));
        self::assertSame(0, $this->headlineInk($out));
    }

    public function testT53MixedScriptsAreNeverDrawnPartly(): void
    {
        $font = $this->requireFont('/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf');
        $this->useRenderer('gd', false, ['font_scripts' => ['Devanagari' => $font]]);
        $out = $this->render($this->subject('The budget, or बजट, passed today'));
        self::assertSame(0, $this->headlineInk($out));
    }

    public function testT55EmptyHeadline(): void
    {
        $this->useRenderer('gd', false);
        $post = $this->addPost(10, '<b></b> &nbsp; ');
        $subject = $this->plugin->resolver()->resolvePost($post);
        self::assertSame('', $subject->headline);
        $out = $this->render($subject);
        self::assertSame(0, $this->headlineInk($out));
        $this->viewPost($post);
        self::assertSame('Example News', $this->plugin->chooser()->forRequest()['alt'] ?? null);
    }

    public function testT57ConfiguredFontDeleted(): void
    {
        $copy = WP_CONTENT_DIR . '/gone.ttf';
        copy($this->plugin->fonts()->regular(), $copy);
        $this->useRenderer('gd', false, ['font_regular' => $copy, 'font_bold' => $copy]);
        unlink($copy);
        $this->newPlugin();
        $out = $this->render($this->subject('Latin still drawn'));
        self::assertGreaterThan(200, $this->headlineInk($out));
        $health = (new \IndianOpinion\ShareCards\SiteHealth($this->plugin))->result('fonts');
        self::assertSame('critical', $health['status']);
        self::assertStringContainsString($copy, $health['description']);

        add_filter('share_cards_font_candidates', static fn (): array => []);
        $this->newPlugin();
        try {
            $this->plugin->renderTo($this->subject('No font'), $this->tmp . '/x.jpg');
            self::fail('Expected a render failure.');
        } catch (RenderException $e) {
            self::assertStringContainsString('Latin', $e->getMessage());
        }
        $check = $this->plugin->fonts()->check();
        self::assertInstanceOf(\WP_Error::class, $check);
        self::assertStringContainsString('Latin', $check->get_error_message());
    }

    public function testT57NoFontMakesTheEndpointFallBack(): void
    {
        $this->useRenderer('gd', false);
        add_filter('share_cards_font_candidates', static fn (): array => []);
        $this->newPlugin();
        $this->addPost(10);
        $key = $this->keyForPost(10);
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame('fallback-error', $r->header('X-Share-Card'));
        self::assertSame(404, $r->status);
    }

    public function testT60AnimatedGifUsesTheFirstFrame(): void
    {
        if (!\IndianOpinion\ShareCards\ImagickCanvas::usable()) {
            self::markTestSkipped('Imagick is needed to write the test GIF.');
        }
        $path = $this->addAttachment(20, 1200, 630, false, 'image/gif');
        $gif = new \Imagick();
        foreach (['#e61e1e', '#1e1ee6'] as $colour) {
            $frame = new \Imagick();
            $frame->newImage(1200, 630, new \ImagickPixel($colour));
            $frame->setImageFormat('gif');
            $gif->addImage($frame);
        }
        $gif->setFormat('gif');
        $gif->writeImages($path, true);
        foreach (['gd', 'imagick'] as $renderer) {
            $this->useRenderer($renderer, false);
            $out = $this->render($this->subject('GIF', '', '', 20));
            $px = Images::pixel($out, 5, 5);
            self::assertGreaterThan($px[2], $px[0], "$renderer draws the red first frame");
        }
    }

    public function testT60SvgAndCmykAreRefused(): void
    {
        $this->useRenderer('gd', false);
        $this->addAttachment(20, 1200, 630, true, 'image/svg+xml');
        $out = $this->render($this->subject('SVG', '', '', 20));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));

        if (!\IndianOpinion\ShareCards\ImagickCanvas::usable()) {
            self::markTestSkipped('Imagick is needed to write a CMYK JPEG.');
        }
        $path = $this->addAttachment(21, 1200, 630);
        $cmyk = new \Imagick();
        $cmyk->newImage(1200, 630, new \ImagickPixel('red'));
        $cmyk->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
        $cmyk->setImageFormat('jpeg');
        $cmyk->writeImage($path);
        self::assertSame(4, getimagesize($path)['channels'] ?? null);
        $out = $this->render($this->subject('CMYK', '', '', 21));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));
    }

    #[DataProvider('renderers')]
    public function testT63TransparentSourceShowsTheBackground(string $renderer): void
    {
        $this->useRenderer($renderer, false, ['footer' => 'none']);
        $this->addAttachment(20, 1200, 630, true, 'image/png');
        $out = $this->render($this->subject('', '', '', 20));
        // Top half was transparent: background colour under the treatment.
        $top = Images::pixel($out, 30, 30);
        $grey = (int) round(0.299 * 31 + 0.587 * 41 + 0.114 * 55);
        $expected = [];
        foreach (self::BG as $i => $c) {
            $treated = (0.65 * $c + 0.35 * $grey) * 0.55;
            $expected[$i] = (int) round(0.38 * $treated + 0.62 * $c);
        }
        self::assertTrue(Images::close($top, $expected, 10), json_encode([$top, $expected]) ?: '');
        // Bottom half was opaque red.
        $bottom = Images::pixel($out, 30, 600);
        self::assertGreaterThan($top[0] + 15, $bottom[0]);
    }

    #[DataProvider('renderers')]
    public function testT64FocalPointChoosesTheCrop(string $renderer): void
    {
        $this->useRenderer($renderer, false, ['footer' => 'none']);
        $this->addAttachment(20, 3000, 630, true);
        $sample = function (array $focal): array {
            remove_all_filters('share_cards_focal_point');
            add_filter('share_cards_focal_point', static fn (): array => $focal);
            return Images::pixel($this->render($this->subject('', '', '', 20)), 600, 600);
        };
        $left = $sample([0, 0]);
        $right = $sample([1, 1]);
        self::assertGreaterThan($left[2], $left[0], 'focal 0,0 shows the red left');
        self::assertGreaterThan($right[0], $right[2], 'focal 1,1 shows the blue right');
    }

    public function testT65LowMemoryDropsTheBackdrop(): void
    {
        $this->useRenderer('gd', false);
        $this->addAttachment(20, 1600, 900, true);
        add_filter('share_cards_memory_available', static fn () => static fn (): int => 1024);
        $this->newPlugin();
        $out = $this->render($this->subject('Low memory', '', '', 20));
        self::assertTrue(Images::close(Images::pixel($out, 5, 5), self::BG));
    }

    #[DataProvider('renderers')]
    public function testT69OutputFormat(string $renderer): void
    {
        $this->useRenderer($renderer, false, ['jpeg_quality' => 95]);
        $path = $this->addAttachment(20, 1200, 630);
        Images::write($path, 1200, 630, 'image/jpeg', true);
        $out = $this->render($this->subject('Noisy backdrop', 'Kicker', 'Footnote', 20));
        self::assertLessThanOrEqual(300 * 1024, filesize($out));
        self::assertTrue(Images::progressive($out), 'progressive JPEG');
        self::assertFalse(Images::hasExif($out), 'no EXIF');
    }

    public function testLogoInTheFooter(): void
    {
        $this->addAttachment(30, 240, 120, true, 'image/png');
        $this->useRenderer('gd', false, ['footer' => 'logo', 'logo_id' => 30]);
        $out = $this->render($this->subject('Logo'));
        // The logo's opaque red half sits in the footer.
        self::assertGreaterThan(0, Images::ink($out, 70, 540, 190, 580, self::BG, 60));
    }

    public function testFingerprintInputIsCanonical(): void
    {
        self::assertSame('{"a":1,"b":{"c":"/x","d":"é"}}', Fingerprint::canonicalJson(['b' => ['d' => 'é', 'c' => '/x'], 'a' => 1]));
    }
}
