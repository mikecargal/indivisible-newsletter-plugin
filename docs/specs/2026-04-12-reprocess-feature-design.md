# Newsletter Reprocess Feature — Design

**Status:** Draft, awaiting user review before implementation plan
**Date:** 2026-04-12

## Problem

When the cleaner (`indivisible_newsletter_clean_html`) is changed, existing posts created by previous versions render with the old output. There is no way from the admin UI to re-run the current cleaner against an already-imported newsletter. Today, fixing a bug in the cleaner and validating it against a real newsletter requires manually deleting the post, removing its Message-ID from the processed-IDs option, and re-triggering Check Now — three shell commands that the author doesn't want to rediscover each time, and that aren't available to non-technical users.

This feature adds a "Recent Newsletters" section to the plugin's settings screen with a per-row **Reprocess** button. Clicking it re-runs the currently-installed cleaner against a stored copy of the original email body and updates the post's content in place.

## Out of Scope

- **Legacy posts without stored raw body.** Posts created by plugin versions prior to this feature have no `_in_newsletter_raw_body` meta. They are not reprocessable via this feature and simply do not appear in the Recent Newsletters table. A one-shot shell procedure ("Part A") handles immediate legacy cases by deleting the posts and removing Message-IDs from the processed-IDs option so Check Now recreates them via the fresh cleaner.
- **Backfill of legacy posts.** Could be added later as a separate admin action if the need arises. Not part of this design.
- **Re-fetching from IMAP.** Reprocess operates purely on stored data. The IMAP mailbox is not touched.
- **Changes to the cleaner itself.** That is a separate concern.

## Decisions Reached in Brainstorming

| # | Topic | Decision |
|---|---|---|
| Q1 | Raw body storage | Post meta (`_in_newsletter_raw_body`). Simple, integrates with WordPress, acceptable size (~2 MB/year at weekly cadence). |
| Q2 | Metadata preservation | Inherit from old post: category, author, status, post_date, slug, comments, featured image, `_login_required` all preserved. Only `post_content` changes. |
| Q3 | Post ID | Same ID via `wp_update_post`. Not new ID + delete. Preserves slug, comments, featured image, permalinks, inbound bookmarks. Atomic single DB operation. |
| Q4 | UI placement and row content | New section below "Actions" on the settings page. Six-column table: Title \| Date \| Message-ID \| Raw Subject \| View \| Reprocess. |
| Q5 | Safety against overwriting manual edits | JS `confirm()` dialog before reprocess. WordPress auto-creates a revision via `wp_update_post`, so manual edits are recoverable through the admin Revisions UI. |
| File organization | Where does the feature code live? | **Approach A**: dedicated new file `src/includes/class-in-reprocess.php`. |

## Data Model

Three new post meta keys written by `indivisible_newsletter_create_post_from_email()` at creation time:

- **`_in_newsletter_raw_body`** — the HTML returned by `indivisible_newsletter_extract_forwarded_content()`, before `indivisible_newsletter_clean_html()` runs. This is the input to reprocess.
- **`_in_newsletter_message_id`** — the IMAP Message-ID of the source email (`$email['message_id']`). Displayed in the Recent Newsletters table; also serves as a cross-reference for future tools.
- **`_in_newsletter_raw_subject`** — the unmodified `$email['subject']` as received (before `indivisible_newsletter_clean_subject` strips forwarding prefixes). Displayed in the Recent Newsletters table so the user can distinguish "direct delivery" from "forwarded copy" variants of the same newsletter.

Legacy posts without these keys are unaffected and do not appear in the Recent Newsletters table.

## Core Function — `class-in-reprocess.php`

### Signature

```php
function indivisible_newsletter_reprocess_post( int $post_id ): true|WP_Error
```

### Algorithm

1. **Validate.**
   - `get_post($post_id)` must return a non-null post. Otherwise return `new WP_Error('reprocess_not_found', ...)`.
   - `current_user_can('manage_options')` must be true. Otherwise return `new WP_Error('reprocess_forbidden', ...)`.
   - `get_post_meta($post_id, '_in_newsletter_raw_body', true)` must be non-empty. Otherwise return `new WP_Error('reprocess_no_raw_body', ...)`.
2. **Read raw body** from meta.
3. **Run cleaner:** `$cleaned = indivisible_newsletter_clean_html( $raw )`. Note: `extract_forwarded_content` is NOT re-run; the stored raw body is already post-extract, pre-clean.
4. **Sanitize:** `$cleaned = wp_kses_post( $cleaned )`.
5. **Wrap:** `$cleaned = '<div class="in-newsletter-content">' . $cleaned . '</div>'`.
6. **Wrap in Gutenberg block:** `$content = "<!-- wp:html -->\n" . $cleaned . "\n<!-- /wp:html -->"`.
7. **Update post:** `wp_update_post( ['ID' => $post_id, 'post_content' => $content], true )`. WordPress auto-creates a revision, preserving the prior content.
8. **Return** `true` on success, or the `WP_Error` from `wp_update_post` on failure.

