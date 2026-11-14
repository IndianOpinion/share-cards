<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The plugin's settings: defaults, validation and the settings fingerprint.
 *
 * Stored as one autoloaded option. The fingerprint of every visual setting
 * is computed on save and stored with the values, so emitting tags never
 * touches a file.
 */
final class Settings
{
    public const OPTION = 'share_cards_settings';

    /** Settings that change what a card looks like. */
    public const VISUAL_KEYS = [
        'colour_background', 'colour_text', 'colour_kicker', 'colour_muted',
        'colour_accent', 'font_regular', 'font_bold', 'font_scripts', 'logo_id',
        'footer', 'kicker_uppercase', 'taxonomy_labels', 'term_footnote',
        'backdrop', 'scrim_opacity', 'jpeg_quality', 'max_lines', 'size_ladder',
    ];

    public const COLOUR_KEYS = ['colour_background', 'colour_text', 'colour_kicker', 'colour_muted', 'colour_accent'];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_merge(self::defaults(), $values);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'mode' => 'auto',
            'term_cards' => true,
            'min_width' => 1200,
            'aspect_min' => 1.5,
            'aspect_max' => 2.1,
            'generic_image_ids' => [],
            'default_image_id' => 0,
            'url_prefix' => 'share-card',
            'emit_tags' => 'auto',
            'renderer' => 'auto',
            'colour_background' => '#1f2937',
            'colour_text' => '#ffffff',
            'colour_kicker' => '#93c5fd',
            'colour_muted' => '#d1d5db',
            'colour_accent' => '#60a5fa',
            'font_regular' => '',
            'font_bold' => '',
            'font_scripts' => [],
            'logo_id' => 0,
            'footer' => 'name',
            'kicker_uppercase' => true,
            'taxonomy_labels' => [],
            'term_footnote' => '',
            'backdrop' => true,
            'scrim_opacity' => 0.62,
            'jpeg_quality' => 82,
            'max_lines' => 4,
            'size_ladder' => [64, 56, 48, 42],
            'max_concurrent' => 2,
            'renders_per_minute' => 30,
            'lock_wait_ms' => 2000,
            'slot_wait_ms' => 3000,
            'failure_ttl' => 600,
            'max_source_bytes' => 20971520,
            'max_source_pixels' => 25000000,
            'cache_max_mb' => 512,
            'log_level' => 'error',
        ];
    }

    public static function load(): self
    {
        $stored = get_option(self::OPTION, []);
        return new self(is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function string(string $key): string
    {
        $v = $this->values[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    public function int(string $key): int
    {
        $v = $this->values[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }

    public function float(string $key): float
    {
        $v = $this->values[$key] ?? 0;
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $v = $this->values[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map('intval', array_filter($v, 'is_numeric')));
    }

    /**
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $v = $this->values[$key] ?? [];
        $out = [];
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                if (is_string($k) && is_scalar($item)) {
                    $out[$k] = (string) $item;
                }
            }
        }
        return $out;
    }

    /**
     * Returns a copy with some values replaced.
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(array_merge($this->values, $values));
    }

    /**
     * The settings fingerprint: a hash of every visual setting, including the
     * logo's attached file path and the fonts actually in use.
     */
    public function fingerprint(): string
    {
        $stored = $this->values['fingerprint'] ?? '';
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        return $this->computeFingerprint();
    }

    public function computeFingerprint(): string
    {
        $parts = [];
        foreach (self::VISUAL_KEYS as $key) {
            $parts[$key] = $this->values[$key] ?? null;
        }
        $logo = $this->int('logo_id');
        $parts['logo_file'] = $logo > 0 ? (string) get_attached_file($logo, true) : '';
        $fonts = new Fonts($this->string('font_regular'), $this->string('font_bold'), $this->stringMap('font_scripts'));
        $parts['fonts_in_use'] = [$fonts->regular(), $fonts->bold()];
        return substr(hash('sha256', Fingerprint::canonicalJson($parts)), 0, 16);
    }

    /**
     * Saves the settings, with a freshly computed fingerprint.
     */
    public function save(): self
    {
        $values = $this->values;
        unset($values['fingerprint']);
        $fresh = new self($values);
        $values['fingerprint'] = $fresh->computeFingerprint();
        update_option(self::OPTION, $values, true);
        delete_transient(Capabilities::TRANSIENT);
        return new self($values);
    }

    public static function isColour(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    public static function isPrefix(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/D', $value) === 1;
    }

    /**
     * Directories a font file may live in.
     *
     * @return list<string>
     */
    public static function fontDirectories(): array
    {
        $dirs = ['/usr/share/fonts', '/usr/local/share/fonts'];
        if (defined('WP_CONTENT_DIR')) {
            array_unshift($dirs, (string) WP_CONTENT_DIR);
        }
        $dirs = apply_filters('share_cards_font_dirs', $dirs);
        return is_array($dirs) ? array_values(array_filter($dirs, 'is_string')) : [];
    }

    /**
     * Validates a font path for the settings page. Returns an error message,
     * or null when the path is acceptable.
     */
    public static function fontPathError(string $path): ?string
    {
        if (preg_match('/\.(ttf|otf)$/iD', $path) !== 1) {
            return 'Font files must end in .ttf or .otf.';
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return 'The font file is not readable.';
        }
        $inside = false;
        foreach (self::fontDirectories() as $dir) {
            $dirReal = realpath($dir);
            if ($dirReal !== false && str_starts_with($real, rtrim($dirReal, '/') . '/')) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            return 'The font file is outside the allowed font directories.';
        }
        try {
            if (function_exists('imagettfbbox')) {
                (new GdMeasurer())->width('Abc', $real, 20);
            }
        } catch (RenderException $e) {
            return 'The font file could not measure text.';
        }
        return null;
    }

    /**
     * Validates submitted values against the current ones. Invalid values are
     * rejected and the old value is kept.
     *
     * @param array<string, mixed> $input
     * @return array{0: self, 1: list<string>} The new settings and error messages.
     */
    public function validate(array $input): array
    {
        $new = $this->values;
        $errors = [];

        $enum = static function (string $key, array $allowed) use ($input, &$new, &$errors): void {
            if (!array_key_exists($key, $input)) {
                return;
            }
            if (in_array($input[$key], $allowed, true)) {
                $new[$key] = $input[$key];
            } else {
                $errors[] = sprintf('%s: invalid value, kept the old one.', $key);
            }
        };
        $enum('mode', ['auto', 'always', 'never']);
        $enum('emit_tags', ['auto', 'on', 'off']);
        $enum('renderer', ['auto', 'gd', 'imagick']);
        $enum('footer', ['name', 'logo', 'both', 'none']);
        $enum('log_level', ['error', 'info', 'debug']);

        foreach (['term_cards', 'kicker_uppercase', 'backdrop'] as $key) {
            if (array_key_exists($key, $input)) {
                $new[$key] = in_array($input[$key], [true, 1, '1', 'on', 'yes'], true);
            }
        }

        foreach (self::COLOUR_KEYS as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (self::isColour($input[$key])) {
                $new[$key] = strtolower((string) $input[$key]);
            } else {
                $errors[] = sprintf('%s: colours must be #rrggbb, kept the old one.', $key);
            }
        }

        $ints = [
            'min_width' => [1, 20000], 'jpeg_quality' => [60, 95], 'max_lines' => [1, 8],
            'max_concurrent' => [1, 32], 'renders_per_minute' => [1, 10000],
            'lock_wait_ms' => [0, 30000], 'slot_wait_ms' => [0, 30000],
            'failure_ttl' => [0, 86400], 'max_source_bytes' => [1024, 1073741824],
            'max_source_pixels' => [1, 400000000], 'cache_max_mb' => [1, 1048576],
        ];
        foreach ($ints as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            if (is_numeric($v) && (int) $v >= $min && (int) $v <= $max) {
                $new[$key] = (int) $v;
            } else {
                $errors[] = sprintf('%s: must be a whole number from %d to %d, kept the old one.', $key, $min, $max);
            }
        }

        $floats = ['aspect_min' => [0.1, 10.0], 'aspect_max' => [0.1, 10.0], 'scrim_opacity' => [0.0, 1.0]];
        foreach ($floats as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            if (is_numeric($v) && (float) $v >= $min && (float) $v <= $max) {
                $new[$key] = (float) $v;
            } else {
                $errors[] = sprintf('%s: must be a number from %s to %s, kept the old one.', $key, $min, $max);
            }
        }
        if ((float) $new['aspect_min'] > (float) $new['aspect_max']) {
            $errors[] = 'aspect_min must not exceed aspect_max, kept the old values.';
            $new['aspect_min'] = $this->values['aspect_min'];
            $new['aspect_max'] = $this->values['aspect_max'];
        }

        if (array_key_exists('size_ladder', $input)) {
            $raw = is_array($input['size_ladder']) ? $input['size_ladder'] : explode(',', (string) (is_scalar($input['size_ladder']) ? $input['size_ladder'] : ''));
            $ladder = [];
            $ok = true;
            foreach ($raw as $item) {
                $item = is_scalar($item) ? trim((string) $item) : '';
                if ($item === '') {
                    continue;
                }
                if (!ctype_digit($item) || (int) $item < 8 || (int) $item > 200) {
                    $ok = false;
                    break;
                }
                $ladder[] = (int) $item;
            }
            if ($ok && $ladder !== [] && count($ladder) <= 8) {
                rsort($ladder);
                $new['size_ladder'] = array_values(array_unique($ladder));
            } else {
                $errors[] = 'size_ladder: one to eight sizes from 8 to 200 px, kept the old one.';
            }
        }

        if (array_key_exists('url_prefix', $input)) {
            if (self::isPrefix($input['url_prefix'])) {
                $new['url_prefix'] = $input['url_prefix'];
            } else {
                $errors[] = 'url_prefix: lower-case letters, digits and hyphens, 1 to 32 characters, kept the old one.';
            }
        }

        foreach (['logo_id', 'default_image_id'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $id = is_numeric($input[$key]) ? (int) $input[$key] : -1;
            if ($id === 0 || ($id > 0 && wp_attachment_is_image($id))) {
                $new[$key] = $id;
            } else {
                $errors[] = sprintf('%s: must be the ID of an image attachment, kept the old one.', $key);
            }
        }

        if (array_key_exists('generic_image_ids', $input)) {
            $raw = is_array($input['generic_image_ids']) ? $input['generic_image_ids'] : preg_split('/[\s,]+/', (string) (is_scalar($input['generic_image_ids']) ? $input['generic_image_ids'] : ''), -1, PREG_SPLIT_NO_EMPTY);
            $ids = [];
            foreach ($raw ?: [] as $item) {
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }
            $new['generic_image_ids'] = array_values(array_unique($ids));
        }

        foreach (['font_regular', 'font_bold'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $path = is_string($input[$key]) ? trim($input[$key]) : '';
            if ($path === '') {
                $new[$key] = '';
                continue;
            }
            $error = self::fontPathError($path);
            if ($error === null) {
                $new[$key] = (string) realpath($path);
            } else {
                $errors[] = sprintf('%s: %s Kept the old one.', $key, $error);
            }
        }

        if (array_key_exists('font_scripts', $input) && is_array($input['font_scripts'])) {
            $map = [];
            $old = $this->stringMap('font_scripts');
            foreach ($input['font_scripts'] as $script => $path) {
                if (!is_string($script) || preg_match('/^[A-Z][A-Za-z_]{1,30}$/D', $script) !== 1) {
                    continue;
                }
                $path = is_string($path) ? trim($path) : '';
                if ($path === '') {
                    continue;
                }
                $error = self::fontPathError($path);
                if ($error === null) {
                    $map[$script] = (string) realpath($path);
                } else {
                    $errors[] = sprintf('font_scripts[%s]: %s Kept the old one.', $script, $error);
                    if (isset($old[$script])) {
                        $map[$script] = $old[$script];
                    }
                }
            }
            $new['font_scripts'] = $map;
        }

        if (array_key_exists('taxonomy_labels', $input) && is_array($input['taxonomy_labels'])) {
            $labels = [];
            foreach ($input['taxonomy_labels'] as $tax => $label) {
                if (is_string($tax) && is_string($label) && trim($label) !== '') {
                    $labels[sanitize_key($tax)] = TextLayout::normalise($label, TextLayout::MAX_KICKER);
                }
            }
            $new['taxonomy_labels'] = $labels;
        }

        if (array_key_exists('term_footnote', $input)) {
            $new['term_footnote'] = is_string($input['term_footnote'])
                ? TextLayout::normalise($input['term_footnote'], TextLayout::MAX_FOOTNOTE)
                : '';
        }

        unset($new['fingerprint']);
        return [new self($new), $errors];
    }
}
