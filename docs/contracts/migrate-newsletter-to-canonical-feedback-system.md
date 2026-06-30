# Contract: Migrate newsletter to canonical feedback system

- **Provider scope:** newsletter
- **Requester scope:** global

## Requirements (FROZEN at ratify — requester-authored)

- Target the CON6 .ids-alert family - for this PHP-only plugin that means ids_render_alert PHP banners (replacing hand-built notice divs and surfacing currently-silent outcomes) plus IDS.confirmModal for the one JS confirm. Do NOT use the deprecated .ids-message/.ids-notice. Each change strict-TDD, deployed, and visually verified against indivisible-shared/docs/contracts/feedback-visual-reference (+ /admin).
- A1 - Reprocess per row (class-in-reprocess.php:184): the bad-nonce/bad-post-id path silently returns - surface a failure via ids_render_alert .ids-alert-error and align nonce-failure handling with the wp_die siblings.
- A2 - Check Now (class-in-admin.php:257): batch partial failures are logged only - surface them to the admin in the result notice via .ids-alert.
- A3 - Webmaster notify (class-in-processor.php:299): the wp_mail() return is ignored - surface a send failure to the admin instead of M-NONE.
- B1 - Reprocess confirm (class-in-reprocess.php JS): replace the native confirm() with IDS.confirmModal.
- C1 - Save settings (class-in-admin.php:290): the sanitizer silently coerces bad input - report rejected/coerced values via ids_render_alert validation feedback instead of an unconditional Settings saved.
- D1 - Banner unification: migrate the Reprocess hand-built success/error div (class-in-reprocess.php:184) onto .ids-alert and bring the Diagnose plain-text error (class-in-admin.php:278) onto a canonical .ids-alert-error so the sibling buttons share one idiom.

## API surface

newsletter exposes **no new public API** — CON10 is consumer-side conformance to
the CON6 canonical feedback toolkit (`indivisible-shared/docs/contracts/canonical-feedback-toolkit.md`).
This is a PHP-first admin plugin: it migrates its own admin-page feedback onto the
contract's primitives and adds one small JS module for the single destructive
confirm. The contact points are:

- **`ids_render_alert( $type, $msg, $opts )` (CON6 PHP emitter)** — server-rendered
  `.ids-alert` banners on the Settings → Newsletter Poster page: the Reprocess
  invalid-post / `WP_Error` failure (A1, D1), the Check Now batch result (A2) and
  webmaster-notify failure (A3), the settings-save validation feedback (C1), and
  the Diagnose failure (D1). `$type ∈ {success,error,warning,info}`; `$msg` is
  escaped via `esc_html()`.
