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
    await saveSettings({ subscription_enable: 'true', subscription_url: SUBSCRIBE_URL, subscription_preview_percent: '10', subscription_roles: 'cb_member, Bad Role!, cb_reader' },
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
    // Library and cover cards, as a guest: every book shows its card, even a locked one without a preview.
    const lib = await browser.newContext();
    const l = await lib.newPage();
    l.setDefaultTimeout(90000);
    const libraryHtml = await (await l.goto(`${BASE}/?pagename=library-test`)).text();
    const year = String(new Date().getFullYear());
    check(await l.$$eval('.cb-library .cb-library-item .cb-thumb-card', (e) => e.length) >= 2 && await l.$(`.cb-library-years .cb-filter[data-filter="${year}"]`) !== null
      && !PHP_ERRORS.test(libraryHtml), 'library shows every book as a card with year buttons');
    check(await l.$('.cb-thumb-card .cb-cover img[src*="cover.png"]') !== null && (await l.$eval('.cb-thumb-card .cb-preview-button', (e) => e.textContent)).trim() === 'Read Magazine',
      'cards show the cover image and a Read Magazine button');
    await l.click('.cb-library-years .cb-filter[data-filter="all"]');
    await l.click(`.cb-library-years .cb-filter[data-filter="${year}"]`);
    check(await l.$$eval('.cb-library-item', (e) => e.filter((i) => !i.hidden).length) >= 1
      && await l.$eval(`.cb-library-years .cb-filter[data-filter="${year}"]`, (e) => e.classList.contains('cb-active')), 'year filter keeps that year and marks its chip');
    await l.click('.cb-library-filter .cb-filter[data-filter="cat-asia"]').catch(() => {});
    check(await l.$$eval('.cb-library-item', (e) => e.filter((i) => i.offsetParent).length) === 1 && await l.$('.cb-library-years .cb-filter[data-filter="2019"]') !== null, 'library filters by category (Asia) and files books under a year category (2019)');
    check(await l.$('.cb-library-bar .cb-library-search [name="cb_q"]') !== null && await l.$('.cb-library aside') === null, 'library has one toolbar with search and chips, no sidebar');
    await lib.close();
    let v = await visit(null);
    check(hasFile(v.body, 'corporate_book_pdf=') && hasFile(v.body, 'start=1') && !hasFile(v.body, 'cb-test/sample.pdf') && !v.body.includes('cb-sign-in'), 'guest gets the free preview (from the protected link, never the file)');
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
    const fits = await v.p.$eval('.cb-lock', (e) => { const c = e.offsetParent.getBoundingClientRect(), r = e.getBoundingClientRect(); return getComputedStyle(e).overflowY === 'auto' && r.bottom <= c.bottom + 1; }).catch(() => false);
    check(fits, 'preview-ended panel stays inside the book and scrolls');
    check(/no-cache|no-store/.test(v.res.headers()['cache-control'] || ''), 'locked page is not cacheable');
    await v.ctx.close();
    v = await visit('reader');
    check(hasFile(v.body, 'start=1') && !hasFile(v.body, 'cb-test/sample.pdf'), 'reader gets preview only');
    // Signing in does not open a second free window: the account shares the window this browser already used.
    const readerBook = (v.body.match(/corporate_book_pdf=(\d+)/) || [])[1];
    const second = readerBook ? Buffer.from(await (await v.p.request.get(`${BASE}/?corporate_book_pdf=${readerBook}&start=3`)).body()).toString('latin1') : '';
    check(/\(Page 1\)/.test(second) && !/\(Page 3\)/.test(second), 'a signed-in reader gets the same single free window, not a new one');
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
    // Free preview from any page: only the reader's window is real (10% of the 3-page sample is 1 page).
    const testBookId = new URL(editUrl).searchParams.get('post');
    // One free window per reader: this IP already opened the book from page 1, so asking for page 3 gets page 1 again.
    const win = Buffer.from(await (await fetch(`${BASE}/?corporate_book_pdf=${testBookId}&start=3`)).arrayBuffer()).toString('latin1');
    check(/\(Page 1\)/.test(win) && !/\(Page 2\)/.test(win) && !/\(Page 3\)/.test(win) && /Subscribe to read this page/.test(win), 'a reader gets only one free window per book, even when asking for another page');
    const full = await fetch(`${BASE}/?corporate_book_pdf=${testBookId}`);
    check(full.status === 403, `the full PDF still needs access (${full.status})`);
    const startCtx = await browser.newContext();
    const sp = await startCtx.newPage();
    sp.setDefaultTimeout(90000);
    await sp.goto(`${BASE}/?pagename=book-test#cbstart-3`);
    await sp.waitForFunction(() => { const a = window.jQuery && window.jQuery('._df_book').data('df-app'); return a && a.pageCount; }, null, { timeout: 60000 }).catch(() => {});
    await sp.waitForTimeout(2500);
    const opened = await sp.evaluate(() => { const a = window.jQuery('._df_book').data('df-app'); return { page: a.currentPageNumber, start: window['option_' + document.querySelector('._df_book').id].cbLock.window.start }; }).catch(() => ({}));
    check(opened.start === 1, `a start link does not open a second free window (${JSON.stringify(opened)})`);
    await sp.evaluate(() => window.jQuery('._df_book').data('df-app').gotoPage(3)).catch(() => {});
    await sp.waitForSelector('._df_book .cb-lock', { state: 'visible', timeout: 20000 }).catch(() => {});
    check(await sp.$('._df_book .cb-lock .cb-lock-from') === null, 'the panel has no button to start another free preview');
    await startCtx.close();
    // Preview link from a page: the book's editor copies it from the bar under the book; it moves the free preview there.
    await page.goto(`${BASE}/?pagename=book-test`);
    await page.waitForSelector('.cb-reader-bar .cb-share', { timeout: 60000 }).catch(() => {});
    await page.evaluate(() => window.jQuery('._df_book').data('df-app').gotoPage(2)).catch(() => {});
    await page.waitForTimeout(1000);
    await page.click('.cb-reader-bar .cb-share').catch(() => {});
    await page.waitForFunction(() => /cb_key=/.test((document.querySelector('.cb-share-out') || {}).value || ''), null, { timeout: 20000 }).catch(() => {});
    const shareLink = await page.$eval('.cb-share-out', (e) => e.value).catch(() => '');
    const sharedPage = parseInt((shareLink.match(/cb_from=(\d+)/) || [])[1] || '0', 10);
    const linkCtx = await browser.newContext();
    const lp = await linkCtx.newPage();
    lp.setDefaultTimeout(90000);
    const windowStart = async (url) => { await lp.goto(url); return lp.evaluate(() => { const o = window['option_' + document.querySelector('._df_book').id]; return o && o.cbLock && o.cbLock.window ? o.cbLock.window.start : 0; }).catch(() => 0); };
    const linked = shareLink ? await windowStart(shareLink) : 0;
    const tampered = shareLink ? await windowStart(shareLink.replace(/cb_from=\d+/, `cb_from=${sharedPage === 1 ? 2 : 1}`)) : 0;
    check(sharedPage > 0 && !/cb_key/.test(shareLink) && linked === 1 && tampered === 1, `page links are plain; a reader who already had free pages keeps them (${shareLink.slice(-40)} ${linked} ${tampered})`);
    await linkCtx.close();
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
    check(preview.pages === 1 && /created/i.test(preview.status || ''), `preview has 10 percent of pages (${preview.pages} ${preview.status || ''})`);
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
    check(['paper_curl', 'paper_curl_amount', 'desk_tilt', 'desk_tilt_back', 'desk_tilt_side'].every((k) => html.includes(`id="corporate_book_${k}"`)), 'Layout has Soft, Curling Paper and Tilted on a Desk with their amounts');
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
    const bookPage = await reloadUntil(`${BASE}/?corporate_book=test-book`, async () => (await page.content()).includes('cb-edition'));
    check(/cb-edition/.test(bookPage) && /cb-ed-cover[\s\S]*cb-cover/.test(bookPage) && /Go Unlimited with/.test(bookPage), 'book has its own edition page: cover card that opens the book, and the ways to buy');
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
    check(archive.includes('cb-library-grid') && !archive.includes('wp-block-post-featured-image'), 'book list page is the library grid, without full-width featured images');
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
    let planCards = '';
    for (let i = 0; i < 10 && !/399/.test(planCards); i++) {
      await a.goto(`${BASE}/?pagename=subscription`);
      planCards = await a.$$eval('.cb-plan-card', (els) => els.map((e) => e.textContent).join(' | ')).catch(() => '');
      if (!/399/.test(planCards)) await a.waitForTimeout(500);
    }
    check(/Monthly.*399.*30 days/.test(planCards) && /Yearly/.test(planCards) && await a.$('.cb-plans-page form.cb-signin') !== null
      && await a.$('.cb-plan-card .cb-plan-signin') !== null, `subscription page is created with a card per plan and sign-in for guests (${planCards.slice(0, 80)})`);
    await acct.close();

    // Readers never see WordPress itself: no toolbar, no dashboard, no wp-login screens; a front-end account page instead.
    const r = await visit('reader');
    check(!r.body.includes('id="wpadminbar"'), 'signed-in readers get no WordPress toolbar');
    await r.p.goto(`${BASE}/wp-admin/profile.php`).catch(() => {});
    check(/my-account/.test(r.p.url()) && !/wp-admin/.test(r.p.url()), `readers who open the dashboard land on My account (${r.p.url()})`);
    let profile = false;
    for (let i = 0; i < 10 && !profile; i++) {
      await r.p.goto(`${BASE}/?pagename=my-account`);
      profile = await r.p.$('.cb-profile form.cb-profile-details [name="email"]') !== null;
      if (!profile) await r.p.waitForTimeout(500);
    }
    check(profile && await r.p.$('.cb-profile form.cb-profile-password') !== null && await r.p.$('.cb-profile .cb-profile-orders') !== null,
      'My account page has details, password and payment history');
    await r.p.fill('.cb-profile-details [name="first_name"]', 'Ria').catch(() => {});
    await r.p.fill('.cb-profile-details [name="phone"]', '+91 98765 43210').catch(() => {});
    await r.p.click('.cb-profile-details button[type="submit"]').catch(() => {});
    await r.p.waitForFunction(() => /saved/i.test(document.querySelector('.cb-profile-details').closest('.cb-account').querySelector('.cb-account-message').textContent), null, { timeout: 30000 }).catch(() => {});
    let saved = '';
    for (let i = 0; i < 10 && saved !== 'Ria +91 98765 43210'; i++) {
      await r.p.goto(`${BASE}/?pagename=my-account`);
      saved = await r.p.evaluate(() => ['first_name', 'phone'].map((n) => document.querySelector(`.cb-profile-details [name="${n}"]`).value).join(' ')).catch(() => '');
    }
    check(saved === 'Ria +91 98765 43210', `readers can save their details (${saved})`);
    await r.p.fill('.cb-profile-details [name="email"]', 'thief@example.com').catch(() => {});
    await r.p.click('.cb-profile-details button[type="submit"]').catch(() => {});
    await r.p.waitForFunction(() => /password/i.test(document.querySelector('.cb-profile-details').closest('.cb-account').querySelector('.cb-account-message').textContent), null, { timeout: 30000 }).catch(() => {});
    check(/current password/i.test(await r.p.$eval('.cb-profile-details', (f) => f.closest('.cb-account').querySelector('.cb-account-message').textContent).catch(() => '')), 'changing the email needs the current password');
    await r.ctx.close();
    const lost = await (await browser.newContext()).newPage();
    await lost.goto(`${BASE}/wp-login.php?action=lostpassword`).catch(() => {});
    check(/my-account/.test(lost.url()) && await lost.$('form.cb-lost') !== null, `lost password opens the site's own form, not wp-login (${lost.url()})`);
    await lost.fill('form.cb-lost [name="email"]', 'reader@example.com').catch(() => {});
    await lost.click('form.cb-lost button[type="submit"]').catch(() => {});
    await lost.waitForFunction(() => /email/i.test(document.querySelector('form.cb-lost').closest('.cb-account').querySelector('.cb-account-message').textContent), null, { timeout: 30000 }).catch(() => {});
    check(/email/i.test(await lost.$eval('.cb-account-message', (e) => e.textContent).catch(() => '')), 'lost password form answers on the page');
    const mail = (() => { try { return fs.readFileSync(path.join(fixtures, 'mail.txt'), 'utf8'); } catch (e) { return ''; } })();
    const link = (mail.match(/https?:\/\/\S*cb_reset=\S+/) || [''])[0];
    check(link.includes('my-account') && !link.includes('wp-login'), `reset email links to My account (${link.slice(0, 90)})`);
    if (link) {
      await lost.goto(link);
      await lost.fill('form.cb-reset [name="pwd"]', 'password').catch(() => {});
      await Promise.all([lost.waitForNavigation({ timeout: 30000 }), lost.click('form.cb-reset button[type="submit"]')]).catch(() => {});
    }
    check(await lost.$('.cb-profile form.cb-profile-details') !== null, 'resetting the password signs the reader in on My account');
    const hook = await fetch(`${BASE}/wp-admin/admin-ajax.php?action=corporate_book_webhook`, { method: 'POST', body: '{"event":"payment.captured"}', headers: { 'X-Razorpay-Signature': 'bad' } });
    check(hook.status === 401, `Razorpay webhook rejects a bad signature (${hook.status})`);
    await page.goto(`${BASE}/wp-admin/post-new.php?post_type=corporate_book`);
    check(/\[corporate_book id="\d+"\]/.test(await page.$eval('input.cb-shortcode', (e) => e.value).catch(() => '')), 'new book shows its shortcode before the first save');
    const editorSimple = await page.evaluate(() => !document.querySelector('a[href="#corporate-book-tab-content-layout"]').offsetParent && !!document.querySelector('#corporate_book_pdf_source').closest('.corporate-book-tab-content').offsetParent).catch(() => false);
    check(editorSimple, 'book editor shows only the Book tab until "Show more options"');
    const d = await (await browser.newContext()).newPage();
    d.setDefaultTimeout(90000);
    const deskErrors = []; d.on('pageerror', (e) => deskErrors.push(e.message));
    await d.goto(`${BASE}/?pagename=book-test`);
    await d.waitForSelector('._df_book', { timeout: 30000 }).catch(() => {});
    await d.waitForTimeout(1500);
    check(await d.$('.cb-desk') === null && deskErrors.length === 0, `by default the book shows plain, without the desk background ${deskErrors.join(' | ')}`);
    await d.close();

    // ---- Print copies shop: author sets prices, reader buys with a cart, admin ships. ----
    await saveSettings({ print_cod: 'true' }).catch(() => '');
    const setPrint = async (url, values) => {
      for (let i = 0; i < 3; i++) {
        await page.goto(url);
        await page.evaluate((v) => { for (const [k, val] of Object.entries(v)) document.querySelector(`#corporate_book_${k}`).value = val; }, values);
        await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('#publish').click())]).catch(() => {});
        await page.goto(url);
        if (await page.$eval('#corporate_book_print_price', (e) => e.value).catch(() => '') === values.print_price) return true;
      }
      return false;
    };
    const bulkEdit = `${BASE}/wp-admin/post.php?post=${bulkId}&action=edit`;
    // An earlier check made Test Book free; lock it again so the preview-ended panel shows.
    const priced = await setPrint(editUrl, { subscription: 'required', print_sell: 'true', print_price: '499', print_weight: '200', print_stock: '5' })
      && await setPrint(bulkEdit, { print_sell: 'true', print_price: '250', print_cost: '120', print_stock: '' });
    check(priced, 'authors set print price, weight, cost and stock in the book editor');
    // Free until a date: everyone reads the whole book until the last free day.
    const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    const freed = await setPrint(editUrl, { subscription: 'until', free_until: tomorrow, print_price: '499' });
    let freeHtml = '';
    for (let i = 0; i < 8 && !/cb-free-note/.test(freeHtml); i++) {
      freeHtml = await (await fetch(`${BASE}/?pagename=book-test`)).text();
      if (!/cb-free-note/.test(freeHtml)) await new Promise((r) => setTimeout(r, 1500));
    }
    check(freed && /Free to read until/.test(freeHtml) && !/start=\d/.test(freeHtml), 'a book free until a date opens in full for everyone, with a "Free to read until" note');
    await setPrint(editUrl, { subscription: 'required', free_until: '', print_price: '499' });    const shopCtx = await browser.newContext();
    await shopCtx.exposeBinding('cbSign', (src, o, p) => require('crypto').createHmac('sha256', 'smokesecret').update(`${o}|${p}`).digest('hex'));
    // Razorpay Checkout stand-in: "pays" at once with a correctly signed payment.
    await shopCtx.addInitScript(() => {
      window.Razorpay = function (o) {
        this.open = async () => { const p = o.order_id.replace('order_', 'pay_'); o.handler({ razorpay_order_id: o.order_id, razorpay_payment_id: p, razorpay_signature: await window.cbSign(o.order_id, p) }); };
      };
    });
    const s = await shopCtx.newPage();
    s.setDefaultTimeout(90000);
    const shopErrors = []; s.on('pageerror', (e) => shopErrors.push(e.message));
    // The test server can serve the page from before the price was saved for a moment, so reload until it shows.
    let bookHtml = '';
    for (let i = 0; i < 10; i++) {
      bookHtml = await (await s.goto(`${BASE}/?pagename=book-test`)).text();
      if (bookHtml.includes('cb-add-cart')) break;
      await s.waitForTimeout(500);
    }
    await pastEnd(s).catch(() => {});
    await s.waitForSelector('.cb-lock .cb-lock-print .cb-add-cart', { state: 'visible', timeout: 30000 }).catch(() => {});
    check(/Buy print copy/.test(await s.$eval('.cb-lock .cb-add-cart', (e) => e.textContent).catch(() => '')), 'the preview-ended panel offers a print copy');
    await s.goto(`${BASE}/wp-login.php`);
    await s.fill('#user_login', 'member'); await s.fill('#user_pass', 'password');
    await Promise.all([s.waitForNavigation(), s.click('#wp-submit')]);
    let cards = 0;
    for (let i = 0; i < 10 && cards < 2; i++) {
      await s.goto(`${BASE}/?pagename=subscription`);
      cards = (await s.$$('.cb-print-section .cb-add-cart')).length;
      if (cards < 2) await s.waitForTimeout(500);
    }
    check(cards === 2, `Subscription page lists the print copies (${cards})`);
    const addBook = (title) => s.click(`.cb-print-card:has(h3:text-is("${title}")) .cb-add-cart`);
    for (const t of ['Test Book', 'Test Book', 'Bulk Book']) { await addBook(t); await s.waitForTimeout(200); }
    check(await s.$eval('.cb-toast', (e) => /Added to cart/.test(e.textContent)).catch(() => false), 'adding a book shows "Added to cart"');
    await s.goto(`${BASE}/?post_type=corporate_book`);
    const cardCart = await s.$eval('.cb-thumb-card .cb-thumb-actions .cb-add-cart', (e) => `${e.textContent.trim()}|${e.getAttribute('aria-label')}`).catch(() => '');
    check(cardCart === '' && await s.$('.cb-library .cb-thumb-price') === null && /business-magazine\/|corporate_book=/.test(await s.$eval('.cb-library .cb-thumb-actions a.cb-preview-button', (e) => e.href).catch(() => '')), `All Magazines cards open the magazine page, without price or cart (${cardCart})`);
    await s.goto(`${BASE}/?pagename=subscription`);
    check(/^2 in your cart/.test(await s.$eval('.cb-print-card:has(h3:text-is("Test Book")) .cb-in-cart', (e) => e.textContent).catch(() => '')), 'the book card shows how many copies are in the cart');
    await s.goto(`${BASE}/?pagename=cart`);
    await s.waitForSelector('.cb-cart-table tr', { timeout: 30000 }).catch(() => {});
    const quoteFor = async (pin) => {
      await s.fill('.cb-checkout [name="pin"]', pin);
      await s.waitForFunction(() => document.querySelector('.cb-cart-totals') && /Shipping \(/.test(document.querySelector('.cb-cart-totals').textContent), null, { timeout: 20000 }).catch(() => {});
      await s.waitForTimeout(1500);
      return s.$eval('.cb-cart-totals', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
    };
    const rows = (await s.$$('.cb-cart-table tr')).length;
    const local = await quoteFor('201301');
    const far = await quoteFor('560001');
    check(rows === 2 && /^Price Details/.test(local) && /Price \(3 copies\)₹1,248/.test(local) && /Shipping₹70/.test(local) && /Total Amount₹1,318/.test(local), `Price Details: books plus the Delhi NCR courier rate for 650 g (${local})`);
    check(/Shipping₹115/.test(far) && /Total Amount₹1,363/.test(far), `a metro PIN uses the metro courier rate (${far})`);
    const address = { name: 'Asha Rao', phone: '12345', email: 'asha@example.test', line1: 'Flat 4B, Lotus Tower', line2: 'Sector 62', city: 'Noida', pin: '201309' };
    for (const [k, v] of Object.entries(address)) await s.fill(`.cb-checkout [name="${k}"]`, v);
    await s.selectOption('.cb-checkout [name="state"]', 'Uttar Pradesh');
    await s.click('.cb-place-order');
    await s.waitForFunction(() => document.querySelector('.cb-field-error[data-for="phone"]').textContent !== '', null, { timeout: 20000 }).catch(() => {});
    check(/mobile/i.test(await s.$eval('.cb-field-error[data-for="phone"]', (e) => e.textContent).catch(() => '')), 'a wrong mobile number is pointed out');
    await s.fill('.cb-checkout [name="phone"]', '98765 43210');
    await s.click('.cb-place-order');
    await s.waitForSelector('.cb-cart-done', { timeout: 40000 }).catch(() => {});
    const thanks = await s.$eval('.cb-cart-done', (e) => e.textContent).catch(() => '');
    const orderNo = (thanks.match(/#(\d+)/) || [])[1];
    check(!!orderNo && await s.evaluate(() => localStorage.getItem('cbCart')) === '[]', `paying online places the order and empties the cart (${thanks.slice(0, 60)})`);
    let mine = '';
    for (let i = 0; i < 10 && !/Paid/.test(mine); i++) {
      await s.goto(`${BASE}/?pagename=my-account`);
      mine = await s.$eval('.cb-print-orders', (e) => e.textContent).catch(() => '');
    }
    check(mine.includes(`#${orderNo}`) && /Paid/.test(mine) && /2 × Test Book/.test(mine), `My account lists the paid order (${mine.slice(0, 80)})`);
    let stock = '';
    for (let i = 0; i < 10 && stock !== '3'; i++) {
      await page.goto(editUrl);
      stock = await page.$eval('#corporate_book_print_stock', (e) => e.value).catch(() => '');
    }
    check(stock === '3', `stock goes down when an order is paid (${stock})`);
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=cb_order`);
    check((await page.content()).includes(`Order #${orderNo}`), 'Print Orders lists the order for the admin');
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=cb_order&s=201309`);
    check((await page.content()).includes(`Order #${orderNo}`), 'admin finds orders by the customer\'s PIN code');
    const w2 = await (await browser.newContext()).newPage();
    await w2.goto(`${BASE}/wp-login.php`);
    await w2.fill('#user_login', 'writer'); await w2.fill('#user_pass', 'password');
    await Promise.all([w2.waitForNavigation(), w2.click('#wp-submit')]).catch(() => {});
    const authorView = await (await w2.goto(`${BASE}/wp-admin/edit.php?post_type=cb_order`)).text();
    check(!authorView.includes('Asha Rao') && !authorView.includes(`Order #${orderNo}`), 'authors cannot see print orders or customer details');
    await w2.close();
    await page.goto(`${BASE}/wp-admin/post.php?post=${orderNo}&action=edit`);
    await page.selectOption('#cb_order_status', 'cb-shipped');
    await page.fill('#cb_order_courier', 'India Post');
    await page.fill('#cb_order_tracking', 'EE123456789IN');
    await Promise.all([page.waitForNavigation(), page.click('#cb_order_manage button[type="submit"]')]).catch(() => {});
    const mailText = (() => { try { return fs.readFileSync(path.join(fixtures, 'mail.txt'), 'utf8'); } catch (e) { return ''; } })();
    check(/Shipped/.test(mailText) && /EE123456789IN/.test(mailText), `shipping the order emails the tracking number (${mailText.slice(0, 60)})`);
    let shipped = '';
    for (let i = 0; i < 10 && !/Shipped/.test(shipped); i++) {
      await s.goto(`${BASE}/?pagename=my-account`);
      shipped = await s.$eval('.cb-print-orders', (e) => e.textContent).catch(() => '');
    }
    check(/Shipped/.test(shipped) && /EE123456789IN/.test(shipped), 'the reader sees Shipped with the tracking number');
    await s.goto(`${BASE}/?pagename=subscription`);
    await addBook('Bulk Book');
    await s.goto(`${BASE}/?pagename=cart`);
    await s.waitForSelector('.cb-cart-table tr', { timeout: 30000 }).catch(() => {});
    await s.check('.cb-pay [value="cod"]').catch(() => {});
    await s.click('.cb-place-order');
    await s.waitForSelector('.cb-cart-done', { timeout: 40000 }).catch(() => {});
    const codNo = ((await s.$eval('.cb-cart-done', (e) => e.textContent).catch(() => '')).match(/#(\d+)/) || [])[1];
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=cb_order`);
    check(!!codNo && (await page.content()).includes('Cash on delivery'), `cash on delivery orders are placed without paying online (${codNo})`);
    check(shopErrors.length === 0, `shop pages have no JavaScript errors ${shopErrors.join(' | ')}`);
    await shopCtx.close();

    {
    // ---- New features: coupons, gifts, invoices, cancel, CSV, Shiprocket, dashboard, wishlist, stock alerts, bookmarks, gift subscription. ----
    const lastMail = () => { try { return fs.readFileSync(path.join(fixtures, 'mail.txt'), 'utf8'); } catch (e) { return ''; } };
    await saveSettings({ print_terms_url: 'https://example.com/terms', print_coupons: 'WELCOME10 | 10%', shiprocket_email: 'api@example.test', shiprocket_password: 'secret', invoice_gstin: '09ABCDE1234F1Z5' }, { print_coupons: 'WELCOME10 | 10%' }).catch(() => '');
    const fx = await browser.newContext();
    await fx.exposeBinding('cbSign', (src, o, p) => require('crypto').createHmac('sha256', 'smokesecret').update(`${o}|${p}`).digest('hex'));
    const m = await fx.newPage();
    m.setDefaultTimeout(90000);
    const fxErrors = []; m.on('pageerror', (e) => fxErrors.push(e.message));
    m.on('dialog', (d) => d.accept());
    await m.goto(`${BASE}/wp-login.php`);
    await m.fill('#user_login', 'member'); await m.fill('#user_pass', 'password');
    await Promise.all([m.waitForNavigation(), m.click('#wp-submit')]);
    // Wishlist.
    await m.goto(`${BASE}/?pagename=subscription`);
    await m.click('.cb-print-card:has(h3:text-is("Test Book")) .cb-wish');
    await m.waitForFunction(() => document.querySelector('.cb-wish.cb-wished'), null, { timeout: 20000 }).catch(() => {});
    let saved = '';
    for (let i = 0; i < 10 && !/Test Book/.test(saved); i++) {
      await m.goto(`${BASE}/?pagename=my-account`).catch(() => {});
      saved = await m.$eval('.cb-wishlist', (e) => e.textContent).catch(() => '');
    }
    check(/Test Book/.test(saved), 'Save for later lists the book under Saved books');
    // Coupon, delivery estimate, gift and cash on delivery in one order.
    await m.goto(`${BASE}/?pagename=subscription`);
    await m.click('.cb-print-card:has(h3:text-is("Bulk Book")) .cb-add-cart');
    await m.goto(`${BASE}/?pagename=cart`);
    await m.waitForSelector('.cb-cart-table tr', { timeout: 30000 }).catch(() => {});
    await m.fill('.cb-checkout [name="pin"]', '201301');
    // The test server can serve the settings from before the coupon was saved for a moment, so apply again until it counts.
    for (let i = 0; i < 4; i++) {
      await m.fill('.cb-checkout [name="coupon"]', 'welcome10');
      await m.click('.cb-apply-coupon');
      if (await m.waitForFunction(() => /WELCOME10/.test(document.querySelector('.cb-cart-totals').textContent), null, { timeout: 8000 }).then(() => true).catch(() => false)) break;
    }
    const totals = await m.$eval('.cb-cart-totals', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
    check(/Coupon discount \(WELCOME10\)− ₹25/.test(totals) && /You'll save ₹25 on this order/.test(totals) && /By placing the order, you agree to our Terms of Use/.test(totals) && /Delivery in about 1-2 days/.test(totals), `coupon, savings and delivery estimate in Price Details (${totals})`);
    await m.check('.cb-checkout [name="gift"]');
    await m.fill('.cb-checkout [name="gift_message"]', 'Happy birthday, Ravi!');
    await m.check('.cb-pay [value="cod"]').catch(() => {});
    await m.click('.cb-place-order');
    await m.waitForSelector('.cb-cart-done', { timeout: 40000 }).catch(() => {});
    const giftNo = ((await m.$eval('.cb-cart-done', (e) => e.textContent).catch(() => '')).match(/#(\d+)/) || [])[1];
    check(!!giftNo, `gift order with coupon placed (${giftNo})`);
    // Admin: packing slip, invoice, Shiprocket.
    const slip = await (await page.goto(`${BASE}/?cb_doc=slip&cb_order=${giftNo}`)).text();
    check(/Happy birthday, Ravi!/.test(slip) && !/₹/.test(slip), 'packing slip shows the gift message and no prices');
    const invoice = await (await page.goto(`${BASE}/?cb_doc=invoice&cb_order=${giftNo}`)).text();
    check(/CB-\d{5}/.test(invoice) && /09ABCDE1234F1Z5/.test(invoice) && /HSN 4901/.test(invoice), 'invoice has a running number, the GSTIN and the GST note');
    await page.goto(`${BASE}/wp-admin/post.php?post=${giftNo}&action=edit`);
    await Promise.all([page.waitForNavigation(), page.click('.cb-order-docs a:has-text("Send to Shiprocket")')]).catch(() => {});
    check(/Sent to Shiprocket/.test(await page.content()) && await page.$eval('#cb_order_tracking', (e) => e.value).catch(() => '') === 'SR123AWB', 'Send to Shiprocket stores the courier tracking number');
    // Reader: invoice link and cancel.
    let rowText = '';
    for (let i = 0; i < 10 && !rowText.includes(`#${giftNo}`); i++) {
      await m.goto(`${BASE}/?pagename=my-account`).catch(() => {});
      rowText = await m.$eval('.cb-print-orders', (e) => e.textContent).catch(() => '');
    }
    check(await m.$(`.cb-order-actions a[href*="cb_order=${giftNo}"]`) !== null, 'My account links to the invoice');
    const ownInvoice = await (await m.goto(`${BASE}/?cb_doc=invoice&cb_order=${giftNo}`)).text();
    check(/Invoice/.test(ownInvoice) && /Bulk Book/.test(ownInvoice), 'readers can open their own invoice');
    const otherSlip = await m.goto(`${BASE}/?cb_doc=slip&cb_order=${giftNo}`);
    check(otherSlip.status() === 403, 'readers cannot open packing slips');
    await m.goto(`${BASE}/?pagename=my-account`).catch(() => {});
    await m.click(`.cb-cancel-order[data-order="${giftNo}"]`).catch(() => {});
    await m.waitForTimeout(3500);
    let cancelled = '';
    for (let i = 0; i < 10 && !/Cancelled/.test(cancelled); i++) {
      await m.goto(`${BASE}/?pagename=my-account`).catch(() => {});
      cancelled = await m.$eval('.cb-print-orders', (e) => e.textContent).catch(() => '');
    }
    check(/Cancelled/.test(cancelled), 'readers can cancel an order that has not shipped');
    // CSV export.
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=cb_order`);
    const csvUrl = await page.$eval('a:has-text("Export CSV")', (e) => e.href).catch(() => '');
    const csv = csvUrl ? await (await page.request.get(csvUrl)).text() : '';
    check(/Order,Date,Status/.test(csv) && /Asha Rao/.test(csv), 'orders export as CSV');
    // Back in stock.
    await setPrint(bulkEdit, { print_sell: 'true', print_price: '250', print_cost: '120', print_stock: '0' });
    await m.goto(`${BASE}/?pagename=subscription`);
    await m.click('.cb-print-card:has(h3:text-is("Bulk Book")) .cb-notify').catch(() => {});
    await m.waitForFunction(() => /email you/i.test((document.querySelector('.cb-notify') || {}).textContent || ''), null, { timeout: 20000 }).catch(() => {});
    await setPrint(bulkEdit, { print_sell: 'true', print_price: '250', print_cost: '120', print_stock: '5' });
    check(/back in stock/i.test(lastMail()), `readers who asked are emailed when a book is back in stock (${lastMail().slice(0, 50)})`);
    // Gift a digital subscription.
    const giftOrder = await m.evaluate(async () => {
      const f = new FormData(); f.append('action', 'corporate_book_order'); f.append('plan', '0'); f.append('gift_email', 'friend@example.test'); f.append('_ajax_nonce', window.corporateBookAccount.nonce);
      return (await (await fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: f, credentials: 'same-origin' })).json()).data || {};
    }).catch(() => ({}));
    const giftPay = String(giftOrder.order_id || '').replace('order_', 'pay_');
    const giftSig = require('crypto').createHmac('sha256', 'smokesecret').update(`${giftOrder.order_id}|${giftPay}`).digest('hex');
    await m.evaluate(async (d) => {
      const f = new FormData(); f.append('action', 'corporate_book_verify'); f.append('order_id', d.o); f.append('payment_id', d.p); f.append('signature', d.s); f.append('_ajax_nonce', window.corporateBookAccount.nonce);
      return fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: f, credentials: 'same-origin' });
    }, { o: String(giftOrder.order_id), p: giftPay, s: giftSig }).catch(() => {});
    await page.goto(`${BASE}/wp-admin/users.php?s=friend%40example.test`);
    check(/friend@example.test/.test(await page.content()) && /gift for friend@example.test/.test(lastMail()), 'a gifted subscription creates the friend\'s account and emails them');
    // Bookmarks and reading statistics.
    await m.goto(`${BASE}/?pagename=book-test`);
    await m.waitForSelector('.cb-mark-add', { timeout: 30000 }).catch(() => {});
    await m.click('.cb-mark-add').catch(() => {});
    check(await m.$('.cb-mark .cb-mark-go') !== null, 'readers can bookmark a page');
    await m.waitForTimeout(2500);
    const sales = await (await page.goto(`${BASE}/wp-admin/edit.php?post_type=corporate_book&page=corporate_book-sales`)).text();
    check(/Print sales/.test(sales) && /₹/.test(sales) && /Test Book|Book Test/.test(sales.slice(sales.indexOf('Reading'))), 'Sales page shows sales and reading statistics');
    const readersPage = await (await page.goto(`${BASE}/wp-admin/edit.php?post_type=corporate_book&page=corporate_book-readers`)).text();
    check(/Reader accounts/.test(readersPage) && /member@example.com/.test(readersPage) && /Active subscribers/.test(readersPage), 'Readers page lists reader accounts with their details');
    const myAccount = await (await m.goto(`${BASE}/?pagename=my-account`)).text();
    check(/cb-dash-head/.test(myAccount) && /corporate-book-pages\.css/.test(myAccount), 'My account uses the dashboard layout and its stylesheet');
    check(/id="cb-sec-read"[\s\S]*cb-read-item[\s\S]*Continue reading/.test(myAccount), 'My account lists the books the reader opened');
    check(/Books read/.test(readersPage) && /<strong>\d+<\/strong><br><small><a /.test(readersPage), 'Readers page shows which books each reader read');
    check(/cb-site-head[\s\S]*All Magazines[\s\S]*Subscription[\s\S]*Cart[\s\S]*My Account/.test(myAccount) && /name="cb_q"/.test(myAccount), 'every page has the header with All Books, Subscription, Cart, My Account and search');
    await m.goto(`${BASE}/?post_type=corporate_book&cb_q=Bulk`);
    const searched = await m.$$eval('.cb-library-item', (e) => e.length).catch(() => -1);
    check(searched === 1 && /1 book found for "Bulk"/.test(await m.content()), `header search finds books by title (${searched})`);
    check(fxErrors.length === 0, `new feature pages have no JavaScript errors ${fxErrors.join(' | ')}`);
    await fx.close();
    }
    await page.goto(settingsUrl);
    const calc = await page.evaluate(() => ({ rates: document.querySelectorAll('.cb-zone-table input').length, profit: ((document.querySelector('.cb-calc .cb-calc-total') || {}).textContent || '').replace(/\s+/g, ' ') }));
    check(calc.rates === 12 && /Your profit/.test(calc.profit) && /₹239/.test(calc.profit), `Shipping & Selling shows the courier-rate table and the profit calculator (${calc.rates} ${calc.profit})`);
    const simple = await page.evaluate(() => ({ main: !!document.querySelector('#corporate_book_bg_color_box').offsetParent, extra: !!document.querySelector('#corporate_book_duration_box').offsetParent, layoutTab: !!document.querySelector('a[href="#corporate-book-tab-content-layout"]').offsetParent }));
    await page.check('#cb-show-all');
    const all = await page.evaluate(() => !!document.querySelector('#corporate_book_duration_box').offsetParent && !!document.querySelector('a[href="#corporate-book-tab-content-layout"]').offsetParent);
    check(simple.main && !simple.extra && simple.layoutTab && all, `Global Settings show main settings and the Layout look options first, all with "Show all settings" (${JSON.stringify(simple)} ${all})`);
    await page.uncheck('#cb-show-all');
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
