const { chromium } = require('playwright');
const W=Number(process.env.W||390);
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  for(const p of ['/login','/register','/forgot','/reset']){
    const ctx=await b.newContext({viewport:{width:W,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
    const page=await ctx.newPage();
    const resp=await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'}).catch(e=>null);
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      const vw=document.documentElement.clientWidth;
      const ovf=[];for(const el of document.querySelectorAll('body *')){const b=el.getBoundingClientRect();if(b.width<1)continue;if(b.right>vw+1)ovf.push(String(el.className).slice(0,20)+':'+Math.round(b.right));}
      const card=document.querySelector('.auth-card');
      const inputs=[...document.querySelectorAll('input:not([type=hidden]),select')].map(i=>({n:i.name||i.type,w:Math.round(i.getBoundingClientRect().width)}));
      const btns=[...document.querySelectorAll('button,.button')].map(b=>({t:b.textContent.trim().slice(0,16),w:Math.round(b.getBoundingClientRect().width),h:Math.round(b.getBoundingClientRect().height)}));
      const cw=[...new Set(inputs.map(i=>i.w))];
      return {status:document.title, vw, scrollW:document.documentElement.scrollWidth, ovf:[...new Set(ovf)].slice(0,3), cardW:card?Math.round(card.getBoundingClientRect().width):0, inputWidths:cw, btns, h1:document.querySelector('h1')?document.querySelector('h1').textContent.trim().slice(0,20):null};
    });
    console.log(`${p} [${resp?resp.status():'ERR'}] scrollW=${r.scrollW}/${r.vw} ovf=${JSON.stringify(r.ovf)} cardW=${r.cardW} inputW=${JSON.stringify(r.inputWidths)} h1="${r.h1}"`);
    for(const bt of r.btns) console.log('     btn', bt.t, bt.w+'x'+bt.h);
    await ctx.close();
  }
  await b.close();
})();
