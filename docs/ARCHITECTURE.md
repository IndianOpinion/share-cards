# share-cards: architecture

This is how the plugin is built and why. The behaviour it implements is in
[PRD.md](PRD.md); case numbers (C1 to C78) and test numbers (T1 to T78) refer
to it.

## Modules

All classes live in `src/` under the `IndianOpinion\ShareCards` namespace,
with the SEO adapters under `IndianOpinion\ShareCards\Seo`. The plugin loads
them with its own autoloader (`autoload.php`), so Composer is not needed at
runtime.

| Area | Classes | Cases |
|---|---|---|
| Wiring | `Plugin` (services, hooks, activation, uninstall), `share-cards.php`, `uninstall.php`, `functions.php` | C66, C78 |
| Settings | `Settings` (defaults, validation, settings fingerprint), `SettingsPage`, `SiteHealth`, `Capabilities` | C77, 4.8, section 11 |
| Keys and URLs | `Key`, `Cards`, `Rewrite` | C17 to C19 |
| Subjects | `Subject`, `SubjectResolver` | C7, C8, C13, C24 |
| Choosing | `ImageChooser`, `HeadTags`, `Seo\SeoAdapter`, `Seo\YoastAdapter`, `Seo\RankMathAdapter` | C1 to C16 |
| Serving | `Endpoint`, `Response`, `Cache`, `LockProvider`, `Lock`, `FileLockProvider`, `FileLock`, `RenderBudget`, `Clock`, `SystemClock` | C20 to C33 |
| Invalidation | `Fingerprint`, `Invalidator`, `GarbageCollector` | C34 to C43 |
| Text | `TextLayout`, `TextMeasurer`, `FittedText`, `Fonts`, `FontInfo` | C44 to C57 |
| Drawing | `Renderer`, `CanvasRenderer`, `GdRenderer`, `ImagickRenderer`, `CardComposer`, `Canvas`, `GdCanvas`, `ImagickCanvas`, `GdMeasurer`, `PangoTextLayer`, `PangoResult`, `Template`, `Geometry` | 4.7 to 4.9 |
| Sources | `SourceResolver`, `SourceImage` | C58 to C65 |
| Errors | `ShareCardsException`, `InvalidKeyException`, `RenderException`, `SourceImageException`, `UnsupportedEnvironmentException`, `Errors` | everywhere |
| CLI | `Cli` | C71 to C76 |

Only `Plugin`, `SettingsPage`, `SiteHealth`, `Cli`, `SubjectResolver`,
`ImageChooser`, `Invalidator`, `GarbageCollector`, `SourceResolver`, `Cards`
and `Capabilities` call WordPress. Text layout, fingerprints, keys, locks and
the canvases are plain PHP; the composer asks one filter
(`share_cards_needs_shaping`).

## Data flow

### Page view

```
wp_head (priority 5)
  HeadTags::print
    -> emit? (emit_tags setting, active SEO adapter, share_cards_emit_tags)
    -> ImageChooser::forRequest (memoised)
         custom type matchers -> front page card (filter, default off)
         -> single post: allowed type? public? mode? featured eligible?
         -> term archive: allowed taxonomy? term_cards?
         -> default image
       card(Subject) -> Cards::keyFor -> Fingerprint::hash (database values only)
    -> eight escaped meta tags
```

With an SEO adapter active, `Plugin::onInit` registers the adapter's filters
with `ImageChooser::forRequest` as the provider, and no tags are printed.

### Card request

```
parse_request (priority 1)
  Plugin::responseFor(query vars)
    Endpoint::handle(raw key, method, If-None-Match)
      method -> 405 | Key::tryParse -> 404
      previous prefix -> 302 to the same key under the current prefix
      SubjectResolver::resolve -> null or not public -> 404 (identical bytes)
      Plugin::unavailableReason -> fallback (unavailable | unwritable)
      current key differs -> 302 stale
      file exists -> 200 hit (or 304)
      cold:
        key lock (wait lock_wait_ms) -> file appeared? hit : fallback busy
        failure marker -> fallback error
        RenderBudget::tryStart -> fallback budget
        slot lock (wait slot_wait_ms) -> fallback slots
        Plugin::renderToCache -> Cache::writeAtomic (temp file, rename)
          failure -> marker, log, action, fallback error
        200 render
  Response::send; exit
```

