=== Share cards ===
Contributors: indianopinion
Tags: open graph, og image, social, share card, twitter card
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Social share cards rendered on the first request and served from disk, with GD or Imagick and a content hash in the URL.

== Description ==

Share cards makes the og:image and twitter:image for posts, categories, tags and custom pages. Featured images that are already at least 1200 px wide and close to 16:9 are used as they are. Posts with a small, misshapen, generic or missing image, and every category and tag archive, get a 1200 by 630 card with the headline drawn on it.

Cards are rendered the first time a scraper asks for them, not at publish, and served from disk after that. The URL carries a hash of everything drawn on the card, so cards can be cached for a year and still change when the post changes.

* Works with GD alone; uses Imagick and Pango when present.
* Complex scripts (Devanagari, Tamil, Arabic, Thai and others) are drawn only when they can be shaped, never as broken glyphs.
* Bounded work on a cold cache: one render per card, a site-wide concurrency limit and a per-minute budget, with a real image as the fallback.
* Old cards are deleted at once when a post is edited, unpublished or deleted, and an action tells you which URLs to purge from your CDN.
* Hands the image to Yoast SEO or Rank Math through their public filters; prints its own tags otherwise.
* One neutral template: colours, fonts, logo, labels and layout numbers. No branding.
* WP-CLI: warm, status, render, url, gc, flush.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin to `wp-content/plugins/share-cards`, or install it with Composer as `indianopinion/share-cards`.
2. Activate it.
3. Open Settings, Share cards. Any missing requirement (GD, JPEG or FreeType support, a writable directory, a font) is named at the top.
4. Press Render preview.

Fonts are not bundled. Install Noto Sans or DejaVu Sans on the server (for example `apt-get install fonts-noto-core`), and map a font per script for anything beyond Latin, Greek and Cyrillic.

To run it as a must-use plugin, see the loader in the README.

== Frequently Asked Questions ==

= The card URL redirects to the featured image =

Check the X-Share-Card response header. fallback-busy, fallback-slots and fallback-budget mean renders were limited; the next request gets the card. fallback-error means the render failed; the reason is in the PHP error log.

= A Hindi or Tamil headline is missing =

Shaping these scripts needs Imagick with ImageMagick built with Pango. Without it the field is left blank rather than drawn broken. Run `wp share-cards status`.

= Does it work with a page cache or CDN? =

Yes. Cards are images with their own cache headers, and the URL changes with the content.

== Changelog ==

= 0.1.0 =
* First release.
