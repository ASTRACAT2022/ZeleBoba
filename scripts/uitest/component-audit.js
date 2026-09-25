const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(300);
  // dump every distinct component box in order with height, to spot anomalies
  console.log(await page.evaluate(()=>{
    const out=[];
    const main=document.querySelector('main');
    for(const el of main.querySelectorAll('section,article,form,.meta-grid,.inline-layout-3,.summary-row,.feature-list,.badge,.button,footer')){
      const b=el.getBoundingClientRect(); if(b.width<20||b.height<2)continue;
      out.push(`${el.tagName}.${String(el.className).slice(0,24).padEnd(24)} ${Math.round(b.width)}x${Math.round(b.height)} y=${Math.round(b.top)}`);
    }
    return out.join('\n');
  }));
  await b.close();
})();