### Invariants

- Raw body meta is never modified → reprocess is idempotent.
- `post_title`, `post_date`, `post_status`, `post_author`, category assignments, and `_login_required` meta are untouched.
- Function is pure: no `echo`, no redirect, no HTTP response side effects. UI layer is separate.

### Error codes

| Code | Meaning |
|---|---|
| `reprocess_not_found` | No post with that ID |
| `reprocess_forbidden` | Current user lacks `manage_options` |
| `reprocess_no_raw_body` | Post has no `_in_newsletter_raw_body` meta (legacy post) |
| Propagated WP_Error | `wp_update_post` failed (DB or filter veto) |

## Settings UI — Recent Newsletters Section

### Placement

Inserted between the existing "Actions" section and "Reliable Scheduling" section in `indivisible_newsletter_render_settings_page()`. The page flow becomes:

```
Newsletter Poster Settings
  [settings form]
  [Actions: Test Connection | Check Now | Diagnose]
  [Recent Newsletters]              ← NEW
  [Reliable Scheduling]
```

The rendering function lives in `class-in-reprocess.php` and is called from `indivisible_newsletter_render_settings_page()` via a direct function call.

### Query

```php
$posts = get_posts([
    'post_type'      => 'post',
    'post_status'    => 'any',
    'posts_per_page' => 50,
    'date_query'     => [[ 'column' => 'post_date', 'after' => '90 days ago' ]],
    'meta_key'       => '_in_newsletter_raw_body',
    'meta_compare'   => 'EXISTS',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);
```

The `meta_compare => 'EXISTS'` filter ensures only posts with the new meta appear. Legacy posts are filtered out at query time. The 50-row cap is a safety stop independent of the 90-day window.

### Table structure

| Column | Source |
|---|---|
| Title | `$post->post_title`, linked to `get_edit_post_link($post->ID)` |
| Date | `get_the_date('Y-m-d', $post)` |
| Message-ID | `get_post_meta($post->ID, '_in_newsletter_message_id', true)`, truncated to 40 chars with `…`, wrapped in `<code>` |
| Raw Subject | `get_post_meta($post->ID, '_in_newsletter_raw_subject', true)`, truncated to 60 chars |
| View | Link to `get_permalink($post->ID)` with `target="_blank"` |
| Reprocess | Form POST button (see below) |

### Reprocess button

```html
<form method="post" style="display:inline">
    <?php wp_nonce_field('in_reprocess_action_' . $post->ID); ?>
    <input type="hidden" name="in_reprocess_post_id" value="<?php echo esc_attr($post->ID); ?>">
    <button type="submit" name="in_reprocess"
            class="button button-small"
            onclick="return confirm('Reprocessing will replace the current content with a fresh clean of the original email. Any manual edits will be moved to a post revision. Continue?');">
        Reprocess
    </button>
</form>
```

- Per-post nonce `in_reprocess_action_<post_id>` — prevents CSRF and cross-post replay.
- JS `confirm()` dialog per Q5.
- `button-small` class for visual consistency with other WP table actions.

### Action handler

Invoked from `indivisible_newsletter_render_settings_page()` alongside the existing Check Now / Test Connection / Diagnose handlers:

```php
if (isset($_POST['in_reprocess']) && isset($_POST['in_reprocess_post_id'])) {
    $post_id = absint($_POST['in_reprocess_post_id']);
    if (check_admin_referer('in_reprocess_action_' . $post_id)) {
        $result = indivisible_newsletter_reprocess_post($post_id);
        if (is_wp_error($result)) {
            // Admin notice: error with error-code-to-message mapping
        } else {
            // Admin notice: success with title and "view post" link
        }
    }
}
```

### Feedback

**Success:** admin notice at top of settings page:
> Reprocessed: *April General Assembly Recap* — [view post](permalink)

The user stays on the settings page so they can iterate (click Reprocess on another row or the same row).

**Error:** admin notice at top with the `WP_Error` message, mapped from the error code to a user-friendly string:
- `reprocess_not_found` → "That post no longer exists."
- `reprocess_forbidden` → "You don't have permission to reprocess newsletters."
- `reprocess_no_raw_body` → "This post doesn't have the original email stored. Only newsletters created after this feature shipped can be reprocessed."
- Generic `WP_Error` → the error's `get_error_message()` as-is.

