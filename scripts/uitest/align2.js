const { chromium } = require('playwright');
const fs = require('fs');
const SID = fs.readFileSync('/tmp/sid.txt', 'utf8').trim();
const TARGET = process.env.TARGET || 'http://127.0.0.1:8099';
const PAGES = ['/', '/plans', '/orders', '/balance', '/settings'];
(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'ru-RU' });
  await ctx.addCookies([{ name: 'zb_session', value: SID, domain: new URL(TARGET).hostname, path: '/' }]);
  const page = await ctx.newPage();
  const out = {};
  for (const p of PAGES) {
    await page.goto(TARGET + p, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);
    out[p] = await page.evaluate(() => {
      const res = { overflow: [], radii: {}, fonts: {}, leftEdges: [], cardWidths: {}, gridRows: {} };
      for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.right > innerWidth + 1 && !el.closest('.table-wrap')) {
          res.overflow.push({ tag: el.tagName, cls: String(el.className).slice(0, 40) });
          if (res.overflow.length > 4) break;
        }
      }
      for (const [role, sel] of Object.entries({ card: '.panel, .plan, .stat-card, .auth-card', ctrl: '.button, input, select', pill: '.badge, .term-badge' })) {
        const seen = {};
        for (const el of document.querySelectorAll(sel)) {
          const br = getComputedStyle(el).borderRadius.split(' ')[0];
          if (br && br !== '0px') seen[br] = (seen[br] || 0) + 1;
        }
        res.radii[role] = seen;
      }
      for (const sel of ['h1', 'h2', 'body', 'td', 'th']) {
        const seen = {};
        for (const el of document.querySelectorAll(sel)) { const v = getComputedStyle(el).fontSize; seen[v] = (seen[v] || 0) + 1; }
        const k = Object.keys(seen); if (k.length > 1) res.fonts[sel] = seen;
      }
      const tops = [...document.querySelectorAll('.main > section, .main > header, .main > .panel, .main > .overview, .main > .stat-grid, .main > .alert')];
      res.leftEdges = [...new Set(tops.filter(t => t.getBoundingClientRect().width > 0).map(t => Math.round(t.getBoundingClientRect().left)))];
      for (const g of document.querySelectorAll('.stat-grid, .control-metrics, .pricing, .overview, .meta-grid')) {
        const kids = [...g.children].filter(k => k.getBoundingClientRect().width > 0);
        if (kids.length < 2) continue;
        const rows = {};
        for (const k of kids) { const r = k.getBoundingClientRect(); const key = Math.round(r.top / 5) * 5; (rows[key] = rows[key] || []).push(Math.round(r.width)); }
        for (const [t, w] of Object.entries(rows)) { const d = Math.max(...w) - Math.min(...w); if (d > 2) res.gridRows[g.className + '@' + t] = { w, d }; }
      }
      return res;
    });
  }
  await b.close();
  fs.writeFileSync('/out/align2.json', JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));
})();
