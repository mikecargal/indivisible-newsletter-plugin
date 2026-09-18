# Changelog

## 1.2.1 — 2026-09-18

### Internal
- Drop the wp_head inline <style> for .in-newsletter-content; the theme (extendable-child >= 1.3.0, CON20) now serves those rules verbatim. Requires theme 1.3.0+ on the same site (audit P4-2, CON21).
- Normalize IN_REQUIRED_IDS_VERSION to plain 3.3.0 and add a no-suffix guard test (audit P4-11).
- Adopt prettier (WordPress config): tooling, mass reformat, and .git-blame-ignore-revs for the reformat commit.
- Register the safari-mcp-stp MCP server in project-scope .mcp.json.
- Document the claude-smoke smoke-test login in CLAUDE.md.


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
