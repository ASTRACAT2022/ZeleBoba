const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(300);
  console.log(await page.evaluate(()=>{
    const out=[];
    for(const el of document.querySelectorAll('.sidebar, .sidebar > *, .sidebar .brand, .theme-toggle, .sidebar-bottom, nav, nav a')){
      const b=el.getBoundingClientRect();
      out.push({tag:el.tagName,cls:String(el.className).slice(0,20),x:Math.round(b.left),y:Math.round(b.top),w:Math.round(b.width),h:Math.round(b.height),disp:getComputedStyle(el).display});
    }
    const sb=document.querySelector('.sidebar').getBoundingClientRect();
    return JSON.stringify({sidebarH:Math.round(sb.height),items:out},null,1);
  }));
  await b.close();
})();
