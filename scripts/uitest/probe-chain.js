const { chromium } = require('playwright');
const fs = require('fs');
const SID = fs.readFileSync('/tmp/sid.txt', 'utf8').trim();
(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 } });
  await ctx.addCookies([{ name: 'zb_session', value: SID, domain: '127.0.0.1', path: '/' }]);
  const page = await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/admin/operations', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);
  const info = await page.evaluate(() => {
    const t = document.querySelector('table');
    const chain = [];
    let el = t;
    while (el && el !== document.body) {
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      chain.push({ tag: el.tagName, cls: String(el.className).slice(0, 40), ox: cs.overflowX, w: Math.round(r.width), right: Math.round(r.right) });
      el = el.parentElement;
    }
    return { tableW: Math.round(t.getBoundingClientRect().width), chain, docOverflow: document.documentElement.scrollWidth > innerWidth + 1 };
  });
  console.log(JSON.stringify(info, null, 2));
  await b.close();
})();
