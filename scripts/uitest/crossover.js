const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  for(const W of [860,861,900,1024,1025]){
    const ctx=await b.newContext({viewport:{width:W,height:900},deviceScaleFactor:1,locale:'ru-RU'});
    await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage();
    await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(200);
    const r=await page.evaluate(()=>{
      const sb=document.querySelector('.sidebar');
      const main=document.querySelector('main');
      const sbcs=getComputedStyle(sb);
      const mcs=getComputedStyle(main);
      const card=document.querySelector('.panel');
      const cb=card.getBoundingClientRect();
      return {sbPos:sbcs.position, sbW:Math.round(sb.getBoundingClientRect().width), mainML:mcs.marginLeft, cardW:Math.round(cb.width), cardL:Math.round(cb.left), vw:innerWidth};
    });
    console.log(`${W}px sidebar=${r.sbPos}/${r.sbW} mainML=${r.mainML} card=${r.cardW}@${r.cardL} (vw=${r.vw})`);
    await ctx.close();
  }
  await b.close();
})();
