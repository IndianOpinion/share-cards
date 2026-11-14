<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Settings, Share cards. Capability `manage_options`, nonces on every action.
 */
final class SettingsPage
{
    public const SLUG = 'share-cards';
    public const SAVE_ACTION = 'share_cards_save';
    public const PREVIEW_ACTION = 'share_cards_preview';
    public const PREVIEW_IMAGE_ACTION = 'share_cards_preview_image';
    public const NOTICE_TRANSIENT = 'share_cards_notice_';

    /** Sample text per script for the preview. */
    public const SAMPLES = [
        'Latin' => 'A sample headline to check fonts, colours and the layout',
        'Greek' => 'Ένας δοκιμαστικός τίτλος',
        'Cyrillic' => 'Пример заголовка для проверки',
        'Arabic' => 'عنوان تجريبي للتحقق من الخط',
        'Hebrew' => 'כותרת לדוגמה לבדיקת הגופן',
        'Devanagari' => 'फ़ॉन्ट जाँचने के लिए एक नमूना शीर्षक',
        'Bengali' => 'ফন্ট পরীক্ষার জন্য একটি নমুনা শিরোনাম',
        'Gurmukhi' => 'ਫੌਂਟ ਦੀ ਜਾਂਚ ਲਈ ਨਮੂਨਾ ਸਿਰਲੇਖ',
        'Gujarati' => 'ફોન્ટ તપાસવા માટે નમૂનાનું શીર્ષક',
        'Oriya' => 'ଫଣ୍ଟ ପରୀକ୍ଷା ପାଇଁ ଏକ ନମୁନା ଶୀର୍ଷକ',
        'Tamil' => 'எழுத்துருவைச் சோதிக்க ஒரு மாதிரி தலைப்பு',
        'Telugu' => 'ఫాంట్ తనిఖీ కోసం ఒక నమూనా శీర్షిక',
        'Kannada' => 'ಫಾಂಟ್ ಪರಿಶೀಲನೆಗೆ ಒಂದು ಮಾದರಿ ಶೀರ್ಷಿಕೆ',
        'Malayalam' => 'ഫോണ്ട് പരിശോധിക്കാൻ ഒരു മാതൃകാ തലക്കെട്ട്',
        'Thai' => 'หัวข้อตัวอย่างสำหรับตรวจสอบแบบอักษร',
        'Han' => '用于检查字体的示例标题',
        'Hiragana' => 'フォントを確認するための見出しの例です',
        'Katakana' => 'フォントを確認するための見出しの例です',
        'Hangul' => '글꼴을 확인하기 위한 예시 제목',
    ];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::PREVIEW_ACTION, [$this, 'handlePreview']);
        add_action('admin_post_' . self::PREVIEW_IMAGE_ACTION, [$this, 'handlePreviewImage']);
    }

    public function addPage(): void
    {
        add_options_page('Share cards', 'Share cards', 'manage_options', self::SLUG, [$this, 'render']);
    }

    /**
     * Checks capability and nonce for an admin action.
     *
     * @param array<string, mixed> $request
     */
    public function authorised(array $request, string $action): bool
    {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $nonce = isset($request['_wpnonce']) && is_string($request['_wpnonce']) ? $request['_wpnonce'] : '';
        return $nonce !== '' && wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Validates and saves submitted settings.
     *
     * @param array<string, mixed> $request The raw POST array, already unslashed.
     * @return array{ok: bool, errors: list<string>}
     */
    public function processSave(array $request): array
    {
        if (!$this->authorised($request, self::SAVE_ACTION)) {
            return ['ok' => false, 'errors' => ['You are not allowed to change these settings.']];
        }
        $input = isset($request['share_cards']) && is_array($request['share_cards']) ? $request['share_cards'] : [];
        $input = self::expandTextareas($input);
        foreach (['term_cards', 'kicker_uppercase', 'backdrop'] as $checkbox) {
            $input[$checkbox] = isset($input[$checkbox]) ? '1' : '0';
        }

        $old = $this->plugin->settings();
        [$new, $errors] = $old->validate($input);
        $saved = $new->save();
        $this->plugin->useSettings($saved);

        if ($saved->string('url_prefix') !== $old->string('url_prefix')) {
            $this->plugin->rewrite()->prefixChanged($old->string('url_prefix'), $saved);
        }
        return ['ok' => true, 'errors' => $errors];
    }

    /**
     * Turns the settings page's text formats into arrays.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function expandTextareas(array $input): array
    {
        foreach (['font_scripts', 'taxonomy_labels'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                continue;
            }
            $map = [];
            foreach (preg_split('/\r\n|\r|\n/', $input[$key]) ?: [] as $line) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2 && trim($parts[0]) !== '') {
                    $map[trim($parts[0])] = trim($parts[1]);
                }
            }
            $input[$key] = $map;
        }
        return $input;
    }

    public function handleSave(): void
    {
        $request = wp_unslash($_POST);
        $result = $this->processSave(is_array($request) ? $request : []);
        if (!$result['ok']) {
            wp_die(esc_html($result['errors'][0] ?? 'Not allowed.'), 403);
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $result['errors'], 60);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }

    /**
     * Renders sample cards to the preview files only: one Latin headline and
     * one per mapped script.
     *
     * @param array<string, mixed> $request
     * @return array{ok: bool, files: list<string>, errors: list<string>}
     */
    public function processPreview(array $request): array
    {
        if (!$this->authorised($request, self::PREVIEW_ACTION)) {
            return ['ok' => false, 'files' => [], 'errors' => ['You are not allowed to preview cards.']];
        }
        $files = [];
        $errors = [];
        $scripts = array_merge(['Latin' => ''], $this->plugin->fonts()->scripts());
        $site = (string) get_bloginfo('name');
        foreach (array_keys($scripts) as $script) {
            if (!isset(self::SAMPLES[$script])) {
                continue;
            }
            $subject = new Subject('preview', '1', self::SAMPLES[$script], 'Kicker', 'Footnote', 0, $site, true);
            try {
                $files[] = $this->plugin->cache()->writePreview($script === 'Latin' ? '' : $script, function (string $temp) use ($subject): void {
                    $this->plugin->renderTo($subject, $temp);
                });
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', $script, $e->getMessage());
            }
        }
        return ['ok' => true, 'files' => $files, 'errors' => $errors];
    }

    public function handlePreview(): void
    {
        $request = wp_unslash($_POST);
        $result = $this->processPreview(is_array($request) ? $request : []);
        if (!$result['ok']) {
            wp_die(esc_html($result['errors'][0] ?? 'Not allowed.'), 403);
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $result['errors'], 60);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'preview' => '1'], admin_url('options-general.php')));
        exit;
    }

    /**
     * Streams one preview file to an administrator.
     */
    public function handlePreviewImage(): void
    {
        $request = wp_unslash($_GET);
        $request = is_array($request) ? $request : [];
        if (!$this->authorised($request, self::PREVIEW_IMAGE_ACTION)) {
            wp_die('Not allowed.', 403);
        }
        $script = isset($request['script']) && is_string($request['script']) ? preg_replace('/[^A-Za-z_]/', '', $request['script']) : '';
        $name = '.preview' . ($script === '' || $script === null ? '' : '-' . strtolower($script)) . '.jpg';
        $path = $this->plugin->cache()->directory() . '/' . $name;
        if (preg_match(Cache::PREVIEW_PATTERN, $name) !== 1 || !is_file($path)) {
            wp_die('No preview yet.', 404);
        }
        nocache_headers();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->plugin->settings();
        $notices = get_transient(self::NOTICE_TRANSIENT . get_current_user_id());
        delete_transient(self::NOTICE_TRANSIENT . get_current_user_id());

        echo '<div class="wrap"><h1>' . esc_html__('Share cards', 'share-cards') . '</h1>';

        $reason = $this->plugin->unavailableReason();
        if ($reason !== null) {
            $missing = Capabilities::missing($this->plugin->capabilities());
            $text = $reason === 'unwritable'
                ? sprintf('The card directory %s is not writable. No card URLs are advertised.', $this->plugin->cache()->directory())
                : sprintf('No renderer is usable (missing: %s). No card URLs are advertised.', $missing === [] ? 'the selected renderer' : implode(', ', $missing));
            echo '<div class="notice notice-error"><p>' . esc_html($text) . '</p></div>';
        }
        if (is_array($notices)) {
            foreach ($notices as $notice) {
                if (is_string($notice)) {
                    echo '<div class="notice notice-warning"><p>' . esc_html($notice) . '</p></div>';
                }
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="share-cards-settings" data-prefix="' . esc_attr($s->string('url_prefix')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '" />';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<table class="form-table" role="presentation">';
        foreach ($this->fields() as $field) {
            $this->renderField($field, $s);
        }
        echo '</table>';
        submit_button();
        echo '</form>';
        echo '<script>document.getElementById("share-cards-settings").addEventListener("submit",function(e){var f=e.target,p=f.querySelector("[name=\'share_cards[url_prefix]\']");if(p&&p.value!==f.dataset.prefix&&!window.confirm(' . wp_json_encode('Changing the URL prefix changes every card URL. Social platforms keep the old URLs for weeks; the old prefix redirects for 30 days. Continue?') . ')){e.preventDefault();}});</script>';

        echo '<h2>' . esc_html__('Preview', 'share-cards') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::PREVIEW_ACTION) . '" />';
        wp_nonce_field(self::PREVIEW_ACTION);
        submit_button(__('Render preview', 'share-cards'), 'secondary', 'submit', false);
        echo '</form>';
        $dir = $this->plugin->cache()->directory();
        foreach (array_merge([''], array_keys($this->plugin->fonts()->scripts())) as $script) {
            $file = $dir . '/.preview' . ($script === '' ? '' : '-' . strtolower($script)) . '.jpg';
            if (!is_file($file)) {
                continue;
            }
            $url = wp_nonce_url(add_query_arg(['action' => self::PREVIEW_IMAGE_ACTION, 'script' => $script, 't' => (string) filemtime($file)], admin_url('admin-post.php')), self::PREVIEW_IMAGE_ACTION);
            echo '<p><img src="' . esc_url($url) . '" width="600" height="315" alt="' . esc_attr($script === '' ? 'Latin preview' : $script . ' preview') . '" /></p>';
        }
        echo '</div>';
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3?: array<string, string>, 4?: string}>
     */
    private function fields(): array
    {
        return [
            ['mode', 'Mode', 'select', ['auto' => 'auto: card only when the featured image is unfit', 'always' => 'always', 'never' => 'never']],
            ['term_cards', 'Cards for category and tag archives', 'checkbox'],
            ['min_width', 'Minimum featured image width (px)', 'number'],
            ['aspect_min', 'Minimum width to height ratio', 'text'],
            ['aspect_max', 'Maximum width to height ratio', 'text'],
            ['generic_image_ids', 'Generic image attachment IDs (comma separated)', 'text', [], 'Placeholders and logo slates: never used as they are, never as a backdrop.'],
            ['default_image_id', 'Default image attachment ID', 'number'],
            ['url_prefix', 'URL prefix', 'text', [], 'Social platforms hold card URLs for weeks. After a change the old prefix redirects for 30 days.'],
            ['emit_tags', 'Print image tags', 'select', ['auto' => 'auto: unless an SEO plugin takes the image', 'on' => 'on', 'off' => 'off: the theme prints them']],
            ['renderer', 'Renderer', 'select', ['auto' => 'auto', 'gd' => 'GD', 'imagick' => 'Imagick']],
            ['colour_background', 'Background colour', 'colour'],
            ['colour_text', 'Headline colour', 'colour'],
            ['colour_kicker', 'Kicker colour', 'colour'],
            ['colour_muted', 'Footnote colour', 'colour'],
            ['colour_accent', 'Accent bar colour', 'colour'],
            ['font_regular', 'Regular font file (.ttf or .otf)', 'text', [], 'Empty: Noto Sans, then DejaVu Sans, from the system font directories.'],
            ['font_bold', 'Bold font file', 'text'],
            ['font_scripts', 'Fonts per script, one per line: Script=/path/to/font.ttf', 'textarea', [], 'For example Devanagari=/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf'],
            ['logo_id', 'Logo attachment ID', 'number'],
            ['footer', 'Footer', 'select', ['name' => 'site name', 'logo' => 'logo', 'both' => 'logo and site name', 'none' => 'none']],
            ['kicker_uppercase', 'Upper-case the kicker', 'checkbox'],
            ['taxonomy_labels', 'Kicker per taxonomy, one per line: taxonomy=Label', 'textarea'],
            ['term_footnote', 'Term card footnote ({count} is the number of posts)', 'text', [], 'A count drawn on a card is part of its hash, so the card re-renders whenever the count moves.'],
            ['backdrop', 'Use the featured image as a backdrop', 'checkbox'],
            ['scrim_opacity', 'Scrim opacity (0 to 1)', 'text'],
            ['jpeg_quality', 'JPEG quality (60 to 95)', 'number'],
            ['max_lines', 'Maximum headline lines', 'number'],
            ['size_ladder', 'Headline sizes, largest first (px)', 'text'],
            ['max_concurrent', 'Concurrent renders', 'number'],
            ['renders_per_minute', 'Renders per minute', 'number'],
            ['lock_wait_ms', 'Key lock wait (ms)', 'number'],
            ['slot_wait_ms', 'Render slot wait (ms)', 'number'],
            ['failure_ttl', 'Failure marker lifetime (s)', 'number'],
            ['max_source_bytes', 'Largest backdrop file (bytes)', 'number'],
            ['max_source_pixels', 'Largest backdrop (pixels)', 'number'],
            ['cache_max_mb', 'Cache size cap (MB)', 'number'],
            ['log_level', 'Log level', 'select', ['error' => 'error', 'info' => 'info', 'debug' => 'debug']],
        ];
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3?: array<string, string>, 4?: string} $field
     */
    private function renderField(array $field, Settings $s): void
    {
        [$key, $label, $type] = $field;
        $options = $field[3] ?? [];
        $help = $field[4] ?? '';
        $name = 'share_cards[' . $key . ']';
        $id = 'share-cards-' . str_replace('_', '-', $key);
        $value = $s->get($key);
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = implode(', ', array_map('strval', $value));
            } else {
                $lines = [];
                foreach ($value as $k => $v) {
                    $lines[] = $k . '=' . (is_scalar($v) ? (string) $v : '');
                }
                $value = implode("\n", $lines);
            }
        }
        $value = is_scalar($value) ? (string) $value : '';

        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        switch ($type) {
            case 'select':
                echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '">';
                foreach ($options as $optValue => $optLabel) {
                    echo '<option value="' . esc_attr($optValue) . '"' . selected($value, $optValue, false) . '>' . esc_html($optLabel) . '</option>';
                }
                echo '</select>';
                break;
            case 'checkbox':
                echo '<input type="checkbox" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="1"' . checked((bool) $s->get($key), true, false) . ' />';
                break;
            case 'textarea':
                echo '<textarea class="large-text code" rows="4" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '">' . esc_textarea($value) . '</textarea>';
                break;
            case 'colour':
                echo '<input type="text" class="regular-text code" pattern="#[0-9a-fA-F]{6}" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '" />';
                break;
            default:
                echo '<input type="' . ($type === 'number' ? 'number' : 'text') . '" class="regular-text" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '" />';
        }
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }
}
