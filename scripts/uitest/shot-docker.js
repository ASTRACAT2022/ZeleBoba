const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const out = '/out';
  fs.mkdirSync(out, { recursive: true });
  const base = process.env.TARGET || 'http://host.docker.internal:8099';
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    ignoreHTTPSErrors: true,
    locale: 'ru-RU',
    deviceScaleFactor: 1,
  });
  const page = await ctx.newPage();
  const done = [];
  for (const [name, p] of [['login', '/login'], ['register', '/register']]) {
    try {
      await page.goto(base + p, { waitUntil: 'domcontentloaded', timeout: 25000 });
      await page.waitForTimeout(600);
      await page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
      done.push(name);
    } catch (e) { console.log(`${name}: FAIL ${e.message.split('\n')[0]}`); }
  }
  // dark mode
  try {
    await page.goto(base + '/login', { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${out}/login-dark.png`, fullPage: true });
    done.push('login-dark');
  } catch (e) { console.log('dark: FAIL ' + e.message.split('\n')[0]); }
  await browser.close();
  console.log('done:', done.join(','));
})();
