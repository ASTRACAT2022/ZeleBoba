const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  let bad=0;
  for(const W of [320,390,768,1440]){
    const ctx=await b.newContext({viewport:{width:W,height:900},deviceScaleFactor:1,isMobile:W<768,locale:'ru-RU'});
    await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage();
    for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
      await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
      await page.waitForTimeout(180);
      const r=await page.evaluate(()=>{
        // only direct block children of <main>: these are the page's structural blocks
        const main=document.querySelector('main');
        const edges=[], rights=[];
        for(const el of main.children){
          const b=el.getBoundingClientRect(); if(b.width<40||b.height<4)continue;
          const cs=getComputedStyle(el); if(cs.display==='none')continue;
          if(el.classList.contains('demo-bar'))continue;
          edges.push(Math.round(b.left));
          rights.push(Math.round(document.documentElement.clientWidth-b.right));
        }
        return {vw:document.documentElement.clientWidth, edges:[...new Set(edges)], rights:[...new Set(rights)]};
      });
      const flag=r.edges.length>2||r.rights.length>2;
      if(flag)bad++;
      console.log(`${W}px ${p.padEnd(11)} L=${JSON.stringify(r.edges)} R=${JSON.stringify(r.rights)} ${flag?'<<<':'ok'}`);
    }
    await ctx.close();
  }
  console.log(bad===0?'✓ ALL STRUCTURAL BLOCKS ALIGNED':'✗ '+bad+' pages with mixed block edges');
  await b.close();
})();
