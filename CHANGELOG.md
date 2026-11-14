# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Card choice per request: featured images at least 1200 px wide and between
  1.5 and 2.1 wide to high are used as they are; small, misshapen, generic and
  missing images get a 1200 by 630 card. Category and tag archives get term
  cards; other views get the default image.
- Card URLs with a content hash, `/{prefix}/{type}-{id}-{hash}.jpg`, and the
  `?share_card=` form for plain permalinks. Previous prefix redirects for 30
  days after a change.
- Endpoint on `parse_request`: immutable caching, ETag and 304, HEAD, stale
  redirects, identical 404s for unknown and non-public subjects.
- Cold-cache protection: per-key file lock, `max_concurrent` render slots, a
  per-minute render budget, failure markers, fallback redirects to a real
  image, atomic writes.
- GD and Imagick renderers sharing one layout, and a Pango text layer for
  complex scripts. Scripts that cannot be shaped or have no font are left off,
  never drawn broken. Text reaches Pango literally: markup, ImageMagick `%`
  escapes and backslash escapes are neutralised.
- Backdrops from the featured image: intermediate size choice, byte, pixel and
  memory caps, CMYK, SVG and truncated JPEG refusal, transparency over the
  background, focal point, darkening and scrim.
- Invalidation on title, featured image, status, term and deletion changes,
  with the `share_cards_purged` action for CDN purges.
- Daily garbage collection in resumable batches with a disk cap.
- Yoast SEO and Rank Math adapters through their public filters; own tags
  otherwise.
- Settings page with validation and preview, Site Health tests, WP-CLI
  commands `warm`, `status`, `render`, `url`, `gc`, `flush`.
- Custom card types (`share_cards_types`) and an opt-in front page card.

[Unreleased]: https://github.com/IndianOpinion/share-cards/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/share-cards/releases/tag/v0.1.0
