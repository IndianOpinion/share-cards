<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests\Unit;

use IndianOpinion\ShareCards\TextLayout;
use IndianOpinion\ShareCards\Tests\MonoMeasurer;
use PHPUnit\Framework\TestCase;

/**
 * T44 to T47, T51 (wrapping), T54, T56: normalisation and layout.
 */
final class TextLayoutTest extends TestCase
{
    private function layout(): TextLayout
    {
        return new TextLayout(new MonoMeasurer());
    }

    public function testT46EntitiesTagsAndPunctuation(): void
    {
        self::assertSame('Tom & Jerry <3', TextLayout::normalise('Tom &amp; Jerry &lt;3', 300));
        self::assertSame("\u{2019}", TextLayout::normalise('&#8217;', 300));
        self::assertSame('x', TextLayout::normalise('<em>x</em>', 300));
        self::assertSame('Tom & Jerry', TextLayout::normalise('Tom &amp;amp; Jerry', 300));
        self::assertSame('a b', TextLayout::normalise("a<script>alert(1)</script>\n\t b", 300));
    }

    public function testT47ControlCharacters(): void
    {
        $in = "Head\u{202E}line\x00 a\u{200D}b c\u{200C}d \u{2066}x\u{2069} \x7F\u{0085}";
        $out = TextLayout::normalise($in, 300);
        self::assertStringNotContainsString("\u{202E}", $out);
        self::assertStringNotContainsString("\x00", $out);
        self::assertStringNotContainsString("\u{2066}", $out);
        self::assertStringContainsString("a\u{200D}b", $out);
        self::assertStringContainsString("c\u{200C}d", $out);
        self::assertSame("Headline a\u{200D}b c\u{200C}d x", $out);
    }

    public function testT56EmojiRemoved(): void
    {
        self::assertSame('Launch day', TextLayout::normalise("Launch day \u{1F680}", 300));
        self::assertSame('Family', TextLayout::normalise("Family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 300));
        self::assertSame('Flag', TextLayout::normalise("Flag \u{1F1EE}\u{1F1F3}", 300));
        self::assertSame('Thumb', TextLayout::normalise("Thumb \u{1F44D}\u{1F3FD}", 300));
    }

    public function testTruncatesByGraphemes(): void
    {
        // q with a combining acute has no precomposed form, so NFC keeps two
        // code points per grapheme.
        $text = str_repeat("q\u{0301}", 100);
        $out = TextLayout::normalise($text, 60);
        self::assertCount(60, TextLayout::graphemes($out));
        self::assertStringEndsWith("q\u{0301}", $out);
    }

    public function testT54KickerCase(): void
    {
        self::assertSame('ÉCONOMIE', TextLayout::upper('économie'));
        self::assertSame('राजनीति', TextLayout::upper('राजनीति'));
    }

    public function testScriptClassification(): void
    {
        self::assertSame(['Latin'], TextLayout::scripts('Hello, world 2026!'));
        self::assertSame(['Latin', 'Devanagari'], TextLayout::scripts('Budget बजट'));
        self::assertTrue(TextLayout::needsShaping('Budget बजट'));
        self::assertTrue(TextLayout::needsShaping('ข่าววันนี้'));
        self::assertFalse(TextLayout::needsShaping('日本のニュース'));
        self::assertTrue(TextLayout::isRtl('مرحبا'));
        self::assertTrue(TextLayout::isCjk('日本のニュース'));
        self::assertSame([], TextLayout::scripts('2026 ... !!'));
    }

    public function testT44LargestSizeThatFits(): void
    {
        $words = [];
        for ($i = 0; $i < 40; $i++) {
            $words[] = 'abcdef' . ($i % 10);
        }
        $text = implode(' ', $words);
        $layout = $this->layout();
        // 1060 wide box, 340 high, ladder 64..24 so a size fits.
        $ladder = [64, 56, 48, 42, 36, 30, 24];
        $fit = $layout->fit($text, 'font', $ladder, 1060, 340, 4);
        self::assertFalse($fit->truncated);
        self::assertLessThanOrEqual(4, count($fit->lines));
        $index = array_search($fit->size, $ladder, true);
        self::assertIsInt($index);
        self::assertGreaterThan(0, $index);
        $larger = $ladder[$index - 1];
        self::assertGreaterThan(4, count($layout->wrap($text, 'font', $larger, 1060)), 'the next larger size must not fit');
        self::assertSame($text, implode(' ', $fit->lines));
    }

    public function testT44EllipsisAtSmallestSize(): void
    {
        $text = trim(str_repeat('lorem ipsum dolor sit amet ', 24));
        $measurer = new MonoMeasurer();
        $fit = (new TextLayout($measurer))->fit($text, 'font', [64, 56, 48, 42], 1060, 340, 4);
        self::assertTrue($fit->truncated);
        self::assertSame(42, $fit->size);
        self::assertCount(4, $fit->lines);
        self::assertStringEndsWith("\u{2026}", $fit->lines[3]);
        foreach ($fit->lines as $line) {
            self::assertLessThanOrEqual(1060, $measurer->width($line, 'font', 42));
        }
    }

    public function testT45LongTokenBrokenAtGraphemes(): void
    {
        $token = str_repeat("a\u{0301}", 90);
        $lines = $this->layout()->wrap('x ' . $token . ' y', 'font', 40, 400);
        self::assertGreaterThan(2, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(400, count(TextLayout::graphemes($line)) * 20);
            // No line starts with a bare combining mark.
            self::assertDoesNotMatchRegularExpression('/^\p{M}/u', $line);
            self::assertStringNotContainsString('-', $line);
        }
        self::assertSame($token, str_replace([' ', 'x', 'y'], '', implode('', $lines)));
    }

    public function testT51CjkBreaksAnywhereWithKinsoku(): void
    {
        $text = '日本の首都は東京です。「新しい」ニュースです。';
        $layout = $this->layout();
        foreach ([100, 140, 180, 220] as $width) {
            $lines = $layout->wrap($text, 'font', 40, $width);
            self::assertGreaterThan(1, count($lines));
            foreach ($lines as $i => $line) {
                $first = TextLayout::graphemes($line)[0];
                $last = TextLayout::graphemes($line)[count(TextLayout::graphemes($line)) - 1];
                self::assertNotContains($first, ['。', '」', '、'], "line $i at width $width starts with closing punctuation");
                self::assertNotSame('「', $last, "line $i at width $width ends with opening punctuation");
            }
            self::assertSame($text, implode('', $lines));
        }
    }

    public function testLatinWrapKeepsWordsWhole(): void
    {
        $lines = $this->layout()->wrap('one two three four five', 'font', 20, 100);
        self::assertSame(['one two', 'three four', 'five'], $lines);
    }
}
