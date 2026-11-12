# share-cards: product requirements

Status: draft for build 0.1.0. Audience: the engineer who builds this plugin
from scratch without seeing any other code. Every case in section 4 has an
acceptance test in section 12 with the same number.

Terms used throughout:

- **Card**: a 1200 by 630 JPEG made for one subject, used as the page's
  `og:image` and `twitter:image`.
- **Subject**: the thing a card shows. A post, a term (category or tag), or
  a custom type a site registers (for example a page keyed by day).
- **Key**: the string that names one card file, `{type}-{id}-{hash}`.
- **Fingerprint**: the hash part of the key. It changes whenever anything
  drawn on the card changes.
- **Renderer**: the engine that draws the card. GD is the baseline; Imagick
  is an alternative; Pango through Imagick is the optional shaping engine
  for complex scripts.
- **Shaping**: reordering, joining and conjunct formation that scripts such
  as Devanagari, Tamil and Arabic need before glyphs can be drawn.

## 1. Problem

Social platforms show a large preview image when a link is shared. The image
comes from `og:image` and `twitter:image`. Without this plugin a news site
has three bad options:

1. Use the featured image as it is. Many featured images are too small or
   the wrong shape. Platforms crop them badly or drop to a small thumbnail.
2. Use one generic placeholder for every archive and every image-less post.
   Every shared category, tag and image-less story then looks identical.
3. Generate a card for every post at publish time. Most posts are never
   shared, so the CPU and disk are spent on images nobody fetches.

This plugin generates a card only when a scraper or browser first asks for
it, stores it on disk outside the media library, and serves the file from
then on without rendering again.

Measured result (one site, one week of featured images): about 90% of
featured images were already at least 1200 px wide and close to 16:9 and are
used as they are; the other 10% (one was 1200 by 152) and every category and
tag page now get their own card instead of one shared placeholder that had
served every tag page. Rendering on request instead of at publish
avoided about 1,700 renders a day for posts nobody shared.

## 2. Goals and non-goals

Goals:

1. A correct `og:image` and `twitter:image` on every single post, category
   archive and tag archive, with width, height, type and alt that match the
   file actually served.
2. Cards rendered on the first request, then served from disk. A second
   render for the same content never happens.
3. Cards change when their content changes, even behind a CDN that caches
   for a year. Achieved by a content hash in the URL, not by purging.
4. No stampede on a cold cache. A burst of scrapers never queues PHP workers
   behind a render.
5. Never draw broken text. A headline in a script the renderer cannot shape
   is left off the card, not drawn as garbage.
6. One neutral, configurable template: colours, fonts, logo, labels, layout
   numbers. No branding ships with the plugin.
7. Works with GD alone (`ext-gd` with JPEG and FreeType). Uses Imagick and
   Pango when present for complex scripts.
8. Safe request path: no file read outside the cache directory, no remote
   fetch, hard caps on source size, render count and concurrency.

Non-goals:

1. Not an SEO plugin. It does not write titles, descriptions, canonical tags
   or other Open Graph fields. It supplies the image only.
2. No multiple templates, no drag-and-drop designer, no per-post design.
3. No square, portrait or other sizes. 1200 by 630 only (see rule 14.3).
4. No WebP, AVIF or PNG output. JPEG only (see rule 14.4).
5. No media library attachments for cards. Cards are cache files.
6. No remote image fetching. Backdrops come from local attachment files only.
7. No judging whether a featured image fits the story. Sites that do that
   tell the plugin through a filter (5.4).
8. No CDN purge client. The plugin fires an action with the URLs to purge.
9. No text in emoji, and no colour fonts.

## 3. Users and setting

- **Who installs it**: a site operator or developer on a WordPress site that
  is shared on social platforms and messaging apps. News and magazine sites
  with many image-less or badly-sized posts gain most.
- **Where it runs**: inside WordPress on the origin. It can run as a normal
  plugin or as a must-use plugin through a loader file. It needs a writable
  local directory for the cache.
- **Who calls the endpoint**: social scrapers (Facebook, LinkedIn, X,
  WhatsApp, Telegram, Slack, Discord and similar), browsers, and CDNs in
  front of the site. None of them send cookies or authenticate.
- **Who reads its output**: the same scrapers, through the meta tags in the
  page head.
- **Who configures it**: an administrator with `manage_options`, through a
  settings page, filters, or WP-CLI.

## 4. Behaviour

Cases are numbered C1 to C78. Each states the input, the outcome and, for
failures, whether it fails open (page still gets an image or the request
still succeeds) or closed (nothing is served), and why.

### 4.1 Choosing the image for a page

The plugin decides what image the current request should advertise. The
decision runs once per request and is memoised.

Eligibility of a featured image for use as it is (mode `auto`): the
attachment exists, is an image, is not in the generic image list (7), its
largest available size is at least `min_width` pixels wide (default 1200),
and its width divided by height is between `aspect_min` (default 1.5) and
`aspect_max` (default 2.1) inclusive.

- **C1. Good featured image, mode auto.** A single post of an allowed type
  whose featured image is eligible. The plugin advertises the featured image
  itself: the URL of the size used, and that size's real width and height.
  No card URL appears in the page.
- **C2. Featured image too narrow.** Width below `min_width` (for example
  800 by 450). The plugin advertises the post's card. The featured image is
  still used as the card's backdrop if it passes the backdrop rules (4.7).
- **C3. Featured image the wrong shape.** Ratio outside the range (1200 by
  152 banner, 1200 by 1200 square, 900 by 1600 portrait). Card, as C2.
- **C4. No featured image.** Card with no backdrop.
- **C5. Generic featured image.** The featured image's attachment ID is in
  the generic list (a site-wide placeholder or logo slate). Card with no
  backdrop. A generic image is never used as a backdrop: a card on a card
  looks broken. This holds even when the generic image is 1200 by 675.
- **C6. Mode always and mode never.** Mode `always`: every eligible post
  gets a card; the featured image becomes the backdrop if it passes 4.7.
  Mode `never`: the plugin never advertises a card for posts. It advertises
  the featured image (any size, real dimensions) or the default image, or
  nothing. Term cards follow their own switch (`term_cards`, default on).
  The mode is filterable per post (5.4).
- **C7. Category archive.** An archive of a term in an allowed taxonomy. The
  plugin advertises the term's card.
- **C8. Tag archive.** Same as C7 for `post_tag`.
- **C9. Archive of a taxonomy not in the allowed list.** The plugin
  advertises nothing for that request and leaves the head alone.
- **C10. Other views.** Front page, posts page, search, author, date
  archives, attachment pages, 404. The plugin advertises the default image
  if one is configured, else nothing. It never advertises a card here.
- **C11. Post type not allowed.** A single view of a post type outside the
  allowed list (default: `post` only). Treated as C10.
- **C12. Password-protected or non-public post.** Viewed by someone who can
  see it (an editor previewing). No card is advertised, no card URL appears;
  the default image is advertised if configured. Fails closed for the card
  because the card URL would be fetchable by anyone (C24).
