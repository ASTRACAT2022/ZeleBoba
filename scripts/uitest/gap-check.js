const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  for(const W of [861,900,1000,1100,1200,1440]){
    const ctx=await b.newContext({viewport:{width:W,height:900},deviceScaleFactor:1,locale:'ru-RU'});
    await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage();
    await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(180);
    const r=await page.evaluate(()=>{
      const card=document.querySelector('.panel');
      const b=card.getBoundingClientRect();
      return {rightGap:Math.round(innerWidth-b.right), cardW:Math.round(b.width)};
    });
    console.log(`${W}px cardW=${r.cardW} rightGap=${r.rightGap}px`);
    await ctx.close();
  }
  await b.close();
})();
