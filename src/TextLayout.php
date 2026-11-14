<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Text handling in plain PHP: normalisation, script classification, line
 * breaking and the size ladder. Measurement is delegated to the canvas, so
 * both renderers share one layout.
 */
final class TextLayout
{
    public const MAX_HEADLINE = 300;
    public const MAX_KICKER = 60;
    public const MAX_FOOTNOTE = 80;
    public const ELLIPSIS = "\u{2026}";
    public const LINE_HEIGHT = 1.2;

    /**
     * Scripts that need a shaping engine (PCRE script names).
     */
    public const SHAPING_SCRIPTS = [
        'Arabic', 'Syriac', 'Thaana', 'Nko', 'Hebrew', 'Devanagari', 'Bengali',
        'Gurmukhi', 'Gujarati', 'Oriya', 'Tamil', 'Telugu', 'Kannada',
        'Malayalam', 'Sinhala', 'Thai', 'Lao', 'Tibetan', 'Myanmar', 'Khmer',
        'Mongolian',
    ];

    /**
     * Right-to-left scripts among the shaping scripts.
     */
    public const RTL_SCRIPTS = ['Arabic', 'Syriac', 'Thaana', 'Nko', 'Hebrew'];

    /**
     * Scripts drawn with the regular and bold fonts without a map entry.
     */
    public const BASE_SCRIPTS = ['Latin', 'Greek', 'Cyrillic'];

    /**
     * Other scripts the classifier names. Anything else is reported as `Other`.
     */
    public const OTHER_SCRIPTS = [
        'Armenian', 'Georgian', 'Han', 'Hiragana', 'Katakana', 'Bopomofo',
        'Hangul', 'Ethiopic', 'Cherokee', 'Canadian_Aboriginal', 'Tifinagh',
        'Vai', 'Yi', 'Ol_Chiki', 'Coptic', 'Runic', 'Ogham', 'Gothic',
    ];

    /**
     * Scripts whose text may break between any two characters.
     */
    public const CJK_SCRIPTS = ['Han', 'Hiragana', 'Katakana', 'Bopomofo'];

    /** No line may start with one of these. */
    private const CLOSING = "、。，．）」』】〉》！？：；,.)!?:;";

    /** No line may end with one of these. */
    private const OPENING = "（「『【〈《(";

    public function __construct(private TextMeasurer $measurer)
    {
    }

    /**
     * Applies the normalisation steps of the spec in order.
     */
    public static function normalise(string $text, int $maxGraphemes): string
    {
        $text = mb_scrub($text, 'UTF-8');

        // 1. Strip HTML tags, dropping script and style bodies.
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text);
        $text = strip_tags($text);

