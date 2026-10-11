const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const css=fs.readFileSync(path.join(__dirname,'../public/styles.css'),'utf8');
const amount='123 456 789 012 345,67';
const fixture=`<!doctype html><html lang="pl"><meta charset="utf-8"><style>${css}</style><body>
<div class="shell"><main class="main">
 <div class="page-title"><div><h1>Przegląd portfela</h1><p>Pełne kwoty w małych ekranach.</p></div><div class="flex"><button class="btn">Dodaj transakcję</button></div></div>
 <div class="metrics">${['Wartość portfela','Wynik całego portfela','Dostępna gotówka','Dywidendy i odsetki'].map((label,i)=>`<div class="metric ${i===0?'main-metric':''}"><div class="metric-label">${label}</div><div class="metric-value"><span>${i===1?'+':''}${amount}</span><small>PLN</small></div><div class="metric-bottom"><b>+123,45%</b><span>względem wpłat netto</span></div></div>`).join('')}</div>
 <section class="wealth-overview panel"><div><span>CAŁY PORTFEL</span><strong class="wealth-total mono">${amount} PLN</strong><span class="muted small">Inwestycje + gotówka + OFE + PPK + zapis ZUS</span></div><div class="wealth-mini"><span>OFE + PPK <b>${amount} PLN</b></span><span>Zapis ZUS <b>${amount} PLN</b></span><button class="btn">Zestawienie i wykres</button></div></section>
 <div class="cash-cards"><section class="panel cash-card"><span>USD</span><div class="balance">${amount} USD</div><p class="muted small">Saldo waluty z ostatniej wyceny.</p></section></div>
 <div class="card-grid"><section class="panel market-card" data-asset="fixture" tabindex="0" role="button"><span class="asset-cell"><span class="asset-logo">AB</span><span><strong>BardzoDługaNazwaInstrumentuBezSpacjiDoPrzetestowania</strong><small>SYMBOL.INSTRUMENTU · Giełda</small></span></span><div class="market-price">${amount} PLN</div><div class="meta">Źródło i data wyceny</div></section></div>
</main></div></body></html>`;

test('narrow and tablet cards show complete amounts and keyboard focus',async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.STOCK_TEST_BROWSER_CHANNEL||undefined});
 try{
  const page=await browser.newPage();
  for(const width of [320,768,1440]){
   await page.setViewportSize({width,height:1000});await page.setContent(fixture);
   const overflow=await page.evaluate(()=>({viewport:innerWidth,page:document.documentElement.scrollWidth,fields:[...document.querySelectorAll('.metric-value,.wealth-total,.cash-card .balance,.market-price')].filter(el=>el.scrollWidth>el.clientWidth+1).map(el=>({selector:el.className,text:el.textContent,scroll:el.scrollWidth,client:el.clientWidth}))}));
   assert.ok(overflow.page<=overflow.viewport,`${width}px viewport has horizontal overflow: ${JSON.stringify(overflow)}`);
   assert.deepEqual(overflow.fields,[],`${width}px amount is clipped`);
   if(width===320)assert.equal(await page.locator('.metric').first().evaluate(el=>getComputedStyle(el.parentElement).gridTemplateColumns.split(' ').length),1);
   const card=page.locator('[data-asset]');await card.focus();
   const focus=await card.evaluate(el=>({visible:el.matches(':focus-visible'),style:getComputedStyle(el).outlineStyle,width:getComputedStyle(el).outlineWidth}));
   assert.deepEqual(focus,{visible:true,style:'solid',width:'2px'});
   assert.ok(await page.locator('.market-card .meta').evaluate(el=>parseFloat(getComputedStyle(el).fontSize))>=12);
  }
 }finally{await browser.close();}
});
