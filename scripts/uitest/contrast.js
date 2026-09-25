/* Real WCAG contrast check: composites every ancestor background (alpha-aware)
   down to an opaque base, then compares against the element's own colour.
   Run in the Playwright image against the live app. */
const { chromium } = require('playwright');
const SID = require('fs').readFileSync('/tmp/sid.txt', 'utf8').trim();
const TARGET = process.env.TARGET || 'http://127.0.0.1:8099';
const lum = (r, g, b) => { const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
const ratio = (a, b) => { const l1 = lum(...a), l2 = lum(...b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
/* Handles rgb(), rgba() AND color(srgb r g b) where channels are 0..1 floats. */
const parseColor = s => {
  const m = s.match(/[\d.]+/g)?.map(Number) || [0, 0, 0];
  if (/^color\(/i.test(s)) return m.slice(0, 3).map(v => Math.round(v * 255));
  return m.slice(0, 3);
};
const parseBgChannel = s => {
  const m = s.match(/[\d.]+/g)?.map(Number);
  if (!m) return null;
  if (/^color\(/i.test(s)) return m.map(v => v * 255);
  return m;
};
(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  let fails = 0;
  for (const theme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    await ctx.addCookies([{ name: 'zb_session', value: SID, domain: new URL(TARGET).hostname, path: '/' }]);
    const page = await ctx.newPage();
    for (const p of ['/', '/plans', '/orders', '/balance', '/settings']) {
      await page.goto(TARGET + p, { waitUntil: 'domcontentloaded' });
      if (theme === 'dark') await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
      await page.waitForTimeout(200);
      const rows = await page.evaluate(() => {
        const chainOf = el => { const c = []; let n = el; while (n) { c.push(getComputedStyle(n).backgroundColor); n = n.parentElement; } return c; };
        const out = []; const seen = new Set();
        for (const el of document.querySelectorAll('a,.muted,.badge,.hint,.meta-label,th,td,.term-meta,.summary-row span,.empty p,.steps,.stat-label')) {
          const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) continue;
          const cs = getComputedStyle(el); const key = String(el.className) + cs.color + cs.fontSize + cs.fontWeight;
          if (seen.has(key)) continue; seen.add(key);
          out.push({ cls: String(el.className || el.tagName).slice(0, 22), color: cs.color, chain: chainOf(el), size: parseFloat(cs.fontSize), weight: Number(cs.fontWeight) });
        }
        return out;
      });
      for (const s of rows) {
        // composite ancestor bgs (top-most last) onto an opaque white base
        const chain = [...s.chain].reverse();
        let [r, g, b] = [255, 255, 255];
        for (const c of chain) { const m = parseBgChannel(c); if (!m) continue; const a = m.length > 3 ? m[3] : 1; if (a === 0) continue; r = m[0] * a + r * (1 - a); g = m[1] * a + g * (1 - a); b = m[2] * a + b * (1 - a); }
        const col = parseColor(s.color);
        const c = ratio(col, [r, g, b]);
        const need = (s.size >= 18.66 || (s.size >= 14 && s.weight >= 700)) ? 3 : 4.5;
        if (c < need) { console.log(`${theme} ${p} ${s.cls.padEnd(24)} ${s.size}px w${s.weight} = ${c.toFixed(2)} < ${need}`); fails++; }
      }
    }
    await ctx.close();
  }
  console.log(fails === 0 ? 'ALL PASS (WCAG AA)' : 'FAILS=' + fails);
  await browser.close();
})();
