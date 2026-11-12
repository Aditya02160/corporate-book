# Corporate Book Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `3d-corporate-book` 1.0.0, a working, GPL-compliant rebrand of the DearFlip lite WordPress plugin (3D/2D flipbook, PDF viewer, slider).

**Architecture:** Import pristine upstream DearFlip lite 2.4.37 onto branch `upstream`, then rebrand on the work branch as one reviewable diff. Change only what users see: name, slug, text domain, labels and readme. Internal identifiers stay upstream, because the bundled minified JS reads them and renaming them caused every break in the uploaded file. Future upstream security fixes arrive by merging `upstream`.

**Tech Stack:** PHP 7.4+ (8.3 local), WordPress 6.x, bundled DearFlip JS + PDF.js + three.js, WordPress Playground CLI (throwaway WP site, SQLite, no Docker), Playwright (preinstalled Chromium).

**Spec:** user uploads `readme.txt`, `3d-corporate-book.php`, `index.php` (chat of 2026-11-12). No separate spec.

## Global Constraints

- Slug, folder, text domain: `3d-corporate-book`. Main file: `3d-corporate-book.php`. Plugin Name: `Corporate Book`.
- Internal names stay upstream: class `DFlip`, option `_dflip_settings`, post type and shortcode `dflip`, handles `dflip-*`, element ids `dflip_*`, JS globals `dFlipLocation` / `dFlipWPGlobal` (confirm in Task 1).
- User-visible "DearFlip", "dFlip", "FlipBook" (labels, menus, notices, mail text, loading text, readme) become "Corporate Book". Share prefix default: `corporate-book-`.
- No spaces in any identifier, handle, file path, element id, option key or slug.
- Version `1.0.0`. License `GPLv2 or later`. Keep upstream copyright notices; add "Based on DearFlip lite by DearHive, modified by aditya (2026)".
- Readme lists only features present in this code; changelog starts at `= 1.0.0 =`.
- Never edit `assets/**/*.min.js`, PDF.js or three.js files.
- `index.php` stays as uploaded (directory-listing guard).

## Review Focus

1. Fresh install, no settings saved: viewer renders with defaults.
2. Admin text setting `"></script><img src=x onerror=alert(1)>`: no script runs, front end still works.
3. `[dflip id="999999"]` (unknown id): empty output, no PHP notice.
4. Two books on one page: both render.
5. Site also running real DearFlip: admin notice, no fatal error.

---

### Task 1: Import upstream (gate: needs source)

**Files:**
- Create: full upstream tree at repo root; `LICENSE` (GPLv2 text)

**Interfaces:**
- Produces: branch `upstream`, tag `upstream-2.4.37`; recorded JS global names, upstream text domain and PDF.js version (copy into the PR description).

- [ ] **Step 1:** Get source: the user's full plugin folder, or the wordpress.org zip (slug `3d-flipbook-dflip-lite`, version 2.4.37; confirm slug on wordpress.org).
- [ ] **Step 2:** Run `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`. Expected: every line `No syntax errors detected`.
- [ ] **Step 3:** Run `grep -ohE '\b[A-Za-z]*(Location|WPGlobal)\b' assets/js/*.js | sort | uniq -c`. Expected: the two globals the main PHP file prints.
- [ ] **Step 4:** Run `grep -rohE 'pdfjsVersion *= *"[0-9.]+"' assets/`. Record the version.
- [ ] **Step 5:** Commit on `upstream`: `chore: import DearFlip lite 2.4.37 (GPLv2+)`.

### Task 2: Smoke-test harness (red first)

**Files:**
- Create: `package.json` (devDependency `@wp-playground/cli` at an exact version; script `test`), `tests/blueprint.json`, `tests/smoke.spec.mjs`, `tests/fixtures/sample.pdf` (3 pages)

**Interfaces:**
- Produces: `npm test` starts Playground on `http://127.0.0.1:9400` with the plugin mounted at `wp-content/plugins/3d-corporate-book`, `WP_DEBUG` and `WP_DEBUG_DISPLAY` true, admin logged in, and page `/book-test/` holding two `[dflip id="<book>"]` and one `[dflip id="999999"]`.

- [ ] **Step 1: Write the tests (names exact)**
  - `activates without fatal`: Plugins screen shows "Corporate Book" active; body has no `Fatal error`.
  - `front end renders`: `/book-test/` has zero `pageerror` events, two viewer containers, a download control titled `Download PDF File`.
  - `settings persist`: global settings background color `#123456`, save, reload, field value is `#123456`.
  - `no php notices`: settings screen and `/book-test/` contain none of `Warning:`, `Notice:`, `Deprecated:`.
  - `xss setting inert`: Share text set to the Review Focus 2 payload; `/book-test/` fires no dialog and no `pageerror`.
  - `dearflip coexistence`: blueprint variant also installs upstream from wordpress.org; admin shows a notice naming DearFlip; no `Fatal error`.