## Tests

All tests follow TDD: written before implementation, confirmed red, then green. Target: ~15 new PHPUnit tests, ~274 total suite size.

### New file: `tests/test-reprocess.php`

Class `Test_IN_Reprocess extends IN_Test_Case` (for `assertHtml`).

1. `test_reprocess_returns_error_when_post_does_not_exist` — post_id 999999 → `reprocess_not_found`
2. `test_reprocess_returns_error_when_user_lacks_capability` — subscriber user → `reprocess_forbidden`
3. `test_reprocess_returns_error_when_raw_body_meta_missing` — post without meta → `reprocess_no_raw_body`
4. `test_reprocess_updates_post_content_with_cleaned_raw_body` — post with known raw body → post_content contains expected cleaned HTML wrapped in `.in-newsletter-content`
5. `test_reprocess_is_idempotent` — two calls produce identical result
6. `test_reprocess_preserves_post_metadata` — title, date, status, author, category, `_login_required` unchanged
7. `test_reprocess_creates_revision` — `wp_get_post_revisions()` has one more entry after reprocess

### Extend: `tests/test-processor-extended.php`

8. `test_create_post_stores_raw_body_meta` — after `create_post_from_email`, `_in_newsletter_raw_body` equals the post-extract pre-clean HTML
9. `test_create_post_stores_message_id_meta` — after `create_post_from_email`, `_in_newsletter_message_id` equals `$email['message_id']`
10. `test_create_post_stores_raw_subject_meta` — after `create_post_from_email`, `_in_newsletter_raw_subject` equals `$email['subject']` (pre-`clean_subject`)

### Extend: `tests/test-admin-settings.php`

11. `test_recent_newsletters_table_shows_posts_with_raw_body_meta` — two posts, one with meta and one without; `assertHtml` finds row for the meta'd one and no row for the other
12. `test_recent_newsletters_table_limits_to_90_days` — post dated 100 days ago with meta does not appear
13. `test_recent_newsletters_table_includes_reprocess_button_with_nonce` — each row has form with per-post nonce
14. `test_reprocess_action_handler_rejects_missing_nonce` — POST without nonce → no action, no content change
15. `test_reprocess_action_handler_shows_success_notice_on_valid_request` — POST with valid nonce → success notice HTML rendered

### Manual QA after green

1. Reload settings page → Recent Newsletters table shows the Part-A-replacement posts
2. Click Reprocess → confirm dialog fires → accept → success notice → click "view post" → frontend renders identically (raw body and cleaner unchanged between runs)
3. Edit a newsletter post manually in admin, add a typo, save → reload settings → click Reprocess → confirm → typo replaced by fresh clean, revision created containing the typo (safety-net verification)

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| User accidentally reprocesses a post that has manual edits | JS confirm dialog (Q5); WordPress revision auto-created by `wp_update_post` so edits are recoverable |
| Legacy posts show up in the table in a broken state | Meta query with `meta_compare => 'EXISTS'` filters them out at query time |
| Stored raw body grows unbounded over time | 90-day window + 50-row hard cap on the table display; actual meta rows accumulate but volume is small (~2 MB/year) |
| Cleaner produces unexpected output on a stored raw body (e.g., a bug in a new cleaner version) | The cleaner is a pure string-in / string-out function and does not throw, so reprocess cannot abort mid-flight. A bad cleaner produces bad output, which the user sees and can roll back via the post revision automatically created by `wp_update_post`. |
| CSRF against reprocess button | Per-post nonce `in_reprocess_action_<post_id>`, verified in action handler |
| Reprocess on a post not belonging to this plugin | `reprocess_no_raw_body` error code catches any post without the new meta, including non-newsletter posts |

## Version Bump

Feature-level change. Patch bump: `1.1.5 → 1.1.6` (or minor bump to `1.2.0` if the user considers "new admin UI surface" a minor-level change). Decision deferred to implementation time.

## File Organization

One new file: `src/includes/class-in-reprocess.php` containing:
- `indivisible_newsletter_reprocess_post( int $post_id ): true|WP_Error`
- `indivisible_newsletter_render_recent_newsletters_section()` (UI)
- `indivisible_newsletter_handle_reprocess_action()` (POST handler)

Minimal touches to existing files:
- `src/indivisible-newsletter.php` — add `require_once` for the new file
- `src/includes/class-in-processor.php` — add three `update_post_meta()` calls in `create_post_from_email` for the new keys
- `src/includes/class-in-admin.php` — call the UI renderer from `render_settings_page` and call the action handler before output

No changes to `class-in-email.php`, `class-in-cron.php`, or `composer.json`.
