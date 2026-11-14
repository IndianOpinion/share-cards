# Contributing

Thank you for helping. This file covers setup, tests, style and how pull
requests are reviewed.

## Setup

You need PHP 8.1 or later with `gd` (JPEG and FreeType) and `mbstring`, and
Composer. Imagick with Pango, `intl` and `pcntl` are optional; tests that need
them skip with a message when they are missing.

```sh
git clone https://github.com/IndianOpinion/share-cards.git
cd share-cards
composer install
```

Some tests draw text and need fonts. On Debian or Ubuntu:

```sh
sudo apt-get install fonts-noto-core fonts-dejavu-core fonts-ipafont-gothic
```

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 6
composer lint      # PHP_CodeSniffer, PSR-12
composer check     # all three
```

The tests run without WordPress: `tests/stubs/` is a small in-memory stand-in
for the WordPress functions the plugin calls. If you use a new WordPress
function, add it to the stub layer with the smallest faithful behaviour.

Every behaviour case in [docs/PRD.md](docs/PRD.md) has a test numbered after
it (C12 is tested by T12). New behaviour needs a case and a test. Image tests
compare dimensions, format and sampled pixels, never bytes.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Code must run on PHP 8.1: no readonly classes, no DNF types, no standalone
  `null`, `true` or `false` types, no constants in traits.
- Typed exceptions; no silent failures; no `@` error suppression; no `eval`,
  no shell, no `exec` of ImageMagick binaries.
- Escape everything printed in WordPress. Capability checks and nonces on
  anything admin-facing.
- Prose in docs and comments: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request, with a short description of what and why.
- Tests, PHPStan and PHPCS must pass. CI runs them on PHP 8.1, 8.2 and 8.3.
- Update the README, `docs/ARCHITECTURE.md` and `CHANGELOG.md` (under
  Unreleased) when behaviour or configuration changes.
- A sign-off line (DCO) is not required.

## Reporting problems

Bugs and feature requests: open an issue with the templates provided. Security
problems: see [SECURITY.md](SECURITY.md).