- **C13. Custom card type.** A site registers a type (5.4,
  `share_cards_types`) with a resolver and a request matcher. When the
  matcher claims the current request, the plugin advertises that type's
  card. Two different URLs that present the same content (for example a
  stable "today" page and that day's dated post) return the same type and
  id, so they share one key and one file.
- **C14. An SEO plugin with a supported adapter is active.** The plugin
  hands its image to that plugin through the SEO plugin's own public filter,
  with explicit width and height. It prints no tags of its own. The page
  ends up with exactly one `og:image` set.
- **C15. No supported SEO plugin.** The plugin prints its own tags in
  `wp_head` (priority 5), in this order: `og:image`, `og:image:type`,
  `og:image:width`, `og:image:height`, `og:image:alt`, `twitter:card`
  (`summary_large_image`), `twitter:image`, `twitter:image:alt`. All URLs
  absolute; scheme follows `home_url()`. All values escaped. Setting
  `emit_tags` = `off` suppresses printing for themes that print their own,
  which then call the public function in 5.2.
- **C16. No usable renderer.** GD and Imagick both missing, GD present but
  without JPEG or FreeType support, or the cache directory not writable. No
  card URL is ever advertised. Fallback chain for any view that would have
  had a card: featured image (any size, real dimensions), else the default
  image, else no image tags at all. Fails open: pages keep an image where
  one exists. A broken card URL is worse than none.

### 4.2 URL scheme and rewrite rule

- **C17. URL format.** With pretty permalinks:

  `{home}/{prefix}/{type}-{id}-{hash}.jpg`

  - `prefix`: setting, default `share-card`. Lower-case letters, digits and
    hyphens, 1 to 32 characters, not starting or ending with a hyphen.
  - `type`: `post`, `term`, or a registered custom type. Pattern
    `[a-z][a-z0-9]{0,15}`.
  - `id`: decimal digits, 1 to 20, no leading zero. For `post` the post ID,
    for `term` the term ID (term IDs are unique across taxonomies), for a
    custom type whatever the type defines, validated by its own pattern
    (for a day key, `YYYYMMDD`).
  - `hash`: exactly 10 lower-case hexadecimal characters, the first 10 of
    the SHA-256 of the fingerprint input (4.5).

  Rewrite rule, added at `top`:
  `^{prefix}/([a-z][a-z0-9]{0,15})-([1-9][0-9]{0,19})-([0-9a-f]{10})\.jpg$`
  mapping to one query var holding the whole key.

  With plain permalinks the plugin advertises
  `{home}/?share_card={type}-{id}-{hash}` instead, and the endpoint accepts
  the same key through that query var, validated by the same pattern.
- **C18. Prefix changed.** Saving a new prefix flushes rewrite rules once.
  New pages advertise the new prefix. The old prefix stops routing and
  returns the normal WordPress 404. The settings page warns before saving
  that social platforms hold the old URLs for weeks.
- **C19. Rewrite rules.** The rule is registered on `init` on every
  request. Rules are flushed only on activation, on deactivation and on a
  prefix change, never on every request. If the stored rules lack the
  plugin's pattern (another plugin flushed before ours registered), Site
  Health reports it, `status` reports it, and the next admin page load
  schedules one flush. The endpoint never depends on a flush to be safe:
  without the rule a card URL is a plain 404.

### 4.3 Serving a card

The endpoint runs on `parse_request`, before the main query and before any
theme code. It handles `GET` and `HEAD` only; any other method gets 405.

- **C20. Warm hit.** Valid key, current hash, file present. Response 200,
  body is the file. Headers:
  - `Content-Type: image/jpeg`
  - `Content-Length`
  - `Cache-Control: public, max-age=31536000, immutable`
  - `ETag` (quoted, the key's hash plus the file size)
  - `Last-Modified` (file mtime)
  - `X-Content-Type-Options: nosniff`
  - `X-Share-Card: hit`

  `HEAD` returns the same headers and no body. A matching `If-None-Match`
  returns 304 with no body. No cookie is set, no session is started.
- **C21. Cold miss.** Valid key, current hash, no file. The card is
  rendered (4.4), then served as C20 with `X-Share-Card: render`.
- **C22. Stale hash.** Valid key, subject public, hash differs from the
  current fingerprint (an old URL from a cached page or a social cache).
  Response 302 to the current card URL, `Cache-Control: public,
  max-age=300`, and `X-Share-Card: stale`. Nothing is rendered for the stale
  hash. Only current
  hashes are ever written to disk, so random hashes cannot fill the cache.
- **C23. Malformed key.** Anything that does not match the pattern (upper
  case, leading zero, extra path segments, dots, encoded slashes, missing
  hash, wrong extension, an over-long id) never reaches the endpoint with
  pretty permalinks; through the query var form it gets 404. No part of a
  malformed key is used for anything.
- **C24. Unknown or non-public subject.** Unknown type, no such post or
  term, post not published, password-protected, of a type not allowed, or
  a term in a taxonomy not allowed, or a custom resolver that returns
  nothing. Response 404, empty body, `Cache-Control: public, max-age=60`.
  The response is identical whether the subject does not exist or exists
  but is not public. Nothing is rendered. Fails closed: a card shows a
  title, and a title of an unpublished post must not leak.
- **C25. Query strings.** Any query string on a pretty card URL is ignored.
  It is not part of the key and does not change the response.
- **C26. Render failure.** The backdrop fails to load: the card renders
  without it (fails open). The whole render fails (font unreadable, write
  error, renderer exception): the error is logged with the key, a failure
  marker is written for that key, and the response is the fallback response (defined after
  C27) with `X-Share-Card: fallback-error`.
- **C27. Failure marker active.** A key with an unexpired failure marker
  (default 600 seconds) is not rendered again. The fallback is served at
  once. A broken source cannot cost a render on every request.

Fallback response, used by C26, C27, C29, C30, C31, C66 and C67: 302 to the
fallback URL with `Cache-Control: public, max-age=60` and `X-Share-Card:
fallback-{reason}`. Fallback URL: the post's featured image (largest size)
for a post; else the default image; else 404 with the C24 headers. An image
URL is never redirected to an HTML page (rule 14.11).

### 4.4 Concurrency on a cold cache

Locks are files under `{cache}/.locks/`, taken with `flock`. The operating
system releases them when a process dies, so a stale lock cannot wedge a
key. The lock provider is replaceable (5.4) for hosts where `flock` is not
reliable.

Sequence for a cold miss:

1. Take the key lock without blocking. If busy, poll every 50 ms up to
   `lock_wait_ms` (default 2000).
2. On getting the key lock, check again for the file; if another process
   finished it, serve it (C20).
3. Check the failure marker (C27) and the render budget (C31).
4. Take one of `max_concurrent` slot locks (default 2), trying each without
   blocking, polling up to `slot_wait_ms` (default 3000).
5. Render to a temporary file in the cache directory, named
   `.{key}.{random}.tmp`, then `rename` it to `{key}.jpg`. Rename on one
   filesystem is atomic, so no reader sees a half-written file.
6. Release the slot and the key lock in a `finally` block. Serve.

- **C28. Two requests, same missing key.** One renders. The other waits on
  the key lock, then finds the file and serves it with `X-Share-Card: hit`.
  Exactly one render happens.
- **C29. Waiter times out.** The renderer takes longer than `lock_wait_ms`.
  The waiter checks for the file once more; if absent it returns the
  fallback response with reason `busy`. It never blocks longer than the
  wait. Fails open: the scraper gets a real image now and the card next
  time.
- **C30. Burst of different keys.** Many cold keys at once (after a
  settings change, or a scraper crawling an archive). At most
  `max_concurrent` renders run site-wide. Others wait up to `slot_wait_ms`,
  then get the fallback with reason `slots`.
- **C31. Render budget.** More than `renders_per_minute` renders (default
  30) started in the current minute: further cold misses get the fallback
  with reason `budget`. The counter is approximate and cheap (5.6). This
  bounds the CPU an attacker can burn by requesting the current card of
  every published post.
- **C32. Client disconnects mid-render.** The render continues
  (`ignore_user_abort`) and the file lands. The next request is a hit.
- **C33. Process killed mid-render.** The temporary file is orphaned; locks
  are released by the OS. The final file never exists half-written. The
  garbage collector (C42) removes temporary files older than one hour.

### 4.5 Cache keys, fingerprints and invalidation

The fingerprint input is a canonical JSON object (keys sorted, UTF-8,
no escaped slashes) holding:

- the plugin's template version (a constant bumped when drawing code
  changes output);
- the settings fingerprint: a hash of every visual setting (colours, font
  paths, layout numbers, labels, logo attachment ID and its attached file
  path, footer choice, JPEG quality), computed when settings are saved and
  stored with them, so tag emission does no file I/O;
- the renderer kind (`gd`, `imagick`, `gd+pango`) because output differs;
- the site name when the footer shows it;
- the subject fields after normalisation (4.6): headline, kicker, footnote;
- the backdrop attachment ID and its attached file path (an edit in the
  image editor writes a new file name, so the path changes), or empty;
- anything a site adds through `share_cards_fingerprint_parts`.

The hash is computed from database values only. Computing it never reads an
image file.

- **C34. Post title edited.** On `save_post` for a published post, the
  plugin computes the new key. If the hash changed, it deletes every file
  `post-{id}-*.jpg` except the current one, and fires `share_cards_purged`
  with the removed URLs. The next head emission advertises the new URL; the
  next request renders it. A legally-sensitive title edit is not left on
  disk or in the static path.
- **C35. Featured image changed or removed.** Same as C34.
- **C36. Category renamed, or a post's first category changed.** The term's
  own card: on `edited_term`, old variants deleted and purge fired, as C34.
  Posts that use the term as kicker get a new hash the next time their page
  is rendered; their old files are left to the garbage collector, because
  enumerating every post in a large term on save is too slow.
- **C37. Post unpublished, trashed, deleted, made private or given a
  password.** Every variant for that post is deleted at once and purge
  fired. Static serving (10) would otherwise keep showing its title.
- **C38. Term deleted.** Every variant for that term is deleted and purge
  fired.
- **C39. Settings changed.** The settings fingerprint changes, so every key
  changes. Nothing is deleted on save (that could be tens of thousands of
  files in one request). Cards re-render lazily as pages are shared; the
  garbage collector removes the old ones. Rendering is bounded by C30 and
  C31.
- **C40. Site name changed.** Part of the fingerprint when the footer shows
  it, so as C39.
- **C41. Attachment edited in the image editor.** The attached file path
  changes, so the post's hash changes on its next page render. The old
  variant goes at the next garbage collection.
- **C42. Garbage collection.** A daily cron event walks the cache directory
  in batches (default 500 files a run, resuming where it stopped). It
  removes: variants whose hash is not current; variants whose subject is
  gone or not public; temporary files older than one hour; expired failure
  markers; lock files not touched for a day. It then enforces `cache_max_mb`
  (default 512) by deleting oldest-mtime cards first until the directory is
  under 90% of the cap. It touches only files whose names match the plugin's
  own patterns.
- **C43. Page cache holds old HTML.** The old URL arrives with a stale
  hash. Through PHP it is C22. If the web server serves files statically
  and the old file still exists, the old card is served until garbage
  collection. Accepted: it is only for hashes made stale by C36, C39, C40
  and C41, never C34, C35, C37, C38, which delete at once.

Values that change while a card is cached are the trap (rule 14.9): a count
of stories, a "last updated" time. If such a value is drawn, it must be in
the fingerprint, which then re-keys the card every time the value moves. The
default template draws no counts.

### 4.6 Text: normalisation, scripts and wrapping

Fields drawn: kicker (top), headline (middle), footer (site name or logo,
bottom left), footnote (bottom right, optional).

Default field values:

| Subject | Headline | Kicker | Footnote |
|---|---|---|---|
| post | title as the site shows it | name of the first category, else empty | empty |
| term | term name | taxonomy label from settings (default the taxonomy's singular name) | empty, or the `term_footnote` template with `{count}` |
| custom | from its resolver | from its resolver | from its resolver |

All fields are filterable (`share_cards_subject`).

Normalisation, applied to every field in this order:

1. Strip HTML tags.
2. Decode HTML entities as UTF-8 (`ENT_QUOTES | ENT_HTML5`), repeated until
   stable, at most twice (titles can arrive double-encoded).
3. Unicode NFC when `ext-intl` is present.
4. Remove C0 and C1 control characters, and bidirectional embedding,
   override and isolate controls (U+202A to U+202E, U+2066 to U+2069).
   Keep ZWJ (U+200D) and ZWNJ (U+200C): Indic scripts need them.
5. Remove emoji and pictographs (`\p{Extended_Pictographic}` and variation
   selectors). Fonts that GD and Pango use here have no colour glyphs.
6. Collapse runs of whitespace to one space; trim.
7. Truncate by grapheme clusters: headline 300, kicker 60, footnote 80.

Script classification: each field is classified by the Unicode scripts of
its letters (Common and Inherited ignored).

- **Needs shaping**: Arabic, Syriac, Thaana, N'Ko, Hebrew (right to left),
  Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada,
  Malayalam, Sinhala, Thai, Lao, Tibetan, Myanmar, Khmer, Mongolian.
  Filterable (`share_cards_needs_shaping`).
- **Safe for GD**: Latin, Greek, Cyrillic, Armenian, Georgian, Han,
  Hiragana, Katakana, Hangul, Ethiopic, and anything else not listed above.

Font selection: settings hold a regular font, a bold font, and a map from
script name to font file. Latin, Greek and Cyrillic use the regular and bold
fonts. Every other script must have an explicit map entry. A field whose
script has no font is not drawn (tofu boxes are never acceptable). Default
fonts are discovered from common system paths (Noto Sans, then DejaVu Sans)
when the setting is empty.

- **C44. Latin headline.** Break opportunities at whitespace. Fit loop:
  for each size in the ladder (default 64, 56, 48, 42 px), lay out greedily
  within the box width (template width minus both paddings), measuring each
  candidate line with the renderer's text box. Pick the largest size that
  fits in at most `max_lines` (default 4) lines and the box height. At the
  smallest size, keep `max_lines` lines and end the last one with "…",
  removing graphemes until it fits. Line height is 1.2 times the size.
- **C45. Token wider than the box.** A single word wider than a line (a
  URL, a long compound) is broken at grapheme cluster boundaries, never
  inside a cluster, with no hyphen added.
- **C46. Entities, tags and punctuation.** "Tom &amp; Jerry &lt;3",
  "&#8217;" and "<em>x</em>" are drawn as "Tom & Jerry <3", "’" and "x".
- **C47. Control characters.** A title holding U+202E, a NUL and a ZWJ is
  drawn without the first two; the ZWJ survives into the shaping engine.
- **C48. Complex script with a shaping renderer.** A Hindi, Tamil, Bengali
  or other needs-shaping headline, and the shaping renderer is available
  (Imagick with the Pango format, 4.8). The field is laid out, wrapped and
  shaped by Pango (word wrap, falling back to character wrap for words
  wider than the box) with the mapped font, using the same size ladder; the
  result must fit the box height, else the next size, else truncate by
  graphemes with "…" and retry (at most 6 attempts in total).
- **C49. Complex script without a shaping renderer.** That field is not
  drawn. The card is still rendered with everything else (backdrop, other
  fields, footer). One info log line per key. Fails closed for the field:
  a blank space is fine, broken conjuncts are damaging.
- **C50. Right-to-left headline.** Arabic or Hebrew, with the shaping
  renderer: drawn right-aligned in the box. Without it: C49.
- **C51. CJK headline.** Drawn by GD with the mapped font. Break
  opportunities between any two grapheme clusters, except: no line starts
  with closing punctuation (、。，．）」』】〉》！？：；) and no line ends
  with opening punctuation (（「『【〈《). No mapped font: C49.
- **C52. Thai, Lao, Khmer, Myanmar.** No spaces between words, so wrapping
  needs a dictionary. Only the shaping renderer draws them. Otherwise C49.
- **C53. Mixed scripts in one field.** An English headline with one
  Devanagari word needs shaping as a whole. The whole field goes to the
  shaping renderer or is not drawn; it is never drawn partly.
- **C54. Kicker case.** With `kicker_uppercase` on, the kicker is
  upper-cased with multibyte functions. Scripts without case are unchanged.
  Byte-wise upper-casing is never used (rule 14.12).
- **C55. Empty headline.** A post whose title is empty after normalisation
  gets a card with no headline. The alt text falls back to the site name.
- **C56. Emoji.** "Launch day 🚀" is drawn as "Launch day".
- **C57. Font missing at render.** A configured font file is no longer
  readable. The renderer falls back to discovered system fonts for Latin,
  Greek and Cyrillic and omits fields in other scripts. If no font is usable
  at all, the render fails (C26). Site Health reports the missing file.

### 4.7 Backdrop

The backdrop is the featured image of a post, darkened under a scrim so text
stays legible. Terms and custom types have no backdrop unless a resolver
supplies a local attachment.

- **C58. Source choice.** Use the smallest registered intermediate size at
  least 1200 wide and at least 630 high; else the full file. Intermediate
  sizes are already rotated by WordPress and much cheaper to decode.
- **C59. Source over the caps.** File larger than `max_source_bytes`
  (default 20 MB) or more than `max_source_pixels` (default 25 million),
  checked with `getimagesize` before decoding. No backdrop.
- **C60. Unsupported source.** SVG, HEIC, AVIF or WebP when the build lacks
  that format, or a CMYK JPEG (four channels; GD reads its colours wrongly).
  No backdrop. Animated GIF: first frame.
- **C61. Corrupt source.** Decoding fails or throws. No backdrop; logged
  with the attachment ID.
- **C62. Source not local.** The resolved path is outside the uploads base
  directory after `realpath`, or the file does not exist locally (media
  offloaded to object storage). No backdrop. A filter may supply a local
  path; it is subject to the same checks.
- **C63. Transparent source.** A PNG or WebP with alpha is composited over
  the background colour first.
- **C64. Crop.** Cover crop to 1200 by 630. The focal point defaults to the
  centre and is filterable as fractions (0 to 1) of width and height.
  Treatment after crop: brightness to 55%, saturation to 65%, then a scrim
  of the background colour at `scrim_opacity` (default 0.62).
- **C65. Memory headroom.** Before decoding, estimate `width * height * 5`
  bytes plus the canvas. If that exceeds the memory limit minus current use
  minus 16 MB, no backdrop.

### 4.8 Renderers and environments without GD

Renderer selection, setting `renderer` (default `auto`):

- `auto`: GD if it has JPEG and FreeType support; else Imagick if it can
  write JPEG; else none.
- `gd`, `imagick`: that one, or none if unusable.
- Shaping: whenever the Imagick extension is loaded and
  `Imagick::queryFormats('PANGO')` lists Pango, the shaping text layer is
  available to either renderer. With GD as the canvas, Pango renders the
  text layer to a transparent PNG in memory, which GD composites.

Capability checks run once and are cached in a transient for 5 minutes,
cleared on settings save.

- **C66. GD missing at activation.** The plugin activates anyway. If
  Imagick is usable it is used. If no renderer is usable: an admin notice
  on the plugin's settings page, a critical Site Health test naming what is
  missing (`ext-gd`, JPEG support, FreeType support), and C16 fallbacks for
  every page. A card URL that reaches the endpoint (old cached pages) gets
  the fallback response with reason `unavailable`.
- **C67. Cache directory not writable.** Same as C66 with reason
  `unwritable`.
- **C68. Uploads on a stream wrapper.** The uploads base directory contains
  `://` (an object-storage stream). `flock` and atomic `rename` are not
  reliable there, so the cache directory moves to
  `WP_CONTENT_DIR/cache/share-cards/`. Filterable.

### 4.9 Output file

- **C69. Format.** 1200 by 630, progressive sRGB JPEG, no
  metadata, quality `jpeg_quality` (default 82, allowed 60 to 95). If the
  file exceeds 300 KB, re-encode at 10 lower until it fits or quality 60.
  Never WebP, AVIF or PNG.
- **C70. Files and directory.** Cache directory `{uploads}/share-cards/`,
  mode 0755, with an empty `index.html` to stop directory listings. Cards
  are 0644. File names are only ever built from a validated key.

### 4.10 WP-CLI

Command namespace `wp share-cards`.

- **C71. warm.** `wp share-cards warm [--posts=<n>] [--terms] [--dry-run]`.
  Renders cards that do not exist yet for the `n` most recent published
  posts that would get a card (default 50) and, with `--terms`, every
  non-empty term in an allowed taxonomy. Respects `max_concurrent` but not
  the per-minute budget. Prints rendered, skipped and failed counts.
- **C72. status.** Prints renderer, GD capabilities (JPEG, PNG, WebP,
  FreeType), Imagick and Pango availability, fonts and their readability,
  cache directory, file count and size, whether the rewrite rule is
  present, and the last garbage collection result.
- **C73. render.** `wp share-cards render <post|term|type>:<id> [--force]
  [--out=<file>]`. Renders one card, ignoring locks and budget, reporting
  time and peak memory. `--out` writes elsewhere and leaves the cache
  untouched.
- **C74. gc.** `wp share-cards gc [--dry-run]` runs one full collection.
- **C75. flush.** `wp share-cards flush --yes` removes every cached card,
  temporary file, marker and lock. Without `--yes` it refuses.
- **C76. url.** `wp share-cards url <post|term|type>:<id>` prints the
  current card URL and the image the page would advertise.

### 4.11 Settings and lifecycle

- **C77. Settings page.** Under Settings, capability `manage_options`,
  nonce checked on save. Colours must be `#rrggbb`; an invalid value is
  rejected with a notice and the old value kept. Font paths must end in
  `.ttf` or `.otf`, be readable, resolve by `realpath` inside an allowed
  directory (`WP_CONTENT_DIR`, `/usr/share/fonts`, `/usr/local/share/fonts`,
  filterable), and measure text without error; otherwise rejected. The logo
  and default image are attachment IDs of images, never URLs. A preview
  button renders a sample card (one Latin headline, and one per mapped
  script) to a preview file outside the key space and shows it.
- **C78. Deactivate and uninstall.** Deactivation unschedules the cron
  event and flushes rewrite rules; cached cards stay. Uninstall deletes the
  settings option, the transients, the cron event, every file in the cache
  directory matching the plugin's patterns, `index.html`, `.locks/`, and
  the directory if then empty, and flushes rewrite rules. On multisite it
  does this for every site.

## 5. Public interface

Namespace `IndianOpinion\ShareCards`. Names below are the contract; the
builder may add internals.

### 5.1 Plugin files

- `share-cards.php`: plugin header (Requires at least 6.4, Requires PHP
  8.1), bootstraps without Composer.
- `uninstall.php`: C78.
- A must-use loader example in the README.

### 5.2 Global functions (for themes)

- `share_cards_image_for_request(): ?array` returns the image the current
  request advertises, or null: `url`, `width`, `height`, `type`, `alt`,
  `source` (`card`, `featured`, `default`).
- `share_cards_url_for_post(int|\WP_Post $post): ?string` current card URL,
  or null when not eligible.
- `share_cards_url_for_term(int|\WP_Term $term): ?string`.
- `share_cards_url_for(string $type, string $id): ?string`.

### 5.3 Classes

- `Plugin`: wiring, activation, deactivation.
- `Key`: value object. `Key::parse(string): Key` (throws
  `InvalidKeyException`), `type()`, `id()`, `hash()`, `filename()`.
- `Subject`: value object: type, id, headline, kicker, footnote, backdrop
  attachment ID, alt, isPublic.
- `SubjectResolver`: key type and id to `Subject` or null.
- `Fingerprint`: builds the canonical input and the hash.
- `ImageChooser`: C1 to C16.
- `HeadTags`: C14, C15.
- `Endpoint`: C20 to C33.
- `Cache`: paths, atomic write, variant deletion, failure markers.
- `LockProvider` interface: `acquire(string $name, int $waitMs): ?Lock`;
  `Lock::release(): void`. `FileLockProvider` is the default.
- `Renderer` interface: `name(): string`, `isAvailable(): bool`,
  `canShape(): bool`, `render(Subject $s, Template $t, string $dest): void`
  (throws `RenderException`).
- `GdRenderer`, `ImagickRenderer`, `PangoTextLayer`.
- `TextLayout`: normalisation, script classification, wrapping, fit loop.
- `Template`: the resolved layout and colours.
- `Settings`, `SettingsPage`, `SiteHealth`, `GarbageCollector`, `Cli`.
- Exceptions: `ShareCardsException` (base), `InvalidKeyException`,
  `RenderException`, `SourceImageException`,
  `UnsupportedEnvironmentException`.

### 5.4 Filters

| Filter | Value | Default |
|---|---|---|
| `share_cards_post_types` | string[] | `['post']` |
| `share_cards_taxonomies` | string[] | `['category', 'post_tag']` |
| `share_cards_mode` | `auto`, `always`, `never`; args: post | setting |
| `share_cards_generic_images` | int[] attachment IDs | setting |
| `share_cards_backdrop_attachment` | int (0 vetoes); args: post | featured image ID |
| `share_cards_backdrop_path` | local path or ''; args: attachment ID | resolved path |
| `share_cards_focal_point` | `[x, y]` fractions; args: attachment ID | `[0.5, 0.5]` |
| `share_cards_subject` | `Subject`; args: type, id | resolved subject |
| `share_cards_types` | map of type to `{pattern, resolve, matchRequest}` | `[]` |
| `share_cards_fingerprint_parts` | array; args: `Subject` | `[]` |
| `share_cards_template` | array of layout and colours | from settings |
| `share_cards_fonts` | map: `regular`, `bold`, script name to path | from settings |
| `share_cards_needs_shaping` | bool; args: text | classification |
| `share_cards_renderer` | `Renderer` or null | selected renderer |
| `share_cards_lock_provider` | `LockProvider` | `FileLockProvider` |
| `share_cards_cache_dir` | absolute path | `{uploads}/share-cards` |
| `share_cards_fallback_url` | string; args: key, reason | chain in 4.3 |
| `share_cards_emit_tags` | bool | true unless C14 or setting off |
| `share_cards_image` | array or null; final image for the request | chosen image |

A custom type's `resolve` callable takes the id string and returns a
`Subject` or null; returning null makes the key a C24 404. `matchRequest`
takes no arguments and returns the id for the current request or null.

### 5.5 Actions

| Action | Arguments |
|---|---|
| `share_cards_rendered` | key, path, milliseconds, renderer name |
| `share_cards_render_failed` | key, `\Throwable` |
| `share_cards_fallback` | key, reason |
| `share_cards_purged` | string[] URLs, reason (`edit`, `unpublish`, `delete`, `gc`, `flush`) |

### 5.6 Options and transients

- Option `share_cards_settings` (array, autoloaded, small).
- Option `share_cards_gc_state` (cursor and last result, not autoloaded).
- Transient `share_cards_caps` (capability check, 5 minutes).
- Transient `share_cards_budget_{minute}` (render counter, 120 seconds). A
  lost increment under a race is acceptable.

### 5.7 Cron

- `share_cards_gc`, daily.

### 5.8 SEO plugin adapters

An adapter interface `SeoAdapter`: `isActive(): bool`, `register(callable
$imageProvider): void`. Adapters use only each SEO plugin's public,
documented filters and pass explicit width and height. Which adapters ship
in 0.1.0 is open question 1.

## 6. Data

- **Files**: cards in the cache directory, named `{key}.jpg`. Failure
  markers `{key}.fail` (empty, mtime is the time). Temporary files
  `.{key}.{random}.tmp`. Locks in `.locks/`. Preview file
  `.preview.jpg`. Nothing else is written.
- **Database**: the two options and transients in 5.6. No custom tables,
  no post meta, no attachment rows.
- **Retention**: cards live until their hash goes stale, their subject
  goes, the disk cap trims them, or `flush`. Failure markers 600 seconds.
  Temporary files one hour.
- **Uninstall**: C78. Files the plugin did not name are never removed.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| `mode` | `auto` | C1 to C6 |
| `term_cards` | on | C7, C8 |
| `min_width` | 1200 | C1 |
| `aspect_min`, `aspect_max` | 1.5, 2.1 | C1 |
| `generic_image_ids` | empty | C5 |
| `default_image_id` | 0 | C10, C16 |
| `url_prefix` | `share-card` | C17, C18 |
| `emit_tags` | `auto` | `auto`, `on`, `off`; C14, C15 |
| `renderer` | `auto` | 4.8 |
| `colour_background` | `#1f2937` | neutral slate |
| `colour_text` | `#ffffff` | headline |
| `colour_kicker` | `#93c5fd` | |
| `colour_muted` | `#d1d5db` | footnote |
| `colour_accent` | `#60a5fa` | bottom bar |
| `font_regular`, `font_bold` | discovered | C57 |
| `font_scripts` | empty | script name to path |
| `logo_id` | 0 | PNG or WebP with alpha |
| `footer` | `name` | `name`, `logo`, `both`, `none` |
| `kicker_uppercase` | on | C54 |
| `taxonomy_labels` | singular names | C7 kicker |
| `term_footnote` | empty | `{count}` token; see 4.5 trap |
| `backdrop` | on | |
| `scrim_opacity` | 0.62 | |
| `jpeg_quality` | 82 | 60 to 95 |
| `max_lines` | 4 | |
| `size_ladder` | 64, 56, 48, 42 | px |
| `max_concurrent` | 2 | C30 |
| `renders_per_minute` | 30 | C31 |
| `lock_wait_ms`, `slot_wait_ms` | 2000, 3000 | C28 to C30 |
| `failure_ttl` | 600 | seconds, C27 |
| `max_source_bytes` | 20971520 | C59 |
| `max_source_pixels` | 25000000 | C59 |
| `cache_max_mb` | 512 | C42 |
| `log_level` | `error` | `error`, `info`, `debug`; section 11 |

Default layout (in the template array, all overridable): padding 70; kicker
baseline 96, size 30; headline box top 150, height 340; footer baseline 572,
size 34; logo maximum height 60; footnote size 26, right-aligned; accent bar
8 px at the bottom edge.

## 8. Security and privacy

1. The request path never builds a file path from user input. It parses
   the key against a strict pattern, converts the id to an integer or a
   type-validated string, and rebuilds the file name from those parts.
   The final path is checked to sit inside the `realpath` of the cache
   directory.
2. Only publicly viewable subjects are rendered or served (C24). Unknown
   and non-public look the same from outside.
3. Cards for subjects that stop being public are deleted at once (C37).
4. Backdrops come from local attachment files only, resolved by WordPress
   and checked inside the uploads directory (C62). No URL is ever fetched,
   so there is no server-side request forgery surface.
5. Decompression bombs are refused before decoding (C59, C65).
6. Render work is bounded per key (one render per hash), site-wide
   (`max_concurrent`) and per minute (`renders_per_minute`). Stale or random
   hashes never render (C22).
7. Text fields are truncated before layout (4.6), so layout cost is
   bounded.
8. The endpoint sets no cookies, starts no session, reads no cookies and
   does not vary by user. It is safe to cache publicly.
9. Admin: capability checks and nonces on every save and on the preview
   action; colours, paths and IDs validated (C77). Font paths limited to
   allowed directories.
10. Every value in the head is escaped (`esc_url`, `esc_attr`).
11. No `eval`, no shell, no `@` error suppression, no `exec` of
    ImageMagick binaries; only the PHP extensions.
12. Logs carry keys and attachment IDs, never visitor data.

## 9. Performance budget

- Tag emission: no file I/O, at most one extra query beyond what the view
  already loaded (the featured image metadata), under 1 ms of PHP time.
- Warm hit through PHP: no render, at most two queries beyond WordPress
  bootstrap (subject check), `readfile` of a file under 300 KB.
- Warm hit through a static web server rule (10): no PHP at all.
- Cold render with GD and a backdrop from a 1200 px intermediate: target
  p95 under 600 ms on one vCPU and under 48 MB peak memory above baseline.
  Without a backdrop: under 250 ms.
- Imagick renders use more memory: one production measurement saw 40 to
  70 MB per card at Q16. `max_concurrent` exists for this reason.
- Output: 60 to 300 KB per card (C69).
- Garbage collection: batches of 500 files, under 2 seconds a batch.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `declare(strict_types=1)`.
- WordPress 6.4 or later. Single site and multisite (each site has its own
  cache directory through its own uploads directory).
- Required: `ext-gd` with JPEG and FreeType support, or `ext-imagick` that
  can write JPEG. `ext-mbstring`. Composer metadata declares `ext-gd` and
  the README states the Imagick alternative.
- Optional: PNG and WebP support in GD for those backdrops; `ext-intl` for
  NFC; Imagick 3.7 or later with ImageMagick built with the Pango delegate
  for complex scripts; `ext-exif` is not needed (C58).
- Permalinks: pretty and plain (C17).
- Web servers: works through PHP on nginx, Apache and LiteSpeed. The README
  ships optional rules for nginx and Apache that serve
  `/{prefix}/{file}` straight from the cache directory when the file exists
  and pass to WordPress otherwise, adding the C20 headers.
- Page caches: the endpoint returns `image/jpeg` with its own cache headers;
  page caches that store by URL hold a correct image. The plugin does not
  need to be excluded.
- CDNs: immutable one-year caching is safe because the URL changes with the
  content. Fallback redirects and 404s are cached for 60 seconds only.
- Media offload plugins: cards stay on local disk; backdrops need a local
  copy or the path filter (C62).
- Social platforms: JPEG at 1200 by 630 under 300 KB is accepted by every
  platform named in section 3.

## 11. Observability

- Log lines go to `error_log` with the prefix `[share-cards]`, one line
  per event: render (key, renderer, ms, bytes, backdrop yes or no), render
  failure (key, exception class, message), field omitted for lack of
  shaping or font (key, field, script), fallback (key, reason), purge (count,
  reason), garbage collection (scanned, removed by reason, bytes freed).
  A setting `log_level` (`error`, `info`, `debug`, default `error`) gates
  the non-error lines.
- Response header `X-Share-Card` on every endpoint response: `hit`,
  `render`, `stale`, `fallback-busy`, `fallback-slots`, `fallback-budget`,
  `fallback-error`, `fallback-unavailable`, `fallback-unwritable`,
  `not-found`.
- Site Health tests: renderer usable, fonts readable, cache directory
  writable, rewrite rule present, shaping available (recommended, not
  critical, when any script font is mapped).
- `wp share-cards status` (C72) shows the whole state in one screen.
- The actions in 5.5 let operators wire metrics.

## 12. Acceptance tests

Each test T*n* checks case C*n*. Tests run under PHPUnit with a WordPress
stub layer; GD tests run where GD is installed; Pango tests are skipped,
with a message, where the Pango format is missing. Image assertions check
dimensions, format and sampled pixel colours, not exact bytes.

- **T1.** Post with a 1600 by 900 featured image, mode auto: the chosen
  image is the featured URL with width 1600 and height 900; no card URL in
  the head output.
- **T2.** Featured 800 by 450: chosen image is the card URL, 1200 by 630.
- **T3.** Featured 1200 by 152, 1200 by 1200 and 900 by 1600: each yields
  the card.
- **T4.** No featured image: card; rendered card has no backdrop (corner
  pixel equals the background colour).
- **T5.** Featured image ID in the generic list, 1200 by 675: card, and the
  rendered card has no backdrop.
- **T6.** Mode always with an eligible image: card with backdrop. Mode
  never with no featured image and a default image: default image; with
  neither: no image tags.
- **T7.** Category archive: card URL with type `term` and the term ID.
- **T8.** Tag archive: same.
- **T9.** Archive of a custom taxonomy not allowed: no tags printed.
- **T10.** Front page, search and 404 with a default image: default image
  advertised; without: nothing.
- **T11.** A `page` single view with default allowed types: as T10.
- **T12.** Password-protected post viewed by an editor: no card URL in the
  output.
- **T13.** Two requests matched to the same custom type id produce the
  same URL; rendering one then requesting the other is a hit.
- **T14.** With a stub SEO adapter active: the adapter receives the image
  with width and height; the plugin prints no tags.
- **T15.** Without an adapter: head output holds the eight tags in order,
  absolute URLs, escaped alt containing `"` and `<`; with `emit_tags` off,
  nothing is printed and the public function still returns the image.
- **T16.** Renderer unavailable: no card URL anywhere; featured image of
  any size advertised with real dimensions, else default, else nothing.
- **T17.** Key parse accepts `post-12-0123456789` and `term-7-abcdef0123`;
  the generated rewrite rule matches them; plain permalinks produce the
  query form, which the endpoint accepts.
- **T18.** Changing the prefix flushes rewrite rules exactly once and new
  URLs use the new prefix.
- **T19.** Rules are not flushed on an ordinary request; with the rule
  missing, the Site Health test fails and an admin load schedules a flush.
- **T20.** Warm hit: 200, all C20 headers present and correct, body equals
  the file; `HEAD` has no body; matching `If-None-Match` gives 304.
- **T21.** Cold miss: file created, 200, `X-Share-Card: render`.
- **T22.** Stale hash: 302 to the current URL, max-age 300, no file
  written for the stale hash.
- **T23.** Each of `Post-1-…`, `post-01-…`, `post-1-ABCDEF0123`,
  `post-1-abc.jpg`, `post-1-0123456789.png`, `../x`, `post-1-0123456789/x`
  and a 21-digit id is rejected by the pattern; the query form returns 404.
- **T24.** Draft post, missing post, unknown type and disallowed taxonomy
  each give a byte-identical 404 response with max-age 60 and no render.
- **T25.** `?w=9999&x=1` on a warm URL: response identical to T20.
- **T26.** Corrupt backdrop: card rendered without it. Renderer throws:
  failure marker written, 302 fallback with `fallback-error`, one log line.
- **T27.** With a fresh failure marker the renderer is not called; after
  its TTL it is.
- **T28.** Two processes request one cold key concurrently (forked or a
  held lock in the test): one render call total, both responses 200.
- **T29.** With the key lock held beyond `lock_wait_ms`: the response is
  the fallback with reason `busy` within the wait plus 100 ms.
- **T30.** With all slots held: fallback with reason `slots`.
- **T31.** With the minute's counter at the budget: fallback with reason
  `budget`; renderer not called.
- **T32.** Render with `connection_aborted` simulated: file still lands.
- **T33.** A temporary file aged 2 hours is removed by gc; one aged 10
  minutes is kept. No `{key}.jpg` exists before rename completes.
- **T34.** Title edit on a published post: old variant files removed,
  current kept, `share_cards_purged` fired with the old URLs, new hash
  differs.
- **T35.** Featured image swapped and removed: as T34.
- **T36.** Term renamed: term variants removed and purged; a post with that
  kicker gets a new hash on next computation, its old file untouched until
  gc.
- **T37.** Each of unpublish, trash, delete, private and password: all
  variants removed, purge fired.
- **T38.** Term deleted: variants removed, purge fired.
- **T39.** Settings colour change: every computed hash changes; no file is
  deleted during the save.
- **T40.** Site name change with footer `name` changes hashes; with footer
  `none` it does not.
- **T41.** Attached file path changed: post hash changes.
- **T42.** gc removes stale variants, orphans, old temporaries, expired
  markers and old locks; never touches a file named outside the patterns;
  resumes from its cursor; trims to under 90% of the cap oldest first.
- **T43.** Stale URL through PHP after a settings change: 302 to current.
- **T44.** A 40-word Latin headline: chosen size is the largest that fits
  in 4 lines; a 120-word headline ends with "…" at the smallest size and
  every line measures within the box.
- **T45.** A 90-character token is split across lines; no split falls
  inside a grapheme cluster (test with combining accents).
- **T46.** The C46 inputs normalise to the expected strings, including a
  double-encoded `&amp;amp;`.
- **T47.** U+202E and NUL removed, ZWJ and ZWNJ kept.
- **T48.** (Pango) A Hindi headline renders through the shaping layer; the
  text layer is non-empty and fits the box; renderer name `gd+pango` in the
  fingerprint.
- **T49.** Without the shaping layer, a Hindi headline: card rendered, the
  headline box region contains only background and backdrop pixels, one
  info log line.
- **T50.** Arabic headline with shaping: right-aligned (ink on the right
  of the box); without: omitted.
- **T51.** Japanese headline with a mapped font: wraps between characters,
  no line starts with "。" or "」"; without a mapped font: omitted.
- **T52.** Thai headline without shaping: omitted.
- **T53.** English headline with one Devanagari word, no shaping: whole
  headline omitted, not partly drawn.
- **T54.** Kicker "économie" upper-cases to "ÉCONOMIE"; a Hindi kicker is
  unchanged by case conversion.
- **T55.** Title that normalises to empty: card with no headline; alt is
  the site name.
- **T56.** Emoji removed from "Launch day 🚀".
- **T57.** Configured font deleted: Latin still drawn with a discovered
  font; with no font at all, the render fails as T26; Site Health reports
  the path.
- **T58.** Attachment with a 1536 wide intermediate and a 6000 wide
  original: the intermediate is decoded.
- **T59.** Source over the byte cap and over the pixel cap (headers only,
  never decoded): no backdrop.
- **T60.** SVG, CMYK JPEG and an animated GIF: none, none, first frame.
- **T61.** Truncated JPEG: no backdrop, log line with attachment ID.
- **T62.** Path outside uploads via the path filter, and a missing local
  file: no backdrop.
- **T63.** Transparent PNG backdrop: transparent areas show the background
  colour under the scrim.
- **T64.** Focal point (0, 0) versus (1, 1) crops different regions of an
  asymmetric source.
- **T65.** Memory limit set low: no backdrop, render succeeds.
- **T66.** Stubbed GD and Imagick absent: activation succeeds, notice and
  Site Health critical present, endpoint returns fallback `unavailable`.
- **T67.** Read-only cache directory: fallback `unwritable`, no card URLs
  advertised.
- **T68.** Uploads base directory `s3://bucket/uploads`: cache directory is
  under `WP_CONTENT_DIR/cache/share-cards`.
- **T69.** Output is 1200 by 630 JPEG, progressive, under 300 KB for a
  noisy backdrop (re-encode step exercised), no EXIF.
- **T70.** Directory 0755 with `index.html`; card 0644.
- **T71.** `warm --posts=3 --terms --dry-run` lists without writing;
  without `--dry-run` writes and reports counts.
- **T72.** `status` prints every field listed in C72.
- **T73.** `render post:5 --out=/tmp/x.jpg` writes there and not to the
  cache.
- **T74.** `gc --dry-run` reports and deletes nothing.
- **T75.** `flush` without `--yes` refuses; with it, empties the cache of
  plugin files only.
- **T76.** `url post:5` prints the card URL and the advertised image.
- **T77.** Save without nonce or capability rejected; invalid colour and
  a font outside allowed directories rejected with old values kept; preview
  writes the preview file only.
- **T78.** Deactivate keeps files and unschedules cron; uninstall removes
  option, transients, cron, plugin files and the empty directory, leaves a
  foreign file in place, and flushes rules.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

1. Which SEO plugin adapters ship in 0.1.0, and their exact public filter
   names and signatures, confirmed from those plugins' public documentation.
2. Font coverage: rely on the explicit script-to-font map only, or also
   read the font's character map to refuse a font that lacks the glyphs.
3. Shaping detection: can a GD built against libgd with Raqm be detected
   reliably from PHP? If not, GD stays non-shaping (PHP's bundled GD has no
   Raqm).
4. When Imagick is the canvas renderer, draw the whole card in Imagick, or
   keep one GD-equivalent drawing path and only use Imagick for decode,
   compose and encode.
5. Ship a database-backed `LockProvider` for multi-server hosts on shared
   storage, or document the interface only.
6. Bundle an open-licence font (for example Noto Sans under the SIL Open
   Font Licence) so a host with no system fonts still renders Latin, or
   rely on discovery.
7. Should a site-wide card (site name and tagline) be offered for the front
   page, or is the default image enough.
8. Render budget counter: transient as specified, or a small file in the
   cache directory to avoid object cache variance.
9. Should the previous prefix keep routing (302 to the new URL) for a
   period after a prefix change.

## 14. Lessons from production

Each rule came from a real failure or a real near miss.

1. **Render on request, never at publish.** Scrapers fetch `og:image` only
   for pages that are shared. Rendering every post at publish spends CPU
   and disk on images nobody fetches.
2. **Keep cards out of the media library.** No attachment rows, no
   metadata, no derivative sizes. A card is a cache file the web server can
   serve statically.
3. **1200 by 630 only.** A synthetic square cut from a 16:9 source is
   below the 1200 px minimum some platforms expect, which is worse than no
   square at all.
4. **JPEG, not WebP.** Some messaging apps and professional networks still
   fail on WebP previews. A card that does not render where most links are
   shared is not worth the smaller file.
5. **Always state width and height for the image you serve.** An SEO layer
   that derives them from the original upload reports the wrong numbers
   whenever the served image is not that upload.
6. **Use a good featured image as it is.** Real photos at the right size
   and shape engage better than a generated card. Card only what is too
   small, the wrong shape, generic, or missing.
7. **Never put a card on a card.** A site-wide placeholder used as a
   featured image passes the size test. Treat it as no image, and never use
   it as a backdrop.
8. **Never block and never stampede.** On a cold key, one process renders;
   others wait briefly, then get a real image by redirect. Cap concurrent
   renders site-wide; a single render can take tens of megabytes.
9. **Do not bake a moving value into an immutable image.** A count of
   stories drawn on a card cached for weeks is quietly wrong by evening.
   Either put the value in the fingerprint or draw something that stays
   true.
10. **When the picture behind a card changes, the URL must change.** A
    card cached as immutable keeps showing a rejected backdrop for weeks
    unless its key changes. Hash the inputs into the URL instead of hoping
    a purge reaches every cache.
11. **Never redirect an image URL to an HTML page.** With no fallback
    image, answer 404. A scraper given the home page as an image shows
    nothing or caches an error.
12. **Never upper-case UTF-8 byte by byte.** It corrupts every non-ASCII
    character. Use multibyte case functions, and expect caseless scripts.
13. **Without a shaper, do not draw complex scripts.** A plain text
    drawing call does no reordering or conjunct formation; Devanagari comes
    out as broken glyphs. A card with no headline is fine; a card with
    mangled text is actively damaging.
14. **Escape for every layer that decodes.** When text passes through
    Imagick's Pango pseudo-format, ImageMagick decodes one level of
    entities before Pango parses the markup. Text escaped once reaches
    Pango with a bare `&` or `<`, Pango drops the markup, and the headline
    vanishes. Escape twice on that path, and test with `&` and `<`.
15. **Release Imagick objects explicitly.** Call `clear()` and
    `destroy()`; memory is not reliably freed on reference count alone.
16. **A bad source image must not kill the card.** Catch every decode
    error on the backdrop and draw the card without it.
17. **Write atomically.** Render to a temporary name and rename. A static
    web server rule serves whatever file exists, including a half-written
    one.
18. **Two pages that show the same content share one card.** Key the card
    by the content (for example by day), not by the URL, so it renders once.
19. **Delete what must not be seen.** A card shows a headline. When a post
    is unpublished or its title is changed for legal reasons, the old file
    must go at once, not at the next sweep, because a static rule and the
    CDN keep serving it.
