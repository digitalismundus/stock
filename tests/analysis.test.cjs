const test=require('node:test'),assert=require('node:assert/strict'),A=require('../public/analysis.js');
const series=values=>values.map((close,i)=>({date:new Date(Date.UTC(2020,0,i+1)).toISOString().slice(0,10),close}));
test('indicators use exact available prices; flat market is neutral',()=>{const t=A.technical(series(Array(210).fill(100)),'2025-01-01');assert.equal(t.sma20,100);assert.equal(t.sma200,100);assert.equal(t.rsi14,50);assert.equal(t.macd,0);assert.equal(t.signal,0);assert.equal(t.bollingerLower,100);assert.equal(t.changes.find(x=>x.sessions===252).value,null);});
test('RSI Wilder matches a known independently calculated reference',()=>{const v=[44.34,44.09,44.15,43.61,44.33,44.83,45.10,45.42,45.84,46.08,45.89,46.03,45.61,46.28,46.28];assert.ok(Math.abs(A.rsi(v)-70.46413502109705)<1e-9);assert.equal(A.rsi(Array.from({length:16},(_,i)=>100+i)),100);assert.equal(A.rsi(Array.from({length:16},(_,i)=>100-i)),0);});
test('no extrapolation for short history; today and future are excluded',()=>{const t=A.technical([{date:'2024-01-01',close:10},{date:'2024-01-02',close:20},{date:'2024-01-03',close:30}],'2024-01-03');assert.equal(t.count,2);assert.equal(t.last,20);assert.equal(t.sma20,null);assert.equal(t.macd,null);assert.equal(t.changes[0].value,100);});
test('bad dates and conflicting duplicates reject the entire analysis',()=>{assert.throws(()=>A.technical([{date:'2024-02-30',close:1}],'2025-01-01'));assert.throws(()=>A.technical([{date:'2024-01-01',close:1},{date:'2024-01-01',close:2}],'2025-01-01'));assert.throws(()=>A.technical([{date:'2024-01-01',close:NaN}],'2025-01-01'));});
test('SMA-seeded EMA and MACD use actual observations only',()=>{assert.deepEqual(A.ema([1,2,3,4,5],3),[null,null,2,3,4]);const t=A.technical(series(Array.from({length:34},(_,i)=>100+i)),'2025-01-01');assert.equal(t.macd,7);assert.equal(t.signal,7);assert.equal(t.histogram,0);assert.equal(A.technical(series(Array(33).fill(100)),'2025-01-01').signal,null);});
test('UTC market cutoff excludes an unfinished crypto day after midnight in Warsaw',()=>{
 // At 2026-10-12 00:30 Warsaw it is still October 11 in the UTC market.
 const t=A.technical([{date:'2026-10-10',close:100},{date:'2026-10-11',close:150}],'2026-10-11');
 assert.equal(t.asOf,'2026-10-10');assert.equal(t.last,100);assert.equal(t.count,1);
});
test('Asian market cutoff retains completed market dates even while Warsaw is a day behind',()=>{
 // The server has already retained the completed Tokyo October 12 bar and
 // supplies October 13 as the exclusive market day; Warsaw is still October 12.
 const t=A.technical([{date:'2026-10-09',close:100},{date:'2026-10-12',close:110},{date:'2026-10-13',close:120}],'2026-10-13');
 assert.equal(t.asOf,'2026-10-12');assert.equal(t.last,110);assert.equal(t.count,2);
 assert.ok(Math.abs(t.changes[0].value-10)<1e-9);
});
test('missing or invalid market cutoff cannot silently include unfinished data',()=>{
 for(const cutoff of [undefined,null,'','2026-02-30','2026-1-01','not-a-date'])assert.throws(()=>A.technical([{date:'2026-10-11',close:100}],cutoff),/daty odcięcia rynku/);
});
