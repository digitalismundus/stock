<?php
declare(strict_types=1);
define('STOCK_API', true);
date_default_timezone_set('Europe/Warsaw');
require __DIR__.'/../public/validation.php';
function requireThat(bool $ok, string $message): void { if (!$ok) throw new InvalidArgumentException($message); }
function validNumber(mixed $v, bool $positive=false): bool { return (is_int($v)||is_float($v))&&is_finite((float)$v)&&($positive?$v>0:$v>=0); }
function validCurrency(mixed $v): bool { return is_string($v)&&preg_match('/^[A-Z]{3}$/D',$v)===1; }
function checkBackend(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectsBackend(callable $test): void { try { $test(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid range or trade accepted'); }

foreach ([[12750,.29,0,36.98],[1,.5,0,.01],[1,.49999999999999994,0,0.0],[100,.29,1.005,1.01],[1e-7,1e7,0,.01],[1e20,1e-20,0,.01],[0,99,0,0.0]] as [$base,$percent,$minimum,$fee]) checkBackend(stockPercentFee($base,$percent,$minimum)===$fee, 'Decimal half-up fee mismatch');
rejectsBackend(fn()=>stockPercentFee(1e308,100,0));
checkBackend(stockPercentFeeMatches(12750,.29,0,36.97)&&stockPercentFeeMatches(12750,.29,0,36.98)&&!stockPercentFeeMatches(12750,.29,0,37),'Historical fee compatibility mismatch');

$market=['meta'=>['currency'=>'USD','exchangeTimezoneName'=>'America/New_York'],'timestamp'=>[strtotime('2026-10-09T16:00:00Z'),strtotime('2026-10-10T16:00:00Z')],'indicators'=>['adjclose'=>[['adjclose'=>[10,20]]]]];
$result=stockTechnicalHistory($market,new DateTimeImmutable('2026-10-10T22:30:00Z'));
checkBackend($result['history']===[['date'=>'2026-10-09','close'=>10]],'Warsaw midnight included the current New York session');
checkBackend($result['marketDay']==='2026-10-10'&&$result['timezone']==='America/New_York','Market cutoff metadata missing');
$market['meta']['exchangeTimezoneName']='Asia/Tokyo';
$market['timestamp']=[strtotime('2026-10-09T04:00:00Z'),strtotime('2026-10-10T04:00:00Z')];
$result=stockTechnicalHistory($market,new DateTimeImmutable('2026-10-10T20:00:00Z'));
checkBackend(count($result['history'])===2,'Previous Tokyo day was incorrectly excluded by Warsaw date');
$market['indicators']['adjclose'][0]['adjclose']=[null,0];
checkBackend(stockTechnicalHistory($market,new DateTimeImmutable('2026-10-12T20:00:00Z'))['history']===[],'Missing provider prices were converted to zero');

$asset=['id'=>'__proto__','symbol'=>'TEST','currency'=>'PLN','quote'=>['price'=>0,'date'=>'2026-01-03','history'=>[['date'=>'2026-01-02','close'=>10],['date'=>'2026-01-03','close'=>0]]]];
$buy=['id'=>'buy','type'=>'buy','assetId'=>'__proto__','date'=>'2026-01-02','currency'=>'PLN','quantity'=>1,'price'=>10,'fee'=>0,'fx'=>1];
$state=['assets'=>[$asset],'ledger'=>[$buy],'fx'=>['PLN'=>['rate'=>1,'date'=>'2026-01-03']],'retirement'=>[]];
stockValidateFinancialRange($state,$state['ledger']);
$micro=$state;$micro['ledger'][0]['quantity']=5e-9;$micro['ledger'][0]['price']=2e10;
$micro['ledger'][]=array_replace($micro['ledger'][0],['id'=>'sell','type'=>'sell']);
stockValidateFinancialRange($micro,$micro['ledger']);
$micro['ledger'][1]['quantity']=6e-9;rejectsBackend(fn()=>stockValidateFinancialRange($micro,$micro['ledger']));
$bad=$state;$bad['ledger'][0]['quantity']=1e308;$bad['ledger'][0]['price']=1e308;
rejectsBackend(fn()=>stockValidateFinancialRange($bad,$bad['ledger']));
$bad=$state;$bad['assets'][0]['quote']['history'][0]['close']=1e308;$bad['ledger'][0]['quantity']=10;
rejectsBackend(fn()=>stockValidateFinancialRange($bad,$bad['ledger']));
$bad=$state;$bad['ledger']=[['id'=>'d1','type'=>'deposit','date'=>'2026-01-02','currency'=>'PLN','amount'=>1e308,'fee'=>0,'fx'=>1],['id'=>'d2','type'=>'deposit','date'=>'2026-01-03','currency'=>'PLN','amount'=>1e308,'fee'=>0,'fx'=>1]];
rejectsBackend(fn()=>stockValidateFinancialRange($bad,$bad['ledger']));

$future=$state;$future['assets'][0]['symbol']='GC=F';
rejectsBackend(fn()=>stockValidateNewTrades($future,[]));
stockValidateNewTrades($future,$future);
$notes=$future;$notes['ledger'][0]['note']='Historical correction';stockValidateNewTrades($notes,$future);
$changed=$future;$changed['ledger'][0]['quantity']=2;rejectsBackend(fn()=>stockValidateNewTrades($changed,$future));
echo "PASS: decimal half-up, safe cents, source-timezone technical sessions, missing provider data, zero manual quote, __proto__ identifiers, product/sum/history overflow, preserved legacy futures and blocked new futures.\n";
