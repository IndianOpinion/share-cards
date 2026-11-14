<?php

// A small in-memory stand-in for the WordPress functions the plugin calls.
// Tests set state through WpStub and reset it between tests.

declare(strict_types=1);

final class WpStub
{
    /** @var array<string, array<int, list<callable>>> */
    public static array $hooks = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    public static array $transients = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<int, WP_Term> */
    public static array $terms = [];
    /** @var array<int, array<string, list<WP_Term>>> */
    public static array $postTerms = [];
    /** @var array<string, bool> */
    public static array $query = [];
    public static mixed $queried = null;
    public static string $home = 'https://example.test';
    public static string $uploadsBase = '';
    public static string $uploadsUrl = 'https://example.test/wp-content/uploads';
    /** @var array<string, string> */
    public static array $bloginfo = ['name' => 'Example News', 'description' => ''];
    public static bool $canManage = true;
    public static bool $isAdmin = false;
    /** @var list<array{0: string, 1: string, 2: string}> */
    public static array $rewriteRules = [];
    public static int $flushes = 0;
    /** @var array<string, int> */
    public static array $cron = [];
    /** @var list<string> */
    public static array $privateTypes = [];
    /** @var list<string> */
    public static array $privateTaxonomies = [];
    public static int $userId = 1;
    public static int $now = 1767225600;
    /**
     * Multisite: per-site state, saved while another site is current. Empty
     * means a single site.
     *
     * @var array<int, array{options: array<string, mixed>, transients: array<string, array{0: mixed, 1: int}>, cron: array<string, int>, uploadsBase: string}>
     */
    public static array $sites = [];
    public static int $blogId = 1;
    /** @var list<int> */
    public static array $blogStack = [];

    public static function saveSite(): void
    {
        self::$sites[self::$blogId] = ['options' => self::$options, 'transients' => self::$transients, 'cron' => self::$cron, 'uploadsBase' => self::$uploadsBase];
    }

    public static function loadSite(int $id): void
    {
        self::$blogId = $id;
        $site = self::$sites[$id] ?? ['options' => [], 'transients' => [], 'cron' => [], 'uploadsBase' => ''];
        self::$options = $site['options'];
        self::$transients = $site['transients'];
        self::$cron = $site['cron'];
        self::$uploadsBase = $site['uploadsBase'];
    }

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = ['permalink_structure' => '/%postname%/'];
        self::$transients = [];
        self::$posts = [];
        self::$meta = [];
        self::$terms = [];
        self::$postTerms = [];
        self::$query = [];
        self::$queried = null;
        self::$home = 'https://example.test';
        self::$uploadsBase = '';
        self::$uploadsUrl = 'https://example.test/wp-content/uploads';
        self::$bloginfo = ['name' => 'Example News', 'description' => ''];
        self::$canManage = true;
        self::$isAdmin = false;
        self::$rewriteRules = [];
        self::$flushes = 0;
        self::$cron = [];
        self::$privateTypes = [];
        self::$privateTaxonomies = [];
        self::$sites = [];
        self::$blogId = 1;
        self::$blogStack = [];
        WP_CLI::$out = [];
        WP_CLI::$commands = [];
    }
}

// Hooks.

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = $callback;
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function has_filter(string $hook, mixed $callback = false): bool
{
    return !empty(WpStub::$hooks[$hook]);
}

function remove_all_filters(string $hook): void
{
    unset(WpStub::$hooks[$hook]);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(WpStub::$hooks[$hook])) {
        return $value;
    }
    $byPriority = WpStub::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    if (empty(WpStub::$hooks[$hook])) {
        return;
    }
    $byPriority = WpStub::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}

// Options and transients.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    return true;
}

function add_option(string $name, mixed $value): bool
{
    WpStub::$options[$name] ??= $value;
    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);
    return true;
}

function get_transient(string $name): mixed
{
    if (!isset(WpStub::$transients[$name])) {
        return false;
    }
    [$value, $expires] = WpStub::$transients[$name];
    if ($expires > 0 && $expires <= WpStub::$now) {
        unset(WpStub::$transients[$name]);
        return false;
    }
    return $value;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[$name] = [$value, $ttl > 0 ? WpStub::$now + $ttl : 0];
    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}

// Posts, attachments and meta.

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return is_numeric($post) ? (WpStub::$posts[(int) $post] ?? null) : null;
}

