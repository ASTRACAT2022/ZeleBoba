const { chromium } = require('playwright');
const fs = require('fs');
const SID = fs.readFileSync('/tmp/sid.txt', 'utf8').trim();

const PAGES = ['/admin', '/admin/plans', '/admin/users', '/admin/config', '/admin/operations',
               '/', '/plans', '/balance', '/orders', '/settings'];

// spacing scale used by the design system
const SCALE = [0, 4, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32, 34, 36, 40, 44];

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'ru-RU' });
  await ctx.addCookies([{ name: 'zb_session', value: SID, domain: '127.0.0.1', path: '/' }]);
  const page = await ctx.newPage();
  const out = {};

  for (const p of PAGES) {
    await page.goto('http://127.0.0.1:8099' + p, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(400);
    out[p] = await page.evaluate((SCALE) => {
      const res = { unaligned: [], cardGrids: [], radii: {}, fonts: {} };
      const px = v => Math.round(parseFloat(v) || 0);

      // 1) grid-row alignment: within a CSS grid, sibling cards should share top
      for (const grid of document.querySelectorAll('.plans, .control-metrics, .earnings-grid, .ops-kpi, .overview, .control-layout, .config-layout, .form-grid, .admin-form, .recovery-grid')) {
        const kids = [...grid.children].filter(k => k.getBoundingClientRect().width > 0);
        if (kids.length < 2) continue;
        const rows = {};
        for (const k of kids) {
          const r = k.getBoundingClientRect();
          const key = Math.round(r.top / 5) * 5;
          (rows[key] = rows[key] || []).push(Math.round(r.width));
        }
        for (const [top, widths] of Object.entries(rows)) {
          const maxd = Math.max(...widths) - Math.min(...widths);
          if (maxd > 2) res.cardGrids.push({ grid: grid.className, top, widths, delta: maxd });
        }
      }

      // 2) radius consistency per role
      const roles = { card: '.panel, .plan, .auth-card', ctrl: '.button, input, select', pill: '.badge' };
      for (const [role, sel] of Object.entries(roles)) {
        const seen = {};
        for (const el of document.querySelectorAll(sel)) {
          const cs = getComputedStyle(el);
          const r = cs.borderRadius.split(' ')[0];
          if (r && r !== '0px') seen[r] = (seen[r] || 0) + 1;
        }
        res.radii[role] = seen;
      }

      // 3) font-size consistency for same-level headings
      for (const sel of ['h1', 'h2', '.button', 'td', 'th', 'label']) {
        const seen = {};
        for (const el of document.querySelectorAll(sel)) {
          const f = getComputedStyle(el).fontSize;
          seen[f] = (seen[f] || 0) + 1;
        }
        // flag if >1 distinct size (could be intentional, but worth noting)
        const k = Object.keys(seen);
        if (k.length > 1) res.fonts[sel] = seen;
      }

      // 4) left edges: top-level blocks should share an x origin
      const tops = [...document.querySelectorAll('.main > section, .main > header, .main > .panel, .main > .overview')];
      const xs = tops.filter(t => t.getBoundingClientRect().width > 0).map(t => Math.round(t.getBoundingClientRect().left));
      res.leftEdges = [...new Set(xs)];

      return res;
    }, SCALE);
  }
  await b.close();
  fs.writeFileSync('/out/align.json', JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));
})();
