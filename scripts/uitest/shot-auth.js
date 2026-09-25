const { chromium } = require('playwright');
const fs = require('fs');

const SID = fs.readFileSync('/tmp/sid.txt', 'utf8').trim();
const PAGES = [
  ['admin', '/admin'],
  ['admin-plans', '/admin/plans'],
  ['admin-users', '/admin/users'],
  ['admin-config', '/admin/config'],
  ['admin-operations', '/admin/operations'],
  ['admin-intelligence', '/admin/intelligence'],
  ['account', '/'],
  ['plans', '/plans'],
  ['balance', '/balance'],
  ['orders', '/orders'],
  ['settings', '/settings'],
];

(async () => {
  const base = process.env.TARGET || 'http://127.0.0.1:8099';
  const out = '/out';
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'ru-RU' });
  await ctx.addCookies([{ name: 'zb_session', value: SID, domain: '127.0.0.1', path: '/' }]);
  const page = await ctx.newPage();
  const report = [];
  for (const [n, p] of PAGES) {
    try {
      const resp = await page.goto(base + p, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(700);
      const url = page.url();
      const code = resp ? resp.status() : 0;
      if (url.includes('/login')) { report.push({ n, p, skip: 'redirected to login', code }); continue; }
      await page.screenshot({ path: `${out}/${n}.png`, fullPage: true });
      const geo = await page.evaluate(() => {
        const doc = document.documentElement;
        const issues = [];
        for (const el of document.querySelectorAll('*')) {
          const r = el.getBoundingClientRect();
          if (r.width > 0 && (r.right > innerWidth + 1 || r.left < -1)) {
            issues.push({ tag: el.tagName, cls: String(el.className).slice(0, 50), right: Math.round(r.right) });
            if (issues.length > 8) break;
          }
        }
        return { vw: innerWidth, scrollW: doc.scrollWidth, overflowX: doc.scrollWidth > innerWidth + 1, issues };
      });
      report.push({ n, p, code, ...geo });
    } catch (e) { report.push({ n, p, error: e.message.split('\n')[0] }); }
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 2));
})();
