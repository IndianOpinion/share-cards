<?php

/**
 * Renders one card from the command line, without WordPress, to try a
 * template and a font quickly:
 *
 *     php examples/render-without-wordpress.php "A headline" /tmp/card.jpg
 *
 * Uses GD, and the Pango layer when Imagick has it.
 */

declare(strict_types=1);

use IndianOpinion\ShareCards\Fonts;
use IndianOpinion\ShareCards\GdRenderer;
use IndianOpinion\ShareCards\PangoTextLayer;
use IndianOpinion\ShareCards\SourceResolver;
use IndianOpinion\ShareCards\Subject;
use IndianOpinion\ShareCards\Template;

require dirname(__DIR__) . '/autoload.php';

// The drawing code asks one WordPress filter; outside WordPress, pass through.
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

$headline = $argv[1] ?? 'Parliament passes the budget after a long night of debate';
$out = $argv[2] ?? sys_get_temp_dir() . '/share-card-example.jpg';

$fonts = new Fonts('', '', [
    // Map any script you need; scripts without a font are left off.
    'Devanagari' => '/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf',
]);
$template = Template::fromArray([
    'site_name' => 'Example News',
    'colour_background' => '#1f2937',
], $fonts);

$renderer = new GdRenderer(new SourceResolver(), PangoTextLayer::isAvailable() ? new PangoTextLayer() : null);
if (!$renderer->isAvailable()) {
    fwrite(STDERR, "GD with JPEG and FreeType support is required.\n");
    exit(1);
}

$started = microtime(true);
$renderer->render(new Subject('post', '1', $headline, 'Politics', 'Example footnote'), $template, $out);
printf("Wrote %s with %s in %d ms\n", $out, $renderer->name(), (int) round((microtime(true) - $started) * 1000));
