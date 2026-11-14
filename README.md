# share-cards

A WordPress plugin that makes the `og:image` and `twitter:image` for posts, categories, tags and custom pages: a 1200 by 630 card with the headline drawn on it, rendered the first time a scraper asks for it and served from disk after that. Featured images that are already the right size are used as they are; only images that are too small, the wrong shape, generic or missing get a card.

Measured on one news site over one week of featured images: about 90% were already at least 1200 px wide and close to 16:9 and are used as they are; the rest, and every category and tag page, now get their own card instead of one shared placeholder. Rendering on request instead of at publish avoided rendering cards for posts nobody shared.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Fonts and scripts](#fonts-and-scripts)
- [Serving cards without PHP](#serving-cards-without-php)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Observability](#observability)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)
- [Contributing](#contributing)

## Requirements

- PHP 8.1, 8.2 or 8.3, with `ext-mbstring`.
- WordPress 6.4 or later, single site or multisite.
- One renderer: `ext-gd` with JPEG and FreeType support (the usual build), or `ext-imagick` that can write JPEG. Composer declares `ext-gd`; on a host with Imagick and no GD, install without Composer or with `--ignore-platform-req=ext-gd`.
- A writable directory for the cache (by default `wp-content/uploads/share-cards`).
- At least one TrueType or OpenType font. Nothing is bundled; see [Fonts and scripts](#fonts-and-scripts).
- Optional: Imagick with ImageMagick built with Pango, to draw complex scripts (Devanagari, Tamil, Bengali, Arabic, Thai and others). `ext-intl` for Unicode normalisation of card text.

## Install

### Without Composer

1. Download the release zip, or clone the repository, into `wp-content/plugins/share-cards`.
2. Activate **Share cards** in Plugins.

The plugin loads its own classes; no `vendor` directory is needed.

### With Composer

```sh
composer require indianopinion/share-cards
```

With `composer/installers` and the usual `wordpress-plugin` path, it lands in `wp-content/plugins/share-cards`. Activate it as above.

### As a must-use plugin

Copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/share-cards-loader.php`:

```php
<?php
/**
 * Plugin Name: Share cards (must-use loader)
 */
require_once WP_CONTENT_DIR . '/plugins/share-cards/share-cards.php';
```

Must-use plugins get no activation hook. The defaults are stored and the daily clean-up is scheduled on the first request, and the rewrite rule is flushed once on the first page load in wp-admin by an administrator.

## Quick start

1. Activate the plugin.
2. Open **Settings, Share cards**. Check the notice at the top: it names anything missing (GD, JPEG or FreeType support, a writable directory).
3. Press **Render preview** to see a sample card.
4. Open a post without a featured image and view the page source. You should see:

```html
<meta property="og:image" content="https://example.com/share-card/post-42-3f9a1c0b7e.jpg" />
<meta property="og:image:type" content="image/jpeg" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta property="og:image:alt" content="The post title" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:image" content="https://example.com/share-card/post-42-3f9a1c0b7e.jpg" />
<meta name="twitter:image:alt" content="The post title" />
```

5. Fetch the image URL. The first response has `X-Share-Card: render`, the next ones `X-Share-Card: hit`.

```sh
curl -sI https://example.com/share-card/post-42-3f9a1c0b7e.jpg | grep -i x-share-card
wp share-cards status
```

With Yoast SEO or Rank Math active, the plugin prints no tags of its own; it hands the image, with its width and height, to that plugin.

## Configuration

Everything is on **Settings, Share cards** (capability `manage_options`). Every value is also stored in the `share_cards_settings` option and most have a filter (see [Hooks](#hooks)).

| Setting | Default | What it does |
|---|---|---|
| `mode` | `auto` | `auto`: card only when the featured image is unfit. `always`: card for every post. `never`: no post cards. |
| `term_cards` | on | Cards for category and tag archives. |
| `min_width` | 1200 | A featured image narrower than this gets a card. |
| `aspect_min`, `aspect_max` | 1.5, 2.1 | A featured image outside this width to height ratio gets a card. |
| `generic_image_ids` | empty | Placeholder or logo images: never used as they are, never a backdrop. |
| `default_image_id` | 0 | Image for views with no card (front page, search, 404) and the last fallback. |
| `url_prefix` | `share-card` | First path segment of card URLs. After a change the old prefix redirects for 30 days. |
| `emit_tags` | `auto` | `auto`: print tags unless Yoast SEO or Rank Math takes the image. `on`: always print. `off`: never; the theme calls `share_cards_image_for_request()`. |
| `renderer` | `auto` | `auto` (GD, else Imagick), `gd`, `imagick`. |
| `colour_background` | `#1f2937` | Card background and scrim. |
| `colour_text` | `#ffffff` | Headline and site name. |
| `colour_kicker` | `#93c5fd` | Kicker (category name). |
| `colour_muted` | `#d1d5db` | Footnote. |
| `colour_accent` | `#60a5fa` | Bar along the bottom edge. |
| `font_regular`, `font_bold` | discovered | Font files for Latin, Greek and Cyrillic. Empty: Noto Sans, then DejaVu Sans. |
| `font_scripts` | empty | One font per script, for example `Devanagari=/usr/share/fonts/.../NotoSansDevanagari-Regular.ttf`. |
| `logo_id` | 0 | Logo image attachment (PNG or WebP with alpha). |
| `footer` | `name` | `name`, `logo`, `both` or `none`. |
| `kicker_uppercase` | on | Upper-case the kicker (multibyte safe). |
| `taxonomy_labels` | singular names | Kicker on term cards, per taxonomy. |
| `term_footnote` | empty | Footnote on term cards; `{count}` is the number of posts. |
| `backdrop` | on | Draw the featured image, darkened, behind the text. |
| `scrim_opacity` | 0.62 | Strength of the background-coloured layer over the backdrop. |
| `jpeg_quality` | 82 | 60 to 95. Cards over 300 KB are re-encoded 10 lower until they fit or reach 60. |
| `max_lines` | 4 | Headline lines. |
| `size_ladder` | 64, 56, 48, 42 | Headline sizes in px, largest first. |
| `max_concurrent` | 2 | Renders at once, site-wide. |
| `renders_per_minute` | 30 | Renders started per minute, site-wide. |
| `lock_wait_ms`, `slot_wait_ms` | 2000, 3000 | How long a request waits for another render or a free slot. |
| `failure_ttl` | 600 | Seconds before a failed card is tried again. |
| `max_source_bytes` | 20971520 | Larger backdrop files are skipped. |
| `max_source_pixels` | 25000000 | Larger backdrops are skipped, checked from the header. |
| `cache_max_mb` | 512 | Disk cap; the oldest cards go first. |
| `log_level` | `error` | `error`, `info` or `debug`. |

The layout numbers (padding, positions, sizes) are in the `share_cards_template` filter:

```php
add_filter('share_cards_template', static function (array $t): array {
    $t['padding'] = 80;
    $t['headline_top'] = 140;
    $t['colour_accent'] = '#e11d48';
    return $t;
});
```

Template keys and defaults: `padding` 70, `kicker_baseline` 96, `kicker_size` 30, `headline_top` 150, `headline_height` 340, `footer_baseline` 572, `footer_size` 34, `logo_max_height` 60, `footnote_size` 26, `accent_height` 8, plus every colour and `size_ladder`, `max_lines`, `scrim_opacity`, `jpeg_quality`, `footer`, `kicker_uppercase`, `backdrop`, `logo_id`.

## How it works

1. **Choosing the image.** On each page view the plugin decides what to advertise, once, without reading any file. A single post of an allowed type (default `post`) with a featured image at least 1200 px wide and between 1.5 and 2.1 times as wide as high uses that image as it is, with its real width and height. Anything else gets a card: too narrow, wrong shape, generic, or missing. Category and tag archives get a term card. Other views get the default image, if set. Drafts, private and password-protected posts never get a card URL.
2. **The URL.** A card URL is `/{prefix}/{type}-{id}-{hash}.jpg`, for example `/share-card/post-42-3f9a1c0b7e.jpg`. The hash is the first 10 hex characters of a SHA-256 over everything drawn on the card: headline, kicker, footnote, backdrop file, settings, renderer and template version. Change any of those and the URL changes, so a CDN can cache cards for a year. With plain permalinks the URL is `/?share_card=post-42-3f9a1c0b7e`.
3. **Serving.** The endpoint runs on `parse_request`, before the main query and the theme. A current card on disk is streamed with `Cache-Control: public, max-age=31536000, immutable`, an ETag and `X-Share-Card: hit`. A stale hash gets a 302 to the current URL. Unknown, unpublished or private subjects get the same empty 404, so nothing leaks.
4. **Rendering on a cold cache.** One process takes a file lock for the key and renders; others wait up to `lock_wait_ms`, then get a 302 to the featured or default image (`X-Share-Card: fallback-busy`). At most `max_concurrent` renders run at once and at most `renders_per_minute` start each minute; past either limit the request gets the fallback too. The card is written to a temporary name and renamed into place, so no reader ever sees half a file. A failed render leaves a marker that stops retries for `failure_ttl` seconds.
5. **Drawing.** Layout (wrapping, the size ladder, ellipsis) is computed once, in plain PHP. GD or Imagick only measures text, draws and encodes. The backdrop is cover-cropped around a focal point, set to 55% brightness and 65% saturation, then covered with the background colour at `scrim_opacity`. Output is a progressive JPEG with no metadata.
6. **Invalidation.** When a post title, featured image or status changes, old variants of that post's card are deleted at once and `share_cards_purged` fires with their URLs. A settings change re-keys every card without deleting anything; a daily clean-up removes stale variants, orphans, old temporary files and markers, and trims the directory to the disk cap.

Files written, all in the cache directory: `{key}.jpg`, `{key}.fail`, `.{key}.{random}.tmp`, `.locks/*.lock`, `.budget`, `.preview*.jpg` and an empty `index.html`. Nothing else, no attachment rows, no post meta.

## Fonts and scripts

No font ships with the plugin. It looks for Noto Sans, then DejaVu Sans, in the usual system directories. On Debian or Ubuntu:

```sh
sudo apt-get install fonts-noto-core fonts-dejavu-core
# CJK, if you need it:
sudo apt-get install fonts-noto-cjk
```

On Alpine: `apk add font-noto font-dejavu`. On RHEL-like systems: `dnf install google-noto-sans-fonts dejavu-sans-fonts`.

If no font is found the card cannot be drawn: Site Health and `wp share-cards status` say "No font found for the Latin script", and pages fall back to the featured or default image.

Latin, Greek and Cyrillic use the regular and bold fonts. Every other script needs an explicit entry in **Fonts per script**, one per line:

```text
Devanagari=/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf
Tamil=/usr/share/fonts/truetype/noto/NotoSansTamil-Regular.ttf
Arabic=/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf
Han=/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.otf
Hiragana=/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.otf
Katakana=/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.otf
```

Font files must end in `.ttf` or `.otf` and sit under `wp-content`, `/usr/share/fonts` or `/usr/local/share/fonts` (filter `share_cards_font_dirs`).

Rules for a field (kicker, headline, footer, footnote):

- A script with no mapped font is not drawn. Tofu boxes never appear.
- Scripts that need shaping (Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam, Sinhala, Arabic, Hebrew, Syriac, Thaana, N'Ko, Thai, Lao, Tibetan, Myanmar, Khmer, Mongolian) are drawn only through the Pango layer, which needs Imagick with ImageMagick built with Pango. **GD never shapes**: without Pango those fields are left off the card and one info line is logged. A blank space is fine; broken conjuncts are not.
- One word in a shaping script makes the whole field need shaping. A field is drawn whole or not at all.
- Pango finds fonts by family name through fontconfig, so a mapped font for a shaping script must also be installed where fontconfig looks (the system font directories). Check with `fc-list | grep -i devanagari`.
- CJK text breaks between any two characters, with no line starting with closing punctuation or ending with opening punctuation.

To check Pango: `php -r 'var_dump(Imagick::queryFormats("PANGO"));'` must list `PANGO`. On Debian or Ubuntu, the `php-imagick` package with the `libmagickcore-6.q16-*-extra` package (the number varies by release) provides it.

## Serving cards without PHP

Cards are plain files, so the web server can serve them directly and pass misses to WordPress. These rules are optional; without them everything still works through PHP. Adjust the prefix and the path.

nginx, inside the `server` block and before the PHP location ([`examples/nginx-static.conf`](examples/nginx-static.conf)):

```nginx
location ~ "^/share-card/(?<share_card>[a-z][a-z0-9]{0,15}-[1-9][0-9]{0,19}-[0-9a-f]{10}\.jpg)$" {
    root /var/www/example.com/wp-content/uploads/share-cards;
    try_files /$share_card @share_cards_php;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Share-Card "static" always;
}

location @share_cards_php {
    rewrite ^ /index.php last;
}
```

Apache, above the WordPress block in `.htaccess` ([`examples/apache-static.htaccess`](examples/apache-static.htaccess)):

```apache
RewriteEngine On
RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/share-cards/$1 -f
RewriteRule ^share-card/([a-z][a-z0-9]{0,15}-[1-9][0-9]{0,19}-[0-9a-f]{10}\.jpg)$ wp-content/uploads/share-cards/$1 [L,E=SHARE_CARD:1]
Header always set Cache-Control "public, max-age=31536000, immutable" env=REDIRECT_SHARE_CARD
Header always set X-Content-Type-Options "nosniff" env=REDIRECT_SHARE_CARD
```

With static serving, a card whose hash went stale through a settings or site name change keeps being served until the daily clean-up removes it. Cards removed for an edit, an unpublish or a delete are deleted at once, so they stop at once.

## Hooks

### Filters

| Filter | Value | Default |
|---|---|---|
| `share_cards_post_types` | `string[]` | `['post']` |
| `share_cards_taxonomies` | `string[]` | `['category', 'post_tag']` |
| `share_cards_mode` | `auto`, `always`, `never`; args: post | setting |
| `share_cards_generic_images` | `int[]` attachment IDs | setting |
| `share_cards_backdrop_attachment` | `int` (0 vetoes); args: post | featured image ID |
| `share_cards_backdrop_path` | local path or `''`; args: attachment ID | resolved path |
| `share_cards_focal_point` | `[x, y]` fractions 0 to 1; args: attachment ID | `[0.5, 0.5]` |
| `share_cards_subject` | `Subject`; args: type, id | resolved subject |
| `share_cards_types` | map of type to `pattern`, `resolve`, `matchRequest` | `[]` |
| `share_cards_fingerprint_parts` | `array`; args: `Subject` | `[]` |
| `share_cards_template` | array of layout and colours | from settings |
| `share_cards_fonts` | map: `regular`, `bold`, script name to path | from settings |
| `share_cards_needs_shaping` | `bool`; args: text | classification |
| `share_cards_renderer` | `Renderer` or null | selected renderer |
| `share_cards_lock_provider` | `LockProvider` | `FileLockProvider` |
| `share_cards_cache_dir` | absolute path | `{uploads}/share-cards` |
| `share_cards_fallback_url` | string; args: key, reason | featured, else default image |
| `share_cards_emit_tags` | `bool` | true unless an SEO plugin takes the image, or `emit_tags` is off |
| `share_cards_image` | array or null: the final image for the request | chosen image |
| `share_cards_front_page_card` | `bool`: a site-wide card on the front page | `false` |
| `share_cards_seo_adapters` | `SeoAdapter[]` | Yoast SEO, Rank Math |
| `share_cards_previous_prefix_days` | `int` | 30 |
| `share_cards_font_dirs` | directories a font may be in | `WP_CONTENT_DIR`, `/usr/share/fonts`, `/usr/local/share/fonts` |
| `share_cards_font_candidates` | paths tried when a font setting is empty; args: `regular` or `bold` | Noto Sans, DejaVu Sans |
| `share_cards_capabilities` | detected capabilities | detection |
| `share_cards_gc_batch` | files per clean-up batch | 500 |
| `share_cards_memory_available` | callable returning free bytes for a decode | from `memory_limit` |

### Actions

| Action | Arguments |
|---|---|
| `share_cards_rendered` | key, path, milliseconds, renderer name |
| `share_cards_render_failed` | key, `Throwable` |
| `share_cards_fallback` | key, reason |
| `share_cards_purged` | `string[]` URLs, reason (`edit`, `unpublish`, `delete`, `gc`, `flush`) |

The plugin never calls a CDN. Hook `share_cards_purged` to purge ([`examples/cdn-purge.php`](examples/cdn-purge.php)).

### Functions for themes

```php
share_cards_image_for_request(): ?array   // url, width, height, type, alt, source (card, featured, default)
share_cards_url_for_post(int|WP_Post $post): ?string
share_cards_url_for_term(int|WP_Term $term): ?string
share_cards_url_for(string $type, string $id): ?string
```

A theme that prints its own Open Graph tags sets `emit_tags` to `off` and calls `share_cards_image_for_request()`.

### Custom card types

Register a type to give pages that are not posts or terms their own card. Two URLs that show the same content return the same type and id, so they share one file. See [`examples/custom-type-day.php`](examples/custom-type-day.php):

```php
add_filter('share_cards_types', static function (array $types): array {
    $types['day'] = [
        'pattern' => '/^\d{8}$/D',                      // ids are digits, no leading zero
        'resolve' => static fn (string $id) => new IndianOpinion\ShareCards\Subject('day', $id, "The day in brief: $id", 'Daily brief'),
        'matchRequest' => static fn (): ?string => is_page('today') ? wp_date('Ymd') : null,
    ];
    return $types;
});
```

`resolve` returns a `Subject` or null (null makes the URL a 404). `matchRequest` returns the id for the current request, or null.

## WP-CLI

```sh
wp share-cards warm [--posts=<n>] [--terms] [--dry-run]   # render missing cards for the n newest posts (default 50)
wp share-cards status                                     # renderer, GD and Imagick, Pango, fonts, cache, rewrite rule, last clean-up
wp share-cards render <post|term|type>:<id> [--force] [--out=<file>]
wp share-cards url <post|term|type>:<id>                  # current card URL and the image the page advertises
wp share-cards gc [--dry-run]                             # one full clean-up
wp share-cards flush --yes                                # delete every cached card, marker, temporary file and lock
```

`warm` respects `max_concurrent` but not the per-minute budget. `render` ignores locks and the budget and prints time and peak memory.

## Observability

- **Header.** Every endpoint response carries `X-Share-Card`: `hit`, `render`, `stale`, `moved` (old prefix), `fallback-busy`, `fallback-slots`, `fallback-budget`, `fallback-error`, `fallback-unavailable`, `fallback-unwritable`, `not-found` or `not-allowed`.
- **Log.** Lines go to `error_log` prefixed `[share-cards]`. Errors always; renders, fallbacks, purges, omitted fields and clean-ups at `info`; skipped backdrops at `debug`. Lines carry keys and attachment IDs, never visitor data.
- **Site Health.** Renderer usable, fonts readable, cache directory writable, rewrite rule present, shaping available when a shaping script has a font.
- **Actions.** Hook the actions above to count renders and fallbacks.

## Limitations

- One template, 1200 by 630, JPEG only. No square or portrait cards, no WebP, no per-post design.
- GD never shapes text. Complex scripts need Imagick with Pango; without it those fields are left blank.
- Fonts are chosen by an explicit script map. The plugin does not read a font's character map, so a mapped font that lacks some glyphs draws boxes for them. Map fonts that cover the script.
- All scripts in one field must map to the same font file. A headline mixing Han and Hangul with two different fonts is left off.
- Thai, Lao, Khmer and Myanmar have no spaces between words; only Pango wraps them.
- Backdrops come from local files only. With media offloaded to object storage, supply a local path with `share_cards_backdrop_path` or go without backdrops.
- Rank Math: the plugin replaces the image Rank Math chose. On a page where Rank Math finds no image at all, it prints none; set a default image in Rank Math so there is always one to replace, or set `emit_tags` to `on` and turn off Rank Math's image output.
- The file lock needs a local file system. On several web servers sharing one network file system, supply a `LockProvider` through `share_cards_lock_provider`.
- Counts drawn on a card (for example `{count}` in the term footnote) are part of the hash, so the card re-renders every time the count moves.

## FAQ

**The card URL returns the featured image instead of a card.** Look at `X-Share-Card`. `fallback-busy` or `fallback-slots` means renders were queued; the next request gets the card. `fallback-budget` means more than `renders_per_minute` cards started this minute. `fallback-error` means the render failed; the reason is in the PHP error log and it is retried after `failure_ttl` seconds.

**Card URLs return the WordPress 404 page.** The rewrite rule is missing. Site Health says so; visit any admin page as an administrator (the plugin flushes once an hour at most) or save **Settings, Permalinks**.

**I changed the colours and old cards still show on social platforms.** Platforms cache images by URL. New page views advertise the new URL; ask the platform to re-scrape the page (for example the Facebook Sharing Debugger) to pick it up sooner.

**A Hindi or Tamil headline is missing from the card.** Without Pango the plugin refuses to draw scripts it cannot shape. Run `wp share-cards status`: `Pango: no` means Imagick or its Pango delegate is missing. Also check the script has an entry in **Fonts per script**.

**Does it work behind a page cache or a CDN?** Yes. Card responses are images with their own cache headers and set no cookies. Because the URL changes with the content, a year of caching is safe. Fallback redirects and 404s are cached for 60 seconds only.

**Does it replace my SEO plugin?** No. It supplies the image only. With Yoast SEO or Rank Math it hands the image over through their public filters; otherwise it prints the eight image tags and nothing else.

**Can I add the site name and tagline card to the front page?** Yes: `add_filter('share_cards_front_page_card', '__return_true');`

**What happens on uninstall?** The settings, transients, cron event and every file the plugin named are removed on every site. Files it did not name are left in place, and so is the directory if anything remains in it.

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, tests and style.
