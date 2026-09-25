const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/orders',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(400);
  console.log(await page.evaluate(()=>{
    const tt=document.querySelector('.theme-toggle').getBoundingClientRect();
    const out=[];
    for(const el of document.querySelectorAll('a')){
      const e=el.getBoundingClientRect(); if(e.width<1||e.height<1)continue;
      const ov=!(tt.right<e.left||tt.left>e.right||tt.bottom<e.top||tt.top>e.bottom);
      if(ov)out.push({txt:el.textContent.trim().slice(0,20),href:el.getAttribute('href'),x:Math.round(e.left),y:Math.round(e.top),w:Math.round(e.width),h:Math.round(e.height)});
    }
    return JSON.stringify({toggle:{x:Math.round(tt.left),y:Math.round(tt.top)},overlaps:out},null,1);
  }));
  await b.close();
})();
