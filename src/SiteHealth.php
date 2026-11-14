<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Site Health tests: renderer, fonts, cache directory, rewrite rule, shaping.
 */
final class SiteHealth
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'addTests']);
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function addTests($tests): array
    {
        $tests = is_array($tests) ? $tests : [];
        $tests['direct'] = isset($tests['direct']) && is_array($tests['direct']) ? $tests['direct'] : [];
        foreach (['renderer', 'fonts', 'cache', 'rewrite', 'shaping'] as $name) {
            $tests['direct']['share_cards_' . $name] = [
                'label' => 'Share cards: ' . $name,
                'test' => fn (): array => $this->result($name),
            ];
        }
        return $tests;
    }

    /**
     * Every test result, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function results(): array
    {
        $out = [];
        foreach (['renderer', 'fonts', 'cache', 'rewrite', 'shaping'] as $name) {
            $out[$name] = $this->result($name);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function result(string $name): array
    {
        return match ($name) {
            'renderer' => $this->renderer(),
            'fonts' => $this->fonts(),
            'cache' => $this->cache(),
            'rewrite' => $this->rewrite(),
            default => $this->shaping(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function renderer(): array
    {
        $renderer = $this->plugin->renderer();
        if ($renderer !== null) {
            return $this->make('renderer', 'good', 'Share cards can render images', sprintf('Renderer in use: %s.', $renderer->name()));
        }
        $missing = Capabilities::missing($this->plugin->capabilities());
        return $this->make(
            'renderer',
            'critical',
            'Share cards cannot render images',
            sprintf('Missing: %s. Pages fall back to the featured image or the default image until this is fixed.', $missing === [] ? 'the selected renderer' : implode(', ', $missing))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fonts(): array
    {
        $fonts = $this->plugin->fonts();
        $check = $fonts->check();
        if ($check instanceof \WP_Error) {
            return $this->make('fonts', 'critical', 'Share cards has no usable font', $check->get_error_message());
        }
        if ($fonts->missing() !== []) {
            return $this->make('fonts', 'critical', 'A share card font file is missing', sprintf(
                'These configured font files cannot be read: %s. Latin text falls back to %s; other scripts are left off cards.',
                implode(', ', $fonts->missing()),
                basename($fonts->regular())
            ));
        }
        return $this->make('fonts', 'good', 'Share card fonts are readable', sprintf('Regular: %s. Bold: %s.', $fonts->regular(), $fonts->bold()));
    }

    /**
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        $dir = $this->plugin->cache()->directory();
        if ($this->plugin->cache()->isWritable()) {
            return $this->make('cache', 'good', 'The share card directory is writable', $dir);
        }
        return $this->make('cache', 'critical', 'The share card directory is not writable', sprintf('Make %s writable by the web server user. No card URLs are advertised until then.', $dir));
    }

    /**
     * @return array<string, mixed>
     */
    private function rewrite(): array
    {
        if ($this->plugin->rewrite()->rulePresent()) {
            return $this->make('rewrite', 'good', 'The share card rewrite rule is present', 'Card URLs route to the plugin.');
        }
        return $this->make('rewrite', 'recommended', 'The share card rewrite rule is missing', 'Another plugin flushed the rewrite rules before this one registered. The next admin page load flushes them once; or visit Settings, Permalinks and save.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shaping(): array
    {
        $needs = array_intersect(array_keys($this->plugin->fonts()->scripts()), TextLayout::SHAPING_SCRIPTS);
        $renderer = $this->plugin->renderer();
        if ($needs === [] || ($renderer !== null && $renderer->canShape())) {
            return $this->make('shaping', 'good', 'Share cards can draw every mapped script', 'No mapped script needs a shaping engine that is missing.');
        }
        return $this->make('shaping', 'recommended', 'Share cards cannot shape complex scripts', sprintf(
            'Fonts are mapped for %s, which need shaping. Install the Imagick extension with ImageMagick built with Pango; until then those fields are left off cards.',
            implode(', ', $needs)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function make(string $name, string $status, string $label, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'Share cards', 'color' => $status === 'good' ? 'blue' : ($status === 'critical' ? 'red' : 'orange')],
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => '',
            'test' => 'share_cards_' . $name,
        ];
    }
}
