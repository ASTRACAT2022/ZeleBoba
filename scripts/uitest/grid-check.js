const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  let allGood=true;
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      // group "card" edges and content edges
      const cardEdges=new Set(), contentEdges=new Set();
      for(const el of document.querySelectorAll('.panel,.panel.detail,.term,.merge-panel,.side-card,.connection,.auth-card')){
        const b=el.getBoundingClientRect(); if(b.width<40)continue;
        cardEdges.add(Math.round(b.left));
        // first text-ish child
        const c=el.querySelector('h2,h3,p,li,span,td,label');
        if(c){const cb=c.getBoundingClientRect(); if(cb.width>10) contentEdges.add(Math.round(cb.left));}
      }
      // detect horizontal centering of "symmetric" groups: cards should share L and R
      const cards=[...document.querySelectorAll('.panel,.term,.side-card,.connection')].map(e=>{const b=e.getBoundingClientRect();return {L:Math.round(b.left),R:Math.round(vw-b.right),W:Math.round(b.width)};}).filter(c=>c.W>40);
      const uniqL=[...new Set(cards.map(c=>c.L))];
      const uniqR=[...new Set(cards.map(c=>c.R))];
      const widths=[...new Set(cards.map(c=>c.W))];
      // vertical gaps between consecutive cards
      const sorted=cards.map(c=>c).sort((a,b)=>a.L-b.L);
      return {vw, uniqL, uniqR, widths};
    });
    const ok = r.uniqL.length<=2 && r.uniqR.length<=2;
    if(!ok)allGood=false;
    console.log(`${p} L-insets=${JSON.stringify(r.uniqL)} R-insets=${JSON.stringify(r.uniqR)} widths=${JSON.stringify(r.widths)} ${ok?'✓':'✗'}`);
  }
  console.log(allGood?'✓ ALL CARDS ALIGNED':'✗ MISALIGNED CARDS');
  await b.close();
})();
