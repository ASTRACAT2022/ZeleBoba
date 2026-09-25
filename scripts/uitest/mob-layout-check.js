const { chromium } = require('playwright');
const SID=require('fs').readFileSync('/tmp/sid.txt','utf8').trim();
const lum=(r,g,b)=>{const f=c=>{c/=255;return c<=0.03928?c/12.92:((c+0.055)/1.055)**2.4;};return .2126*f(r)+.7152*f(g)+.0722*f(b);};
const ratio=(a,b)=>{const l1=lum(...a),l2=lum(...b);return (Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05);};
const pc=s=>{const m=s.match(/[\d.]+/g)?.map(Number)||[0,0,0];return /^color\(/i.test(s)?m.slice(0,3).map(v=>Math.round(v*255)):m.slice(0,3);};
const pcb=s=>{const m=s.match(/[\d.]+/g)?.map(Number);if(!m)return null;return /^color\(/i.test(s)?m.map(v=>v*255):m;};
(async()=>{
  const b=await chromium.launch({args:['--no-sandbox']});
  let contrastFails=0, misalign=[], tinyGap=[];
  for(const theme of ['light','dark']){
    const ctx=await b.newContext({viewport:{width:375,height:812},deviceScaleFactor:2,isMobile:true,hasTouch:true,locale:'ru-RU'});
    await ctx.addCookies([{name:'zb_session',value:SID,domain:'127.0.0.1',path:'/'}]);
    const page=await ctx.newPage();
    for(const p of ['/','/plans','/orders','/balance','/settings','/referral','/gifts']){
      await page.goto('http://127.0.0.1:8099'+p,{waitUntil:'domcontentloaded'});
      if(theme==='dark') await page.evaluate(()=>{document.documentElement.dataset.theme='dark';});
      await page.waitForTimeout(200);
      const r=await page.evaluate(()=>{
        const vw=document.documentElement.clientWidth;
        // contrast
        const chainOf=el=>{const c=[];let n=el;while(n){c.push(getComputedStyle(n).backgroundColor);n=n.parentElement;}return c;};
        const cs=[];const seen=new Set();
        for(const el of document.querySelectorAll('p,span,small,td,th,li,label,a,h1,h2,h3,.badge,.muted,.meta-label,.meta-value')){
          const bb=el.getBoundingClientRect();if(bb.width<2||bb.height<2)continue;
          const st=getComputedStyle(el);const k=String(el.className)+st.color+st.fontSize;
          if(seen.has(k))continue;seen.add(k);
          cs.push({color:st.color,chain:chainOf(el),size:parseFloat(st.fontSize),w:Number(st.fontWeight),cls:String(el.className).slice(0,18)});
        }
        return {vw, cs};
      });
      for(const s of r.cs){
        const chain=[...s.chain].reverse();let [R,G,B]=[255,255,255];
        for(const c of chain){const m=pcb(c);if(!m)continue;const a=m.length>3?m[3]:1;if(a===0)continue;R=m[0]*a+R*(1-a);G=m[1]*a+G*(1-a);B=m[2]*a+B*(1-a);}
        const cr=ratio(pc(s.color),[R,G,B]);
        const need=(s.size>=18.66||(s.size>=14&&s.w>=700))?3:4.5;
        if(cr<need){console.log(`${theme} ${p} CONTRAST ${s.cls} ${s.size}px = ${cr.toFixed(2)}`);contrastFails++;}
      }
    }
    await ctx.close();
  }
  console.log(contrastFails===0?'CONTRAST: ALL PASS AA':'CONTRAST FAILS='+contrastFails);
  await b.close();
})();
