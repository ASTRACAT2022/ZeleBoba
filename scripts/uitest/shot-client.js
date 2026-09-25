const { chromium } = require('playwright');
const fs = require('fs');
const SID = fs.readFileSync('/tmp/sid.txt', 'utf8').trim();
const TARGET = process.env.TARGET || 'http://127.0.0.1:8099';
const PAGES = (process.env.PAGES || '/,/plans,/orders,/balance,/settings,/referral,/gifts').split(',');
const VIEWPORTS = [
  { name: 'desk', width: 1440, height: 1000 },
  { name: 'mob', width: 390, height: 844 },
];
(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const report = [];
  for (const vp of VIEWPORTS) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, locale: 'ru-RU', deviceScaleFactor: 1 });
    await ctx.addCookies([{ name: 'zb_session', value: SID, domain: new URL(TARGET).hostname, path: '/' }]);
    const page = await ctx.newPage();
    for (const p of PAGES) {
      const name = (p === '/' ? 'home' : p.replace(/\//g, '') || 'home') + '-' + vp.name;
      try {
        await page.goto(TARGET + p, { waitUntil: 'networkidle', timeout: 20000 });
        await page.waitForTimeout(300);
        await page.screenshot({ path: '/out/' + name + '.png', fullPage: true });
        const m = await page.evaluate(() => ({
          docOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
          h1: document.querySelector('h1')?.textContent?.trim() || '',
          title: document.title,
        }));
        report.push({ page: p, vp: vp.name, ...m });
      } catch (e) {
        report.push({ page: p, vp: vp.name, error: String(e).slice(0, 120) });
      }
    }
    await ctx.close();
  }
  await b.close();
  fs.writeFileSync('/out/report.json', JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
