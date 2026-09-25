const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:900,height:900},deviceScaleFactor:1,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(200);
  console.log(await page.evaluate(()=>{
    let n=document.querySelector('.panel'), chain=[];
    while(n && n.tagName!=='HTML'){const b=n.getBoundingClientRect();const cs=getComputedStyle(n);
      chain.push(`${n.tagName}.${String(n.className).slice(0,20)} w=${Math.round(b.width)} left=${Math.round(b.left)} maxW=${cs.maxWidth} gap=${cs.gap} grid=${cs.gridTemplateColumns.slice(0,40)}`);
      n=n.parentElement;}
    return chain.join('\n');
  }));
  await b.close();
})();