        // 2. Decode entities, at most twice, until stable.
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        // 3. NFC when ext-intl is present.
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $text = $nfc;
            }
        }

        // 4. Controls: whitespace controls become spaces, the rest go.
        //    ZWJ (U+200D) and ZWNJ (U+200C) are kept.
        $text = (string) preg_replace('/[\t\n\r\x{000B}\x{000C}\x{0085}\x{2028}\x{2029}]/u', ' ', $text);
        $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);

        // 5. Emoji, pictographs, their joiners, modifiers and selectors.
        $text = self::stripEmoji($text);

        // 6. Collapse whitespace.
        $text = trim((string) preg_replace('/[\s\p{Zs}]+/u', ' ', $text));

        // 7. Truncate by grapheme clusters.
        $graphemes = self::graphemes($text);
        if (count($graphemes) > $maxGraphemes) {
            $text = rtrim(implode('', array_slice($graphemes, 0, $maxGraphemes)));
        }

        return $text;
    }

    /**
     * Removes emoji and pictographs, with the joiners, skin tones, keycaps,
     * tags and variation selectors that belong to them.
     */
    public static function stripEmoji(string $text): string
    {
        $pictograph = '(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}])';
        $modifier = '[\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}\x{20E3}]';
        $sequence = '/' . $pictograph . $modifier . '*(?:\x{200D}' . $pictograph . $modifier . '*)*/u';
        $text = (string) preg_replace($sequence, '', $text);
        // Keycap sequences such as "1", U+FE0F, U+20E3 keep the digit only.
        return (string) preg_replace('/' . $modifier . '/u', '', $text);
    }

    /**
     * Splits text into extended grapheme clusters.
     *
     * @return list<string>
     */
    public static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all('/\X/u', $text, $m);
        return $m[0];
    }

    /**
     * Unicode scripts of the letters and marks in the text. Common and
     * Inherited are ignored. Unnamed scripts are reported as `Other`.
     *
     * @return list<string>
     */
    public static function scripts(string $text): array
    {
        $found = [];
        $rest = $text;
        foreach (array_merge(self::BASE_SCRIPTS, self::SHAPING_SCRIPTS, self::OTHER_SCRIPTS) as $script) {
            $pattern = '/\p{' . $script . '}/u';
            if (preg_match($pattern, $rest) === 1) {
                $found[] = $script;
                $rest = (string) preg_replace($pattern, '', $rest);
            }
        }
        $rest = (string) preg_replace('/[\p{Common}\p{Inherited}]/u', '', $rest);
        if (preg_match('/[\p{L}\p{M}]/u', $rest) === 1) {
            $found[] = 'Other';
        }
        return $found;
    }

    /**
     * Whether any script in the text needs shaping. One shaping word makes
     * the whole field need shaping.
     */
    public static function needsShaping(string $text): bool
    {
        return array_intersect(self::scripts($text), self::SHAPING_SCRIPTS) !== [];
    }

    public static function isRtl(string $text): bool
    {
        return array_intersect(self::scripts($text), self::RTL_SCRIPTS) !== [];
    }

    public static function isCjk(string $text): bool
    {
        return array_intersect(self::scripts($text), self::CJK_SCRIPTS) !== [];
    }

    /**
     * Upper-cases with multibyte functions. Caseless scripts are unchanged.
     */
    public static function upper(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }

    /**
     * Picks the largest size in the ladder at which the text fits in the box,
     * else ellipsises at the smallest size.
     *
     * @param list<int> $ladder Sizes in pixels.
     */
    public function fit(string $text, string $font, array $ladder, int $boxWidth, int $boxHeight, int $maxLines): FittedText
    {
        $ladder = array_values(array_filter($ladder, static fn (int $s): bool => $s > 0));
        rsort($ladder);
        if ($ladder === []) {
            $ladder = [48];
        }
        $maxLines = max(1, $maxLines);

        foreach ($ladder as $size) {
            $limit = $this->lineLimit($size, $boxHeight, $maxLines);
            $lines = $this->wrap($text, $font, $size, $boxWidth);
            if ($limit > 0 && count($lines) <= $limit) {
                return new FittedText($size, $lines, $size * self::LINE_HEIGHT, false);
            }
        }

        $size = $ladder[count($ladder) - 1];
        $limit = max(1, $this->lineLimit($size, $boxHeight, $maxLines));
        $lines = $this->wrap($text, $font, $size, $boxWidth);
        $kept = array_slice($lines, 0, $limit);
        $kept[$limit - 1] = $this->ellipsise($kept[$limit - 1] ?? '', $font, $size, $boxWidth);
        return new FittedText($size, $kept, $size * self::LINE_HEIGHT, true);
    }

    /**
     * Fits a single line: ellipsised when wider than the box.
     */
    public function fitLine(string $text, string $font, int $size, int $boxWidth): string
    {
        if ($this->measurer->width($text, $font, $size) <= $boxWidth) {
            return $text;
        }
        return $this->ellipsise($text, $font, $size, $boxWidth);
    }

    /**
     * Greedy line breaking.
     *
     * @return list<string>
     */
    public function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];
        $current = '';
        foreach ($this->tokens($text) as [$token, $spaceBefore]) {
            $candidate = $current === '' ? $token : $current . ($spaceBefore ? ' ' : '') . $token;
            if ($this->measurer->width($candidate, $font, $size) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            if ($this->measurer->width($token, $font, $size) <= $width) {
                $current = $token;
                continue;
            }
            $pieces = $this->breakToken($token, $font, $size, $width);
            $current = (string) array_pop($pieces);
            foreach ($pieces as $piece) {
                $lines[] = $piece;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * Ends a line with an ellipsis, removing graphemes until it fits.
     */
    public function ellipsise(string $line, string $font, int $size, int $width): string
    {
        $graphemes = self::graphemes(rtrim($line));
        while (true) {
            $candidate = rtrim(implode('', $graphemes)) . self::ELLIPSIS;
            if ($graphemes === [] || $this->measurer->width($candidate, $font, $size) <= $width) {
                return $candidate;
            }
            array_pop($graphemes);
        }
    }

    private function lineLimit(int $size, int $boxHeight, int $maxLines): int
    {
        return min($maxLines, (int) floor($boxHeight / ($size * self::LINE_HEIGHT)));
    }

    /**
     * Breaks one over-wide token at grapheme cluster boundaries.
     *
     * @return non-empty-list<string>
     */
    private function breakToken(string $token, string $font, int $size, int $width): array
    {
        $pieces = [];
        $current = '';
        foreach (self::graphemes($token) as $g) {
            $candidate = $current . $g;
            if ($current !== '' && $this->measurer->width($candidate, $font, $size) > $width) {
                $pieces[] = $current;
                $current = $g;
                continue;
            }
            $current = $candidate;
        }
        $pieces[] = $current;
        return $pieces;
    }

    /**
     * Splits text into break units. Each unit carries whether a space came
     * before it. CJK text breaks between any two graphemes, with the
     * line-start and line-end punctuation rules applied by gluing.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public function tokens(string $text): array
    {
        if (!self::isCjk($text)) {
            $out = [];
            foreach (preg_split('/ +/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $out[] = [$word, true];
            }
            return $out;
        }

        $units = [];
        $space = false;
        $word = '';
        foreach (self::graphemes($text) as $g) {
            $alone = self::isBreakAnywhere($g);
            if (($g === ' ' || $alone) && $word !== '') {
                $units[] = [$word, $space];
                $word = '';
                $space = false;
            }
            if ($g === ' ') {
                $space = true;
            } elseif ($alone) {
                $units[] = [$g, $space];
                $space = false;
            } else {
                $word .= $g;
            }
        }
        if ($word !== '') {
            $units[] = [$word, $space];
        }

        // Glue closing punctuation to the unit before it, and opening
        // punctuation to the unit after it.
        $glued = [];
        $carry = null;
        foreach ($units as [$unit, $sp]) {
            if ($carry !== null) {
                $unit = $carry[0] . ($sp ? ' ' : '') . $unit;
                $sp = $carry[1];
                $carry = null;
            }
            if ($glued !== [] && !$sp && self::startsWithClosing($unit)) {
                $glued[count($glued) - 1][0] .= $unit;
                continue;
            }
            if (self::isOpening($unit)) {
                $carry = [$unit, $sp];
                continue;
            }
            $glued[] = [$unit, $sp];
        }
        if ($carry !== null) {
            $glued[] = $carry;
        }
        return $glued;
    }

    private static function isBreakAnywhere(string $g): bool
    {
        return preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Bopomofo}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $g) === 1;
    }

    private static function startsWithClosing(string $unit): bool
    {
        $first = self::graphemes($unit)[0] ?? '';
        return $first !== '' && mb_strpos(self::CLOSING, $first, 0, 'UTF-8') !== false;
    }

    private static function isOpening(string $unit): bool
    {
        $graphemes = self::graphemes($unit);
        return count($graphemes) === 1 && mb_strpos(self::OPENING, $graphemes[0], 0, 'UTF-8') !== false;
    }
}
