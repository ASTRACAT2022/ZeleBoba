const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  await page.goto('http://127.0.0.1:8099/',{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(300);
  console.log(await page.evaluate(()=>{
    // find the .inline-layout-3 with width 292 and trace ancestors
    const out=[];
    for(const el of document.querySelectorAll('.inline-layout-3')){
      const b=el.getBoundingClientRect();
      let n=el, chain=[];
      while(n && n.tagName!=='BODY'){const cs=getComputedStyle(n);chain.push(`${n.tagName}.${String(n.className).slice(0,18)}[w=${Math.round(n.getBoundingClientRect().width)} pad=${cs.padding}]`);n=n.parentElement;}
      out.push({w:Math.round(b.width),cls:String(el.className),chain:chain.join(' < ')});
    }
    return JSON.stringify(out,null,1);
  }));
  await b.close();
})();
