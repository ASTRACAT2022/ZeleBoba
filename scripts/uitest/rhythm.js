const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:375,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/balance','/settings']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      // walk from main down 3 levels, log each element's box + padding
      const out=[];
      const walk=(el,d,baseL)=>{
        for(const c of el.children){
          if(['SCRIPT','STYLE'].includes(c.tagName))continue;
          const b=c.getBoundingClientRect(); if(b.width<10)continue;
          const cs=getComputedStyle(c);
          out.push({d, tag:c.tagName, cls:String(c.className).slice(0,30), L:Math.round(b.left), W:Math.round(b.width), pad:cs.padding, mar:cs.margin, gap:cs.gap});
          if(d<2) walk(c,d+1,b.left);
        }
      };
      walk(document.querySelector('main'),0,0);
      return out.slice(0,40);
    });
    console.log(`\n===== ${p} =====`);
    for(const e of r) console.log(`${'  '.repeat(e.d)}${e.tag}.${e.cls} L=${e.L} W=${e.W} pad=${e.pad} gap=${e.gap}`);
  }
  await b.close();
})();
