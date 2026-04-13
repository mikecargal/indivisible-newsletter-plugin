# Newsletter Reprocess Feature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Recent Newsletters" section to the plugin's settings screen with a per-row Reprocess button that re-runs the current cleaner against the stored original email body and updates the post's content in place.

**Architecture:** One new file `src/includes/class-in-reprocess.php` contains the core reprocess function, the table UI renderer, and the action handler. Three new post meta keys (`_in_newsletter_raw_body`, `_in_newsletter_message_id`, `_in_newsletter_raw_subject`) are written at post creation by minor additions to `indivisible_newsletter_create_post_from_email`. The settings page renderer calls into the new file for UI and handler integration.

**Tech Stack:** PHP 8.x, WordPress 6.9, PHPUnit 9.6, Symfony CssSelector (via `AssertHtmlTrait` in `indivisible-shared`).

**Session context:** Not running in a worktree; work happens on `main` in the newsletter plugin repo. No Docker/npm operations are required for this plan.

**Spec reference:** `docs/specs/2026-04-12-reprocess-feature-design.md`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/includes/class-in-reprocess.php` | **Create** | Core reprocess function, table UI renderer, action handler — the entire feature in one cohesive file. |
| `src/indivisible-newsletter.php` | **Modify** | Add one `require_once` for the new class file. |
| `src/includes/class-in-processor.php` | **Modify** | Add three `update_post_meta` calls in `indivisible_newsletter_create_post_from_email` to stamp raw body, Message-ID, and raw subject on newly-created posts. |
| `src/includes/class-in-admin.php` | **Modify** | Call the action handler and the table renderer from `indivisible_newsletter_render_settings_page`. |
| `tests/test-reprocess.php` | **Create** | PHPUnit tests for `indivisible_newsletter_reprocess_post` (error codes, happy path, invariants). |
| `tests/test-processor-extended.php` | **Modify** | Add three tests for the new meta writes in `create_post_from_email`. |
| `tests/test-admin-settings.php` | **Modify** | Add tests for the table renderer and action handler. |

All test files extend `IN_Test_Case` (available via `class-in-test-case.php`), which provides `assertHtml()` via the shared `AssertHtmlTrait`.

---

## Task 1: Create `class-in-reprocess.php` skeleton and wire into plugin

**Files:**
- Create: `src/includes/class-in-reprocess.php`
- Modify: `src/indivisible-newsletter.php` (add require_once)
- Test: `tests/test-reprocess.php` (create)

**Goal:** Create the feature file, its test file, and the include wiring. One trivial failing test forces the file into existence.

- [ ] **Step 1.1: Create the test file with a smoke test**

Create `tests/test-reprocess.php`:

```php
<?php
/**
 * Tests for the newsletter reprocess feature.
 *
 * @package Indivisible_Newsletter
 */

class Test_IN_Reprocess extends IN_Test_Case {

