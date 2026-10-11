const {test}=require('node:test');
const assert=require('node:assert/strict');
const C=require('../public/core.js');
function asset(s,currency='PLN'){s.assets.push({id:'a',symbol:'TEST',name:'Test',market:'Test',type:'stock',currency,quote:{price:25,date:C.today(),source:'Test',history:[]}});return s;}
const t=(type,more={})=>({id:C.id(),type,date:'2026-01-02',currency:'PLN',fx:1,fee:0,...more});
const near=(a,b)=>assert.ok(Math.abs(a-b)<1e-7,`${a} != ${b}`);
test('FIFO: partial sale, fees, remaining lot and cash',()=>{
 const s=asset(C.empty());s.ledger=[t('deposit',{amount:1000}),t('buy',{assetId:'a',quantity:10,price:10,fee:10}),t('buy',{assetId:'a',quantity:10,price:20,fee:20}),t('sell',{assetId:'a',quantity:15,price:30,fee:15})];
 C.validate(s);const r=C.calculate(s),p=r.positions[0];near(p.quantity,5);near(p.cost,110);near(r.realized,215);near(r.cash.PLN,1105);near(p.value,125);near(p.unrealized,15);near(r.total,1230);near(r.profit,230);near(r.fees,45);
});
test('FX decomposes native price effect and historical purchase FX',()=>{
 const s=asset(C.empty(),'USD');s.fx.USD={rate:4,date:C.today(),source:'Test'};s.ledger=[t('deposit',{currency:'USD',fx:3,amount:1000}),t('buy',{assetId:'a',currency:'USD',quantity:10,price:20,fee:10,fx:3})];const r=C.calculate(s),p=r.positions[0];near(p.cost,630);near(p.value,1000);near(p.priceEffect,160);near(p.fxEffect,210);near(p.unrealized,p.priceEffect+p.fxEffect);near(r.cashValue,3160);near(r.profit,1160);
});
test('Dividend withholding, cash fees, withdrawals and FX conversion',()=>{
 const s=asset(C.empty());s.fx.USD={rate:4,date:C.today()};s.ledger=[t('deposit',{amount:1000,fee:2}),t('exchange',{amount:400,targetCurrency:'USD',targetAmount:100,targetFx:4,fee:3}),t('dividend',{assetId:'a',amount:100,tax:19,fee:1}),t('withdrawal',{amount:100,fee:1}),t('fee',{amount:5,fee:1})];const r=C.calculate(s);near(r.cash.PLN,568);near(r.cash.USD,100);near(r.dividends,80);near(r.fees,13);near(r.netDeposits,900);near(r.total,968);near(r.profit,68);
});
test('Overselling and backdated sale rejected without damaging the input',()=>{
 const s=asset(C.empty());s.ledger=[t('buy',{date:'2026-02-01',assetId:'a',quantity:1,price:10}),t('sell',{date:'2026-01-01',assetId:'a',quantity:1,price:12})];const before=JSON.stringify(s);assert.throws(()=>C.validate(s),/Sprzedaż/);assert.equal(JSON.stringify(s),before);
});
test('Missing FX or price keeps unknown valuations null',()=>{
 const s=asset(C.empty(),'USD');s.assets[0].quote=null;s.ledger=[t('buy',{assetId:'a',quantity:2,price:10,currency:'USD',fx:4})];const r=C.calculate(s);assert.equal(r.positions[0].value,null);assert.ok(r.missing.includes('USD'));assert.ok(r.missing.includes('kurs TEST'));
});
test('History does not fabricate past FX from the latest quote',()=>{
 const s=asset(C.empty(),'USD');s.assets[0].quote.history=[{date:'2026-01-02',close:20},{date:C.today(),close:25}];s.fx.USD={rate:4,date:C.today()};s.ledger=[t('deposit',{currency:'USD',amount:100,fx:4}),t('buy',{assetId:'a',currency:'USD',quantity:1,price:20,fx:4})];const p=C.portfolioHistory(s);assert.equal(p.length,1);assert.equal(p[0].date,C.today());near(p[0].close,420);
});
test('History honors price dates and actual FX history',()=>{
 const s=asset(C.empty(),'USD');s.assets[0].quote.history=[{date:'2026-01-02',close:20},{date:C.today(),close:25}];s.fx.USD={rate:4,date:C.today(),history:[{date:'2026-01-02',rate:3}]};s.ledger=[t('deposit',{currency:'USD',amount:100,fx:3}),t('buy',{assetId:'a',currency:'USD',quantity:1,price:20,fx:3})];const p=C.portfolioHistory(s);near(p[0].close,300);near(p.at(-1).close,420);
});
test('CSV parses BOM and decimal comma, sorts and rejects malformed data',()=>{
 assert.deepEqual(C.parseCSV('\uFEFFData;Cena\n2026-02-02;12,5\n2026-02-01;10,3'),[{date:'2026-02-01',close:10.3},{date:'2026-02-02',close:12.5}]);assert.throws(()=>C.parseCSV('Date,Close\n2026-02-30,2'));assert.throws(()=>C.parseCSV('Date,Close\n2026-02-02,NaN'));assert.throws(()=>C.parseCSV('Name,Price\nx,12'));
});
test('Reject duplicate IDs, invalid tax and base currency FX',()=>{
 const s=asset(C.empty());s.ledger=[t('dividend',{assetId:'a',amount:10,tax:11})];assert.throws(()=>C.validate(s));
 for(const tax of [NaN,false,'']){s.ledger=[t('dividend',{assetId:'a',amount:10,tax})];assert.throws(()=>C.validate(s));}
 s.ledger=[t('deposit',{amount:10,fx:2})];assert.throws(()=>C.validate(s));s.ledger=[];s.assets.push(structuredClone(s.assets[0]));assert.throws(()=>C.validate(s));
 const badBase=C.empty();badBase.fx.PLN.history=[{date:'2026-01-02',rate:2}];assert.throws(()=>C.validate(badBase),/PLN/);
});
test('Demo is valid and finite, foreign valuations have FX, total reconciles',()=>{
 const s=C.validate(C.demo()),r=C.calculate(s);assert.equal(r.positions.length,6);assert.equal(r.missing.length,0);assert.ok(Number.isFinite(r.total));near(r.total,r.cashValue+r.investedValue);assert.ok(C.portfolioHistory(s).length>2);
});
test('Retirement snapshots join wealth without changing investment profit or cash',()=>{
 const s=asset(C.empty());s.ledger=[t('deposit',{amount:1000}),t('buy',{assetId:'a',quantity:10,price:20})];
 const before=C.calculate(s);
 s.retirement=[{id:'z',kind:'zus',name:'ZUS',currency:'PLN',history:[{date:'2026-01-02',amount:2000},{date:C.today(),amount:2400}]},{id:'p',kind:'ppk',name:'PPK',currency:'PLN',history:[{date:C.today(),amount:150}]},{id:'o',kind:'ofe',name:'OFE',currency:'PLN',history:[{date:C.today(),amount:300}]}];
 C.validate(s);const w=C.wealth(s);near(w.total,before.total+2850);near(w.zus,2400);near(w.assetsWithoutZus,before.total+450);near(w.rows.reduce((n,r)=>n+r.share,0),100);assert.deepEqual(C.calculate(s),before);
});
test('Retirement validation rejects duplicate snapshots, future dates and invalid amounts',()=>{
 const s=C.empty();s.retirement=[{id:'z',kind:'zus',name:'ZUS',currency:'PLN',history:[{date:C.today(),amount:-1}]}];assert.throws(()=>C.validate(s));
 s.retirement[0].history=[{date:C.today(),amount:0},{date:C.today(),amount:1}];assert.throws(()=>C.validate(s));
 s.retirement[0].history=[{date:'2999-01-01',amount:1}];assert.throws(()=>C.validate(s));s.retirement[0].history=[{date:C.today(),amount:0}];C.validate(s);
});
test('Older portfolios and negative cash have honest complete-wealth totals',()=>{
 const s=C.empty();delete s.retirement;s.ledger=[t('fee',{amount:10})];const w=C.wealth(C.validate(s));near(w.total,-10);assert.equal(w.hasNegative,true);near(w.positiveTotal,0);assert.ok(w.rows.every(r=>r.share===0));
});

