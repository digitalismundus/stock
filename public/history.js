/* Preserve dated observations and keep incompatible price series separate. */
(function(root,factory){if(typeof module==='object'&&module.exports)module.exports=factory(require('./core.js'));else root.StockHistory=factory(root.Portfolio);})(typeof globalThis!=='undefined'?globalThis:this,function(C){
 'use strict';
 const MAX_POINTS=5000,MAX_ARCHIVES=20;
 function points(series,key){const out=(series?.history||[]).map(p=>({...p,source:p.source||series.source||'Nieznane źródło'}));if(series?.date&&Number.isFinite(series[key]))out.push({date:series.date,[key==='price'?'close':key]:series[key],source:series.source||'Nieznane źródło'});return out;}
 function merge(older,newer){const byDate=new Map();for(const p of [...older,...newer])byDate.set(p.date,{...p});const result=[...byDate.values()].sort((a,b)=>a.date.localeCompare(b.date));if(result.length>MAX_POINTS)throw new Error('Historia przekracza 5000 dat. Wyeksportuj dane i podziel serię; starsze punkty nie zostały usunięte.');return result;}
 function basis(q){return q?.historyBasis||(/Yahoo/i.test(q?.source||'')?'yahoo-close':'unknown');}
 function archive(q){return {source:q.source||'Nieznane źródło',basis:basis(q),providerSymbol:q.providerSymbol||'',archivedAt:C.today(),history:merge([],points(q,'price'))};}
 function normalizedQuote(q){const last=C.latestQuote(q);return last?{...q,price:last.price,date:last.date,source:last.source}:q;}
 function quote(previous,incoming,providerSymbol='',replace=false){
  const next={...incoming,historyBasis:basis(incoming),providerSymbol};
  const compatible=previous&&!replace&&basis(previous)==='yahoo-close'&&basis(next)==='yahoo-close'&&(previous.providerSymbol||providerSymbol)===providerSymbol;
  const archives=[...(previous?.historyArchives||[])];
  if(previous&&!compatible&&points(previous,'price').length){if(archives.length>=MAX_ARCHIVES)throw new Error('Zachowano już 20 archiwów historii. Wyeksportuj dane przed zmianą źródła.');archives.push(archive(previous));}
  next.history=merge(compatible?points(previous,'price'):[],points(next,'price'));
  next.historyArchives=archives;
  return normalizedQuote(next);
 }
 function manualQuote(previous,price,date,providerSymbol=''){
  const history=merge(points(previous,'price'),[{date,close:price,source:'Ręcznie'}]);
  return normalizedQuote({...previous,price,date,source:'Ręcznie',history,historyBasis:previous&&basis(previous)!=='unknown'?'mixed':basis(previous),providerSymbol,change:null});
 }
 function fx(previous,incoming){const history=merge(points(previous,'rate'),points(incoming,'rate'));const next={...incoming,history},last=C.latestFX(next);return last?{...next,rate:last.rate,date:last.date,source:last.source}:next;}
 return {quote,manualQuote,fx};
});
