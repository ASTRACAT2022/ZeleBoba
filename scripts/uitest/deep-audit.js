const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
const W=Number(process.env.W||390);
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:W,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      const issues=[];
      // 1) elements narrower than their container oddly / not full width where siblings are
      // 2) buttons that aren't full width while siblings are
      // 3) text overflow / clipping
      for(const el of document.querySelectorAll('main *')){
        const b=el.getBoundingClientRect(); if(b.width<2||b.height<2)continue;
        const cs=getComputedStyle(el);
        if(el.scrollWidth>el.clientWidth+2 && cs.overflowX!=='auto' && cs.overflowX!=='scroll' && el.children.length===0){
          issues.push({type:'clip', cls:String(el.className).slice(0,24), sw:el.scrollWidth, cw:el.clientWidth, txt:el.textContent.trim().slice(0,24)});
        }
      }
      // 4) inconsistent form control widths inside the same form
      for(const form of document.querySelectorAll('form')){
        const ctrls=[...form.querySelectorAll('input:not([type=hidden]),select,textarea')].map(c=>({w:Math.round(c.getBoundingClientRect().width),tag:c.tagName,n:c.name||c.type}));
        const ws=[...new Set(ctrls.map(c=>c.w))];
        if(ctrls.length>1 && ws.length>1 && Math.max(...ws)-Math.min(...ws)>4){
          issues.push({type:'formctl', form:String(form.className).slice(0,20), widths:ws});
        }
      }
      // 5) lists where items have wildly different heights
      for(const ul of document.querySelectorAll('ul,ol')){
        const items=[...ul.children].map(c=>Math.round(c.getBoundingClientRect().height)).filter(h=>h>0);
        if(items.length>2){const mx=Math.max(...items),mn=Math.min(...items); if(mx>mn*2.2 && mx-mn>40){issues.push({type:'listvar', cls:String(ul.className).slice(0,22), min:mn,max:mx});}}
      }
      // 6) headings with inconsistent left edge inside same container
      const seen=new Set();const bad=[];
      for(const h of document.querySelectorAll('h1,h2,h3')){
        const b=h.getBoundingClientRect();if(b.width<5)continue;
        bad.push({t:h.tagName,x:Math.round(b.left),fs:getComputedStyle(h).fontSize,txt:h.textContent.trim().slice(0,18)});
      }
      return {vw, issues, headings:bad};
    });
    console.log(`\n=== ${p} vw=${r.vw} ===`);
    for(const i of r.issues.slice(0,8)) console.log('  ', JSON.stringify(i));
    if(!r.issues.length) console.log('   (no structural issues)');
    console.log('   headings:', r.headings.map(h=>`${h.t}@${h.x} ${h.fs}`).join(' | '));
  }
  await b.close();
})();