test('Commission input converts both ways, respects explicit minimum and preserves amounts',()=>{
 near(C.commission(1500,'percent',0.29).fee,4.35);
 near(C.commission(1500,'amount',5).percent,1/3);
 near(C.commission(1500,'amount',5.1234).fee,5.1234);
 near(C.commission(1000,'percent',0).fee,0);
 near(C.commission(1000,'amount',0).percent,0);
 const m=C.commission(1000,'percent',0.29,5);near(m.fee,5);near(m.effectivePercent,0.5);assert.equal(m.minimumApplied,true);
 assert.equal(C.commission(0,'amount',5).percent,null);assert.equal(C.commission(0,'percent',0.29).fee,null);
 near(C.commission(1500,'percent',C.commission(1500,'amount',5).percent).fee,5);
 for(const args of [[100,'amount',-1],[100,'percent',NaN],[Infinity,'percent',1],[100,'invalid',1],[100,'percent',1,-5]])assert.throws(()=>C.commission(...args));
});
test('Commission metadata preserves FIFO amounts and accepts older operations',()=>{
 const s=asset(C.empty());s.ledger=[t('deposit',{amount:1000}),t('buy',{assetId:'a',quantity:10,price:10,fee:5})];
 C.validate(s);const before=C.calculate(s);
 Object.assign(s.ledger[1],{feeMode:'percent',feePercent:0.29,feeMinimum:5});C.validate(s);
 near(C.calculate(s).cash.PLN,before.cash.PLN);near(C.calculate(s).positions[0].cost,before.positions[0].cost);
 s.ledger[1].feePercent=10;assert.throws(()=>C.validate(s));s.ledger[1].feeMode='guessed';assert.throws(()=>C.validate(s));
});

