const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      // find elements whose text is clipped by overflow hidden and too small for content
      const clipped=[];
      for(const el of document.querySelectorAll('*')){
        const cs=getComputedStyle(el);
        if(cs.overflow==='hidden'||cs.textOverflow==='ellipsis'){
          if(el.scrollWidth>el.clientWidth+3 && el.clientWidth>0){
            clipped.push({cls:String(el.className).slice(0,22), sw:el.scrollWidth, cw:el.clientWidth, txt:el.textContent.trim().slice(0,30)});
          }
        }
      }
      // elements overlapping: theme toggle vs anything clickable
      const tt=document.querySelector('.theme-toggle');
      const overlaps=[];
      if(tt){
        const t=tt.getBoundingClientRect();
        for(const el of document.querySelectorAll('a,button,.button')){
          if(el===tt||tt.contains(el)||el.contains(tt))continue;
          const e=el.getBoundingClientRect(); if(e.width<1||e.height<1)continue;
          const ov=!(t.right<e.left||t.left>e.right||t.bottom<e.top||t.top>e.bottom);
          if(ov)overlaps.push(String(el.className).slice(0,20)||el.tagName);
        }
      }
      // detect icons/emoji that render as tofu (missing glyph) - check if char renders (heuristic: 0 width)
      const tofu=[];
      for(const el of document.querySelectorAll('span[aria-hidden="true"],.brand-mark')){
        const b=el.getBoundingClientRect();
        if(el.textContent.trim() && b.width<4) tofu.push({txt:el.textContent.trim(),w:Math.round(b.width)});
      }
      return {clipped:clipped.slice(0,6), overlaps:[...new Set(overlaps)], tofu};
    });
    console.log(`=== ${p} === clipped:${r.clipped.length} overlaps:${JSON.stringify(r.overlaps)} tofu:${JSON.stringify(r.tofu)}`);
    for(const c of r.clipped) console.log('    clipped:', JSON.stringify(c));
  }
  await b.close();
})();
