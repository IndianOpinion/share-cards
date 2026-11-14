<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The fonts a card may use: a regular and a bold font for Latin, Greek and
 * Cyrillic, and an explicit map from script name to font file for every
 * other script. No character map is read: a script without a map entry is
 * simply not drawn.
 */
final class Fonts
{
    /** System paths tried, in order, when the regular font setting is empty. */
    public const REGULAR_CANDIDATES = [
        '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
        '/usr/share/fonts/noto/NotoSans-Regular.ttf',
        '/usr/share/fonts/google-noto/NotoSans-Regular.ttf',
        '/usr/share/fonts/google-noto-vf/NotoSans[wght].ttf',
        '/usr/local/share/fonts/NotoSans-Regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
        '/usr/local/share/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
    ];

    /** System paths tried, in order, when the bold font setting is empty. */
    public const BOLD_CANDIDATES = [
        '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
        '/usr/share/fonts/noto/NotoSans-Bold.ttf',
        '/usr/share/fonts/google-noto/NotoSans-Bold.ttf',
        '/usr/local/share/fonts/NotoSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
        '/usr/local/share/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    ];

    /** @var array<string, string> */
    private array $scripts;

    /** @var list<string> */
    private array $missing = [];

    /**
     * @param array<string, string> $scripts Script name to font path.
     * @param list<string> $regularCandidates
     * @param list<string> $boldCandidates
     */
    public function __construct(
        private string $regular,
        private string $bold,
        array $scripts,
        array $regularCandidates = self::REGULAR_CANDIDATES,
        array $boldCandidates = self::BOLD_CANDIDATES
    ) {
        // C57: a configured base font that is no longer readable falls back
        // to a discovered system font.
        if ($this->regular !== '' && !self::usable($this->regular)) {
            $this->missing[] = $this->regular;
            $this->regular = '';
        }
        if ($this->bold !== '' && !self::usable($this->bold)) {
            $this->missing[] = $this->bold;
            $this->bold = '';
        }
        if ($this->regular === '') {
            $this->regular = self::firstUsable($regularCandidates);
        }
        if ($this->bold === '') {
            $this->bold = self::firstUsable($boldCandidates);
            if ($this->bold === '') {
                $this->bold = $this->regular;
            }
        }
        if ($this->regular === '') {
            $this->regular = $this->bold;
        }

        $this->scripts = [];
        foreach ($scripts as $script => $path) {
            if (!is_string($script) || !is_string($path) || $path === '') {
                continue;
            }
            if (!self::usable($path)) {
                $this->missing[] = $path;
                continue;
            }
            $this->scripts[$script] = $path;
        }
    }

    public static function usable(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * @param list<string> $candidates
     */
    public static function firstUsable(array $candidates): string
    {
        foreach ($candidates as $path) {
            if (self::usable($path)) {
                return $path;
            }
        }
        return '';
    }

    public function regular(): string
    {
        return $this->regular;
    }

    public function bold(): string
    {
        return $this->bold;
    }

    /**
     * @return array<string, string> Readable script fonts only.
     */
    public function scripts(): array
    {
        return $this->scripts;
    }

    /**
     * Configured font files that could not be read.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function hasBase(): bool
    {
        return $this->regular !== '';
    }

    /**
     * The font for one field, or null when a script in it has no font.
     *
     * Latin, Greek and Cyrillic use the regular or bold font. Every other
     * script needs a map entry, and all of them in one field must map to
     * the same file; that file then also draws any Latin in the field.
     */
    public function forText(string $text, bool $bold): ?string
    {
        $others = array_values(array_diff(TextLayout::scripts($text), TextLayout::BASE_SCRIPTS));
        if ($others === []) {
            $font = $bold ? $this->bold : $this->regular;
            return $font === '' ? null : $font;
        }
        $files = [];
        foreach ($others as $script) {
            if (!isset($this->scripts[$script])) {
                return null;
            }
            $files[$this->scripts[$script]] = true;
        }
        return count($files) === 1 ? (string) array_key_first($files) : null;
    }

    /**
     * Scripts in the text that have no usable font.
     *
     * @return list<string>
     */
    public function unmappedScripts(string $text): array
    {
        $out = [];
        foreach (TextLayout::scripts($text) as $script) {
            if (in_array($script, TextLayout::BASE_SCRIPTS, true)) {
                if ($this->regular === '') {
                    $out[] = $script;
                }
                continue;
            }
            if (!isset($this->scripts[$script])) {
                $out[] = $script;
            }
        }
        return $out;
    }

    /**
     * Checks that at least the base scripts can be drawn.
     *
     * @return true|\WP_Error
     */
    public function check()
    {
        if ($this->regular === '') {
            return new \WP_Error(
                'share_cards_no_font',
                'No font found for the Latin script. Set a font path in Settings, Share cards, or install Noto Sans or DejaVu Sans (for example the fonts-noto-core or fonts-dejavu-core package).'
            );
        }
        return true;
    }
}