### Drawing

```
Renderer::render(Subject, Template, dest)       GdRenderer | ImagickRenderer
  CardComposer::compose(Subject, Template)       one layout for both
    Canvas::create(background)
    SourceResolver::resolve(backdrop) -> Canvas::drawBackdrop (crop, 55%/65%, scrim)
    per field: Fonts::forText -> needs shaping?
         no  -> TextLayout (wrap, ladder, ellipsis) -> Canvas::drawText
         yes -> PangoTextLayer (PNG) -> Canvas::drawLayer, or omit
    logo, footnote, accent bar
    Canvas::encode(quality), re-encoded 10 lower while over 300 KB
```

### Edits

`save_post`, `added_post_meta`, `updated_post_meta`, `deleted_post_meta`
(only `_thumbnail_id`), `before_delete_post`, `edited_term` and `delete_term`
go to `Invalidator`, which deletes variants with `Cache::deleteVariants` and
fires `share_cards_purged`. The daily `share_cards_gc` event runs
`GarbageCollector::cron`.

## Decisions

### Rulings on the PRD's open questions

1. **SEO adapters: Yoast SEO and Rank Math only, with a generic fallback.**
   Both are confirmed from their public developer documentation, never from
   plugin source.
   - Yoast SEO (active when `WPSEO_VERSION` is defined): `wpseo_opengraph_image`
     (string URL in, string out), `wpseo_opengraph_image_width`,
     `wpseo_opengraph_image_height`, `wpseo_opengraph_image_type`, and
     `wpseo_twitter_image`, all filters taking and returning one value; plus the
     `wpseo_add_opengraph_images` action, which receives Yoast's image container
     and accepts `add_image( array( 'url', 'width', 'height', ... ) )`. The URL
     filter only changes an image Yoast already has, so the action adds ours
     when the container is empty, and only then, so the page has one og:image.
   - Rank Math (active when `RANK_MATH_VERSION` is defined or the `RankMath`
     class exists): `rank_math/opengraph/{$network}/image` for `facebook` and
     `twitter`, and the documented generic per-tag filter
     `rank_math/opengraph/{$network}/{$og_property}` for `og_image_width`,
     `og_image_height`, `og_image_type`, `og_image_alt` and
     `twitter_image_alt`. Rank Math documents no way to add an image to a page
     where it found none, so on such a page Rank Math prints no image; the
     README says to set a Rank Math default image. This is a documented limit.
   - With neither active, the plugin prints its own eight tags (C15).
   Reason: these two cover most sites that print Open Graph tags; a fallback
   covers the rest; using only documented filters keeps the adapters stable
   across their releases. More adapters can be added through
   `share_cards_seo_adapters`.

2. **Font coverage: explicit script-to-font map only.** The plugin never reads
   a font's character map. `FontInfo` reads the font's `name` table, and only
   to give Pango a family name. Known limit, in the README: a mapped font that
   lacks some glyphs draws boxes for them. Reason: parsing `cmap` tables for
   every font format is a large, fragile surface for a small gain; operators
   choose fonts per script anyway.

3. **Shaping detection: GD is treated as non-shaping, always.** Detecting a
   libgd built with Raqm by rendering a short Arabic string with
   `imagettfbbox` and comparing shaped and unshaped advance widths is not
   reliable. Complex-script shaping needs the Imagick renderer's Pango layer
   (`Imagick::queryFormats('PANGO')`). Reason: a false positive draws broken
   text, which is the one outcome rule 14.13 forbids.