test('Latest dated observations preserve point sources, zero prices and primary ties',()=>{
 const quote={price:50,date:'2026-01-10',source:'Primary',history:[{date:'2026-01-20',close:0,source:'Write-down'},{date:'2026-01-05',close:40},{date:'2026-01-10',close:99}]};
 const before=JSON.stringify(quote);
 assert.equal(C.latestQuote(quote,'2026-01-01'),null);
 assert.equal(C.latestQuote(quote,'2026-01-06').price,40);
 assert.equal(C.latestQuote(quote,'2026-01-06').source,'Primary');
 assert.equal(C.latestQuote(quote,'2026-01-15').price,50);
 assert.equal(C.latestQuote(quote,'2026-01-20').price,0);
 assert.equal(C.latestQuote(quote,'2026-01-20').source,'Write-down');
 assert.equal(JSON.stringify(quote),before);
 const fx={rate:4,date:'2026-01-10',source:'Primary',history:[{date:'2026-01-10',rate:9},{date:'2026-01-20',rate:5,source:'Later'}]};
 assert.equal(C.latestFX(fx,'2026-01-15').rate,4);
 assert.equal(C.latestFX(fx,'2026-01-20').source,'Later');
 assert.equal(C.latestFX(fx,'2026-01-01'),null);
});
test('Backdated price and FX corrections keep dashboard and historical endpoint consistent',()=>{
 const s=asset(C.empty());s.assets[0].quote={price:100,date:'2026-01-02',source:'Old correction',history:[{date:'2026-01-02',close:100},{date:'2026-10-09',close:150,source:'Later price'}]};
 s.ledger=[t('deposit',{amount:1000}),t('buy',{assetId:'a',quantity:10,price:100})];C.validate(s);
 const result=C.calculate(s);near(result.total,1500);near(C.portfolioHistory(s).at(-1).close,result.total);assert.equal(result.positions[0].quote.source,'Later price');
 const cash=C.empty();cash.ledger=[t('deposit',{currency:'USD',fx:4,amount:100})];cash.fx.USD={rate:4,date:'2026-01-02',history:[{date:'2026-01-02',rate:4},{date:'2026-10-09',rate:5}]};C.validate(cash);
 near(C.calculate(cash).total,500);near(C.portfolioHistory(cash).at(-1).close,C.calculate(cash).total);
});
test('Cash-only history includes recorded FX dates and dates before the first transaction are excluded',()=>{
 const s=C.empty();s.ledger=[t('deposit',{currency:'USD',fx:4,amount:100})];s.fx.USD={rate:4,date:C.today(),history:[{date:'2025-12-31',rate:9},{date:'2026-01-02',rate:4},{date:'2026-02-02',rate:5},{date:'2026-03-02',rate:3}]};C.validate(s);
 assert.deepEqual(C.portfolioHistory(s).map(p=>[p.date,p.close]),[['2026-01-02',400],['2026-02-02',500],['2026-03-02',300],[C.today(),400]]);
});
test('A primary quote date contributes a point even with an empty quote history',()=>{
 const s=asset(C.empty());s.assets[0].quote={price:25,date:'2026-01-03',history:[]};s.ledger=[t('deposit',{amount:100}),t('buy',{assetId:'a',quantity:2,price:20})];C.validate(s);
 const history=C.portfolioHistory(s);assert.equal(history[0].date,'2026-01-03');near(history[0].close,110);near(history.at(-1).close,C.calculate(s).total);
});
test('Zero valuations and CSV prices record losses without becoming missing data',()=>{
 const s=asset(C.empty());s.assets[0].quote={price:0,date:C.today(),history:[{date:'2026-01-02',close:100},{date:C.today(),close:0}]};s.ledger=[t('deposit',{amount:100}),t('buy',{assetId:'a',quantity:1,price:100})];C.validate(s);
 const r=C.calculate(s);near(r.total,0);near(r.profit,-100);assert.deepEqual(r.missing,[]);near(C.portfolioHistory(s).at(-1).close,0);
 assert.deepEqual(C.parseCSV('Date,Close\n2026-01-02,100\n2026-01-03,0'),[{date:'2026-01-02',close:100},{date:'2026-01-03',close:0}]);
 const invalid=structuredClone(s);invalid.ledger[1].price=0;assert.throws(()=>C.validate(invalid));
});
test('Tiny holdings remain valued and a sale of exactly 1e-8 consumes its FIFO cost',()=>{
 for(const quantity of [1e-8,1e-12]){
  const s=asset(C.empty());s.assets[0].quote.price=1e8;s.ledger=[t('deposit',{amount:1}),t('buy',{assetId:'a',quantity,price:1e8})];C.validate(s);
  let r=C.calculate(s);assert.ok(r.positions[0].value>0);assert.equal(r.positions[0].quantity,quantity);assert.ok(C.wealth(s).rows.some(x=>x.key==='stock'));
  s.ledger.push(t('sell',{assetId:'a',quantity,price:1e8}));C.validate(s);r=C.calculate(s);
  assert.equal(r.positions[0].quantity,0);assert.deepEqual(r.positions[0].lots,[]);near(r.positions[0].cost,0);near(r.realized,0);near(r.unrealized,0);near(r.cash.PLN,1);
  s.ledger.push(t('sell',{assetId:'a',quantity,price:1e8}));assert.throws(()=>C.validate(s),/Sprzedaż/);
 }
 const emptyHolding=asset(C.empty());emptyHolding.ledger=[t('sell',{assetId:'a',quantity:1e-8,price:100})];assert.throws(()=>C.validate(emptyHolding),/Sprzedaż/);
});
test('Fractional full sales tolerate representation noise and reconcile all lots',()=>{
 const s=asset(C.empty());s.ledger=[t('deposit',{amount:30}),t('buy',{assetId:'a',quantity:0.1,price:100}),t('buy',{assetId:'a',quantity:0.2,price:100}),t('sell',{assetId:'a',quantity:0.3,price:100})];C.validate(s);
 const r=C.calculate(s);assert.equal(r.positions[0].quantity,0);assert.equal(r.positions[0].cost,0);near(r.realized,0);near(r.total,30);
});
test('Prototype-like identifiers are ordinary holdings and prototype names cannot become operation types',()=>{
 const s=asset(C.empty());s.assets[0].id='__proto__';s.ledger=[t('deposit',{amount:1000}),t('buy',{assetId:'__proto__',quantity:10,price:100})];C.validate(s);
 const r=C.calculate(s);assert.equal(r.positions.length,1);near(r.positions[0].cost,1000);near(r.total,250);assert.deepEqual(r.missing,[]);
 const bad=structuredClone(s);bad.assets[0].type='constructor';assert.throws(()=>C.validate(bad),/instrument/);
 bad.assets[0].type='stock';bad.ledger[0].type='__proto__';assert.throws(()=>C.validate(bad),/operacja/);
});
test('Overflow in products, running balances, historical valuations and retirement totals is rejected',()=>{
 const cases=[];
 let s=asset(C.empty());s.ledger=[t('buy',{assetId:'a',quantity:1e308,price:1e308})];cases.push(s);
 s=C.empty();s.ledger=[t('deposit',{amount:1e308}),t('deposit',{amount:1e308})];cases.push(s);
 s=asset(C.empty());s.assets[0].quote.price=1e308;s.ledger=[t('buy',{assetId:'a',quantity:2,price:1})];cases.push(s);
 s=asset(C.empty());s.assets[0].quote.history=[{date:'2026-01-02',close:1e308}];s.ledger=[t('buy',{assetId:'a',quantity:2,price:1}),t('sell',{date:C.today(),assetId:'a',quantity:2,price:1})];cases.push(s);
 s=C.empty();s.fx.USD={rate:2,date:C.today()};s.ledger=[t('deposit',{currency:'USD',fx:1,amount:1e308})];cases.push(s);
 s=C.empty();s.ledger=[t('exchange',{amount:1,targetCurrency:'USD',targetAmount:1e308,targetFx:2})];cases.push(s);
 s=asset(C.empty());s.ledger=[t('buy',{assetId:'a',quantity:Number.MIN_VALUE,price:100,fee:1})];cases.push(s);
 s=C.empty();s.retirement=['a','b'].map(id=>({id,kind:'zus',name:id,currency:'PLN',history:[{date:C.today(),amount:1e308}]}));cases.push(s);
 for(const state of cases)assert.throws(()=>C.validate(state),/zakres obliczeń/);
});
test('Percent commissions use decimal half-up cents, including minima and exponent notation',()=>{
 near(C.commission(12750,'percent',0.29).fee,36.98);
 near(C.commission(1000,'percent',1.0075).fee,10.08);
 near(C.commission(100,'percent',0,1.005).fee,1.01);
 near(C.commission(1e-7,'percent',5e6).fee,0.01);
 near(C.commission(100,'percent',0.0049).fee,0);
 near(C.commission(100,'percent',0.005).fee,0.01);
 assert.throws(()=>C.commission(1e308,'percent',1),/zakres obliczeń/);
 const s=asset(C.empty());s.ledger=[t('buy',{assetId:'a',quantity:10,price:1275,fee:36.98,feeMode:'percent',feePercent:0.29,feeMinimum:0})];C.validate(s);near(C.calculate(s).positions[0].cost,12786.98);
});
test('Legacy percent fee amounts remain readable without changing historical cost',()=>{
 const s=asset(C.empty());s.ledger=[t('buy',{assetId:'a',quantity:10,price:1275,fee:36.97,feeMode:'percent',feePercent:0.29,feeMinimum:0})];
 const before=JSON.stringify(s);C.validate(s);assert.equal(JSON.stringify(s),before);near(C.calculate(s).positions[0].cost,12786.97);
 s.ledger[0].fee=36.98;C.validate(s);near(C.calculate(s).positions[0].cost,12786.98);
 s.ledger[0].fee=37;assert.throws(()=>C.validate(s),/prowizji/);
});
test('Archived price series validate provenance and bounds without affecting active valuation',()=>{
 const s=asset(C.empty());s.assets[0].quote.historyArchives=[{source:'CSV',basis:'manual',providerSymbol:'',archivedAt:C.today(),history:[{date:'2026-01-02',close:0,source:'CSV'}]}];C.validate(s);
 const before=C.calculate(s).total;s.assets[0].quote.historyArchives[0].history[0].close=999;C.validate(s);near(C.calculate(s).total,before);
 for(const mutate of [q=>q.historyArchives.push(...Array(20).fill(q.historyArchives[0])),q=>q.historyArchives[0].archivedAt='2999-01-01',q=>q.historyArchives[0].basis='x'.repeat(501),q=>q.historyArchives[0].history[0].close=-1,q=>q.historyArchives[0].history[0].source={}]){
  const bad=structuredClone(s);mutate(bad.assets[0].quote);assert.throws(()=>C.validate(bad));
 }
});