    public function test_reprocess_function_exists(): void {
        $this->assertTrue(
            function_exists( 'indivisible_newsletter_reprocess_post' ),
            'indivisible_newsletter_reprocess_post() must be defined'
        );
    }
}
```

- [ ] **Step 1.2: Run the test to verify it fails**

```bash
./run-test.sh newsletter test_reprocess_function_exists
```

Expected: FAIL with "indivisible_newsletter_reprocess_post() must be defined" or similar.

- [ ] **Step 1.3: Create the stub file**

Create `src/includes/class-in-reprocess.php`:

```php
<?php
/**
 * Newsletter reprocess feature: reprocess existing newsletter posts
 * by re-running the current cleaner against their stored raw email body.
 *
 * @package Indivisible_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Re-run the current cleaner against a newsletter post's stored raw email body
 * and update the post content in place.
 *
 * @param int $post_id Post ID to reprocess.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function indivisible_newsletter_reprocess_post( int $post_id ) {
    return new WP_Error( 'not_implemented', 'Reprocess not yet implemented.' );
}
```

- [ ] **Step 1.4: Wire the file into the plugin**

Modify `src/indivisible-newsletter.php` at line 35 (after the existing `require_once` for `class-in-cron.php`), add:

```php
require_once IN_PLUGIN_DIR . 'includes/class-in-reprocess.php';
```

- [ ] **Step 1.5: Run the test to verify it passes**

```bash
./run-test.sh newsletter test_reprocess_function_exists
```

Expected: PASS.

- [ ] **Step 1.6: Run full suite to confirm no regressions**

```bash
./run-tests.sh newsletter
```

Expected: all 260+ tests pass (259 pre-existing + 1 new).

- [ ] **Step 1.7: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-reprocess.php src/indivisible-newsletter.php tests/test-reprocess.php
git commit -m "$(cat <<'EOF'
Scaffold newsletter reprocess feature file

Add empty class-in-reprocess.php with a stub reprocess_post() that returns
WP_Error('not_implemented'). Wire it into the main plugin via require_once.
Add a smoke test asserting the function is defined.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Store `_in_newsletter_raw_body` post meta on creation

**Files:**
- Modify: `src/includes/class-in-processor.php` (add meta write in `create_post_from_email`)
- Test: `tests/test-processor-extended.php` (add test)

**Goal:** When a newsletter post is created, store its pre-clean HTML as post meta so reprocess can use it.

- [ ] **Step 2.1: Write the failing test**

Append to the `clean_html` section of `tests/test-processor-extended.php` (class `Test_IN_Processor_Extended`):

```php
public function test_create_post_stores_raw_body_meta(): void {
    $raw_html = '<table class="nl-container" style="background-color: #0068a5">Content</table>';
    $email = array(
        'subject'    => 'Test Newsletter',
        'html'       => $raw_html,
        'date'       => '2026-04-12',
        'message_id' => '<test@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored_raw = get_post_meta( $post_id, '_in_newsletter_raw_body', true );
    $this->assertSame(
        $raw_html,
        $stored_raw,
        'Post meta _in_newsletter_raw_body should equal the pre-clean HTML'
    );
}
```

- [ ] **Step 2.2: Run test to verify it fails**

```bash
./run-test.sh newsletter test_create_post_stores_raw_body_meta
```

Expected: FAIL — `_in_newsletter_raw_body` meta is empty or missing.

- [ ] **Step 2.3: Implement the meta write**

In `src/includes/class-in-processor.php`, inside `indivisible_newsletter_create_post_from_email`, line ~66 (right after the `extract_forwarded_content` call and BEFORE the `clean_html` call), capture the raw body in a local variable:

```php
    // Extract the original newsletter content (strips forwarding wrapper if present).
    $html = indivisible_newsletter_extract_forwarded_content($email['html']);

    // Capture the post-extract, pre-clean HTML for the reprocess feature.
    $raw_body = $html;

    // Clean the HTML.
    $html = indivisible_newsletter_clean_html($html);
```

Then after line 109 (after the existing `update_post_meta($post_id, '_login_required', '1')`), add:

```php
    // Store the original pre-clean HTML so the reprocess feature can re-run
    // the current cleaner against it without re-fetching from IMAP.
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw_body );
```

- [ ] **Step 2.4: Run test to verify it passes**

```bash
./run-test.sh newsletter test_create_post_stores_raw_body_meta
```

Expected: PASS.

- [ ] **Step 2.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 2.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-processor.php tests/test-processor-extended.php
git commit -m "$(cat <<'EOF'
Store _in_newsletter_raw_body meta on newsletter post creation

Capture the post-extract, pre-clean HTML when creating a post from an email
and store it as post meta. Enables the upcoming reprocess feature to re-run
the current cleaner against the original email body without re-fetching
from IMAP.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Store `_in_newsletter_message_id` post meta

**Files:**
- Modify: `src/includes/class-in-processor.php`
- Test: `tests/test-processor-extended.php`

**Goal:** Store the source email's Message-ID on the post so it can be displayed in the Recent Newsletters table and cross-referenced later.

- [ ] **Step 3.1: Write the failing test**

Append to `Test_IN_Processor_Extended` in `tests/test-processor-extended.php`:

```php
public function test_create_post_stores_message_id_meta(): void {
    $email = array(
        'subject'    => 'Test Newsletter',
        'html'       => '<p>Body</p>',
        'date'       => '2026-04-12',
        'message_id' => '<abc123@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored = get_post_meta( $post_id, '_in_newsletter_message_id', true );
    $this->assertSame(
        '<abc123@example.com>',
        $stored,
        'Post meta _in_newsletter_message_id should equal $email["message_id"]'
    );
}
```

- [ ] **Step 3.2: Run test to verify it fails**

```bash
./run-test.sh newsletter test_create_post_stores_message_id_meta
```

Expected: FAIL — meta empty.

- [ ] **Step 3.3: Implement**

In `src/includes/class-in-processor.php`, `create_post_from_email`, directly after the raw body meta write added in Task 2:

```php
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw_body );

    // Store the source email's Message-ID so the reprocess feature UI can
    // display it and so it can be cross-referenced against the processed-IDs option.
    if ( ! empty( $email['message_id'] ) ) {
        update_post_meta( $post_id, '_in_newsletter_message_id', $email['message_id'] );
    }
```

- [ ] **Step 3.4: Run test to verify it passes**

```bash
./run-test.sh newsletter test_create_post_stores_message_id_meta
```

Expected: PASS.

- [ ] **Step 3.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 3.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-processor.php tests/test-processor-extended.php
git commit -m "$(cat <<'EOF'
Store _in_newsletter_message_id meta on newsletter post creation

Stamps the source email's Message-ID onto the created post as meta so the
upcoming Recent Newsletters table can display it and cross-reference
against the processed-IDs option.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Store `_in_newsletter_raw_subject` post meta

**Files:**
- Modify: `src/includes/class-in-processor.php`
- Test: `tests/test-processor-extended.php`

**Goal:** Store the unmodified email subject (before `clean_subject` strips `Fwd:`/`Re:` prefixes) so the Recent Newsletters table can show forwarded vs. direct delivery.

- [ ] **Step 4.1: Write the failing test**

Append to `Test_IN_Processor_Extended`:

```php
public function test_create_post_stores_raw_subject_meta(): void {
    $email = array(
        'subject'    => 'Fwd: April General Assembly Recap',
        'html'       => '<p>Body</p>',
        'date'       => '2026-04-12',
        'message_id' => '<id@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored = get_post_meta( $post_id, '_in_newsletter_raw_subject', true );
    $this->assertSame(
        'Fwd: April General Assembly Recap',
        $stored,
        'Post meta _in_newsletter_raw_subject should equal the unmodified $email["subject"]'
    );
}
```

- [ ] **Step 4.2: Run test to verify it fails**

```bash
./run-test.sh newsletter test_create_post_stores_raw_subject_meta
```

Expected: FAIL — meta empty.

- [ ] **Step 4.3: Implement**

In `src/includes/class-in-processor.php`, `create_post_from_email`, directly after the Message-ID meta write from Task 3:

```php
    if ( ! empty( $email['message_id'] ) ) {
        update_post_meta( $post_id, '_in_newsletter_message_id', $email['message_id'] );
    }

    // Store the unmodified subject (pre-clean_subject) so the Recent Newsletters
    // table can distinguish "Fwd:" forwards from direct deliveries at a glance.
    if ( ! empty( $email['subject'] ) ) {
        update_post_meta( $post_id, '_in_newsletter_raw_subject', $email['subject'] );
    }
```

- [ ] **Step 4.4: Run test to verify it passes**

```bash
./run-test.sh newsletter test_create_post_stores_raw_subject_meta
```

Expected: PASS.

- [ ] **Step 4.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 4.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-processor.php tests/test-processor-extended.php
git commit -m "$(cat <<'EOF'
Store _in_newsletter_raw_subject meta on newsletter post creation

Stamps the unmodified email subject (pre-clean_subject) onto the post as
meta. Displayed in the upcoming Recent Newsletters table so forwarded
('Fwd: ...') copies are distinguishable from direct deliveries.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: reprocess_post — validation error branches

**Files:**
- Modify: `src/includes/class-in-reprocess.php`
- Test: `tests/test-reprocess.php`

**Goal:** Make `indivisible_newsletter_reprocess_post` return the three documented `WP_Error` codes for its validation failures: `reprocess_not_found`, `reprocess_forbidden`, `reprocess_no_raw_body`.

- [ ] **Step 5.1: Write three failing tests**

Append to `Test_IN_Reprocess` in `tests/test-reprocess.php`:

```php
public function test_reprocess_returns_not_found_error_when_post_missing(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

    $result = indivisible_newsletter_reprocess_post( 999999 );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'reprocess_not_found', $result->get_error_code() );
}

public function test_reprocess_returns_forbidden_error_when_user_lacks_capability(): void {
    $subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
    wp_set_current_user( $subscriber );
    $post_id = $this->factory->post->create();

    $result = indivisible_newsletter_reprocess_post( $post_id );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'reprocess_forbidden', $result->get_error_code() );
}

public function test_reprocess_returns_no_raw_body_error_when_meta_missing(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $post_id = $this->factory->post->create();
    // Deliberately do not set _in_newsletter_raw_body meta.

    $result = indivisible_newsletter_reprocess_post( $post_id );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'reprocess_no_raw_body', $result->get_error_code() );
}
```

- [ ] **Step 5.2: Run the three new tests to verify they fail**

```bash
./run-test.sh newsletter "test_reprocess_returns_not_found_error_when_post_missing|test_reprocess_returns_forbidden_error_when_user_lacks_capability|test_reprocess_returns_no_raw_body_error_when_meta_missing"
```

Expected: all three FAIL (current stub returns `not_implemented`, not the expected codes).

- [ ] **Step 5.3: Implement the validation branches**

Replace the body of `indivisible_newsletter_reprocess_post` in `src/includes/class-in-reprocess.php` with:

```php
function indivisible_newsletter_reprocess_post( int $post_id ) {
    $post = get_post( $post_id );
    if ( null === $post ) {
        return new WP_Error(
            'reprocess_not_found',
            sprintf( 'Post %d does not exist.', $post_id )
        );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error(
            'reprocess_forbidden',
            'You do not have permission to reprocess newsletters.'
        );
    }

    $raw_body = get_post_meta( $post_id, '_in_newsletter_raw_body', true );
    if ( empty( $raw_body ) ) {
        return new WP_Error(
            'reprocess_no_raw_body',
            "This post doesn't have the original email stored. Only newsletters created after the reprocess feature shipped can be reprocessed."
        );
    }

    // Happy path implemented in Task 6.
    return new WP_Error( 'not_implemented', 'Reprocess happy path not yet implemented.' );
}
```

- [ ] **Step 5.4: Run the three tests to verify they pass**

```bash
./run-test.sh newsletter "test_reprocess_returns_not_found_error_when_post_missing|test_reprocess_returns_forbidden_error_when_user_lacks_capability|test_reprocess_returns_no_raw_body_error_when_meta_missing"
```

Expected: all three PASS.

- [ ] **Step 5.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 5.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-reprocess.php tests/test-reprocess.php
git commit -m "$(cat <<'EOF'
Add validation branches to reprocess_post

Return typed WP_Error codes for the three documented validation failures:
missing post, missing manage_options capability, and missing raw body meta.
Happy path still returns not_implemented pending Task 6.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: reprocess_post — happy path

**Files:**
- Modify: `src/includes/class-in-reprocess.php`
- Test: `tests/test-reprocess.php`

**Goal:** When validation passes, run the cleaner pipeline against the stored raw body and update the post content via `wp_update_post`.

- [ ] **Step 6.1: Write the failing test**

Append to `Test_IN_Reprocess`:

```php
public function test_reprocess_updates_post_content_with_cleaned_raw_body(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

    $raw_body = '<table class="nl-container" style="background-color: #0068a5"><tr><td>Hello</td></tr></table>';
    $post_id  = $this->factory->post->create( array( 'post_content' => 'OLD CONTENT' ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw_body );

    $result = indivisible_newsletter_reprocess_post( $post_id );

    $this->assertTrue( $result, 'Reprocess should return true on success' );

    $post = get_post( $post_id );
    $this->assertStringNotContainsString( 'OLD CONTENT', $post->post_content );
    $this->assertStringContainsString( 'in-newsletter-content', $post->post_content );
    $this->assertStringContainsString( 'Hello', $post->post_content );
    $this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
}
```

- [ ] **Step 6.2: Run test to verify it fails**

```bash
./run-test.sh newsletter test_reprocess_updates_post_content_with_cleaned_raw_body
```

Expected: FAIL — reprocess still returns `not_implemented`.

- [ ] **Step 6.3: Implement the happy path**

In `src/includes/class-in-reprocess.php`, replace the trailing `return new WP_Error( 'not_implemented', ... )` line with:

```php
    // Happy path: run the current cleaner against the stored raw body and
    // update the post content via wp_update_post (which auto-creates a revision).
    $cleaned = indivisible_newsletter_clean_html( $raw_body );
    $cleaned = wp_kses_post( $cleaned );
    $wrapped = '<div class="in-newsletter-content">' . $cleaned . '</div>';
    $content = "<!-- wp:html -->\n" . $wrapped . "\n<!-- /wp:html -->";

    $update_result = wp_update_post(
        array(
            'ID'           => $post_id,
            'post_content' => $content,
        ),
        true
    );

    if ( is_wp_error( $update_result ) ) {
        return $update_result;
    }

    return true;
}
```

- [ ] **Step 6.4: Run the new test to verify it passes**

```bash
./run-test.sh newsletter test_reprocess_updates_post_content_with_cleaned_raw_body
```

Expected: PASS.

- [ ] **Step 6.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 6.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-reprocess.php tests/test-reprocess.php
git commit -m "$(cat <<'EOF'
Implement reprocess_post happy path

Run the current cleaner against the stored raw body, sanitize via
wp_kses_post, wrap in the standard .in-newsletter-content div and
Gutenberg HTML block, and update the post via wp_update_post. WordPress
auto-creates a revision so the pre-reprocess content is recoverable.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: reprocess_post — invariants (idempotence, metadata preservation, revision)

**Files:**
- Test: `tests/test-reprocess.php`

**Goal:** Lock in three behavioral invariants as regression guards. No production code should need to change — these tests document what the current code already does.

- [ ] **Step 7.1: Write the three invariant tests**

Append to `Test_IN_Reprocess`:

```php
public function test_reprocess_is_idempotent(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $raw = '<table class="nl-container" style="background-color: #fff">X</table>';
    $post_id = $this->factory->post->create();
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );

    indivisible_newsletter_reprocess_post( $post_id );
    $first = get_post( $post_id )->post_content;

    indivisible_newsletter_reprocess_post( $post_id );
    $second = get_post( $post_id )->post_content;

    $this->assertSame(
        $first,
        $second,
        'Two reprocess calls should produce identical post_content'
    );
    $this->assertSame(
        $raw,
        get_post_meta( $post_id, '_in_newsletter_raw_body', true ),
        'Raw body meta should never change after reprocess'
    );
}

public function test_reprocess_preserves_post_metadata(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $raw = '<p>Body</p>';
    $author_id = $this->factory->user->create( array( 'role' => 'editor' ) );
    $post_id = $this->factory->post->create( array(
        'post_title'  => 'Original Title',
        'post_date'   => '2026-01-15 10:00:00',
        'post_status' => 'publish',
        'post_author' => $author_id,
    ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );
    update_post_meta( $post_id, '_login_required', '1' );

    indivisible_newsletter_reprocess_post( $post_id );

    $post = get_post( $post_id );
    $this->assertSame( 'Original Title', $post->post_title );
    $this->assertSame( '2026-01-15 10:00:00', $post->post_date );
    $this->assertSame( 'publish', $post->post_status );
    $this->assertEquals( $author_id, $post->post_author );
    $this->assertSame( '1', get_post_meta( $post_id, '_login_required', true ) );
}

public function test_reprocess_creates_revision(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $raw = '<p>Body</p>';
    $post_id = $this->factory->post->create( array( 'post_content' => 'ORIGINAL' ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );
    $revisions_before = count( wp_get_post_revisions( $post_id ) );

    indivisible_newsletter_reprocess_post( $post_id );

    $revisions_after = count( wp_get_post_revisions( $post_id ) );
    $this->assertGreaterThan(
        $revisions_before,
        $revisions_after,
        'wp_update_post should create a revision so the pre-reprocess content is recoverable'
    );
}
```

- [ ] **Step 7.2: Run the three tests**

```bash
./run-test.sh newsletter "test_reprocess_is_idempotent|test_reprocess_preserves_post_metadata|test_reprocess_creates_revision"
```

Expected: all three PASS on the first run (these are regression guards against existing Task 6 code, not driving new behavior). If any fail, investigate before continuing.

- [ ] **Step 7.3: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 7.4: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add tests/test-reprocess.php
git commit -m "$(cat <<'EOF'
Add reprocess invariant tests (idempotence, metadata preservation, revision)

Lock in three behavioral invariants as regression guards: reprocess is
idempotent (two calls produce identical content and leave raw_body meta
untouched), it preserves post_title/date/status/author and _login_required
meta, and wp_update_post creates a revision so the pre-reprocess content is
recoverable through the admin Revisions UI.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Recent Newsletters table renderer

**Files:**
- Modify: `src/includes/class-in-reprocess.php`
- Test: `tests/test-admin-settings.php`

**Goal:** Add a function that renders the Recent Newsletters table as an HTML string and returns it. Driven by three tests: rows appear for meta-stamped posts, legacy posts are excluded, 90-day window is honored.

- [ ] **Step 8.1: Write the three failing tests**

Append to the appropriate test class in `tests/test-admin-settings.php` (it already uses `AssertHtmlTrait`):

```php
public function test_recent_newsletters_table_shows_posts_with_raw_body_meta(): void {
    // Create one meta-stamped post and one plain post.
    $with_meta = $this->factory->post->create( array(
        'post_title' => 'Meta Newsletter',
        'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );
    update_post_meta( $with_meta, '_in_newsletter_raw_body', '<p>Body</p>' );

    $without_meta = $this->factory->post->create( array(
        'post_title' => 'Plain Post',
        'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters tbody tr' )
        ->count( 1 );
    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters' )
        ->containsText( 'Meta Newsletter' );
    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters' )
        ->doesNotContainText( 'Plain Post' );
}

public function test_recent_newsletters_table_limits_to_90_days(): void {
    $recent = $this->factory->post->create( array(
        'post_title' => 'Recent Newsletter',
        'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
    ) );
    update_post_meta( $recent, '_in_newsletter_raw_body', '<p>Body</p>' );

    $old = $this->factory->post->create( array(
        'post_title' => 'Old Newsletter',
        'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
    ) );
    update_post_meta( $old, '_in_newsletter_raw_body', '<p>Body</p>' );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters' )
        ->containsText( 'Recent Newsletter' );
    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters' )
        ->doesNotContainText( 'Old Newsletter' );
}

public function test_recent_newsletters_table_includes_reprocess_button_with_nonce(): void {
    $post_id = $this->factory->post->create( array(
        'post_title' => 'Test',
        'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', '<p>Body</p>' );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters button[name="in_reprocess"]' )
        ->exists();
    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters input[name="_wpnonce"]' )
        ->exists();
    $this->assertHtml( $html )
        ->find( 'table.in-recent-newsletters input[name="in_reprocess_post_id"]' )
        ->hasAttribute( 'value', (string) $post_id );
}
```

- [ ] **Step 8.2: Run the three tests to verify they fail**

```bash
./run-test.sh newsletter "test_recent_newsletters_table_shows_posts_with_raw_body_meta|test_recent_newsletters_table_limits_to_90_days|test_recent_newsletters_table_includes_reprocess_button_with_nonce"
```

Expected: FAIL with "Call to undefined function indivisible_newsletter_render_recent_newsletters_section()".

- [ ] **Step 8.3: Implement the renderer**

Append to `src/includes/class-in-reprocess.php`:

```php
/**
 * Render the "Recent Newsletters" section of the settings page as an HTML
 * string. Returns the markup so callers can echo it or pass it to tests.
 *
 * Lists newsletter-origin posts from the last 90 days that have the
 * _in_newsletter_raw_body meta. Each row offers View and Reprocess buttons.
 *
 * @return string HTML markup.
 */
