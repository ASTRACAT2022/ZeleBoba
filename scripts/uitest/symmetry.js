const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
const W=Number(process.env.W||375);
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:W,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      // collect "card-like" containers (panels, cards, terms, buttons, list items) and check
      // their left/right insets relative to viewport -> symmetry
      const items=[];
      const sels='.panel,.panel.detail,.term,.stat-card,.side-card,.auth-card,.empty,.alert,.button,.badge,.summary,.subscription,.readiness-list article,.meta-grid,.feature-list,.table-cards tr,.earnings-card';
      for(const el of document.querySelectorAll(sels)){
        const b=el.getBoundingClientRect();
        if(b.width<20||b.height<8)continue;
        items.push({cls:String(el.className).slice(0,26),L:Math.round(b.left),R:Math.round(b.right),Wi:Math.round(b.width),Hi:Math.round(b.height)});
      }
      const vwr=vw;
      const Linset = items.map(i=>i.L);
      const Rinset = items.map(i=>vwr-i.R);
      const uniqL=[...new Set(Linset)].sort((a,b)=>a-b);
      const uniqR=[...new Set(Rinset)].sort((a,b)=>a-b);
      const widths=[...new Set(items.map(i=>i.Wi))].sort((a,b)=>a-b);
      return {vw, count:items.length, uniqL, uniqR, widths, sample:items.slice(0,14)};
    });
    console.log(`\n=== ${p} === vw=${r.vw} items=${r.count}`);
    console.log('  LEFT insets :', r.uniqL.join(', '));
    console.log('  RIGHT insets:', r.uniqR.join(', '));
    console.log('  WIDTHS      :', r.widths.join(', '));
    for(const s of r.sample) console.log(`    ${s.cls.padEnd(26)} L=${s.L} R=${s.R} W=${s.Wi} H=${s.Hi}`);
  }
  await b.close();
})();
