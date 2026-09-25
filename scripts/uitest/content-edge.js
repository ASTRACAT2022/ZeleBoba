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
        const vw=document.documentElement.clientWidth;
        // collect left edges of "content leaves" inside panels (text, buttons, inputs)
        const edges=new Set();
        for(const el of document.querySelectorAll('.panel *, .side-card *, .merge-panel *')){
          if(!el.matches('h1,h2,h3,p,span,strong,small,label,input,select,button,.button,td,li,a'))continue;
          const b=el.getBoundingClientRect(); if(b.width<10||b.height<3)continue;
          const cs=getComputedStyle(el); if(cs.display==='none')continue;
          edges.add(Math.round(b.left));
        }
        return {vw, edges:[...edges].sort((a,b)=>a-b)};
      });
      // a healthy page has few distinct content left-edges (ideally 2-4)
      const n=r.edges.length;
      const flag = n>6;
      if(flag)bad++;
      console.log(`${W}px ${p.padEnd(11)} edges=${n} [${r.edges.join(',')}] ${flag?'<<< RAGGED':''}`);
    }
    await ctx.close();
  }
  console.log(bad===0?'✓ CONTENT EDGES CONSISTENT':'✗ '+bad+' ragged pages');
  await b.close();
})();
