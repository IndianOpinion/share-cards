# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Email **hello@indianopinion.org** with "share-cards security" in the subject.
Please do not open a public issue for a security problem.

Include what you found, the version, how to reproduce it, and what an
attacker gains. A proof of concept against a local test site is welcome; do
not test against sites you do not own.

What to expect:

- An acknowledgement within 3 working days.
- A first assessment within 10 working days.
- A fix, or a plan with a date, within 30 days for confirmed issues. We will
  agree a disclosure date with you and credit you in the release notes unless
  you prefer otherwise.

## Scope

In scope: anything in this repository, in particular the card endpoint (path
handling, the subjects it will render, resource limits), image decoding, the
settings page (capability checks, nonces, validation) and output escaping.

Out of scope: vulnerabilities in WordPress, PHP, GD, ImageMagick, Pango or
fonts themselves (report those upstream), and denial of service that needs
more requests than the documented per-minute budget allows.
