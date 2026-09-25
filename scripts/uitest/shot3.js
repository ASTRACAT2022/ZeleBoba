const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  // logged-in pages
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:3,isMobile:true,hasTouch:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const [p,n] of [['/','home'],['/plans','plans'],['/orders','orders'],['/balance','balance'],['/settings','settings']]){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(400);
    await page.screenshot({path:`/out/${n}.png`,fullPage:false});
  }
  await ctx.close();
  // auth page
  const ctx2=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:3,isMobile:true,locale:'ru-RU'});
  const p2=await ctx2.newPage();
  await p2.goto('http://127.0.0.1:8099/login',{waitUntil:'domcontentloaded'});
  await p2.waitForTimeout(400);
  await p2.screenshot({path:'/out/login.png',fullPage:false});
  await b.close();
})();
