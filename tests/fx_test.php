<?php
declare(strict_types=1);
define('STOCK_API',true);date_default_timezone_set('Europe/Warsaw');require __DIR__.'/../public/fx.php';
function check(bool $ok): void {if(!$ok)throw new RuntimeException('Test failed');}
function rejects(callable $f): void {try{$f();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
$fixture=['table'=>'A','code'=>'USD','rates'=>[['no'=>'191/A/NBP/2026','effectiveDate'=>'2026-10-02','mid'=>3.6584],['no'=>'190/A/NBP/2026','effectiveDate'=>'2026-10-01','mid'=>3.6634]]];
$ref=nbpNormalize($fixture,'USD','2026-10-04','A');check($ref['date']==='2026-10-02'&&$ref['rate']===3.6584);nbpValidateReference($ref,'USD','2026-10-04',3.6584);
foreach(['2026-02-30','2002-01-01','9999-01-01',''] as $day)rejects(fn()=>nbpPeriod('USD',$day));
foreach(['date'=>'2026-10-05','rate'=>4,'currency'=>'EUR','number'=>'wrong','sourceUrl'=>'https://example.com'] as $key=>$bad){$copy=$ref;$copy[$key]=$bad;rejects(fn()=>nbpValidateReference($copy,'USD','2026-10-04',3.6584));}
$copy=$fixture;$copy['rates'][0]['mid']=0;rejects(fn()=>nbpNormalize($copy,'USD','2026-10-04','A'));
$calls=[];$b=$fixture;$b['table']='B';$b['code']='KRW';foreach($b['rates'] as &$r)$r['no']=str_replace('/A/','/B/',$r['no']);unset($r);
$result=nbpHistorical('KRW','2026-10-04',function($url)use(&$calls,$b){$calls[]=$url;return count($calls)===1?null:$b;});check($result['table']==='B'&&count($calls)===2&&str_contains($calls[0],'/a/krw/2026-09-03/2026-10-04/'));
rejects(fn()=>nbpHistorical('XXX','2026-10-04',fn($url)=>null));
echo "PASS: historical NBP dates, actual publication, provenance, rate validation, table B fallback and no-data handling.\n";
