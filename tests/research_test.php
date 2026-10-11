<?php
define('STOCK_API',true);date_default_timezone_set('Europe/Warsaw');require __DIR__.'/../public/research.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$annual=['start'=>'2024-01-01','end'=>'2024-12-31','val'=>50,'accn'=>'0000000001-25-000001','form'=>'10-K','filed'=>'2025-02-01'];
$older=$annual;$older['end']='2023-12-31';$older['start']='2023-01-01';$older['val']=999;
$quarter=$annual;$quarter['start']='2024-10-01';$quarter['val']=100000;
$data=['cik'=>1,'entityName'=>'Fixture','facts'=>['us-gaap'=>[
 'NetIncomeLoss'=>['units'=>['USD'=>[$older,$quarter,$annual]]],
 'Revenues'=>['units'=>['USD'=>[array_replace($annual,['val'=>100])]]],
 'Assets'=>['units'=>['USD'=>[array_diff_key(array_replace($annual,['val'=>1000]),['start'=>0])]]],
 'Liabilities'=>['units'=>['USD'=>[array_diff_key(array_replace($annual,['val'=>300]),['start'=>0])]]],
 'StockholdersEquity'=>['units'=>['USD'=>[array_diff_key(array_replace($annual,['val'=>999,'accn'=>'0000000001-25-000002']),['start'=>0])]]],
]]];
$f=extractSecFacts($data,1);check(count($f['rows'])===4,'Wrong period or filing included');check($f['ratios'][0]['value']===50.0,'Wrong net margin');check($f['ratios'][1]['value']===30.0,'Wrong liabilities ratio');
try{extractSecFacts($data,2);throw new RuntimeException('Mismatched CIK accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Identyfikator'),'Unexpected mismatch exception');}
$data['facts']['us-gaap']['Revenues']['units']['USD'][]=array_replace($annual,['val'=>101]);$f=extractSecFacts($data,1);check(!in_array('revenue',array_column($f['rows'],'key')),'Conflicting XBRL values accepted');check(count($f['ratios'])===1,'Ratio invented from missing revenue');
$future=$annual;$future['filed']='2099-01-01';check(!factValid($future),'Future report accepted');
$html='<article><h2>H1 2024 earnings</h2><p class="byline">September 2, 2024</p><section>In the first half of 2024 the Group generated 435 million PLN in revenues, and posted 249 million PLN in consolidated net profit</section></article>';
$f=extractCdResults($html);check($f['rows'][0]['value']===435000000.0&&$f['rows'][1]['value']===249000000.0,'CD figures incorrect');check(!$f['ratios'],'Rounded summary should not produce ratios');check(!extractCdResults(str_replace('million PLN','million USD',$html))['rows'],'Currency guessed');
check(issuerAnchor('<a href="/investors">Investors</a>','https://example.com/','/^Investors$/i')==='https://example.com/investors','Real link not extracted');
check(resolveIssuerUrl('https://example.com/','javascript:alert(1)')==='','Unsafe link accepted');
foreach(['https://127.0.0.1/','https://localhost/','https://example.com:8443/','http://example.com/','https://user:pass@example.com/'] as $u){try{researchGet($u);throw new RuntimeException('Unsafe fetch accepted');}catch(RuntimeException $e){check($e->getMessage()!=='Unsafe fetch accepted','SSRF guard failed');}}
echo "PASS: exact annual period, filing, units, CIK, conflicting values, future dates, rounded issuer data, missing ratios and safe URLs.\n";
