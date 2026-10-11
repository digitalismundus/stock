/* Deterministic calculations only; no forecasts, invented prices or missing-value substitution. */
(function(root){
 'use strict';
 // cutoffDate is exclusive and belongs to the source market's timezone. The caller
 // supplies the server's market day; do not replace it with the browser's local day.
 function series(history,cutoffDate){
  if(typeof cutoffDate!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(cutoffDate)||Number.isNaN(Date.parse(cutoffDate))||new Date(cutoffDate+'T12:00:00Z').toISOString().slice(0,10)!==cutoffDate)throw new Error('Brak poprawnej daty odcięcia rynku.');
  const days=new Map();
  for(const p of history||[]){if(!p||!/^\d{4}-\d{2}-\d{2}$/.test(p.date)||!Number.isFinite(p.close)||p.close<=0)throw new Error('Historia zawiera nieprawidłowe dane.');if(new Date(p.date+'T12:00:00Z').toISOString().slice(0,10)!==p.date)throw new Error('Historia zawiera nieprawidłową datę.');if(p.date>=cutoffDate)continue;if(days.has(p.date)&&days.get(p.date)!==p.close)throw new Error('Sprzeczne ceny dla tej samej daty.');days.set(p.date,p.close);}
  return [...days].sort((a,b)=>a[0].localeCompare(b[0])).map(([date,close])=>({date,close}));
 }
 const sma=(v,n)=>v.length>=n?v.slice(-n).reduce((a,b)=>a+b,0)/n:null;
 function ema(v,n){const out=Array(v.length).fill(null);if(v.length<n)return out;let e=v.slice(0,n).reduce((a,b)=>a+b,0)/n;out[n-1]=e;const k=2/(n+1);for(let i=n;i<v.length;i++){e=v[i]*k+e*(1-k);out[i]=e;}return out;}
 function rsi(v,n=14){if(v.length<n+1)return null;let g=0,l=0;for(let i=1;i<=n;i++){const d=v[i]-v[i-1];g+=Math.max(d,0);l+=Math.max(-d,0);}g/=n;l/=n;for(let i=n+1;i<v.length;i++){const d=v[i]-v[i-1];g=(g*(n-1)+Math.max(d,0))/n;l=(l*(n-1)+Math.max(-d,0))/n;}return g===0&&l===0?50:l===0?100:100-100/(1+g/l);}
 function technical(history,cutoffDate){const p=series(history,cutoffDate),v=p.map(p=>p.close),last=v.at(-1)??null;const fast=ema(v,12),slow=ema(v,26),macds=v.map((_,i)=>fast[i]!==null&&slow[i]!==null?fast[i]-slow[i]:null).filter(x=>x!==null),signal=ema(macds,9).at(-1)??null,macd=macds.at(-1)??null;
  const avg=sma(v,20),std=avg===null?null:Math.sqrt(v.slice(-20).reduce((a,b)=>a+(b-avg)**2,0)/20);
  const changes=[1,5,21,63,252].map(n=>({sessions:n,value:v.length>n?(last/v[v.length-1-n]-1)*100:null}));
  return {points:p,count:p.length,asOf:p.at(-1)?.date??null,last,sma20:sma(v,20),sma50:sma(v,50),sma200:sma(v,200),rsi14:rsi(v),macd,signal,histogram:macd!==null&&signal!==null?macd-signal:null,bollingerLower:std===null?null:avg-2*std,bollingerUpper:std===null?null:avg+2*std,changes};
 }
 const api={technical,sma,ema,rsi,series};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.StockAnalysis=api;
})(typeof globalThis!=='undefined'?globalThis:this);