function get_the_title(mixed $post = 0): string
{
    $post = get_post($post);
    return $post === null ? '' : (string) apply_filters('the_title', $post->post_title, $post->ID);
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $value = WpStub::$meta[$id][$key] ?? '';
    return $single ? $value : [$value];
}

function get_post_thumbnail_id(mixed $post = null): int|false
{
    $post = get_post($post);
    if ($post === null) {
        return false;
    }
    return (int) (WpStub::$meta[$post->ID]['_thumbnail_id'] ?? 0);
}

function get_post_mime_type(mixed $post = null): string|false
{
    $post = get_post($post);
    return $post === null ? false : $post->post_mime_type;
}

function wp_attachment_is_image(mixed $post = null): bool
{
    $post = get_post($post);
    return $post !== null && $post->post_type === 'attachment' && str_starts_with($post->post_mime_type, 'image/');
}

function wp_get_attachment_metadata(int $id): array|false
{
    $meta = WpStub::$meta[$id]['_wp_attachment_metadata'] ?? null;
    return is_array($meta) ? $meta : false;
}

function get_attached_file(int $id, bool $unfiltered = false): string|false
{
    $file = WpStub::$meta[$id]['_wp_attached_file'] ?? '';
    if ($file === '') {
        return false;
    }
    return str_starts_with((string) $file, '/') ? (string) $file : WpStub::$uploadsBase . '/' . $file;
}

/**
 * @return array{0: string, 1: int, 2: int, 3: bool}|false
 */
function wp_get_attachment_image_src(int $id, string|array $size = 'thumbnail'): array|false
{
    $meta = wp_get_attachment_metadata($id);
    if (!wp_attachment_is_image($id) || $meta === false) {
        return false;
    }
    return [WpStub::$uploadsUrl . '/' . $meta['file'], (int) $meta['width'], (int) $meta['height'], false];
}

function wp_is_post_revision(mixed $post): int|false
{
    return false;
}

function wp_is_post_autosave(mixed $post): int|false
{
    return false;
}

function is_post_type_viewable(mixed $type): bool
{
    return !in_array($type, WpStub::$privateTypes, true);
}

function is_object_in_taxonomy(string $type, string $taxonomy): bool
{
    return $type === 'post' && in_array($taxonomy, ['category', 'post_tag'], true);
}

/**
 * @param array<string, mixed> $args
 * @return list<WP_Post>
 */
function get_posts(array $args = []): array
{
    $types = (array) ($args['post_type'] ?? ['post']);
    $posts = array_values(array_filter(WpStub::$posts, static fn (WP_Post $p): bool => in_array($p->post_type, $types, true)
        && $p->post_status === ($args['post_status'] ?? 'publish')
        && (!isset($args['has_password']) || $args['has_password'] !== false || $p->post_password === '')));
    usort($posts, static fn (WP_Post $a, WP_Post $b): int => $b->ID <=> $a->ID);
    $per = (int) ($args['posts_per_page'] ?? 5);
    $page = (int) ($args['paged'] ?? 1);
    return array_slice($posts, ($page - 1) * $per, $per);
}

// Terms.

function get_term(mixed $term, string $taxonomy = ''): WP_Term|WP_Error|null
{
    if ($term instanceof WP_Term) {
        return $term;
    }
    return WpStub::$terms[(int) $term] ?? null;
}

/**
 * @return list<WP_Term>|false
 */
function get_the_terms(mixed $post, string $taxonomy): array|false
{
    $post = get_post($post);
    $terms = $post === null ? [] : (WpStub::$postTerms[$post->ID][$taxonomy] ?? []);
    return $terms === [] ? false : $terms;
}

/**
 * @param array<string, mixed> $args
 * @return list<WP_Term>
 */
function get_terms(array $args = []): array
{
    $taxes = (array) ($args['taxonomy'] ?? []);
    return array_values(array_filter(WpStub::$terms, static fn (WP_Term $t): bool => in_array($t->taxonomy, $taxes, true) && (empty($args['hide_empty']) || $t->count > 0)));
}

function get_taxonomy(string $taxonomy): WP_Taxonomy|false
{
    $labels = ['category' => 'Category', 'post_tag' => 'Tag', 'topic' => 'Topic'];
    return isset($labels[$taxonomy]) ? new WP_Taxonomy($taxonomy, $labels[$taxonomy]) : false;
}

function is_taxonomy_viewable(mixed $taxonomy): bool
{
    return !in_array($taxonomy, WpStub::$privateTaxonomies, true);
}

