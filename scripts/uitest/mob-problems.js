const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:375,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(300);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      const small=[],overflow=[];
      for(const el of document.querySelectorAll('a,button,[role=button],input[type=submit]')){
        const b=el.getBoundingClientRect(); if(b.width<1||b.height<1)continue;
        if(b.height<36) small.push({t:el.tagName,c:String(el.className).slice(0,18),h:Math.round(b.height),w:Math.round(b.width),x:el.textContent.trim().slice(0,16)});
        if(b.right>vw+1) overflow.push({c:String(el.className).slice(0,18),r:Math.round(b.right)});
      }
      const s=new Set();
      return {h:document.documentElement.scrollHeight, small:small.filter(x=>{const k=x.t+x.c+x.h+x.x;if(s.has(k))return false;s.add(k);return true;}).slice(0,8), overflow:overflow.slice(0,5)};
    });
    console.log(`${p} height=${r.h}px`);
    if(r.small.length) console.log('   SMALL(<36px):', r.small.map(x=>`${x.t}.${x.c}(${x.h}px "${x.x}")`).join(', '));
    if(r.overflow.length) console.log('   OVERFLOW:', JSON.stringify(r.overflow));
  }
  await b.close();
})();
