# Contract: Newsletter drops the wp_head inline <style> for .in-newsletter-content once the theme serves it (audit P4-2)

- **Provider scope:** newsletter
- **Requester scope:** global

## Requirements (FROZEN at ratify — requester-authored)

- Remove indivisible_newsletter_frontend_css() and its add_action('wp_head', ...) registration from indivisible-newsletter.php (currently lines 96-106). No replacement enqueue — the rules move verbatim to extendable-child/style.css under the companion theme Contract.
- Delete the six test-plugin-bootstrap.php tests that pin the hook and each CSS rule (test_frontend_css_*); add one test asserting has_action('wp_head','indivisible_newsletter_frontend_css') is false so the echo cannot silently return.
- Ship as a newsletter patch version bump.
- Sequencing (hard): deploy ONLY after the theme change is verified live on dev, staging and prod. Plugin-first leaves the ~15 stored newsletter posts unstyled (dark-theme unreadable) in the gap.

## API surface

- `indivisible_newsletter_frontend_css()` is removed. It was never a public/documented function; no other plugin or theme called it (grep across the workspace: only the plugin and its tests referenced it).
- No `wp_head` action is registered by the plugin. The plugin emits nothing into the front-end `<head>`.
- The `.in-newsletter-content` wrapper `<div>` that the processor bakes into stored newsletter post content is unchanged. Its styling is now owned by the theme (`extendable-child/style.css` >= 1.3.0, CON20).
- Folded in (audit P4-11): `IN_REQUIRED_IDS_VERSION` is normalized from `3.3.0-dev` to plain `3.3.0`. The gate semantics (`version_compare >=`) are unchanged.

## Behavioral guarantees

- A newsletter post renders identically before and after this release on a site running theme >= 1.3.0: the theme serves the same three rules verbatim (`.in-newsletter-content`, `.in-newsletter-content *`, `.in-newsletter-content table.nl-container`).
- The front-end `<head>` contains no `<style>` block from the plugin; `has_action('wp_head', 'indivisible_newsletter_frontend_css')` is `false`.
- Sequencing: this plugin release is deployed only after the theme change is verified live on the same environment (dev, staging, prod). Deploying plugin-first leaves stored newsletter posts unstyled.
- Version floor: the plugin requires Indivisible Shared Design System >= `3.3.0` (plain release floor).

## Contract tests & fixture

- `tests/test-plugin-bootstrap.php::test_frontend_css_is_not_hooked_to_wp_head` asserts the `wp_head` hook is absent, so the inline echo cannot silently return.
- The six former `test_frontend_css_*` tests that pinned the hook and each CSS rule are deleted; the rules are now pinned on the theme side under CON20.
- `tests/test-admin-deps.php::test_required_ids_version_is_a_plain_release_version` asserts `IN_REQUIRED_IDS_VERSION` matches `^\d+\.\d+\.\d+$` (P4-11); the existing live-floor test keeps asserting the deployed design system satisfies it.
- No fixture: the acceptance check is a manual before/after render of a stored newsletter post on dev with a cache-busted URL.
