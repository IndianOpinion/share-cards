<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Settings;
use IndianOpinion\ShareCards\SettingsPage;
use WpStub;

/**
 * T77: the settings page.
 */
final class SettingsTest extends TestCase
{
    private function page(): SettingsPage
    {
        return new SettingsPage($this->plugin);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function request(array $values, string $action = SettingsPage::SAVE_ACTION): array
    {
        return ['_wpnonce' => wp_create_nonce($action), 'share_cards' => $values];
    }

    public function testT77SaveWithoutNonceOrCapabilityIsRejected(): void
    {
        $result = $this->page()->processSave(['share_cards' => ['colour_text' => '#000000']]);
        self::assertFalse($result['ok']);
        $result = $this->page()->processSave(['_wpnonce' => 'forged', 'share_cards' => ['colour_text' => '#000000']]);
        self::assertFalse($result['ok']);
        WpStub::$canManage = false;
        $result = $this->page()->processSave($this->request(['colour_text' => '#000000']));
        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey(Settings::OPTION, WpStub::$options);
    }

    public function testT77InvalidColourAndFontRejectedOldValuesKept(): void
    {
        $outside = $this->tmp . '/evil.ttf';
        copy((string) $this->plugin->fonts()->regular(), $outside);
        $result = $this->page()->processSave($this->request([
            'colour_text' => 'red',
            'colour_kicker' => '#00ff00',
            'font_regular' => $outside,
            'font_bold' => '/etc/passwd',
        ]));
        self::assertTrue($result['ok']);
        self::assertCount(3, $result['errors']);
        $saved = Settings::load();
        self::assertSame('#ffffff', $saved->string('colour_text'));
        self::assertSame('#00ff00', $saved->string('colour_kicker'));
        self::assertSame('', $saved->string('font_regular'));
        self::assertSame('', $saved->string('font_bold'));
    }

    public function testFontInsideAnAllowedDirectoryIsAccepted(): void
    {
        $font = WP_CONTENT_DIR . '/fonts/Sample.ttf';
        if (!is_dir(dirname($font))) {
            mkdir(dirname($font), 0755, true);
        }
        copy($this->plugin->fonts()->regular(), $font);
        $result = $this->page()->processSave($this->request(['font_regular' => $font, 'font_scripts' => "Devanagari=$font\nbad line\nArabic=/nope.ttf"]));
        self::assertTrue($result['ok']);
        self::assertCount(1, $result['errors']);
        $saved = Settings::load();
        self::assertSame(realpath($font), $saved->string('font_regular'));
        self::assertSame(['Devanagari' => realpath($font)], $saved->stringMap('font_scripts'));
        unlink($font);
    }

    public function testNumbersEnumsAndAttachments(): void
    {
        $this->addAttachment(30, 200, 60, false, 'image/png');
        $this->addPost(31, 'Not an image');
        $result = $this->page()->processSave($this->request([
            'jpeg_quality' => '99',
            'max_lines' => '3',
            'mode' => 'sometimes',
            'logo_id' => '31',
            'default_image_id' => '30',
            'size_ladder' => '40, 72,50',
            'generic_image_ids' => '5, 6 x 7',
            'aspect_min' => '3',
            'aspect_max' => '2',
        ]));
        self::assertTrue($result['ok']);
        $s = Settings::load();
        self::assertSame(82, $s->int('jpeg_quality'));
        self::assertSame(3, $s->int('max_lines'));
        self::assertSame('auto', $s->string('mode'));
        self::assertSame(0, $s->int('logo_id'));
        self::assertSame(30, $s->int('default_image_id'));
        self::assertSame([72, 50, 40], $s->intList('size_ladder'));
        self::assertSame([5, 6, 7], $s->intList('generic_image_ids'));
        self::assertSame(1.5, $s->float('aspect_min'));
        self::assertCount(4, $result['errors']);
    }

    public function testSaveStoresAFingerprint(): void
    {
        $this->page()->processSave($this->request(['colour_text' => '#123456']));
        $stored = WpStub::$options[Settings::OPTION];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $stored['fingerprint']);
        self::assertSame((new Settings($stored))->computeFingerprint(), $stored['fingerprint']);
    }

    public function testT77PreviewWritesThePreviewFileOnly(): void
    {
        $result = $this->page()->processPreview([]);
        self::assertFalse($result['ok']);
        $result = $this->page()->processPreview(['_wpnonce' => wp_create_nonce(SettingsPage::PREVIEW_ACTION)]);
        self::assertTrue($result['ok'], implode(', ', $result['errors']));
        self::assertSame([$this->cacheDir() . '/.preview.jpg'], $result['files']);
        $names = array_values(array_diff(scandir($this->cacheDir()) ?: [], ['.', '..', 'index.html']));
        self::assertSame(['.preview.jpg'], $names);
        self::assertSame([1200, 630], array_slice((array) getimagesize($this->cacheDir() . '/.preview.jpg'), 0, 2));
    }

    public function testPreviewPerMappedScript(): void
    {
        $font = '/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf';
        if (!is_readable($font)) {
            self::markTestSkipped('Noto Sans Devanagari is not installed.');
        }
        $this->settings(['font_scripts' => ['Devanagari' => $font]]);
        $result = (new SettingsPage($this->plugin))->processPreview(['_wpnonce' => wp_create_nonce(SettingsPage::PREVIEW_ACTION)]);
        self::assertContains($this->cacheDir() . '/.preview-devanagari.jpg', $result['files']);
    }

    public function testRenderPrintsEscapedForm(): void
    {
        $this->settings(['term_footnote' => '"><script>x</script>']);
        ob_start();
        $this->page()->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('name="share_cards[colour_background]"', $html);
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('_wpnonce', $html);
    }
}
