const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const base = process.env.TARGET || 'http://127.0.0.1:8099';
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);

  const data = await page.evaluate(() => {
    const out = {};
    // visible inputs
    out.inputs = [...document.querySelectorAll('input')].map(i => {
      const r = i.getBoundingClientRect(); const cs = getComputedStyle(i);
      return { type: i.type, name: i.name, vis: cs.display !== 'none' && r.width > 0,
               w: Math.round(r.width), h: Math.round(r.height), radius: cs.borderRadius, border: cs.border,
               font: cs.fontSize, pad: cs.padding };
    });
    // which stylesheet wins for a control: list matching rules
    out.sheets = [...document.styleSheets].map(s => ({ href: s.href || 'inline', rules: (() => { try { return s.cssRules.length; } catch { return 'blocked'; } })() }));
    // computed tokens
    const rootCS = getComputedStyle(document.documentElement);
    out.tokens = {
      ink: rootCS.getPropertyValue('--ink').trim(),
      green: rootCS.getPropertyValue('--green').trim(),
      lime: rootCS.getPropertyValue('--lime').trim(),
      bg: rootCS.getPropertyValue('--bg').trim(),
      canvas: rootCS.getPropertyValue('--canvas').trim(),
      surface: rootCS.getPropertyValue('--surface').trim(),
      line: rootCS.getPropertyValue('--line').trim(),
    };
    // sample: heading colors + radii of all buttons
    out.buttons = [...document.querySelectorAll('.button')].map(b => {
      const cs = getComputedStyle(b);
      return { text: b.textContent.trim().slice(0, 20), bg: cs.backgroundColor, color: cs.color, radius: cs.borderRadius };
    });
    return out;
  });
  await browser.close();
  console.log(JSON.stringify(data, null, 2));
})();
