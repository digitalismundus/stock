const test=require('node:test'),assert=require('node:assert/strict');
const C=require('../public/core.js'),H=require('../public/history.js');
test('older quote and FX corrections retain the latest value and provenance',()=>{
 const q=H.manualQuote({price:100,date:'2026-09-30',source:'Yahoo Finance',history:[{date:'2026-09-01',close:90}]},75,'2026-09-15','ABC');
 assert.equal(q.price,100);assert.equal(q.date,'2026-09-30');assert.equal(q.source,'Yahoo Finance');assert.equal(q.history.find(p=>p.date==='2026-09-15').source,'Ręcznie');
 const fx=H.fx({rate:4,date:'2026-09-30',source:'NBP',history:[{date:'2026-09-01',rate:3.8}]},{rate:3,date:'2026-09-15',source:'Ręcznie',history:[]});
 assert.equal(fx.rate,4);assert.equal(fx.source,'NBP');assert.equal(fx.history.length,3);
});
test('refresh extends compatible histories and preserves old observations',()=>{
 const old={price:90,date:'2025-01-02',source:'Yahoo Finance',history:[{date:'2024-01-02',close:80}],providerSymbol:'ABC'};
 const q=H.quote(old,{price:100,date:'2026-09-30',source:'Yahoo Finance',history:[{date:'2026-09-29',close:99}]},'ABC');
 assert.deepEqual(q.history.map(p=>p.date),['2024-01-02','2025-01-02','2026-09-29','2026-09-30']);assert.deepEqual(q.historyArchives,[]);
 const fx=H.fx({rate:3.5,date:'2024-01-02',source:'NBP',history:[]},{rate:4,date:'2026-09-30',source:'NBP',history:[]});assert.equal(fx.history[0].date,'2024-01-02');
});
test('different price bases and provider symbols are archived instead of mixed',()=>{
 const old={price:80,date:'2025-01-02',source:'Import CSV',history:[{date:'2024-01-02',close:70}]};
 const q=H.quote(old,{price:100,date:'2026-09-30',source:'Yahoo Finance',history:[]},'ABC');
 assert.equal(q.history.length,1);assert.equal(q.historyArchives[0].history.length,2);
 const switched=H.quote(q,{price:20,date:'2026-09-30',source:'Yahoo Finance',history:[]},'XYZ');assert.equal(switched.historyArchives.length,2);assert.equal(switched.historyArchives[1].providerSymbol,'ABC');
 const s=C.empty();s.assets=[{id:'a',name:'A',symbol:'A',type:'stock',currency:'PLN',market:'Test',quote:switched}];assert.equal(C.validate(s),s);
});
test('zero remains a valid latest quote and history limits fail without discarding data',()=>{
 assert.equal(H.manualQuote(null,0,'2026-09-30').price,0);
 const q={price:10,date:'2026-09-30',source:'CSV',history:[],historyArchives:Array.from({length:20},()=>({source:'CSV',basis:'unknown',providerSymbol:'',archivedAt:'2026-09-30',history:[]}))};
 assert.throws(()=>H.quote(q,{price:20,date:'2026-09-30',source:'Yahoo',history:[]},'A'),/20 archiwów/);assert.equal(q.historyArchives.length,20);
});