4. **One drawing model, two thin backends.** `CardComposer` computes the
   layout once in plain PHP (fields, positions, wrapping through `TextLayout`,
   the size ladder, ellipsis, logo scale, re-encode loop) and drives a
   `Canvas`. `GdCanvas` and `ImagickCanvas` only measure, draw and encode.
   Imagick is used for decode, compose and encode when it is the canvas, and
   for text through Pango whenever the Pango format is present (with either
   canvas), because Pango shapes. Layout logic is not duplicated. Renderer
   names: `gd`, `imagick`, `gd+pango`, `imagick+pango` (the PRD lists the
   first three; the fourth follows the same rule).

5. **Locks: interface documented, file provider only.** `LockProvider` and
   `Lock` are public, with the contract in the interface docblock (never block
   past the wait, release on process death or expire soon after, names
   `[a-z0-9-]{1,64}`). `FileLockProvider` is the only provider in 0.1.0. No
   database provider. Reason: a database lock that survives a dead process
   needs expiry and fencing to be safe, which is more than 0.1.0 should carry;
   the filter lets a host plug one in.

6. **Fonts: none bundled.** Fonts are discovered from system paths (Noto Sans,
   then DejaVu Sans; `share_cards_font_candidates`). With none found, the
   render fails with a clear message, and `Fonts::check()` returns a
   `WP_Error` (`share_cards_no_font`) naming the missing script ("No font
   found for the Latin script"), shown in Site Health and `status`. The README
   shows how to install Noto fonts. Reason: bundling fonts adds megabytes and a
   second licence to every install; nearly every Linux host can install Noto
   in one command.

7. **Site-wide front page card: yes, behind a filter that defaults to off.**
   `share_cards_front_page_card` returns `false` by default. When true, the
   front page advertises a `site-1-{hash}` card: headline is the tagline (or
   the site name when there is no tagline), alt is the site name. Reason:
   useful for sites without a good default image, but it changes what the
   front page advertises, so it must be a deliberate choice.

8. **Render budget counter: a small file in the cache directory.**
   `{cache}/.budget` holds `{minute} {count}`, updated under `flock`. It
   replaces the PRD's `share_cards_budget_{minute}` transient. Reason: a
   transient lives in the database or in a persistent object cache depending
   on the host, and behaves differently in each (lost writes, eviction); a
   file behaves the same everywhere. A lost increment is still acceptable. The
   file is one of the plugin's named files, removed on uninstall.

9. **Previous prefix: keeps routing with a 302 for 30 days.** On a prefix
   change, `share_cards_previous_prefix` (one option) stores the old prefix
   and an expiry time (`share_cards_previous_prefix_days`, default 30). While
   it is current, a second rewrite rule maps the old prefix to the
   `share_card_prev` query var, and the endpoint answers 302 to the same key
   under the new prefix (`Cache-Control: public, max-age=300`,
   `X-Share-Card: moved`); the new prefix then handles stale hashes as usual.
   After expiry the endpoint answers 404 and the rule is dropped at the next
   flush. This replaces C18's "the old prefix returns the normal WordPress
   404". Reason: platforms keep scraped image URLs for weeks; a redirect costs
   nothing and keeps old shares working.

### Builder decisions

- **Measured-result wording.** Docs say rendering on request "avoided
  rendering cards for posts nobody shared", with no figure.
- **Flat namespace.** Every class named in PRD 5.3 keeps its exact name in
  `IndianOpinion\ShareCards`, so the contract reads the same in code.
- **Endpoint returns a `Response`.** `Endpoint::handle()` builds a value
  object; `Plugin::onParseRequest` sends it and exits. Every serving case is
  tested without output buffering or `exit`.
- **Extra `X-Share-Card` values.** `moved` (previous prefix) and `not-allowed`
  (405 for methods other than GET and HEAD) join the PRD's list. A fallback
  that has no image to redirect to answers 404 with `fallback-{reason}`.
- **Truncated JPEGs.** GD decodes a JPEG cut short without any warning,
  drawing a grey lower half. `SourceResolver` refuses a JPEG with no
  end-of-image marker in its last 64 bytes, and logs it as a decode failure
  with the attachment ID (C61). Other decode warnings become
  `SourceImageException` through `Errors::call`, never `@`.
- **Imagick decoding by type.** Sources are read from a blob with the coder
  fixed from the `getimagesize` type (JPEG, PNG, GIF, WebP), so a file can
  never be interpreted as SVG, MVG or MSL.
- **Pango positioning and escaping.** Text is escaped twice for the Pango
  path (rule 14.14), tested with `&` and `<`. The paragraph uses
  `pango:wrap=word-char` at the box width; `pango:align=left` is the natural
  start edge, so right-to-left text comes out right-aligned. Single-line
  fields use `pango:ellipsize=end`. Pango picks fonts by family, so a mapped
  font for a shaping script must be visible to fontconfig (README).
- **One font per field.** Latin, Greek and Cyrillic use the base fonts. Any
  other script needs a map entry, and all non-base scripts in one field must
  map to the same file, which then also draws the field's Latin. Otherwise the
  field is omitted and logged. Japanese needs `Han`, `Hiragana` and
  `Katakana` mapped (usually to one CJK font).
- **Script names** are PCRE script names (`Devanagari`, `Han`, `Nko`).
  Scripts outside the named lists are reported as `Other`, which can be
  mapped like any script.
- **Headline placement.** The headline is top-aligned in its box; the first
  baseline sits one font size below the box top, lines 1.2 sizes apart.
- **GD saturation and brightness** are done with a 35% merge of a greyscale
  copy and a 45% black layer; Imagick uses `modulateImage(55, 65, 100)`. Both
  are a few levels apart at most, and the renderer name is in the hash.
- **Capabilities include the cache directory's writability**, cached in the
  same 5-minute transient, so tag emission does no file I/O.
- **`emit_tags = on`** prints the plugin's tags and registers no SEO adapter;
  `off` does neither.
- **Garbage collection** processes batches of `share_cards_gc_batch` (500)
  files in name order, resuming from a cursor in `share_cards_gc_state`. The
  daily event keeps taking batches for up to 20 seconds. The disk cap is
  enforced at the end of each full pass. `gc --dry-run` runs a whole pass in
  memory and saves nothing.
- **Fallback URL** for a post is its featured image at full size even when it
  is too small for a card: a real image now beats none.
- **CLI `render`** without `--force` leaves an existing card alone and says
  so. `warm` waits up to 30 seconds for a slot.
- **Preview files** are `.preview.jpg` for Latin and `.preview-{script}.jpg`
  per mapped script, rendered from a fixed sample per script. They are served
  to administrators through an `admin-post.php` action with a nonce, never as
  public URLs.
- **Settings notices** after a save are kept for 60 seconds in a per-user
  transient `share_cards_notice_{user ID}`.
- **Rewrite repair** (C19) flushes at most once an hour, guarded by the
  `share_cards_flush_guard` transient, so two plugins fighting over rules
  cannot loop.
- **Extra filters** beyond PRD 5.4, each for a test seam or an operator need:
  `share_cards_capabilities`, `share_cards_font_dirs`,
  `share_cards_font_candidates`, `share_cards_seo_adapters`,
  `share_cards_front_page_card`, `share_cards_previous_prefix_days`,
  `share_cards_gc_batch`, `share_cards_memory_available`.
- **Stored data** beyond PRD 5.6: option `share_cards_previous_prefix`,
  transients `share_cards_flush_guard` and `share_cards_notice_{user}`, file
  `.budget`. Uninstall removes all of them (per-user notices expire within a
  minute).

## Testing

PHPUnit runs against a stub layer in `tests/stubs/` (an in-memory
WordPress: hooks, options, transients, posts, terms, attachments, query
conditionals, rewrite and cron), with no WordPress install. Tests use a fake
clock, so lock waits and failure TTLs run instantly. Image tests write real
files with GD and check dimensions, format and sampled pixels, never bytes.
GD and Imagick are both exercised where installed; Pango tests skip with a
message where the Pango format is missing; font-dependent tests skip when the
font is not installed. The concurrency test forks with `pcntl` where
available and also runs a deterministic held-lock variant.
