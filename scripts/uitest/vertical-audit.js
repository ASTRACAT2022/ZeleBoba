const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,locale:'ru-RU'});
  await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
  const page=await ctx.newPage();
  for(const p of ['/','/plans','/orders','/settings']){
    await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
    await page.waitForTimeout(250);
    const r=await page.evaluate(()=>{
      const issues=[];
      // vertical gaps between consecutive siblings inside main (detect inconsistent rhythm)
      const main=document.querySelector('main');
      const walk=(el)=>{ for(const c of el.children){ if(['SCRIPT','STYLE'].includes(c.tagName))continue; walk(c);} };
      // collect top-level blocks in main
      const blocks=[...main.children].filter(c=>c.getBoundingClientRect().height>3);
      const gaps=[];
      for(let i=1;i<blocks.length;i++){ const prev=blocks[i-1].getBoundingClientRect(),cur=blocks[i].getBoundingClientRect(); gaps.push(Math.round(cur.top-prev.bottom)); }
      // text that wraps to many lines (possible bad layout)
      const tallText=[];
      for(const el of main.querySelectorAll('p,span,td,small,li,h2,h3')){
        const b=el.getBoundingClientRect(); if(b.height<1)continue;
        const lh=parseFloat(getComputedStyle(el).lineHeight)||16;
        const lines=Math.round(b.height/lh);
        if(lines>=5 && el.children.length===0 && el.textContent.trim().length<120){tallText.push({cls:String(el.className).slice(0,20),lines,txt:el.textContent.trim().slice(0,30)});}
      }
      // buttons with mismatched heights
      const btnH=[...new Set([...main.querySelectorAll('.button')].map(b=>Math.round(b.getBoundingClientRect().height)))];
      // footer alignment
      const footer=document.querySelector('footer');
      const fH=footer?Math.round(footer.getBoundingClientRect().height):0;
      return {topGaps:gaps, tallText:tallText.slice(0,6), btnH, fH};
    });
    console.log(`\n=== ${p} ===`);
    console.log('  top-level gaps:', r.topGaps.join(', '));
    console.log('  button heights:', r.btnH.join(', '), '| footer h:', r.fH);
    if(r.tallText.length) for(const t of r.tallText) console.log('  tall text:', JSON.stringify(t));
  }
  await b.close();
})();
