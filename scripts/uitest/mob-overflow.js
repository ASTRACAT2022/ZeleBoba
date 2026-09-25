const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const W=Number(process.env.W||375);
  let bad=0;
  for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts','/security']){
    const ctx=await b.newContext({viewport:{width:W,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
    await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage();
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(200);
    const r=await page.evaluate(()=>{const vw=document.documentElement.clientWidth;const w=[];for(const el of document.querySelectorAll('body *')){const bb=el.getBoundingClientRect();if(bb.width<1||bb.height<1)continue;if(bb.right>vw+1)w.push(String(el.className).slice(0,22)+':'+Math.round(bb.right));}return {vw,scrollW:document.documentElement.scrollWidth,w:[...new Set(w)].slice(0,4)};});
    const flag = r.scrollW>r.vw+1 || r.w.length;
    console.log(`${p} vw=${r.vw} scrollW=${r.scrollW} ${flag?'OVERFLOW '+JSON.stringify(r.w):'ok'}`);
    if(flag)bad++;
    await ctx.close();
  }
  // auth pages (anonymous)
  for(const p of ['/login','/register']){
    const ctx=await b.newContext({viewport:{width:W,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
    const page=await ctx.newPage();
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(200);
    const r=await page.evaluate(()=>({vw:document.documentElement.clientWidth,scrollW:document.documentElement.scrollWidth}));
    console.log(`${p} vw=${r.vw} scrollW=${r.scrollW} ${r.scrollW>r.vw+1?'OVERFLOW':'ok'}`);
    if(r.scrollW>r.vw+1)bad++;
    await ctx.close();
  }
  console.log(bad===0?'NO OVERFLOW':'OVERFLOW PAGES='+bad);
  await b.close();
})();
