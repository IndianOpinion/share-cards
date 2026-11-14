<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

use IndianOpinion\ShareCards\Seo\RankMathAdapter;
use IndianOpinion\ShareCards\Seo\SeoAdapter;
use IndianOpinion\ShareCards\Seo\YoastAdapter;

/**
 * Wiring, activation and deactivation, and the services every part shares.
 * One instance per request; services are built on first use.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CRON_HOOK = 'share_cards_gc';

    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?Cache $cache = null;
    private ?SubjectResolver $resolver = null;
    private ?Cards $cards = null;
    private ?ImageChooser $chooser = null;
    private ?Logger $logger = null;
    private ?LockProvider $locks = null;
    private ?Rewrite $rewrite = null;
    /** @var array<string, bool>|null */
    private ?array $caps = null;
    private bool $rendererResolved = false;
    private ?Renderer $renderer = null;
    private bool $adapterResolved = false;
    private ?SeoAdapter $adapter = null;
    private Clock $clock;
    /** @var callable(string): void|null */
    private $logSink;

    /**
     * @param callable(string): void|null $logSink
     */
    public function __construct(?Clock $clock = null, ?callable $logSink = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->logSink = $logSink;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replaces the shared instance (tests, or a host that injects a clock).
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    // ------------------------------------------------------------------
    // Services.

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function settings(): Settings
    {
        return $this->settings ??= Settings::load();
    }

    /**
     * Swaps the settings for the rest of the request, dropping derived services.
     */
    public function useSettings(Settings $settings): void
    {
        $this->settings = $settings;
        $this->resolver = null;
        $this->cards = null;
        $this->chooser = null;
        $this->logger = null;
        $this->rewrite = null;
        $this->caps = null;
        $this->rendererResolved = false;
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->settings()->string('log_level'), $this->logSink);
    }

    public function cache(): Cache
    {
        return $this->cache ??= new Cache(Cache::defaultDirectory());
    }

    public function resolver(): SubjectResolver
    {
        return $this->resolver ??= new SubjectResolver($this->settings());
    }

    public function rewrite(): Rewrite
    {
        return $this->rewrite ??= new Rewrite($this->settings(), $this->clock);
    }

    public function chooser(): ImageChooser
    {
        return $this->chooser ??= new ImageChooser($this);
    }

    public function headTags(): HeadTags
    {
        return new HeadTags($this);
    }

    public function endpoint(): Endpoint
    {
        return new Endpoint($this);
    }

    public function gc(): GarbageCollector
    {
        return new GarbageCollector($this);
    }

    public function locks(): LockProvider
    {
        if ($this->locks === null) {
            $default = new FileLockProvider($this->cache()->lockDirectory(), $this->clock);
            $filtered = apply_filters('share_cards_lock_provider', $default);
            $this->locks = $filtered instanceof LockProvider ? $filtered : $default;
        }
        return $this->locks;
    }

    public function budget(): RenderBudget
    {
        return new RenderBudget($this->cache()->directory() . '/' . RenderBudget::FILENAME, $this->settings()->int('renders_per_minute'), $this->clock);
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return $this->caps ??= Capabilities::get($this->cache()->directory());
    }

    public function sources(): SourceResolver
    {
        $s = $this->settings();
        $memory = apply_filters('share_cards_memory_available', null);
        return new SourceResolver($s->int('max_source_bytes'), $s->int('max_source_pixels'), is_callable($memory) ? $memory : null);
    }

    /**
     * The selected renderer, or null when none is usable.
     */
    public function renderer(): ?Renderer
    {
        if ($this->rendererResolved) {
            return $this->renderer;
        }
        $this->rendererResolved = true;
        $caps = $this->capabilities();
        $pango = !empty($caps['pango']) ? new PangoTextLayer() : null;
        $gd = Capabilities::gdUsable($caps) ? new GdRenderer($this->sources(), $pango) : null;
        $imagick = Capabilities::imagickUsable($caps) ? new ImagickRenderer($this->sources(), $pango) : null;
        $selected = match ($this->settings()->string('renderer')) {
            'gd' => $gd,
            'imagick' => $imagick,
            default => $gd ?? $imagick,
        };
        $filtered = apply_filters('share_cards_renderer', $selected);
        return $this->renderer = $filtered instanceof Renderer ? $filtered : null;
    }

    /**
     * Engine name for the fingerprint, from the cached capabilities only.
     */
    public function rendererKind(): string
    {
        $renderer = $this->renderer();
        return $renderer === null ? 'none' : $renderer->name();
    }

    /**
     * Why cards cannot be served now (`unavailable`, `unwritable`), or null.
     */
    public function unavailableReason(): ?string
    {
        if ($this->renderer() === null) {
            return 'unavailable';
        }
        if (empty($this->capabilities()['cache_writable'])) {
            return 'unwritable';
        }
        return null;
    }

    public function fonts(): Fonts
    {
        $s = $this->settings();
        $map = array_merge($s->stringMap('font_scripts'), ['regular' => $s->string('font_regular'), 'bold' => $s->string('font_bold')]);
        $filtered = apply_filters('share_cards_fonts', $map);
        $map = is_array($filtered) ? $filtered : $map;
        $regular = isset($map['regular']) && is_string($map['regular']) ? $map['regular'] : '';
        $bold = isset($map['bold']) && is_string($map['bold']) ? $map['bold'] : '';
        unset($map['regular'], $map['bold']);
        $scripts = [];
        foreach ($map as $script => $path) {
            if (is_string($script) && is_string($path)) {
                $scripts[$script] = $path;
            }
        }
        $regularCandidates = apply_filters('share_cards_font_candidates', Fonts::REGULAR_CANDIDATES, 'regular');
        $boldCandidates = apply_filters('share_cards_font_candidates', Fonts::BOLD_CANDIDATES, 'bold');
        return new Fonts(
            $regular,
            $bold,
            $scripts,
            is_array($regularCandidates) ? array_values(array_filter($regularCandidates, 'is_string')) : Fonts::REGULAR_CANDIDATES,
            is_array($boldCandidates) ? array_values(array_filter($boldCandidates, 'is_string')) : Fonts::BOLD_CANDIDATES
        );
    }

    public function siteName(): string
    {
        return TextLayout::normalise((string) get_bloginfo('name'), TextLayout::MAX_KICKER);
    }

    public function template(): Template
    {
        return Template::fromSettings($this->settings(), $this->fonts(), $this->siteName());
    }

    /**
     * Fingerprint of runtime filters that change output. Arrays only: no file
     * is read.
     */
    private function runtimeFingerprint(): string
    {
        if (!has_filter('share_cards_template') && !has_filter('share_cards_fonts')) {
            return '';
        }
        $s = $this->settings();
        $template = apply_filters('share_cards_template', []);
        $fonts = apply_filters('share_cards_fonts', array_merge($s->stringMap('font_scripts'), ['regular' => $s->string('font_regular'), 'bold' => $s->string('font_bold')]));
        return substr(hash('sha256', Fingerprint::canonicalJson([$template, $fonts])), 0, 16);
    }

    public function fingerprint(): Fingerprint
    {
        $footer = $this->settings()->string('footer');
        $site = in_array($footer, ['name', 'both'], true) ? $this->siteName() : '';
        return new Fingerprint($this->settings()->fingerprint(), $this->rendererKind(), $site, $this->runtimeFingerprint());
    }

    public function cards(): Cards
    {
        return $this->cards ??= new Cards($this->settings(), $this->resolver(), $this->fingerprint());
    }

    /**
     * The active SEO adapter, if any (emit_tags `auto` only).
     */
    public function activeAdapter(): ?SeoAdapter
    {
        if ($this->adapterResolved) {
            return $this->adapter;
        }
        $this->adapterResolved = true;
        if ($this->settings()->string('emit_tags') !== 'auto') {
            return null;
        }
        $adapters = apply_filters('share_cards_seo_adapters', [new YoastAdapter(), new RankMathAdapter()]);
        foreach (is_array($adapters) ? $adapters : [] as $adapter) {
            if ($adapter instanceof SeoAdapter && $adapter->isActive()) {
                return $this->adapter = $adapter;
            }
        }
        return null;
    }

    /**
     * Takes one of `max_concurrent` render slots, polling up to the wait.
     */
    public function acquireSlot(int $waitMs): ?Lock
    {
        $slots = max(1, $this->settings()->int('max_concurrent'));
        $locks = $this->locks();
        $deadline = $this->clock->now() + max(0, $waitMs) / 1000;
        while (true) {
            for ($i = 0; $i < $slots; $i++) {
                $lock = $locks->acquire('slot-' . $i, 0);
                if ($lock !== null) {
                    return $lock;
                }
            }
            if ($this->clock->now() >= $deadline) {
                return null;
            }
            $this->clock->sleepMs(FileLockProvider::POLL_MS);
        }
    }

    /**
     * Renders a card into the cache atomically. No locks or budget here.
     *
     * @return array{path: string, ms: int, bytes: int}
     *
     * @throws RenderException
     */
    public function renderToCache(Subject $subject, Key $key): array
    {
        $cache = $this->cache();
        if (!$cache->ensure()) {
            throw new RenderException('The cache directory could not be created.');
        }
        $started = $this->clock->now();
        $path = $cache->writeAtomic($key, function (string $temp) use ($subject, $key): void {
            $this->renderTo($subject, $temp, $key->toString());
        });
        $cache->clearFailure($key);
        $ms = (int) round(($this->clock->now() - $started) * 1000);
        $bytes = (int) filesize($path);
        $this->afterRender($key->toString(), $subject, $path, $ms, $bytes);
        return ['path' => $path, 'ms' => $ms, 'bytes' => $bytes];
    }

    /**
     * Renders a card to any path, logging fields left off and backdrop
     * problems under `$label` (the key, when there is one).
     *
     * @throws RenderException
     */
    public function renderTo(Subject $subject, string $dest, string $label = ''): void
    {
        $renderer = $this->renderer();
        if ($renderer === null) {
            throw new UnsupportedEnvironmentException('No usable renderer: ' . implode(', ', Capabilities::missing($this->capabilities())));
        }
        $label = $label !== '' ? $label : $subject->type . '-' . $subject->id;
        try {
            $renderer->render($subject, $this->template(), $dest);
        } finally {
            if ($renderer instanceof CanvasRenderer) {
                $log = $this->logger();
                foreach ($renderer->lastOmitted() as $omitted) {
                    $log->info(sprintf('field omitted key=%s field=%s script=%s reason=%s', $label, $omitted['field'], $omitted['scripts'], $omitted['reason']));
                }
                $note = $renderer->lastBackdropNote();
                if ($note !== '') {
                    $message = sprintf('backdrop skipped key=%s attachment=%d reason=%s', $label, $subject->backdropId, $note);
                    str_starts_with($note, 'decode failed') ? $log->error($message) : $log->debug($message);
                }
            }
        }
    }

    private function afterRender(string $key, Subject $subject, string $path, int $ms, int $bytes): void
    {
        $renderer = $this->renderer();
        $name = $renderer === null ? 'none' : $renderer->name();
        $backdrop = $subject->backdropId > 0 && (!$renderer instanceof CanvasRenderer || $renderer->lastBackdropNote() === '') ? 'yes' : 'no';
        $this->logger()->info(sprintf('render key=%s renderer=%s ms=%d bytes=%d backdrop=%s', $key, $name, $ms, $bytes, $backdrop));
        do_action('share_cards_rendered', $key, $path, $ms, $name);
    }

    // ------------------------------------------------------------------
    // Hooks.

    public function boot(): void
    {
        add_action('init', [$this, 'onInit'], 5);
        add_filter('query_vars', [Rewrite::class, 'queryVars']);
        add_action('parse_request', [$this, 'onParseRequest'], 1);
        add_action('wp_head', [$this, 'onHead'], 5);
        add_action(self::CRON_HOOK, [$this, 'onCron']);
        (new Invalidator($this))->register();

        if (is_admin()) {
            (new SettingsPage($this))->register();
            (new SiteHealth($this))->register();
            add_action('admin_init', [$this, 'onAdminInit']);
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('share-cards', new Cli($this));
        }
    }

    public function onInit(): void
    {
        // Must-use installs get no activation hook: store the defaults once,
        // with their fingerprint, so later requests read no font files.
        if (get_option(Settings::OPTION) === false) {
            $this->useSettings((new Settings())->save());
        }
        $this->rewrite()->register();
        $adapter = $this->activeAdapter();
        if ($adapter !== null) {
            $adapter->register(fn (): ?array => $this->chooser()->forRequest());
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * `admin_init` also runs for admin-ajax.php and admin-post.php, which
     * anyone can request, so only an administrator's page load repairs.
     */
    public function onAdminInit(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $this->rewrite()->repairIfMissing();
    }

    public function onHead(): void
    {
        $this->headTags()->print();
    }

    public function onCron(): void
    {
        $this->gc()->cron();
    }

    /**
     * @param \WP $wp
     */
    public function onParseRequest($wp): void
    {
        $response = $this->responseFor(is_object($wp) && property_exists($wp, 'query_vars') && is_array($wp->query_vars) ? $wp->query_vars : []);
        if ($response === null) {
            return;
        }
        $response->send();
        exit;
    }

    /**
     * The endpoint response for parsed query vars, or null when the request
     * is not for a card.
     *
     * @param array<string, mixed> $vars
     */
    public function responseFor(array $vars): ?Response
    {
        $raw = $vars[Cards::QUERY_VAR] ?? null;
        $previous = false;
        if ($raw === null && isset($vars[Cards::PREVIOUS_QUERY_VAR])) {
            $raw = $vars[Cards::PREVIOUS_QUERY_VAR];
            $previous = true;
        }
        if ($raw === null) {
            return null;
        }
        $endpoint = $this->endpoint();
        if (!is_string($raw) || ($previous && $this->rewrite()->previous() === null)) {
            return $endpoint->notFound();
        }
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) && is_string($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : null;
        return $endpoint->handle($raw, $method, $inm, $previous);
    }

    // ------------------------------------------------------------------
    // Lifecycle.

    public function activate(): void
    {
        // Store defaults (with discovered fonts in the fingerprint).
        if (get_option(Settings::OPTION) === false) {
            (new Settings())->save();
            $this->settings = null;
        }
        delete_transient(Capabilities::TRANSIENT);
        $this->cache()->ensure();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
        }
        $this->rewrite()->register();
        flush_rewrite_rules(false);
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        flush_rewrite_rules(false);
    }

    /**
     * Removes everything the plugin created on the current site.
     */
    public function uninstallSite(): void
    {
        delete_option(Settings::OPTION);
        delete_option(GarbageCollector::STATE_OPTION);
        delete_option(Rewrite::PREVIOUS_OPTION);
        delete_transient(Capabilities::TRANSIENT);
        delete_transient(Rewrite::FLUSH_GUARD);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::removeOwnFiles($this->cache(), true);
        if (is_multisite() && ms_is_switched()) {
            // After switch_to_blog() the global rewrite object still holds the
            // first site's permalink structure and rules, so a flush here would
            // store them for this site. Deleting the stored rules makes
            // WordPress rebuild this site's own rules on its next request.
            delete_option('rewrite_rules');
        } else {
            flush_rewrite_rules(false);
        }
    }

    /**
     * Deletes the plugin's files from the cache directory. Files the plugin
     * did not name are never removed.
     *
     * @return int Files removed.
     */
    public static function removeOwnFiles(Cache $cache, bool $includeIndex): int
    {
        $dir = $cache->directory();
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach ($cache->listNames() as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || !Cache::isOwnName($name) || ($name === 'index.html' && !$includeIndex)) {
                continue;
            }
            if (unlink($path)) {
                $count++;
            }
        }
        $locks = $cache->lockDirectory();
        if (is_dir($locks)) {
            foreach (scandir($locks) ?: [] as $name) {
                if (preg_match(Cache::LOCK_PATTERN, $name) === 1 && is_file($locks . '/' . $name) && unlink($locks . '/' . $name)) {
                    $count++;
                }
            }
            if ($includeIndex && (scandir($locks) ?: []) === ['.', '..']) {
                rmdir($locks);
            }
        }
        if ($includeIndex && (scandir($dir) ?: []) === ['.', '..']) {
            rmdir($dir);
        }
        return $count;
    }
}
