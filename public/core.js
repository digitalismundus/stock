(function (root) {
  'use strict';
  const FX=typeof module==='object'&&module.exports?require('./fx.js'):root.StockFX;
  const TYPES = {stock:'Akcje',etf:'ETF',bond:'Obligacje',fund:'Fundusze',crypto:'Kryptowaluty',forex:'Waluty',commodity:'Surowce',other:'Inne'};
  const RETIREMENT={zus:'ZUS',ofe:'OFE',ppk:'PPK'};
  const OPERATIONS = {buy:'Kupno',sell:'Sprzedaż',deposit:'Wpłata',withdrawal:'Wypłata',dividend:'Dywidenda / odsetki',exchange:'Przewalutowanie',fee:'Opłata'};
  const today = () => new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Warsaw',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const id = () => globalThis.crypto?.randomUUID?.() || 'id-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
  const empty = () => ({version:1,settings:{name:'Mój portfel',feePercent:0.29,minFee:0},assets:[],ledger:[],retirement:[],fx:{PLN:{rate:1,date:today(),source:'Waluta bazowa'}}});
  function assert(condition, message) { if(!condition) throw new Error(message); }
  const has=(object,key)=>Object.prototype.hasOwnProperty.call(object,key);
  const dictionary=()=>Object.create(null);
  function finite(n){assert(Number.isFinite(n),'Wartość portfela przekracza zakres obliczeń.');return n;}
  const product=(...values)=>values.reduce((n,v)=>finite(n*v),1);
  const sum=values=>values.reduce((n,v)=>finite(n+v),0);
  function number(n, name, min=0, strict=false) { assert(typeof n==='number' && Number.isFinite(n) && (strict?n>min:n>=min), 'Nieprawidłowa wartość: '+name); }
  function date(d) { assert(typeof d==='string' && /^\d{4}-\d{2}-\d{2}$/.test(d) && !Number.isNaN(Date.parse(d)) && new Date(d+'T12:00:00Z').toISOString().slice(0,10)===d,'Nieprawidłowa data.'); }
  function source(value){assert(value===undefined||(typeof value==='string'&&value.length<=500),'Nieprawidłowe źródło historii.');}
  function priceHistory(history,end=today()){
    assert(Array.isArray(history)&&history.length<=5000,'Nieprawidłowa historia kursów.');
    for(const p of history){date(p.date);assert(p.date<=end,'Historia nie może zawierać przyszłych cen.');number(p.close,'kurs historyczny');source(p.source);}
  }
  function validate(s) {
    const end=today();
    assert(s && s.version===1 && Array.isArray(s.assets) && Array.isArray(s.ledger) && s.settings && s.fx,'Nieprawidłowy format portfela.');
    assert(s.assets.length<=1000 && s.ledger.length<=20000,'Plik portfela jest zbyt duży.');
    assert(typeof s.settings.name==='string' && s.settings.name.length<=100,'Nieprawidłowa nazwa portfela.');
    number(s.settings.feePercent,'prowizja'); number(s.settings.minFee,'minimalna prowizja');
    const ids=new Set();
    for(const a of s.assets) {
      assert(typeof a.id==='string' && a.id.length>0 && !ids.has(a.id),'Powtórzony identyfikator instrumentu.'); ids.add(a.id);
      assert(typeof a.symbol==='string' && a.symbol.length>0 && a.symbol.length<=30 && typeof a.name==='string' && a.name.length>0 && a.name.length<=200 && has(TYPES,a.type),'Nieprawidłowy instrument.');
      assert(/^[A-Z]{3}$/.test(a.currency),'Waluta musi mieć trzyliterowy kod.');
      assert(typeof a.market==='string' && a.market.length<=100,'Nieprawidłowy rynek.');
      if(a.quote) {
        const q=a.quote;number(q.price,'kurs');date(q.date);assert(q.date<=end,'Cena nie może mieć przyszłej daty.');priceHistory(q.history,end);source(q.source);source(q.historyBasis);
        assert(q.providerSymbol===undefined||(typeof q.providerSymbol==='string'&&q.providerSymbol.length<=100),'Nieprawidłowy symbol historii.');
        if(q.historyArchives!==undefined){
          assert(Array.isArray(q.historyArchives)&&q.historyArchives.length<=20,'Nieprawidłowe archiwa historii.');
          for(const archive of q.historyArchives){
            assert(archive&&typeof archive==='object'&&typeof archive.source==='string'&&typeof archive.basis==='string'&&typeof archive.providerSymbol==='string'&&archive.providerSymbol.length<=100,'Nieprawidłowe archiwum historii.');
            source(archive.source);source(archive.basis);date(archive.archivedAt);assert(archive.archivedAt<=end,'Archiwum nie może mieć przyszłej daty.');priceHistory(archive.history,end);
          }
        }
      }
    }
    for(const [c,r] of Object.entries(s.fx)) {assert(/^[A-Z]{3}$/.test(c),'Nieprawidłowa waluta.');number(r.rate,'kurs waluty',0,true);date(r.date);assert(r.date<=end,'Kurs waluty nie może mieć przyszłej daty.');source(r.source);if(r.history){assert(Array.isArray(r.history)&&r.history.length<=5000,'Nieprawidłowa historia waluty.');for(const p of r.history){date(p.date);assert(p.date<=end,'Historia FX nie może zawierać przyszłych kursów.');number(p.rate,'kurs FX',0,true);assert(c!=='PLN'||p.rate===1,'Historyczny kurs PLN musi wynosić 1.');source(p.source);}}}
    assert(s.fx.PLN?.rate===1,'Kurs PLN musi wynosić 1.');
    const retirement=s.retirement||[];assert(Array.isArray(retirement)&&retirement.length<=100,'Nieprawidłowe rachunki emerytalne.');
    const retirementIds=new Set();
    for(const r of retirement){
      assert(typeof r.id==='string'&&r.id.length>0&&!retirementIds.has(r.id)&&has(RETIREMENT,r.kind),'Nieprawidłowy rachunek emerytalny.');retirementIds.add(r.id);
      assert(typeof r.name==='string'&&r.name.length>0&&r.name.length<=100&&r.currency==='PLN','Nieprawidłowa nazwa lub waluta rachunku.');
      assert(Array.isArray(r.history)&&r.history.length>0&&r.history.length<=1000,'Rachunek wymaga salda z datą.');const days=new Set();
      for(const x of r.history){date(x.date);assert(x.date<=end&&!days.has(x.date),'Powtórzona lub przyszła data salda.');days.add(x.date);number(x.amount,'saldo rachunku');}
      for(const k of ['provider','note'])assert(r[k]===undefined||(typeof r[k]==='string'&&r[k].length<=4000),'Nieprawidłowy opis rachunku.');
    }
    const txIds=new Set();
    for(const t of s.ledger) {
      assert(typeof t.id==='string' && t.id.length>0 && !txIds.has(t.id),'Powtórzony identyfikator operacji.'); txIds.add(t.id);
      assert(has(OPERATIONS,t.type),'Nieprawidłowa operacja.');date(t.date);assert(t.date<=end,'Przyszłe operacje nie są jeszcze wykonanymi transakcjami.');
      assert(/^[A-Z]{3}$/.test(t.currency),'Nieprawidłowa waluta operacji.');number(t.fx,'kurs PLN',0,true);assert(t.currency!=='PLN'||t.fx===1,'Kurs PLN musi wynosić 1.');number(t.fee,'prowizja');
      if(t.fxReference!==undefined)FX.validateReference(t.fxReference,t.currency,t.date,t.fx);
      if(t.targetFxReference!==undefined){assert(t.type==='exchange','Źródło kursu docelowego wymaga przewalutowania.');FX.validateReference(t.targetFxReference,t.targetCurrency,t.date,t.targetFx);}
      if(['buy','sell','dividend'].includes(t.type)) {const a=s.assets.find(a=>a.id===t.assetId);assert(a,'Operacja wskazuje nieistniejący instrument.');if(t.type!=='dividend')assert(a.currency===t.currency,'Waluta transakcji musi odpowiadać walucie instrumentu.');}
      if(['buy','sell'].includes(t.type)) {number(t.quantity,'liczba jednostek',0,true);number(t.price,'cena',0,true);}
      else {number(t.amount,'kwota',0,true);number(t.tax??0,'podatek');assert(t.type!=='dividend'||(t.tax??0)+t.fee<=t.amount,'Podatek i prowizja przekraczają dywidendę.');}
      if(t.feeMode!==undefined){assert(['percent','amount'].includes(t.feeMode),'Nieprawidłowy sposób prowizji.');number(t.feePercent,'prowizja procentowa');number(t.feeMinimum,'minimum prowizji');if(t.feeMode==='percent'){const base=['buy','sell'].includes(t.type)?t.quantity*t.price:t.amount;assert(commissionMatches(base,t.feePercent,t.feeMinimum,t.fee),'Kwota prowizji nie odpowiada stawce i minimum.');}}
      if(t.type==='exchange') {assert(/^[A-Z]{3}$/.test(t.targetCurrency) && t.currency!==t.targetCurrency,'Wybierz różne waluty.');number(t.targetAmount,'otrzymana kwota',0,true);number(t.targetFx,'kurs docelowy',0,true);assert(t.targetCurrency!=='PLN'||t.targetFx===1,'Kurs PLN musi wynosić 1.');}
    }
    wealth(s); return s;
  }
  // A primary observation wins ties; older manual corrections remain historical.
  function observations(record,key,historyKey){
    if(!record)return [];
    const points=new Map();
    for(const p of record.history||[]){
      if(!points.has(p.date))points.set(p.date,{...record,...p,[key]:p[historyKey],source:p.source??record.source});
    }
    points.set(record.date,{...record});
    return [...points.values()].sort((a,b)=>a.date.localeCompare(b.date));
  }
  function latestObservation(record,key,historyKey,day){
    const points=observations(record,key,historyKey);
    for(let i=points.length-1;i>=0;i--)if(points[i].date<=day)return points[i];
    return null;
  }
  const latestQuote=(quote,day=today())=>latestObservation(quote,'price','close',day);
  const latestFX=(rate,day=today())=>latestObservation(rate,'rate','rate',day);
  const orderedLedger=s=>s.ledger.map((t,i)=>({...t,order:i})).sort((a,b)=>a.date.localeCompare(b.date)||a.order-b.order);
  function ledgerState(s){
    const book={cash:dictionary(),positions:dictionary(),maxQuantity:dictionary(),maxCash:dictionary(),deposits:0,withdrawals:0,dividends:0,fees:0,realized:0};
    for(const a of s.assets){book.positions[a.id]={asset:a,lots:[],quantity:0,realized:0,dividends:0};book.maxQuantity[a.id]=0;}
    return book;
  }
  function applyOperation(book,t){
    const p=book.positions[t.assetId],fee=t.fee||0;
    const add=(c,n)=>{book.cash[c]=finite((book.cash[c]||0)+finite(n));book.maxCash[c]=Math.max(book.maxCash[c]||0,Math.abs(book.cash[c]));};
    const addTotal=(key,n)=>{book[key]=finite(book[key]+finite(n));};
    addTotal('fees',product(fee,t.fx));
    if(t.type==='buy'){
      const gross=finite(product(t.quantity,t.price)+fee),unit=finite(gross/t.quantity);
      add(t.currency,-gross);p.lots.push({quantity:t.quantity,unit,fx:t.fx});p.quantity=finite(p.quantity+t.quantity);
      book.maxQuantity[t.assetId]=Math.max(book.maxQuantity[t.assetId],p.quantity);
    }
    if(t.type==='sell'){
      // Relative tolerance permits only floating-point noise, including at tiny quantities.
      const tolerance=Math.max(p.quantity,t.quantity)*Number.EPSILON*8;
      assert(p.quantity>=t.quantity||(p.quantity>0&&t.quantity-p.quantity<=tolerance),`Sprzedaż ${p.asset.symbol} przekracza liczbę posiadanych jednostek (${p.quantity}). Sprawdź daty i kolejność operacji.`);
      let cost=0;
      if(Math.abs(p.quantity-t.quantity)<=tolerance){
        cost=sum(p.lots.map(l=>product(l.quantity,l.unit,l.fx)));p.lots=[];
      }else{
        let remaining=t.quantity;
        while(remaining>0){
          const lot=p.lots[0];assert(lot,'Nieprawidłowe partie zakupu.');
          const q=Math.min(remaining,lot.quantity);
          cost=finite(cost+product(q,lot.unit,lot.fx));lot.quantity-=q;remaining-=q;
          if(lot.quantity===0)p.lots.shift();
        }
      }
      const proceeds=finite(product(t.quantity,t.price)-fee),profit=finite(product(proceeds,t.fx)-cost);
      p.realized=finite(p.realized+profit);addTotal('realized',profit);
      p.quantity=sum(p.lots.map(l=>l.quantity));add(t.currency,proceeds);
    }
    if(t.type==='deposit'){add(t.currency,finite(t.amount-fee));addTotal('deposits',product(t.amount,t.fx));}
    if(t.type==='withdrawal'){add(t.currency,-finite(t.amount+fee));addTotal('withdrawals',product(t.amount,t.fx));}
    if(t.type==='dividend'){
      const net=finite(t.amount-(t.tax||0)-fee),netPLN=product(net,t.fx);
      add(t.currency,net);addTotal('dividends',netPLN);if(p)p.dividends=finite(p.dividends+netPLN);
    }
    if(t.type==='fee'){add(t.currency,-finite(t.amount+fee));addTotal('fees',product(t.amount,t.fx));}
    if(t.type==='exchange'){
      // Validate both recorded PLN amounts even though they do not become contributions.
      product(t.amount,t.fx);product(t.targetAmount,t.targetFx);
      add(t.currency,-finite(t.amount+fee));add(t.targetCurrency,t.targetAmount);
    }
  }
  function valuation(s,book,day=today()){
    let investedValue=0,totalCost=0,unrealized=0,cashValue=0;
    const missing=new Set(),positions=[],rates=dictionary();
    for(const [c,r]of Object.entries(s.fx))rates[c]=c==='PLN'?{rate:1,date:day,source:r.source}:latestFX(r,day);
    for(const asset of s.assets){
      const p={...book.positions[asset.id],asset};
      p.cost=sum(p.lots.map(l=>product(l.quantity,l.unit,l.fx)));
      p.costNative=sum(p.lots.map(l=>product(l.quantity,l.unit)));
      p.quote=latestQuote(asset.quote,day);p.price=p.quote?.price??null;p.rate=rates[asset.currency]?.rate??null;
      if(p.quantity>0&&!p.rate)missing.add(asset.currency);
      if(p.quantity>0&&p.price===null)missing.add('kurs '+asset.symbol);
      p.valueNative=p.quantity===0?0:p.price===null?null:product(p.quantity,p.price);
      p.value=p.quantity===0?0:p.valueNative!==null&&p.rate!==null?product(p.valueNative,p.rate):null;
      p.unrealized=p.value===null?null:finite(p.value-p.cost);
      p.priceEffect=p.quantity===0?0:p.value===null?null:product(finite(p.valueNative-p.costNative),p.rate);
      p.fxEffect=p.quantity===0?0:p.value===null?null:finite(product(p.costNative,p.rate)-p.cost);
      p.percent=p.cost>0&&p.unrealized!==null?finite(p.unrealized/p.cost*100):null;
      if(p.value!==null)investedValue=finite(investedValue+p.value);
      totalCost=finite(totalCost+p.cost);unrealized=finite(unrealized+(p.unrealized??0));positions.push(p);
    }
    for(const [c,n]of Object.entries(book.cash)){
      if(n!==0&&!rates[c])missing.add(c);
      cashValue=finite(cashValue+(rates[c]?product(n,rates[c].rate):0));
    }
    const total=finite(investedValue+cashValue),netDeposits=finite(book.deposits-book.withdrawals),profit=finite(total-netDeposits);
    return {cash:book.cash,positions,investedValue,totalCost,unrealized,cashValue,total,netDeposits,profit,profitPercent:netDeposits>0?finite(profit/netDeposits*100):null,deposits:book.deposits,withdrawals:book.withdrawals,dividends:book.dividends,fees:book.fees,realized:book.realized,missing:[...missing]};
  }
  function calculate(s){
    const book=ledgerState(s);for(const t of orderedLedger(s))applyOperation(book,t);
    historicalEnvelope(s,book);
    return valuation(s,book);
  }
  function historicalEnvelope(s,book){
    const rates=dictionary();let exposure=0;
    for(const [currency,rate]of Object.entries(s.fx))rates[currency]=currency==='PLN'?1:Math.max(rate.rate,...(rate.history||[]).map(p=>p.rate));
    // A finite upper bound also protects old points from overflow after later sales.
    for(const asset of s.assets)if(asset.quote&&rates[asset.currency]){
      const price=Math.max(asset.quote.price,...asset.quote.history.map(p=>p.close));
      exposure=finite(exposure+product(book.maxQuantity[asset.id],price,rates[asset.currency]));
    }
    for(const [currency,amount]of Object.entries(book.maxCash))if(rates[currency])exposure=finite(exposure+product(amount,rates[currency]));
  }
  function portfolioHistory(s){
    const ledger=orderedLedger(s),first=ledger[0]?.date,end=today();if(!first)return [];
    const dates=new Set(ledger.map(t=>t.date));dates.add(end);
    const assetSeries=s.assets.map(a=>({asset:a,points:observations(a.quote,'price','close'),index:0,current:null}));
    const fxSeries=Object.entries(s.fx).map(([currency,rate])=>({currency,points:observations(rate,'rate','rate'),index:0,current:null}));
    for(const series of [...assetSeries,...fxSeries])for(const p of series.points)dates.add(p.date);
    const advance=(series,day)=>{
      while(series.index<series.points.length&&series.points[series.index].date<=day)series.current=series.points[series.index++];
      return series.current?{...series.current,history:[]}:null;
    };
    const book=ledgerState(s),result=[];let transaction=0;
    // Recorded observations and operations are advanced once in chronological order.
    for(const day of [...dates].filter(d=>d>=first&&d<=end).sort()){
      while(transaction<ledger.length&&ledger[transaction].date<=day)applyOperation(book,ledger[transaction++]);
      const sub={...s,assets:assetSeries.map(series=>({...series.asset,quote:advance(series,day)})),fx:dictionary()};
      for(const series of fxSeries){const rate=advance(series,day);if(rate)sub.fx[series.currency]=rate;}
      sub.fx.PLN={rate:1,date:day,source:'Waluta bazowa'};
      const value=valuation(sub,book,day);
      if(!value.missing.length)result.push({date:day,close:value.total,contributions:value.netDeposits});
    }
    return result;
  }
  function wealth(s){
    const invest=calculate(s),accounts=(s.retirement||[]).map(r=>({...r,latest:[...r.history].sort((a,b)=>a.date.localeCompare(b.date)).at(-1)}));
    const retirement={zus:0,ofe:0,ppk:0};for(const r of accounts)retirement[r.kind]=finite(retirement[r.kind]+r.latest.amount);
    const groups=dictionary();for(const p of invest.positions)if(p.quantity>0&&p.value!==null)groups[p.asset.type]=finite((groups[p.asset.type]||0)+p.value);
    groups.cash=invest.cashValue;for(const [key,n]of Object.entries(retirement))groups[key]=n;
    const rows=Object.entries(groups).filter(([key,n])=>n!==0||['zus','ofe','ppk'].includes(key)).map(([key,amount])=>({key,name:TYPES[key]||RETIREMENT[key]||'Gotówka',amount}));
    const pensionAssets=finite(retirement.ofe+retirement.ppk),zus=retirement.zus,assetsWithoutZus=finite(invest.total+pensionAssets),total=finite(assetsWithoutZus+zus);
    const positiveTotal=sum(rows.map(r=>Math.max(0,r.amount)));
    return {investment:invest.total,pensionAssets,zus,total,assetsWithoutZus,retirement,accounts,rows:rows.map(r=>({...r,share:positiveTotal>0?Math.max(0,r.amount)/positiveTotal*100:0})),positiveTotal,missing:invest.missing,hasNegative:rows.some(r=>r.amount<0)};
  }
  function parseCSV(text) {
    const lines=text.replace(/^\uFEFF/,'').trim().split(/\r?\n/);assert(lines.length>=2,'CSV musi zawierać nagłówek i dane.');
    const sep=lines[0].includes(';')?';':',';const headers=lines[0].split(sep).map(h=>h.trim().replaceAll('"','').toLowerCase());
    const di=headers.findIndex(h=>['date','data'].includes(h)),ci=headers.findIndex(h=>['close','zamkniecie','zamknięcie','cena'].includes(h));assert(di>=0&&ci>=0,'Wymagane kolumny: Date, Close (lub Data, Cena).');
    const out=new Map();for(const line of lines.slice(1)){if(!line.trim())continue;const cols=line.split(sep).map(c=>c.trim().replaceAll('"',''));const d=cols[di],n=Number(cols[ci]?.replace(',','.'));date(d);number(n,'kurs CSV');out.set(d,{date:d,close:n});}
    assert(out.size>0&&out.size<=5000,'CSV musi zawierać od 1 do 5000 notowań.');return [...out.values()].sort((a,b)=>a.date.localeCompare(b.date));
  }
  function demo() {
    const s=empty();s.settings.name='Portfel główny';
    const defs=[['cdr','CDR','CD Projekt','stock','GPW','PLN','CDR.WA',264.8,212,90,'#ee695f','Producent i wydawca gier wideo. Opis przykładowy.'],['nvda','NVDA','NVIDIA','stock','NASDAQ','USD','NVDA',184.6,138,32,'#85bd49','Projektowanie procesorów graficznych i rozwiązań obliczeniowych. Opis przykładowy.'],['msft','MSFT','Microsoft','stock','NASDAQ','USD','MSFT',517.3,465,15,'#6fa9ff','Oprogramowanie, usługi chmurowe i produkty technologiczne. Opis przykładowy.'],['pko','PKO','PKO Bank Polski','stock','GPW','PLN','PKO.WA',78.22,70,170,'#9c8cef','Bankowość detaliczna i korporacyjna. Opis przykładowy.'],['vwce','VWCE','Vanguard FTSE All-World','etf','XETRA','EUR','VWCE.DE',142.5,130,36,'#60cccf','ETF z ekspozycją na globalny rynek akcji. Opis przykładowy.'],['btc','BTC','Bitcoin','crypto','Krypto','USD','BTC-USD',108420,98500,0.065,'#e8b36e','Cyfrowe aktywo działające w zdecentralizowanej sieci. Opis przykładowy.']];
    const end=today();const start=new Date(end+'T12:00:00Z');start.setUTCDate(start.getUTCDate()-180);const begin=start.toISOString().slice(0,10);
    s.fx.USD={rate:3.85,date:end,source:'Demo',history:[{date:begin,rate:3.92},{date:end,rate:3.85}]};s.fx.EUR={rate:4.28,date:end,source:'Demo',history:[{date:begin,rate:4.3},{date:end,rate:4.28}]};
    for(const [i,symbol,name,type,market,currency,providerSymbol,price,buy,quantity,color,description] of defs) {
      const history=[];for(let j=0;j<=180;j+=3){const d=new Date(start);d.setUTCDate(d.getUTCDate()+j);const trend=j/180,noise=Math.sin(j*.21+symbol.length)*.022+Math.sin(j*.65)*.012;history.push({date:d.toISOString().slice(0,10),close:buy+(price-buy)*trend+buy*noise});}history.at(-1).close=price;
      s.assets.push({id:i,symbol,name,type,market,currency,providerSymbol,color,description,website:'',irUrl:'',reportUrl:'',quote:{price,date:end,source:'Demo · przykład',change:[1.42,2.17,.68,-.83,.42,1.86][s.assets.length],history}});
    }
    s.ledger.push({id:'deposit1',type:'deposit',date:begin,currency:'PLN',amount:50000,fee:0,fx:1,note:'Wpłata przykładowa'}, {id:'deposit2',type:'deposit',date:begin,currency:'USD',amount:18500,fee:0,fx:3.92,note:'Wpłata przykładowa'}, {id:'deposit3',type:'deposit',date:begin,currency:'EUR',amount:6000,fee:0,fx:4.3,note:'Wpłata przykładowa'});
    defs.forEach(([i,,,type,,currency,,price,buy,quantity])=>s.ledger.push({id:'buy-'+i,type:'buy',date:begin,assetId:i,currency,quantity,price:buy,fee:Math.round(quantity*buy*.0029*100)/100,fx:currency==='PLN'?1:currency==='USD'?3.92:4.3,note:'Transakcja przykładowa'}));
    s.retirement=[{id:'demo-zus',kind:'zus',name:'Konto i subkonto ZUS',provider:'ZUS',currency:'PLN',note:'Przykładowy zapis emerytalny',history:[{date:end,amount:182400}]},{id:'demo-ofe',kind:'ofe',name:'Mój rachunek OFE',provider:'Fundusz OFE',currency:'PLN',history:[{date:end,amount:26400}]},{id:'demo-ppk',kind:'ppk',name:'PPK — pracodawca',provider:'Fundusz PPK',currency:'PLN',history:[{date:end,amount:18750}]}];
    s.ledger.push({id:'div1',type:'dividend',date:end,assetId:'pko',currency:'PLN',amount:850,tax:161.5,fee:0,fx:1,note:'Dywidenda przykładowa'});return s;
  }
  // Percent fees in cents equal base × percent: decimal multiplication avoids binary ties.
  function decimalParts(value){
    const [mantissa,exponent='0']=String(value).toLowerCase().split('e');
    const fraction=(mantissa.split('.')[1]||'').length;
    return {coefficient:BigInt(mantissa.replace('.','')),exponent:Number(exponent)-fraction};
  }
  function roundedDecimalProduct(a,b,shift=0){
    const x=decimalParts(a),y=decimalParts(b),coefficient=x.coefficient*y.coefficient,exponent=x.exponent+y.exponent+shift;
    let cents;
    if(exponent>=0)cents=coefficient*10n**BigInt(exponent);
    else{const divisor=10n**BigInt(-exponent),remainder=coefficient%divisor;cents=coefficient/divisor+(remainder*2n>=divisor?1n:0n);}
    assert(cents<=BigInt(Number.MAX_SAFE_INTEGER),'Prowizja przekracza bezpieczny zakres obliczeń.');
    return Number(cents);
  }
  function commission(base,mode,input,minimum=0){
    assert(Number.isFinite(base)&&base>=0,'Nieprawidłowa wartość operacji.');
    assert(['percent','amount'].includes(mode)&&Number.isFinite(input)&&input>=0&&Number.isFinite(minimum)&&minimum>=0,'Wpisz nieujemną prowizję.');
    if(mode==='amount'){const percent=base>0?input/base*100:null;assert(percent===null||Number.isFinite(percent),'Prowizja przekracza zakres obliczeń.');return {fee:input,percent,minimumApplied:false};}
    if(base===0)return {fee:null,percent:input,minimumApplied:false};
    const raw=base*input/100;assert(Number.isFinite(raw),'Prowizja przekracza zakres obliczeń.');
    const fee=Math.max(roundedDecimalProduct(base,input),roundedDecimalProduct(minimum,1,2))/100;
    return {fee,percent:input,effectivePercent:finite(fee/base*100),minimumApplied:minimum>raw};
  }
  function commissionMatches(base,percent,minimum,fee){
    const current=commission(base,'percent',percent,minimum).fee;
    // Preserve amounts saved by the former binary rounding rule; new entries use half-up.
    const legacy=Math.round((Math.max(base*percent/100,minimum)+Number.EPSILON)*100)/100;
    return Math.abs(current-fee)<1e-7||(Number.isFinite(legacy)&&Math.abs(legacy-fee)<1e-7);
  }
  const api={TYPES,RETIREMENT,wealth,OPERATIONS,today,id,empty,validate,calculate,portfolioHistory,parseCSV,demo,commission,latestQuote,latestFX};if(typeof module!=='undefined')module.exports=api;else root.Portfolio=api;
})(typeof window!=='undefined'?window:globalThis);
