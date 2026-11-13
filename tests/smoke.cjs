// Smoke test: boots a throwaway WordPress (Playground) with this plugin mounted and checks the main flows.
// Run from the repo root: node tests/smoke.cjs   (first run downloads WordPress)
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');

// CORPORATE_BOOK_DIR tests another copy of the plugin, e.g. the unpacked release zip.
const ROOT = process.env.CORPORATE_BOOK_DIR || path.resolve(__dirname, '..');
const PORT = 9400;
const BASE = `http://127.0.0.1:${PORT}`;
const CLI = '@wp-playground/cli@3.1.64';
const PHP_ERRORS = /Fatal error|Warning:|Notice:|Deprecated:/;
const SUBSCRIBE_URL = 'https://example.com/subscribe';
const XSS = '"></script><img src=x onerror=alert(1)>';

// Minimal valid PDF with one line of text per page, so no binary fixture is needed.
function makePdf(pages) {
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', null, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
  const kids = [];
  for (let i = 1; i <= pages; i++) {
    const text = `BT /F1 48 Tf 72 700 Td (Page ${i}) Tj ET`;
    objs.push(`<< /Length ${text.length} >>\nstream\n${text}\nendstream`);
    objs.push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents ${objs.length} 0 R /Resources << /Font << /F1 3 0 R >> >> >>`);
    kids.push(`${objs.length} 0 R`);
  }
  objs[1] = `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${pages} >>`;
  let out = '%PDF-1.4\n';
  const offsets = objs.map((obj, i) => { const at = out.length; out += `${i + 1} 0 obj\n${obj}\nendobj\n`; return at; });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

async function waitFor(ready, ms) {
  for (const end = Date.now() + ms; Date.now() < end; await new Promise((r) => setTimeout(r, 1000))) {
    if (ready()) return;
  }
  throw new Error('WordPress did not start in time');
}

(async () => {
  const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'corporate-book-'));
  fs.writeFileSync(path.join(fixtures, 'sample.pdf'), makePdf(3));
  fs.writeFileSync(path.join(fixtures, 'preview.pdf'), makePdf(1));
  fs.writeFileSync(path.join(fixtures, 'ten.pdf'), makePdf(10));
  fs.writeFileSync(path.join(fixtures, 'bulk.pdf'), makePdf(2));
  fs.writeFileSync(path.join(fixtures, 'other.pdf'), makePdf(2));
  fs.writeFileSync(path.join(fixtures, 'auto.pdf'), makePdf(10));
  const wp = spawn('npx', ['-y', CLI, 'server', `--port=${PORT}`,
    `--mount=${ROOT}:/wordpress/wp-content/plugins/3d-corporate-book`,
    `--mount=${fixtures}:/wordpress/wp-content/uploads/cb-test`,
    `--mount=${path.join(__dirname, 'setup-plugin')}:/wordpress/wp-content/plugins/corporate-book-test-setup`,
    `--blueprint=${path.join(__dirname, 'blueprint.json')}`], { stdio: ['ignore', 'pipe', 'pipe'], detached: true });
  let serverLog = '';
  wp.stdout.on('data', (d) => { serverLog += d; });
  wp.stderr.on('data', (d) => { serverLog += d; });

  const failed = [];
  const check = (ok, name) => { console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`); if (!ok) failed.push(name); };
  let browser;
  try {
    await waitFor(() => serverLog.includes('Ready!'), 300000); // the port answers before WordPress is ready
    browser = await chromium.launch();
    const page = await browser.newPage();
    page.setDefaultTimeout(90000);
    const jsErrors = [];
    const missingAssets = [];
    let dialogShown = false;
    page.on('pageerror', (e) => jsErrors.push(e.message));
    // Accept "leave page?" prompts from edit screens; any other dialog means injected script ran.
    page.on('dialog', (d) => { if (d.type() === 'beforeunload') return d.accept(); dialogShown = true; return d.dismiss(); });
    page.on('response', (r) => { if (r.url().includes('3d-corporate-book') && r.status() >= 400) missingAssets.push(r.url()); });

    await page.goto(`${BASE}/wp-login.php`);
    if (await page.$('#user_login')) {
      await page.fill('#user_login', 'admin');
      await page.fill('#user_pass', 'password');
      await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    }

    let html = await (await page.goto(`${BASE}/wp-admin/plugins.php`)).text();
    check(await page.$('tr.active[data-plugin="3d-corporate-book/3d-corporate-book.php"]') !== null, 'plugin activates');
    check(!PHP_ERRORS.test(html), 'Plugins screen has no PHP errors');

    const settingsUrl = `${BASE}/wp-admin/edit.php?post_type=corporate_book&page=corporate_book-settings`;
    const field = (id) => page.$eval(`#corporate_book_${id}`, (e) => e.value).catch(() => null);
    // Playground's workers can serve a stale read right after a save, so reload until it shows up.
    const reloadUntil = async (url, ready) => {
      let body = '';
      for (let i = 0; i < 10; i++) {
        body = await (await page.goto(url)).text();
        if (await ready()) break;
        await page.waitForTimeout(500);
      }
      return body;
    };
    html = await (await page.goto(settingsUrl)).text();
    check(!PHP_ERRORS.test(html), 'Global Settings page has no PHP errors');
    check(await page.$('#corporate_book_zoom_ratio') !== null && await page.$('#corporate_book_text_share') !== null, 'TOP FEATURES fields are shown');
    check(await page.$('#corporate_book_enable_download') === null && await page.$('#corporate_book_enable_print') === null
      && await page.$('#corporate_book_text_download_PDF_file') === null
      && !/download|print/i.test(await field('more_controls') || ''), 'settings have no download or print options');
    await page.evaluate((xss) => {
      document.querySelector('#corporate_book_zoom_ratio').value = '2.5';
      document.querySelector('#corporate_book_scroll_wheel').value = 'true';
      document.querySelector('#corporate_book_text_share').value = xss;
    }, XSS);
    await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('[name="corporate_book_settings_submit"]').click())]);
    await reloadUntil(settingsUrl, async () => await field('zoom_ratio') === '2.5');
    check(await page.inputValue('#corporate_book_zoom_ratio') === '2.5', 'settings persist after save');

    // Subscription tab (plan Task 1).
    const saveSettings = async (values, expected = values) => {
      await page.goto(settingsUrl);
      await page.evaluate((v) => { for (const [id, val] of Object.entries(v)) document.querySelector(`#corporate_book_${id}`).value = val; }, values);
      await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('[name="corporate_book_settings_submit"]').click())]);
      return reloadUntil(settingsUrl, async () => {
        for (const [id, val] of Object.entries(expected)) if (await field(id) !== val) return false;
        return true;
      });
    };
    await saveSettings({ subscription_url: 'javascript:alert(1)' }, { subscription_url: '' }).catch(() => '');
    check(await field('subscription_url') === '', 'unsafe subscribe link rejected');
    html = await saveSettings({ subscription_roles: 'subscriber' }).catch(() => '');
    check(html.includes('cb-role-warning'), 'subscriber role warning shown');
    await saveSettings({ subscription_enable: 'true', subscription_url: SUBSCRIBE_URL, subscription_preview_percent: '10', subscription_roles: 'cb_member, Bad Role!' },
      { subscription_enable: 'true', subscription_url: SUBSCRIBE_URL, subscription_preview_percent: '10', subscription_roles: 'cb_member,badrole' }).catch(() => '');
    const got = [await field('subscription_enable'), await field('subscription_url'), await field('subscription_preview_percent'), await field('subscription_roles')].join(' ');
    check(got === `true ${SUBSCRIBE_URL} 10 cb_member,badrole`, `subscription settings persist (${got})`);

    jsErrors.length = 0;
    html = await reloadUntil(`${BASE}/?pagename=book-test`, () => page.evaluate(() => (window.corporateBookWPGlobal || {}).zoomRatio === 2.5));
    check(!PHP_ERRORS.test(html), 'front page has no PHP errors (includes unknown book id)');
    await page.waitForSelector('.df-container', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(5000);
    const found = `title=${(html.match(/<title>([^<]*)/) || [])[1]} _df_book=${(html.match(/_df_book/g) || []).length}`;
    check(await page.$$eval('.df-container', (e) => e.length) >= 1, `embedded book renders (${found})`);
    check(await page.$$eval('.df-ui-download, .df-ui-print, a[download]', (e) => e.length) === 0
      && !/downloadPDFFile|enableDownload|showPrintControl/.test(html), 'viewer has no download or print control');
    const viewerJs = await (await page.request.get(`${BASE}/wp-content/plugins/3d-corporate-book/assets/js/corporate_book.min.js`)).text();
    check(!/showDownloadControl|showPrintControl|printHandler|df-print-frame|downloadPDFFile|df-ui-download|canPrint/.test(viewerJs), 'viewer script has no download or print code');
    check(await page.$$eval('._df_thumb', (e) => e.length) === 1, 'popup thumb renders');
    const zoomRatio = await page.evaluate(() => window.corporateBookWPGlobal && window.corporateBookWPGlobal.zoomRatio);
    check(zoomRatio === 2.5, 'saved zoom ratio reaches the viewer as a number');
    check(!dialogShown, 'script in a saved setting does not run');
    check(jsErrors.length === 0, `no JavaScript errors ${jsErrors.join(' | ')}`);
    check(missingAssets.length === 0, `no missing plugin files ${missingAssets.join(' ')}`);

    // Subscription lock (plan Tasks 2-3): guest, non-subscriber and subscriber see different books.
    const hasFile = (body, file) => body.replace(/\\\//g, '/').includes(file);
    const visit = async (user) => {
      const ctx = await browser.newContext();
      const p = await ctx.newPage();
      p.setDefaultTimeout(90000);
      if (user) {
        await p.goto(`${BASE}/wp-login.php`);
        await p.fill('#user_login', user);
        await p.fill('#user_pass', 'password');
        await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
      }
      const res = await p.goto(`${BASE}/?pagename=book-test`);
      return { ctx, p, res, body: await res.text() };
    };
    // Waits for the book, then tries to turn past the last preview page.
    const pastEnd = (p) => p.evaluate(async () => {
      for (let i = 0; i < 60; i++) {
        const app = window.jQuery('._df_book').data('df-app');
        if (app && app.pageCount) {
          await new Promise((r) => setTimeout(r, 1500));
          app.next();
          return;
        }
        await new Promise((r) => setTimeout(r, 500));
      }
    });
    let v = await visit(null);
    check(hasFile(v.body, 'cb-test/preview.pdf') && !hasFile(v.body, 'cb-test/sample.pdf') && !v.body.includes('cb-sign-in'), 'guest gets the free preview');
    check(!PHP_ERRORS.test(v.body), 'guest page has no PHP errors');
    check(await v.p.$eval('.cb-thumb-card .cb-thumb-title', (e) => e.textContent).catch(() => '') === 'Test Book'
      && await v.p.$('.cb-thumb-card .cb-preview-button') !== null, 'thumbnail shows the title and a Preview button to everyone');
    await pastEnd(v.p);
    await v.p.waitForSelector('.cb-lock', { state: 'visible', timeout: 30000 }).catch(() => {});
    check(await v.p.$eval('.cb-lock-title', (e) => e.textContent).catch(() => '') === 'Your free preview has ended'
      && await v.p.isVisible(`.cb-lock a.cb-lock-subscribe[href="${SUBSCRIBE_URL}"]`)
      && await v.p.isVisible('.cb-lock form.cb-signin'), 'guest sees the preview-ended panel with Subscribe and a sign-in form');
    await v.p.click('.cb-lock .cb-signin [name="log"]').catch(() => {});
    await v.p.keyboard.type('ab');
    check(await v.p.$eval('.cb-lock .cb-signin [name="log"]', (e) => e.value).catch(() => '') === 'ab', 'readers can type in the sign-in form over the book');
    check(/no-cache|no-store/.test(v.res.headers()['cache-control'] || ''), 'locked page is not cacheable');
    await v.ctx.close();
    v = await visit('reader');
    check(hasFile(v.body, 'cb-test/preview.pdf') && !hasFile(v.body, 'cb-test/sample.pdf'), 'reader gets preview only');
    await pastEnd(v.p);
    await v.p.waitForSelector(`.cb-lock a[href="${SUBSCRIBE_URL}"]`, { state: 'visible', timeout: 30000 }).catch(() => {});
    check(await v.p.isVisible(`.cb-lock a[href="${SUBSCRIBE_URL}"]`), 'reader sees lock after preview');
    await v.ctx.close();
    v = await visit('member');
    check(hasFile(v.body, '?corporate_book_pdf=') && !hasFile(v.body, 'cb-test/preview.pdf'), 'member gets full book through the protected link');
    await v.ctx.close();

    // Per-book Subscription setting and "Create preview" (plan Task 5), as the admin.
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=corporate_book`);
    const editUrl = await page.locator('a.row-title', { hasText: /^Test Book$/ }).first().getAttribute('href');
    await page.goto(editUrl);
    check(await page.$('#corporate_book_enable_download') === null, 'book editor has no download option');
    const preview = await page.evaluate(async (pdf) => {
      document.querySelector('#corporate_book_pdf_source').value = pdf;
      document.querySelector('#corporate_book_create_preview').click();
      const status = document.querySelector('#corporate_book_preview_status');
      for (let i = 0; i < 120 && !/created|failed/i.test(status.textContent); i++) await new Promise((r) => setTimeout(r, 500));
      const id = document.querySelector('#corporate_book_preview_pdf').value;
      const media = await (await fetch(`/?rest_route=/wp/v2/media/${id}`)).json();
      const doc = await window.PDFLib.PDFDocument.load(await (await fetch(media.source_url)).arrayBuffer());
      return { id, pages: doc.getPageCount(), status: status.textContent };
    }, `${BASE}/wp-content/uploads/cb-test/ten.pdf`).catch((e) => ({ pages: e.message }));
    check(preview.pages === 1, `preview has 10 percent of pages (${preview.pages} ${preview.status || ''})`);
    await page.evaluate(() => { document.querySelector('#corporate_book_subscription').value = 'required'; });
    await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('#publish').click())]);
    await reloadUntil(editUrl, async () => await field('subscription') === 'required');
    check(await field('subscription') === 'required' && await field('preview_pdf') === preview.id, 'book subscription and preview are saved');

    // Protected full PDF (plan Task 6): saving the book as Required moved its PDF out of the public folder.
    const bookId = new URL(editUrl).searchParams.get('post');
    const fetchPdf = async (user, headers = {}) => {
      const ctx = await browser.newContext();
      const p = await ctx.newPage();
      p.setDefaultTimeout(90000);
      if (user) {
        await p.goto(`${BASE}/wp-login.php`);
        await p.fill('#user_login', user);
        await p.fill('#user_pass', 'password');
        await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
      }
      const res = await p.request.get(`${BASE}/?corporate_book_pdf=${bookId}`, { headers });
      const result = { status: res.status(), length: (await res.body()).length };
      await ctx.close();
      return result;
    };
    check((await fetchPdf(null)).status === 403, 'guest cannot fetch full PDF');
    check((await fetchPdf('reader')).status === 403, 'reader cannot fetch full PDF');
    const ranged = await fetchPdf('member', { Range: 'bytes=0-99' });
    check(ranged.status === 206 && ranged.length === 100, `member gets byte range (${ranged.status} ${ranged.length})`);
    let originalStatus = 0;
    for (let i = 0; i < 10 && originalStatus !== 404; i++) {
      originalStatus = (await page.request.get(`${BASE}/wp-content/uploads/cb-test/ten.pdf`)).status();
      if (originalStatus !== 404) await page.waitForTimeout(500);
    }
    check(originalStatus === 404, `original PDF moved out of public folder (${originalStatus})`);

    // Final review fixes.
    const statusOf = async (url, want) => {
      let st = 0;
      for (let i = 0; i < 10 && st !== want; i++) {
        st = (await page.request.get(url)).status();
        if (st !== want) await page.waitForTimeout(500);
      }
      return st;
    };
    check(await statusOf(`${BASE}/wp-content/uploads/cb-test/bulk.pdf`, 404) === 404, 'turning the lock on protects existing books');
    await page.goto(editUrl);
    const protectedUrl = await field('pdf_source');
    const writer = await browser.newContext();
    const w = await writer.newPage();
    w.setDefaultTimeout(90000);
    await w.goto(`${BASE}/wp-login.php`);
    await w.fill('#user_login', 'writer');
    await w.fill('#user_pass', 'password');
    await Promise.all([w.waitForNavigation(), w.click('#wp-submit')]);
    await w.goto(`${BASE}/wp-admin/post-new.php?post_type=corporate_book`).catch(() => w.goto(`${BASE}/wp-admin/post-new.php?post_type=corporate_book`));
    await w.evaluate(([src, prev]) => {
      document.querySelector('#title').value = 'Writer Book';
      document.querySelector('#corporate_book_pdf_source').value = src;
      document.querySelector('#corporate_book_preview_pdf').value = prev;
      document.querySelector('#corporate_book_subscription').value = 'required';
    }, [protectedUrl, preview.id]);
    await Promise.all([w.waitForNavigation(), w.evaluate(() => document.querySelector('#save-post').click())]);
    const writerBook = new URL(w.url()).searchParams.get('post');
    const writerField = (id) => w.$eval(`#corporate_book_${id}`, (e) => e.value).catch(() => null);
    check(await writerField('pdf_source') !== protectedUrl && await writerField('preview_pdf') !== preview.id, 'contributor cannot reuse protected files');
    check((await w.request.get(`${BASE}/?corporate_book_pdf=${writerBook}`)).status() !== 200, 'contributor cannot download another book through a draft');
    await w.evaluate((src) => { document.querySelector('#corporate_book_pdf_source').value = src; }, `${BASE}/wp-content/uploads/cb-test/other.pdf`);
    await Promise.all([w.waitForNavigation(), w.evaluate(() => document.querySelector('#save-post').click())]);
    check(await statusOf(`${BASE}/wp-content/uploads/cb-test/other.pdf`, 200) === 200, 'contributor cannot move other authors PDFs');
    await writer.close();
    // post-new.php rewrites its own URL while loading, which can abort the first navigation.
    await page.goto(`${BASE}/wp-admin/post-new.php?post_type=corporate_book`).catch(() => page.goto(`${BASE}/wp-admin/post-new.php?post_type=corporate_book`));
    await page.evaluate(() => {
      document.querySelector('#title').value = 'External Book';
      document.querySelector('#corporate_book_pdf_source').value = 'https://example.com/book.pdf';
      document.querySelector('#corporate_book_subscription').value = 'required';
    });
    // Publishing a new post first rewrites the URL in place, so wait for the real edit screen.
    await Promise.all([page.waitForURL(/post\.php\?post=\d+/), page.evaluate(() => document.querySelector('#publish').click())]);
    const externalBody = await reloadUntil(page.url(), async () => (await page.content()).includes('cb-unprotected'));
    check(externalBody.includes('cb-unprotected'), 'unprotected PDF is flagged in the editor');
    await page.goto(editUrl);
    await page.evaluate(() => { document.querySelector('#corporate_book_subscription').value = 'free'; });
    await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('#publish').click())]);
    await reloadUntil(editUrl, async () => await field('subscription') === 'free');
    v = await visit(null);
    check(hasFile(v.body, '?corporate_book_pdf=') && (await v.p.request.get(`${BASE}/?corporate_book_pdf=${bookId}`)).status() === 200, 'unlocked book still loads its protected PDF');
    await v.ctx.close();

    // Extra features: tilt, hybrid 3D, spiral, shelf, link and custom triggers, book pages, calendar, thumbnails.
    html = await (await page.goto(settingsUrl)).text();
    check(['tilt_up', 'tilt_left', 'page_rotation', 'spiral', 'calendar_mode', 'shelf_image', 'post_urls'].every((k) => html.includes(`id="corporate_book_${k}"`))
      && /<option value="hybrid"/.test(html), 'extra feature settings are shown');
    const extras = { spiral: 'true', tilt_up: '20', post_urls: 'true', webgl: 'hybrid' };
    await saveSettings(extras, extras).catch(() => '');
    jsErrors.length = 0;
    html = await reloadUntil(`${BASE}/?pagename=extras-test`, () => page.evaluate(() => (window.corporateBookWPGlobal || {}).flipbook3DTiltAngleUp === 20));
    const sent = await page.evaluate(() => { const g = window.corporateBookWPGlobal || {}; return `${g.flipbook3DTiltAngleUp} ${g.hasSpiral}`; });
    // The viewer consumes the webgl option, so check what the page sent; the spiral check below proves 3D is on.
    check(sent === '20 true' && html.includes('"webgl":"hybrid"'), `tilt, hybrid and spiral settings reach the viewer (${sent})`);
    check(html.includes('df-has-shelf') && await page.$$eval('.df-has-shelf ._df_thumb', (e) => e.length) >= 1, 'book shelf renders');
    check(await page.$('.cb-trigger-custom .cb-custom-inner') !== null, 'custom trigger keeps its HTML');
    await page.click('.cb-trigger-link').catch(() => {});
    await page.waitForSelector('.df-lightbox-wrapper', { state: 'visible', timeout: 30000 }).catch(() => {});
    check(await page.isVisible('.df-lightbox-wrapper'), 'link opens the book in a lightbox');
    const lightboxViewer = () => { const box = window.CORPORATE_BOOK && window.CORPORATE_BOOK.activeLightBox; return box && box.app && box.app.viewer; };
    await page.waitForFunction(`(${lightboxViewer})() && (${lightboxViewer})().spiral`, null, { timeout: 30000 }).catch(() => {});
    const rings = await page.evaluate(`(() => { const v = (${lightboxViewer})(); return v && v.spiral ? v.spiral.children.length : 0; })()`);
    check(rings > 0, `spiral rings are drawn (${rings})`);
    check(jsErrors.length === 0, `extras page has no JavaScript errors ${jsErrors.join(' | ')}`);
    const bookPage = await reloadUntil(`${BASE}/?corporate_book=test-book`, async () => (await page.content()).includes('_df_book'));
    check(bookPage.includes('_df_book'), 'book has its own page');
    const calendar = { calendar_mode: 'true', spiral: 'false', bg_image: 'https://example.com/bg.jpg', bg_color: '#112233' };
    await saveSettings(calendar, calendar).catch(() => '');
    jsErrors.length = 0;
    await reloadUntil(`${BASE}/?pagename=book-test`, () => page.evaluate(() => (window.corporateBookWPGlobal || {}).calendarMode === true));
    await page.waitForFunction(() => { const a = window.jQuery('._df_book').data('df-app'); return a && a.viewer && a.pageCount; }, null, { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(3000);
    const vertical = await page.evaluate(() => { const a = window.jQuery('._df_book').data('df-app'); return !!(a && a.viewer && a.viewer.isVertical && a.viewer.isVertical()); });
    const background = await page.evaluate(() => { const g = window.corporateBookWPGlobal || {}; return `${g.backgroundImage} ${g.backgroundColor}`; });
    check(background === 'https://example.com/bg.jpg #112233', `default background image and color come from Global Settings (${background})`);
    check(vertical && jsErrors.length === 0, `calendar mode flips vertically without errors ${jsErrors.join(' | ')}`);
    await page.goto(editUrl);
    const thumb = await page.evaluate(async () => {
      document.querySelector('#corporate_book_create_thumb').click();
      const status = document.querySelector('#corporate_book_thumb_status');
      for (let i = 0; i < 120 && !/created|failed/i.test(status.textContent); i++) await new Promise((r) => setTimeout(r, 500));
      return `${document.querySelector('#corporate_book_pdf_thumb').value} ${status.textContent}`;
    }).catch((e) => e.message);
    check(/\.png /.test(thumb), `thumbnail is generated from the PDF (${thumb.slice(0, 120)})`);

    // Image pickers, cover cards in post lists, automatic preview.
    await page.goto(settingsUrl);
    await page.evaluate(() => document.querySelector('#corporate_book_upload_bg_image').click());
    const pickerOpened = await page.waitForSelector('.media-modal', { state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
    check(pickerOpened && await page.$('#corporate_book_upload_shelf_image') !== null, 'Select Image opens the media library for background and shelf images');
    const archive = await (await page.goto(`${BASE}/?post_type=corporate_book`)).text();
    check(archive.includes('cb-thumb-card') && archive.includes('cb-preview-button'), 'book lists show the cover card with title and button');
    const newBook = `${BASE}/wp-admin/post-new.php?post_type=corporate_book`;
    await page.goto(newBook).catch(() => page.goto(newBook));
    const autoPreview = await page.evaluate(async (pdf) => {
      const source = document.querySelector('#corporate_book_pdf_source');
      source.value = pdf;
      window.jQuery(source).trigger('change');
      const preview = document.querySelector('#corporate_book_preview_pdf');
      for (let i = 0; i < 120 && !Number(preview.value); i++) await new Promise((r) => setTimeout(r, 500));
      return `${preview.value} ${document.querySelector('#corporate_book_preview_status').textContent}`;
    }, `${BASE}/wp-content/uploads/cb-test/auto.pdf`).catch((e) => e.message);
    check(parseInt(autoPreview, 10) > 0, `preview is created automatically when a PDF is chosen (${autoPreview})`);

    // Built-in accounts and Razorpay subscription. Razorpay's API is faked by the test setup plugin.
    const razorpay = { razorpay_key_id: 'rzp_test_SMOKE', razorpay_key_secret: 'smokesecret' };
    await saveSettings(razorpay, razorpay).catch(() => '');
    const acct = await browser.newContext();
    const a = await acct.newPage();
    a.setDefaultTimeout(90000);
    const openAccount = async (ready) => {
      for (let i = 0; i < 10; i++) {
        await a.goto(`${BASE}/?pagename=account-test`);
        if (await ready()) return true;
        await a.waitForTimeout(500);
      }
      return false;
    };
    const has = (selector) => async () => await a.$(selector) !== null;
    check(await openAccount(has('.cb-account form.cb-signin')) && await a.$('.cb-account form.cb-signup') !== null, 'guests get sign-in and sign-up forms');
    await a.fill('.cb-signin [name="log"]', 'reader').catch(() => {});
    await a.fill('.cb-signin [name="pwd"]', 'wrong-password').catch(() => {});
    await a.click('.cb-signin button[type="submit"]').catch(() => {});
    await a.waitForFunction(() => document.querySelector('.cb-account-message').textContent !== '', null, { timeout: 30000 }).catch(() => {});
    check(await a.$eval('.cb-account-message', (e) => e.textContent).catch(() => '') === 'Incorrect email or password.', 'a wrong password is refused with a plain message');
    await a.click('.cb-show-signup').catch(() => {});
    await a.fill('.cb-signup [name="email"]', 'newreader@example.com').catch(() => {});
    await a.fill('.cb-signup [name="pwd"]', 'long-password-1').catch(() => {});
    await Promise.all([a.waitForNavigation({ timeout: 30000 }), a.click('.cb-signup button[type="submit"]')]).catch(() => {});
    const offered = await openAccount(has('.cb-plan'));
    check(await a.$('.cb-signout') !== null, 'sign up creates the account and signs the reader in');
    const plans = await a.$$eval('.cb-plan', (els) => els.map((e) => e.textContent).join(' | ')).catch(() => '');
    check(offered && /Monthly.*399/.test(plans) && /Yearly.*3,?999/.test(plans), `plans are offered with their prices (${plans})`);
    const ajax = (data) => a.evaluate(async (d) => {
      const form = new FormData();
      Object.entries({ ...d, _ajax_nonce: (window.corporateBookAccount || {}).nonce }).forEach(([k, v]) => form.append(k, v));
      return (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: form, credentials: 'same-origin' })).json().catch(() => ({}));
    }, data);
    const order = (await ajax({ action: 'corporate_book_order', plan: '0' })).data || {};
    check(order.amount === 39900 && /^order_/.test(order.order_id || '') && order.key === 'rzp_test_SMOKE', `an order is created for the plan price (${order.amount})`);
    const paymentId = String(order.order_id).replace('order_', 'pay_');
    const sign = (o, p) => require('crypto').createHmac('sha256', 'smokesecret').update(`${o}|${p}`).digest('hex');
    const pay = (signature) => ajax({ action: 'corporate_book_verify', order_id: String(order.order_id), payment_id: paymentId, signature });
    check((await pay(sign(order.order_id, 'pay_OTHER'))).success !== true, 'a forged payment signature is refused');
    let paid = {};
    for (let i = 0; i < 10 && paid.success !== true; i++) {
      paid = await pay(sign(order.order_id, paymentId));
      if (paid.success !== true) await a.waitForTimeout(500);
    }
    const active = await openAccount(has('.cb-account-status'));
    const until = await a.$eval('.cb-account-status', (e) => e.textContent).catch(() => '');
    check(paid.success === true && active && /Subscription active until/.test(until), 'a verified payment starts the subscription');
    await a.waitForTimeout(2000);
    await pay(sign(order.order_id, paymentId));
    await openAccount(has('.cb-account-status'));
    check(until !== '' && await a.$eval('.cb-account-status', (e) => e.textContent).catch(() => '') === until, 'replaying the same payment does not extend the subscription');
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=corporate_book`);
    const bulkId = new URL(await page.locator('a.row-title', { hasText: /^Bulk Book$/ }).first().getAttribute('href')).searchParams.get('post');
    let subscriberPdf = 0;
    for (let i = 0; i < 10 && subscriberPdf !== 200; i++) {
      subscriberPdf = (await a.request.get(`${BASE}/?corporate_book_pdf=${bulkId}`)).status();
      if (subscriberPdf !== 200) await a.waitForTimeout(500);
    }
    check(subscriberPdf === 200, `a paid subscriber can load a locked book (${subscriberPdf})`);
    await Promise.all([a.waitForNavigation({ timeout: 30000 }), a.click('.cb-signout')]).catch(() => {});
    check(await openAccount(has('.cb-account form.cb-signin')), 'sign out returns the reader to the sign-in form');
    await acct.close();
  } catch (e) {
    check(false, e.message);
  } finally {
    if (browser) await browser.close();
    process.kill(-wp.pid); // npx starts the server as a child, so stop the whole process group
  }
  if (failed.length) {
    console.log(serverLog.slice(-1500));
    process.exit(1);
  }
})();