function indivisible_newsletter_render_recent_newsletters_section(): string {
    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 50,
        'date_query'     => array(
            array(
                'column' => 'post_date',
                'after'  => '90 days ago',
            ),
        ),
        'meta_key'       => '_in_newsletter_raw_body',
        'meta_compare'   => 'EXISTS',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    ob_start();
    ?>
    <hr />
    <h2>Recent Newsletters</h2>
    <?php if ( empty( $posts ) ) : ?>
        <p class="description">No newsletter posts from the last 90 days. Posts created before the reprocess feature shipped are not shown.</p>
    <?php else : ?>
        <table class="widefat striped in-recent-newsletters">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Message-ID</th>
                    <th>Raw Subject</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $posts as $post ) : ?>
                    <?php
                    $message_id  = (string) get_post_meta( $post->ID, '_in_newsletter_message_id', true );
                    $raw_subject = (string) get_post_meta( $post->ID, '_in_newsletter_raw_subject', true );
                    $mid_display = strlen( $message_id ) > 40 ? substr( $message_id, 0, 40 ) . '…' : $message_id;
                    $sub_display = strlen( $raw_subject ) > 60 ? substr( $raw_subject, 0, 60 ) . '…' : $raw_subject;
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                                <?php echo esc_html( $post->post_title ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( get_the_date( 'Y-m-d', $post ) ); ?></td>
                        <td><code><?php echo esc_html( $mid_display ); ?></code></td>
                        <td><?php echo esc_html( $sub_display ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="button button-small">View</a>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'in_reprocess_action_' . $post->ID ); ?>
                                <input type="hidden" name="in_reprocess_post_id" value="<?php echo esc_attr( $post->ID ); ?>">
                                <button type="submit" name="in_reprocess" class="button button-small"
                                        onclick="return confirm('Reprocessing will replace the current content with a fresh clean of the original email. Any manual edits will be moved to a post revision. Continue?');">
                                    Reprocess
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}
```

- [ ] **Step 8.4: Run the three tests to verify they pass**

```bash
./run-test.sh newsletter "test_recent_newsletters_table_shows_posts_with_raw_body_meta|test_recent_newsletters_table_limits_to_90_days|test_recent_newsletters_table_includes_reprocess_button_with_nonce"
```

Expected: all three PASS.

- [ ] **Step 8.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 8.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-reprocess.php tests/test-admin-settings.php
git commit -m "$(cat <<'EOF'
Render Recent Newsletters table with per-row Reprocess buttons

Adds indivisible_newsletter_render_recent_newsletters_section() which
queries meta-stamped posts from the last 90 days and renders them as a
widefat striped table with Title, Date, Message-ID, Raw Subject, and
per-row View/Reprocess action buttons. Each Reprocess form has a per-post
nonce. Empty state shows a friendly description.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Reprocess action handler

**Files:**
- Modify: `src/includes/class-in-reprocess.php`
- Test: `tests/test-admin-settings.php`

**Goal:** Add a function that handles the POSTed Reprocess form submission, validates the nonce, calls `reprocess_post`, and returns an admin notice HTML string.

- [ ] **Step 9.1: Write the failing tests**

Append to `tests/test-admin-settings.php`:

```php
public function test_reprocess_action_handler_rejects_missing_nonce(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $post_id = $this->factory->post->create( array( 'post_content' => 'ORIGINAL' ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', '<p>Body</p>' );

    // No nonce in $_POST.
    $_POST = array(
        'in_reprocess'         => '1',
        'in_reprocess_post_id' => (string) $post_id,
    );

    $notice = indivisible_newsletter_handle_reprocess_action();

    $this->assertSame( '', $notice, 'Handler should return empty string when nonce is missing' );
    $this->assertSame( 'ORIGINAL', get_post( $post_id )->post_content, 'Post content must not change without valid nonce' );

    $_POST = array();
}

public function test_reprocess_action_handler_returns_success_notice_on_valid_request(): void {
    wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
    $post_id = $this->factory->post->create( array( 'post_title' => 'Newsletter XYZ' ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', '<p>Body</p>' );

    $_POST = array(
        'in_reprocess'         => '1',
        'in_reprocess_post_id' => (string) $post_id,
        '_wpnonce'             => wp_create_nonce( 'in_reprocess_action_' . $post_id ),
    );
    $_REQUEST = $_POST;

    $notice = indivisible_newsletter_handle_reprocess_action();

    $this->assertHtml( $notice )->find( 'div.notice-success' )->exists();
    $this->assertHtml( $notice )->find( 'div.notice-success' )->containsText( 'Newsletter XYZ' );
    $this->assertHtml( $notice )->find( 'div.notice-success a' )->exists();

    $_POST    = array();
    $_REQUEST = array();
}
```

- [ ] **Step 9.2: Run the tests to verify they fail**

```bash
./run-test.sh newsletter "test_reprocess_action_handler_rejects_missing_nonce|test_reprocess_action_handler_returns_success_notice_on_valid_request"
```

Expected: FAIL with "Call to undefined function indivisible_newsletter_handle_reprocess_action()".

- [ ] **Step 9.3: Implement the handler**

Append to `src/includes/class-in-reprocess.php`:

```php
/**
 * Handle a Reprocess form submission from the settings page.
 *
 * Returns an admin notice HTML string (success or error) that the caller
 * can echo at the top of the settings page. Returns empty string when the
 * form was not submitted or the nonce is missing/invalid.
 *
 * @return string Admin notice HTML, or '' if no action was performed.
 */
function indivisible_newsletter_handle_reprocess_action(): string {
    if ( ! isset( $_POST['in_reprocess'] ) || ! isset( $_POST['in_reprocess_post_id'] ) ) {
        return '';
    }

    $post_id = absint( $_POST['in_reprocess_post_id'] );
    if ( 0 === $post_id ) {
        return '';
    }

    // check_admin_referer dies on failure by default; use the non-die variant
    // wp_verify_nonce so the test harness can assert "no action taken" without
    // triggering wp_die.
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'in_reprocess_action_' . $post_id ) ) {
        return '';
    }

    $result = indivisible_newsletter_reprocess_post( $post_id );

    if ( is_wp_error( $result ) ) {
        return '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
    }

    $post      = get_post( $post_id );
    $title     = $post ? $post->post_title : '(unknown)';
    $permalink = $post ? get_permalink( $post_id ) : '#';

    return sprintf(
        '<div class="notice notice-success"><p>Reprocessed: <strong>%s</strong> — <a href="%s" target="_blank">view post</a></p></div>',
        esc_html( $title ),
        esc_url( $permalink )
    );
}
```

- [ ] **Step 9.4: Run the tests to verify they pass**

```bash
./run-test.sh newsletter "test_reprocess_action_handler_rejects_missing_nonce|test_reprocess_action_handler_returns_success_notice_on_valid_request"
```

Expected: both PASS.

- [ ] **Step 9.5: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass.

- [ ] **Step 9.6: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-reprocess.php tests/test-admin-settings.php
git commit -m "$(cat <<'EOF'
Add reprocess POST action handler returning admin notice HTML

Adds indivisible_newsletter_handle_reprocess_action() which validates the
per-post nonce, calls reprocess_post, and returns a success or error admin
notice HTML string. Empty string when not submitted or nonce invalid.
Caller (settings page renderer in Task 10) echoes the returned HTML.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Wire the section and handler into the settings page

**Files:**
- Modify: `src/includes/class-in-admin.php`
- Test: none new (integration verified by existing tests + manual QA in Task 11)

**Goal:** Call the action handler and the table renderer from `indivisible_newsletter_render_settings_page` so the feature becomes visible to users.

- [ ] **Step 10.1: Integrate the handler and renderer**

In `src/includes/class-in-admin.php`, inside `indivisible_newsletter_render_settings_page`:

**(a) After the existing Diagnose action handler (around line 280), add the reprocess handler call:**

```php
    // Handle Reprocess action submissions.
    $reprocess_notice = indivisible_newsletter_handle_reprocess_action();
    if ( '' !== $reprocess_notice ) {
        echo $reprocess_notice; // Safely constructed in handler; contains pre-escaped title and URL.
    }
```

**(b) After the existing Actions section (after line 315, after the closing `</div>` for the Actions flexbox and any diagnose output, and before the Reliable Scheduling `<hr />`), add:**

```php
    echo indivisible_newsletter_render_recent_newsletters_section();
```

- [ ] **Step 10.2: Run full suite**

```bash
./run-tests.sh newsletter
```

Expected: all tests pass. No new tests in this task — integration is covered by manual QA (Task 11).

- [ ] **Step 10.3: Commit**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/includes/class-in-admin.php
git commit -m "$(cat <<'EOF'
Wire reprocess handler and Recent Newsletters section into settings page

Calls indivisible_newsletter_handle_reprocess_action() early in the page
renderer to emit any success/error notice, and indivisible_newsletter_render_recent_newsletters_section()
below the Actions flexbox. Feature is now visible in the admin.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Manual QA

**Files:** none

**Goal:** Verify end-to-end behavior in the local WordPress environment. Not automatable from within PHPUnit; requires a real admin session and a real newsletter post.

- [ ] **Step 11.1: Deploy to local WordPress**

```bash
cd /Users/mike/indivisible-newsletter-plugin && ./quick-deploy.sh
```

Expected: deploy succeeds.

- [ ] **Step 11.2: Bump version**

Edit `src/indivisible-newsletter.php` line 5, change `Version: 1.1.5` to `Version: 1.1.6`. Redeploy:

```bash
cd /Users/mike/indivisible-newsletter-plugin && ./quick-deploy.sh
```

Confirm via WP-CLI:

```bash
docker-compose exec -T wordpress wp --allow-root plugin status indivisible-newsletter | grep Version
```

Expected: `Version: 1.1.6`.

- [ ] **Step 11.3: Reload the settings page and verify the table**

Open the browser to `https://<hostname>.local/wp-admin/options-general.php?page=indivisible-newsletter`. Scroll to the new "Recent Newsletters" section.

Expected: the table shows the two posts created by the Part-A reprocessing cycle (the replacements for 3380 and 3381), each with Title, Date, truncated Message-ID, Raw Subject, and View/Reprocess buttons.

If the table is empty: check that the posts were created after Task 2 deployed (they need `_in_newsletter_raw_body` meta). If they pre-date Task 2, Part A needs to be re-run so fresh posts get the meta.

- [ ] **Step 11.4: Click Reprocess on one row**

Click the Reprocess button on one row. Expected:
1. Confirmation dialog fires with the Q5b warning text.
2. After confirming, the settings page reloads.
3. A green admin notice at the top reads "Reprocessed: <title> — view post" with a clickable link.
4. Clicking the "view post" link opens the frontend permalink in a new tab and shows the reprocessed content (visually identical to before, since the cleaner has not changed between runs).

- [ ] **Step 11.5: Edit a post manually then reprocess**

In a new tab, open the admin edit screen for one of the listed newsletter posts. Add a visible typo (e.g., "ZZZTYPO") to the content and save. Reload the post in the frontend and confirm the typo is visible.

Return to the settings page, reload, and click Reprocess on the same post. Confirm the dialog. Expected:
1. Success notice appears.
2. Reload the frontend — the ZZZTYPO is gone, replaced by freshly-cleaned content.
3. Open the admin edit screen for the post and click the "Revisions" link. The most recent revision should contain ZZZTYPO, proving the safety net works.

- [ ] **Step 11.6: Test an error branch**

Open the admin edit screen for a newsletter post and open the browser console. Run:

```javascript
// Find the first Reprocess form, change the post ID to a bogus one, submit.
const form = document.querySelector('button[name="in_reprocess"]').closest('form');
form.querySelector('input[name="in_reprocess_post_id"]').value = '999999';
form.submit();
```

Expected: red error notice at top of settings page with the `reprocess_not_found` message. No posts changed.

- [ ] **Step 11.7: Commit the version bump**

```bash
cd /Users/mike/indivisible-newsletter-plugin
git add src/indivisible-newsletter.php
git commit -m "$(cat <<'EOF'
Bump version to 1.1.6 for reprocess feature

Adds the Recent Newsletters admin UI, the _in_newsletter_raw_body /
_message_id / _raw_subject post meta storage at post creation, and the
reprocess_post / render / handler trio in class-in-reprocess.php.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Checklist

Before handing off to execution, verify:

- [ ] Every spec section (Data Model, Core Function, Settings UI, Tests) is covered by at least one task.
- [ ] No placeholders (`TBD`, `TODO`, `fill in later`) in any task.
- [ ] Every code step shows actual code, not a description of it.
- [ ] Function signatures used in later tasks match their definitions in earlier tasks (e.g., `indivisible_newsletter_reprocess_post`, `indivisible_newsletter_render_recent_newsletters_section`, `indivisible_newsletter_handle_reprocess_action` are consistent throughout).
- [ ] Each task ends with a commit.
- [ ] Manual QA task (Task 11) includes enough steps to exercise the confirmation dialog, the success path, the revision safety net, and an error branch.
- [ ] The `assertHtml` selectors in tests (e.g., `table.in-recent-newsletters button[name="in_reprocess"]`) match the rendered HTML in the implementation steps.