- **Hand-emitted canonical `.ids-alert` DOM** — for the one banner that carries a
  link (the Reprocess **success** notice's "view post" anchor, D1): because
  `ids_render_alert` escapes its message, the success banner emits the canonical
  `.ids-alert.ids-alert-success` → `.ids-alert-icon` + `.ids-alert-body` markup
  directly, escaping the title via `esc_html()` and the URL via `esc_url()`.
- **`IDS.confirmModal` (CON6, `ids-confirm-modal` handle)** — the one destructive
  confirm: the per-row Reprocess button (B1) replaces its inline
  `onclick="return confirm(…)"` with `IDS.confirmModal({ variant:'danger', … })`,
  submitting the row's form on confirm.
- **Enqueue + dependency guard** — the Settings page enqueues the
  `indivisible-design-system` stylesheet (for `.ids-alert`) and the
  `ids-confirm-modal` script (for B1); an `IN_REQUIRED_IDS_VERSION` constant + an
  `admin_init` version check mirrors IEC's `iec_check_design_system_dependency`
  (admin notice when the design system is missing or too old).

All caller-supplied text is escaped at the boundary — `esc_html()` / `esc_attr()`
(via `ids_render_alert`) in PHP, `textContent` in JS. The deprecated
`.ids-message` / `.ids-notice` aliases are never emitted (newsletter never used
them; G2 locks it).

## Behavioral guarantees

Every user-facing failure, success, and validation outcome on the newsletter
plugin's admin page surfaces through the canonical CON6 `.ids-alert` family — no
swallowed errors, no native browser dialogs, no hand-built notice divs. **Asserted
by** names the proof: **php** (PHPUnit), **jsdom** (Jest), or **sign-off**
(operator visual verification against the running dev site + the
`indivisible-shared/docs/contracts/feedback-visual-reference`, for CSS facts
php/jsdom can't prove).

| # | Guarantee | Asserted by |
|---|---|---|
| A1 | An attempted Reprocess with a bad nonce `wp_die`s, matching its `check_admin_referer` siblings (Check Now / Test Connection / Diagnose) — no silent return; an attempted Reprocess with an invalid/missing post id surfaces a `.ids-alert-error` instead of returning silently. | php (test-reprocess-feedback.php); sign-off |
| A2 | Check Now reports per-email batch failures (count + reason) in its result banner — `.ids-alert-warning` on partial success, `.ids-alert-error` when none succeed — instead of logging them only; a clean run shows `.ids-alert-success`. | php (test-check-now-feedback.php); sign-off |
| A3 | A failed webmaster notification (`wp_mail()` returns false) is surfaced to the admin via `.ids-alert` on the Check Now path instead of the silent M-NONE; `indivisible_newsletter_notify_webmaster()` returns the send result. | php (test-notify-webmaster-result.php); sign-off |
| B1 | The per-row Reprocess button uses `IDS.confirmModal` (variant `danger`) — on confirm the row's form submits, on cancel it does not; the native `onclick="return confirm(…)"` is removed. | jsdom (reprocess-confirm.test.js); sign-off |
| C1 | Saving settings reports each rejected/coerced value — invalid IMAP encryption, invalid post status, dropped qualified-sender emails, an invalid webmaster email — so the outcome is surfaced, not hidden behind a blanket "Settings saved." Rendered through WordPress's native **dismissible settings notices** (`add_settings_error` → `settings_errors()`) rather than `ids_render_alert` — see Discussion (operator-accepted mechanism deviation, 2026-06-30). | php (test-settings-validation-feedback.php); sign-off |
| D1 | The admin-action feedback shares one `.ids-alert` idiom: the Reprocess success/error notices (formerly hand-built inline-styled divs) and the Diagnose failure (formerly plain `<pre>` "ERROR/FAILED" text) render as `.ids-alert`; with A2's Check Now and the Test Connection sibling, the Settings page no longer emits hand-built `notice`/inline-styled feedback divs. | php (test-reprocess-feedback.php, test-diagnose-error-alert.php); sign-off |
| G1 | **No native browser modal.** No `alert()` / `confirm()` / `prompt()` in the newsletter plugin's user-facing JS. | jsdom (con10-feedback-migration.contract.test.js) |
| G2 | **No deprecated IDS alias.** No `.ids-notice` / `.ids-message` anywhere in newsletter source (PHP or JS). | jsdom (con10-feedback-migration.contract.test.js) |

## Contract tests & fixture

The per-behavior proofs are focused suites written alongside each work item
(strict-TDD, deployed, visually verified):

**PHP (PHPUnit):** `test-reprocess-feedback.php` (A1 + D1 reprocess banners),
`test-check-now-feedback.php` (A2), `test-notify-webmaster-result.php` (A3),
`test-settings-validation-feedback.php` (C1), `test-diagnose-error-alert.php`
(D1 Diagnose), and the enqueue suite `test-admin-enqueue-ids.php`
(`ids-confirm-modal` enqueued on the settings page; the `.ids-alert` CSS is
auto-enqueued site-wide in admin by indivisible-shared).

**JS (jsdom):** `reprocess-confirm.test.js` (B1) — the plugin's first JS test, so
this contract stands up the Jest/jsdom harness (modeled on the agenda /
login-required plugins).

**Satisfy-guard path:** `tests/js/con10-feedback-migration.contract.test.js` — the
aggregate invariant: **G1** (no native `alert`/`confirm`/`prompt` in newsletter
user-facing JS) + **G2** (no `.ids-notice` / `.ids-message` anywhere in newsletter
source, repo-wide). One path for the `satisfy` guard; the focused suites above
keep per-behavior coverage legible.

**Fixture:** none new — the B1 jsdom test builds the minimal Reprocess
form/button markup inline and drives the delegated submit listener directly.

## Discussion

**2026-06-30 — C1 mechanism deviation (operator-accepted, no version bump).**
During the live visual sign-off, surfacing C1 settings-save feedback through
`ids_render_alert` (per the frozen Requirement wording) produced a **duplicate
"Settings saved."** banner: WordPress already renders the queued settings
errors via its native dismissible `settings_errors()` notice, so a parallel
`.ids-alert` render doubled it. Operator (Mike, who owns both the requester and
provider scopes) directed: keep WordPress's native dismissible notice, drop the
parallel `.ids-alert`. The Requirement's **intent** is preserved — every
rejected/coerced value is still surfaced (as a dismissible WP notice), never
hidden behind a blanket "Settings saved." Only the prescribed **mechanism**
(`ids_render_alert`) changed, and only for the settings-save surface; the
Reprocess, Check Now, Test Connection, and Diagnose surfaces remain on
`.ids-alert`. Recorded here in lieu of a `contract-version` bump per operator
decision (option b).