// Query conditionals.

function is_singular(mixed $types = ''): bool
{
    return !empty(WpStub::$query['singular']);
}

function is_attachment(): bool
{
    return !empty(WpStub::$query['attachment']);
}

function is_front_page(): bool
{
    return !empty(WpStub::$query['front']);
}

function is_home(): bool
{
    return !empty(WpStub::$query['home']);
}

function is_category(): bool
{
    return !empty(WpStub::$query['category']);
}

function is_tag(): bool
{
    return !empty(WpStub::$query['tag']);
}

function is_tax(): bool
{
    return !empty(WpStub::$query['tax']);
}

function is_search(): bool
{
    return !empty(WpStub::$query['search']);
}

function is_404(): bool
{
    return !empty(WpStub::$query['404']);
}

function get_queried_object(): mixed
{
    return WpStub::$queried;
}

function is_admin(): bool
{
    return WpStub::$isAdmin;
}

// URLs, uploads and site info.

function home_url(string $path = ''): string
{
    return rtrim(WpStub::$home, '/') . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return home_url('/wp-admin/' . ltrim($path, '/'));
}

/**
 * @param array<string, string>|string $key
 */
function add_query_arg(array|string $key, mixed $value = null, mixed $url = null): string
{
    if (is_array($key)) {
        $url = (string) $value;
        $pairs = $key;
    } else {
        $pairs = [$key => (string) $value];
        $url = (string) $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($pairs);
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

/**
 * @return array<string, mixed>
 */
function wp_upload_dir(mixed $time = null, bool $create = true): array
{
    return ['basedir' => WpStub::$uploadsBase, 'baseurl' => WpStub::$uploadsUrl, 'path' => WpStub::$uploadsBase, 'url' => WpStub::$uploadsUrl, 'subdir' => '', 'error' => false];
}

function get_bloginfo(string $show = ''): string
{
    return WpStub::$bloginfo[$show] ?? '';
}

// Escaping and helpers.

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function wp_json_encode(mixed $data): string|false
{
    return json_encode($data);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function size_format(int|float $bytes): string|false
{
    return $bytes . ' B';
}

// Rewrite and cron.

function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
{
    WpStub::$rewriteRules[] = [$regex, $query, $after];
}

function flush_rewrite_rules(bool $hard = true): void
{
    WpStub::$flushes++;
    $rules = [];
    foreach (WpStub::$rewriteRules as [$regex, $query]) {
        $rules[$regex] = $query;
    }
    WpStub::$options['rewrite_rules'] = $rules;
}

function wp_next_scheduled(string $hook): int|false
{
    return WpStub::$cron[$hook] ?? false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    WpStub::$cron[$hook] = $time;
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset(WpStub::$cron[$hook]);
    return 1;
}

// Users, nonces and admin.

function current_user_can(string $capability): bool
{
    return WpStub::$canManage;
}

function get_current_user_id(): int
{
    return WpStub::$userId;
}

function wp_create_nonce(string $action): string
{
    return substr(md5('nonce' . $action), 0, 10);
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_nonce_field(string $action): void
{
    echo '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce($action) . '" />';
}

function wp_nonce_url(string $url, string $action): string
{
    return add_query_arg('_wpnonce', wp_create_nonce($action), $url);
}

function selected(mixed $a, mixed $b, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function checked(mixed $a, mixed $b, bool $echo = true): string
{
    return $a === $b ? ' checked="checked"' : '';
}

function submit_button(string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" name="' . esc_attr($name) . '">' . esc_html($text) . '</button>';
}

function add_options_page(string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    return $slug;
}

function is_multisite(): bool
{
    return WpStub::$sites !== [];
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return array_keys(WpStub::$sites);
}

function get_current_blog_id(): int
{
    return WpStub::$blogId;
}

function switch_to_blog(int $id): bool
{
    WpStub::saveSite();
    WpStub::$blogStack[] = WpStub::$blogId;
    WpStub::loadSite($id);
    return true;
}

function restore_current_blog(): bool
{
    if (WpStub::$blogStack === []) {
        return false;
    }
    WpStub::saveSite();
    WpStub::loadSite((int) array_pop(WpStub::$blogStack));
    return true;
}

function ms_is_switched(): bool
{
    return WpStub::$blogStack !== [];
}

function __return_true(): bool
{
    return true;
}

function __return_false(): bool
{
    return false;
}