- [ ] **Step 2:** Overlay the three uploaded files on the work branch and run `npm test`. Expected: FAIL `activates without fatal` (`PHP Parse error: syntax error, unexpected identifier "book", expecting "{"`, line 10).
- [ ] **Step 3:** Commit `test: add Playground smoke tests`.

### Task 3: Rebrand from pristine upstream

**Files:**
- Modify: upstream main file, renamed to `3d-corporate-book.php`; `inc/*.php` (text domain and visible strings only)

**Interfaces:**
- Consumes: Task 1 names. Produces: plugin passing every Task 2 test.

- [ ] **Step 1:** Replace the overlaid uploaded main file with upstream's. Set header: Plugin Name `Corporate Book`, Version `1.0.0`, Text Domain `3d-corporate-book`, Author `aditya`, License `GPLv2 or later`, Requires PHP `7.4`.
- [ ] **Step 2:** Text domain: `sed -i "s/'<upstream domain>'/'3d-corporate-book'/g"` on every PHP file, using the domain Task 1 recorded.
- [ ] **Step 3:** Visible strings per Global Constraints, including defaults `text_mail_subject`, `text_loading`, `share_prefix` and admin menu labels.
- [ ] **Step 4:** Diff the uploaded main file against upstream after mapping `corporate book` to `dflip`. Every other hunk is an accidental deletion (`$plugin_name` docblock at L29-41, `text_download_PDF_file` default near L90, `settings_help_page`); confirm none survives in the result.
- [ ] **Step 5:** Coexistence: before the class guard, if `class_exists( 'DFlip' )` is already true, hook an `admin_notices` message "Corporate Book is inactive while DearFlip is active." and return.
- [ ] **Step 6:** Run the `php -l` sweep and `npm test`. Expected: all PASS.
- [ ] **Step 7:** Commit `feat: rebrand DearFlip lite as Corporate Book 1.0.0`.

### Task 4: Security pass

**Files:**
- Modify: only where findings land; tests go in `tests/smoke.spec.mjs`

- [ ] **Step 1:** Review all of `inc/` with the `security-review` skill: shortcode attributes escaped on output, settings and metabox saves check a nonce plus `current_user_can`, every echo escaped.
- [ ] **Step 2:** PDF.js must be ≥ 4.2.67, or `isEvalSupported: false` must reach `getDocument` (CVE-2024-4367). Run `grep -rn isEvalSupported assets/`.
- [ ] **Step 3:** Per finding: failing test, fix, `npm test` PASS, commit `fix(security): <finding>`.

### Task 5: Readme + Plugin Check

**Files:**
- Modify: `readme.txt`, `tests/blueprint.json`

- [ ] **Step 1:** Headers: `=== Corporate Book ===`, Contributors, Tags (max 5), Requires at least `6.0`, Tested up to (WP version in the harness), Requires PHP `7.4`, Stable tag `1.0.0`, License `GPLv2 or later`, License URI, one-line description.
- [ ] **Step 2:** Remove the `Author: dearhive` line, the TOP-FEATURES list (premium features absent from this code), the empty bullet at L84 and the upstream changelog. Add `= 1.0.0 =` and the attribution line. Share prefix text reads `corporate-book-`.
- [ ] **Step 3:** Add blueprint step `wp plugin check 3d-corporate-book` (installs `plugin-check`). Expected: no `ERROR` rows.
- [ ] **Step 4:** Commit `docs: rewrite readme for Corporate Book`.

### Task 6: Package and ship

**Files:**
- Create: `.gitattributes` (`tests/ export-ignore`, `package*.json export-ignore`, `docs/ export-ignore`)

- [ ] **Step 1:** `git archive --prefix=3d-corporate-book/ -o 3d-corporate-book-1.0.0.zip HEAD`.
- [ ] **Step 2:** Blueprint variant installs the zip instead of mounting the folder; run `npm test`. Expected: all PASS.
- [ ] **Step 3:** Push `claude/intelligent-gates-fshkkp`; open a draft PR.

## Skills and models

| Step | Skills | Model |
|---|---|---|
| Open decisions | superpowers:brainstorming | Opus 5.5 (`claude-opus-5-5`) |
| Task 1 import | none (bash) | Haiku 5.5 (`claude-haiku-5-5`) |
| Task 2 tests | superpowers:test-driven-development, example-skills:webapp-testing | Sonnet 5.5 (`claude-sonnet-5-5`) |
| Task 3 rebrand | superpowers:test-driven-development, ultrapowers-dev:php-best-practices; superpowers:systematic-debugging if red | Sonnet 5.5 |
| Task 4 security | security-review (or the claude-security agent) | Opus 5.5 |
| Task 5 readme | none | Haiku 5.5 |
| Task 6 and final review | superpowers:verification-before-completion, code-review, superpowers:finishing-a-development-branch | Opus 5.5 |

## Option B (only if the target is the WordPress.org directory)

The directory requires unique prefixes: rename every internal name to `corporate_book` / `Corporate_Book` / `corporate-book`, including the admin JS that reads `dflip_*` ids. Front-end globals inside the minified bundle still stay. Adds one task, and directory review may still reject a pure rebrand.
