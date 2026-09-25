const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(300);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      // 1) section gap consistency: tops of block-level siblings inside main
      const main=document.querySelector('main');
      // 2) list any element whose padding differs from common values
      const padCount={};
      const gapCount={};
      const fontSizes=[];
      for(const el of main.querySelectorAll('*')){
        const b=el.getBoundingClientRect(); if(b.width<10||b.height<4)continue;
        const cs=getComputedStyle(el);
        if(cs.padding!=='0px') padCount[cs.padding]=(padCount[cs.padding]||0)+1;
        if(cs.gap && cs.gap!=='normal' && cs.gap!=='0px') gapCount[cs.gap]=(gapCount[cs.gap]||0)+1;
        if(el.children.length===0 && el.textContent.trim()) fontSizes.push(cs.fontSize);
      }
      // radii on cards
      const radii={};
      for(const el of main.querySelectorAll('.panel,.term,.button,.badge,.alert,.empty')){
        const cs=getComputedStyle(el);
        radii[cs.borderRadius]=(radii[cs.borderRadius]||0)+1;
      }
      const uniqFont=[...new Set(fontSizes)].sort((a,b)=>parseFloat(a)-parseFloat(b));
      return {vw, padCount, gapCount, radii, uniqFont};
    });
    console.log(`\n=== ${p} ===`);
    console.log('  paddings:', Object.entries(r.padCount).sort((a,b)=>b[1]-a[1]).slice(0,6).map(x=>x[0]+'×'+x[1]).join('  '));
    console.log('  gaps    :', Object.entries(r.gapCount).sort((a,b)=>b[1]-a[1]).slice(0,6).map(x=>x[0]+'×'+x[1]).join('  '));
    console.log('  radii   :', Object.entries(r.radii).map(x=>x[0]+'×'+x[1]).join('  '));
    console.log('  fonts   :', r.uniqFont.join(', '));
  }
  await b.close();
})();