test('Chronological history agrees with independent per-day ledger replay across trades and cash flows',()=>{
 const s=asset(C.empty());s.assets[0].quote={price:25,date:'2026-01-06',source:'PLN',history:[{date:'2026-01-05',close:23},{date:'2026-01-02',close:20}]};
 s.assets.push({id:'u',symbol:'USDTEST',name:'USD Test',market:'Test',type:'stock',currency:'USD',quote:{price:15,date:'2026-01-06',source:'USD',history:[{date:'2026-01-03',close:12},{date:'2026-01-02',close:10}]}});
 s.fx.USD={rate:4.2,date:'2026-01-06',history:[{date:'2026-01-04',rate:5},{date:'2026-01-02',rate:4}]};
 s.ledger=[t('deposit',{amount:1000}),t('deposit',{currency:'USD',fx:4,amount:100}),t('buy',{assetId:'a',quantity:2,price:20}),t('buy',{assetId:'u',currency:'USD',fx:4,quantity:3,price:10}),t('dividend',{date:'2026-01-03',assetId:'u',currency:'USD',fx:4,amount:2,tax:0.2,fee:0.1}),t('sell',{date:'2026-01-04',assetId:'u',currency:'USD',fx:5,quantity:1,price:11,fee:0.5}),t('withdrawal',{date:'2026-01-05',amount:20,fee:1}),t('exchange',{date:'2026-01-06',currency:'USD',fx:4.2,amount:10,fee:1,targetCurrency:'PLN',targetFx:1,targetAmount:40})];
 C.validate(s);const before=JSON.stringify(s),history=C.portfolioHistory(s);
 for(const point of history){
  const sub={...s,ledger:s.ledger.filter(t=>t.date<=point.date),assets:s.assets.map(a=>{const quote=C.latestQuote(a.quote,point.date);return {...a,quote:quote?{...quote,history:[]}:null};}),fx:{PLN:{rate:1,date:point.date}}};
  const fx=C.latestFX(s.fx.USD,point.date);if(fx)sub.fx.USD={...fx,history:[]};
  const replay=C.calculate(sub);near(point.close,replay.total);near(point.contributions,replay.netDeposits);
 }
 assert.equal(JSON.stringify(s),before);near(history.at(-1).close,C.calculate(s).total);
});
