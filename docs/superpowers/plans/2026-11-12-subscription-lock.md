# Subscription Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Readers must sign in (Google, LinkedIn, or email) to open a Corporate Book; subscribers read every page, everyone else reads the first 10% and then sees a lock with the author's Subscribe link.

**Architecture:** A new **Subscription** tab in Global Settings holds the subscribe link, preview percent, which WordPress roles count as subscribed, and the social-login shortcode. The shortcode decides access on the server: guests get a sign-in box, non-subscribers get a *preview PDF* (first 10% of pages, made in the author's browser with pdf-lib) plus lock data, subscribers get the full PDF. The viewer shows the lock overlay after the last preview page. The full PDF URL never reaches a non-subscriber's browser.

**Tech Stack:** existing plugin (PHP 7.4+, viewer JS, PDF.js), pdf-lib 1.17.1 (MIT, admin only), WordPress roles, any social-login plugin that offers a shortcode (for example Nextend Social Login), Playground + Playwright smoke test.

**Spec:** user request of 2026-11-12 (flow: Start, Check Status, subscribed: all pages; not subscribed: 10% then lock and Subscribe link, End).

## Global Constraints

- Settings keys (tab `subscription`): `subscription_enable` (`true`/`false`, default `false`), `subscription_url` (URL, saved with `esc_url_raw`), `subscription_preview_percent` (number 1-100, default `10`), `subscription_roles` (comma-separated role slugs, default empty), `subscription_login_shortcode` (text, default empty).
- New texts (Translate tab): `text_sign_in` "Sign in to read", `text_subscribe` "Subscribe to read the full book", `text_locked` "You have reached the end of the free preview."
- Per-book meta in `_corporate_book_data`: `subscription` (`global` | `required` | `free`, default `global`), `preview_pdf` (attachment ID).
- Preview page count: `max( 1, ceil( pages * percent / 100 ) )`.
- Access API: `Corporate_Book::user_has_access( int $book_id ): bool`, filter `corporate_book_user_is_subscribed( bool $subscribed, WP_User $user, int $book_id )`.
- Pages that show a locked book send `nocache_headers()` and define `DONOTCACHEPAGE`.

## Review Focus

1. Non-subscriber views page source or network log: the full PDF URL is absent.
2. Author lists role `subscriber` (WordPress gives it to every new account): settings page shows a warning.
3. `subscription_url` = `javascript:alert(1)`: saved as empty.
4. 3-page PDF at 10%: preview has 1 page.
5. Page cache plugin active: a guest never gets a subscriber's cached page.

---

### Task 1: Subscription settings tab

**Files:** Modify `3d-corporate-book.php` (defaults + labels), `inc/settings.php` (`$tab_keys['subscription']`, `sanitize_setting`).
- [ ] Tests in `tests/smoke.cjs`: `subscription settings persist` (URL, 10, `cb_member`); `unsafe subscribe link rejected` (`javascript:alert(1)` saves as empty); `subscriber role warning shown`.
- [ ] Sanitize: URL keys use `esc_url_raw`; percent clamped to 1-100; roles split on commas, each `sanitize_key`.
- [ ] Run `node tests/smoke.cjs`: all PASS. Commit.

### Task 2: Access check

**Files:** Modify `3d-corporate-book.php`; `tests/setup-plugin/corporate-book-test-setup.php` (role `cb_member`, users `member` and `reader`, password `password`).
- [ ] Implement `user_has_access( $book_id )`: lock off or book `free`: true. Guest: false. Else true when the user has a role in `subscription_roles`, then pass through the filter.
- [ ] Covered by Task 3 tests. Commit.

### Task 3: Shortcode gating and sign-in box

**Files:** Modify `inc/shortcode.php`.
- [ ] Tests: `guest sees sign-in box` (links to `wp_login_url` and `wp_registration_url`, login shortcode output); `reader gets preview only` (HTML has preview URL, not full URL); `member gets full book`; `locked page is not cacheable` (`Cache-Control` has `no-store` or `no-cache`).
- [ ] Guest: render sign-in box instead of the book (`text_sign_in`, login and register links with `redirect_to` = current page, `do_shortcode( subscription_login_shortcode )`).
- [ ] Non-subscriber: replace `source` with the preview PDF URL and add `lock: { url, text }` to the book options. No preview PDF yet: show the lock box only, never the full PDF.
- [ ] Run tests: PASS. Commit.

### Task 4: Lock overlay in the viewer

**Files:** Create `assets/js/corporate-book-lock.js` (small, separate from the minified bundle), enqueue only when a locked book is on the page.
- [ ] Test `reader sees lock after preview`: go to last page, `.cb-lock a[href="<subscription_url>"]` is visible.
- [ ] Find the viewer's page-change callback in `assets/js/corporate_book.js`; on the last preview page, show overlay `.cb-lock` with `text_locked` and a Subscribe button.
- [ ] Run tests: PASS. Commit.

### Task 5: Preview PDF and per-book setting

**Files:** Modify `inc/metaboxes.php`, `assets/js/metaboxes.js`; add `assets/js/libs/pdf-lib.min.js` (1.17.1).
- [ ] Test `preview has 10 percent of pages`: 10-page sample, click "Create preview", attachment PDF has 1 page.
- [ ] Metabox: Subscription select (`global`/`required`/`free`) and "Create preview" button. pdf-lib copies the first N pages, uploads with `wp.apiFetch` to `/wp/v2/media`, stores the ID in `preview_pdf`. Recreate on PDF change.
- [ ] Run tests: PASS. Commit.

### Task 6: Protected full PDF (only if chosen)

**Files:** Create `inc/protected-pdf.php`.
- [ ] Tests: `reader cannot fetch full PDF` (403); `member gets byte range` (206).
- [ ] Move locked books' PDFs to `uploads/corporate-book-protected/` (with `.htaccess` deny; nginx rule in readme); serve via `GET /wp-json/corporate-book/v1/pdf/<id>` with `Range` support after `user_has_access()`.
- [ ] Run tests: PASS. Commit.

### Task 7: Readme and security review

- [ ] Readme: Subscription section, setup steps (social-login plugin, roles, subscribe link), limits.
- [ ] Run the `security-review` skill on the branch; fix findings test-first. Commit, push.

## Skills and models

| Task | Skills | Model |
|---|---|---|
| Decisions | superpowers:brainstorming | Opus 5.5 |
| 1, 2, 7 readme | superpowers:test-driven-development | Sonnet 5.5 |
| 3, 4, 5 | superpowers:test-driven-development, example-skills:webapp-testing | Sonnet 5.5 |
| 6 and 7 review | security-review, superpowers:verification-before-completion | Opus 5.5 |
