# Changelog

## 1.2.0 — 2026-07-01

Canonical feedback migration (CON10).

### Features
- Settings-save reports each rejected/coerced value (invalid IMAP encryption, invalid post status, dropped qualified-sender emails, invalid webmaster email) instead of a blanket "Settings saved."
- Reprocess replaces its native `confirm()` with `IDS.confirmModal` (danger); previously-silent failures (bad-nonce/post-id reprocess, Check Now batch partial failures, webmaster-notify send result) are now surfaced.
- Feedback renders through the canonical `.ids-alert` family (settings-save uses WordPress's native dismissible settings notices — an operator-accepted mechanism for the admin settings page).

### Internal
- CON10 satisfy-guard aggregate suite (G1 no native modals, G2 no deprecated `.ids-notice`/`.ids-message`); the plugin's first JavaScript + jsdom harness.


## 1.1.9 — 2026-05-28



## 1.1.8 — 2026-05-26

### Internal
- Clarified bundled CHANGELOG behavior in README versioning section


## 1.1.7 — 2026-05-26

### Internal
- Documented the new /seal-version workflow in README


## 1.1.6 — 2026-05-26

Initial sealed version. This is the first entry under the new
plugin-versioning-history system; older releases were not retroactively
documented (forward-only per the design's scope).


This file is appended to by `/seal-version` on every sealed release.
Entries are newest-first; see `docs/superpowers/specs/2026-05-26-plugin-versioning-history-design.md`
for the format and the surrounding versioning history design.
