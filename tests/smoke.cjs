// Smoke test: boots a throwaway WordPress (Playground) with this plugin mounted and checks the main flows.
// Run from the repo root: node tests/smoke.cjs   (first run downloads WordPress)
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
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
    page.on('dialog', (d) => { dialogShown = true; d.dismiss(); });
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
    let v = await visit(null);
    check(v.body.includes('cb-sign-in') && v.body.includes('wp-login.php') && v.body.includes('action=register')
      && !hasFile(v.body, 'cb-test/sample.pdf'), 'guest sees sign-in box');
    check(/no-cache|no-store/.test(v.res.headers()['cache-control'] || ''), 'locked page is not cacheable');
    await v.ctx.close();
    v = await visit('reader');
    check(hasFile(v.body, 'cb-test/preview.pdf') && !hasFile(v.body, 'cb-test/sample.pdf'), 'reader gets preview only');
    await v.p.waitForSelector(`.cb-lock a[href="${SUBSCRIBE_URL}"]`, { state: 'visible', timeout: 30000 }).catch(() => {});
    check(await v.p.isVisible(`.cb-lock a[href="${SUBSCRIBE_URL}"]`), 'reader sees lock after preview');
    await v.ctx.close();
    v = await visit('member');
    check(hasFile(v.body, 'cb-test/sample.pdf'), 'member gets full book');
    await v.ctx.close();

    // Per-book Subscription setting and "Create preview" (plan Task 5), as the admin.
    await page.goto(`${BASE}/wp-admin/edit.php?post_type=corporate_book`);
    const editUrl = await page.$eval('a.row-title', (a) => a.href);
    await page.goto(editUrl);
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
