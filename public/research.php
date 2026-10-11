<?php
/** Factual issuer discovery and statement extraction. No forecasts or generated figures. */
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }
function issuerRegistry(): array {
    $rows=[
        'CDR.WA'=>['CD PROJEKT RED','https://www.cdprojekt.com/pl/inwestorzy/','https://www.cdprojekt.com/pl/inwestorzy/raporty-gieldowe/'],
        'PKO.WA'=>['PKO Bank Polski','https://www.pkobp.pl/relacje-inwestorskie','https://www.pkobp.pl/relacje-inwestorskie/raporty-biezace/'],
        'PKN.WA'=>['ORLEN','https://www.orlen.pl/pl/relacje-inwestorskie','https://www.orlen.pl/pl/relacje-inwestorskie/raporty-i-publikacje/raporty-biezace'],
        'PZU.WA'=>['PZU','https://www.pzu.pl/ri','https://www.pzu.pl/en/investor-relations/reports'],
        'KGH.WA'=>['KGHM','https://kghm.com/en/investors','https://kghm.com/pl/inwestorzy/raporty-i-prezentacje'],
        'DNP.WA'=>['Dino Polska','https://grupadino.pl/','https://grupadino.pl/raporty-gpw/raporty-biezace/'],
        'MSFT'=>['Microsoft','https://www.microsoft.com/en-us/investor','https://www.microsoft.com/en-us/investor/sec-filings'],
        'AAPL'=>['Apple','https://investor.apple.com/investor-relations/default.aspx','https://investor.apple.com/investor-relations/sec-filings/default.aspx'],
        'NVDA'=>['NVIDIA','https://investor.nvidia.com/home/default.aspx',''],
        'TSLA'=>['Tesla','https://ir.tesla.com/',''],
        'GOOG'=>['Alphabet','https://abc.xyz/investor/',''],
        'GOOGL'=>['Alphabet','https://abc.xyz/investor/',''],
        'AMZN'=>['Amazon','https://ir.aboutamazon.com/overview/default.aspx',''],
        'META'=>['Meta','https://investor.atmeta.com/home/default.aspx',''],
    ];
    return $rows;
}
function researchGet(string $target, int $limit=12000000, bool $dynamic=false): string {
    // Dynamic domains only come from a matched Wikidata entity or a link actually present on its site.
    // Pin all resolved addresses and validate every redirect; never allow internal network requests.
    $deadline=$GLOBALS["stockResearchDeadline"]??=microtime(true)+24;
    for($redirect=0;$redirect<3;$redirect++) {
        $remaining=(int)floor($deadline-microtime(true));if($remaining<1)throw new RuntimeException("Źródło nie odpowiedziało w wymaganym czasie.");
        $u=parse_url($target);$host=strtolower($u['host']??'');
        if(($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port'])||!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D',$host)) throw new RuntimeException('Nieprawidłowy adres źródła.');
        $addresses=gethostbynamel($host)?:[];
        if(!$addresses)throw new RuntimeException('Nie można odnaleźć domeny źródła.');
        foreach($addresses as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Niedozwolony adres źródła.');
        $curl=curl_init($target);$body='';$location='';
        curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RESOLVE=>[$host.':443:'.implode(',',$addresses)],CURLOPT_TIMEOUT=>min($dynamic?4:14,$remaining),CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_USERAGENT=>'STOCK Portfolio (stock.mateuszrak.com; contact: mateusz@mateuszrak.com)',CURLOPT_HTTPHEADER=>['Accept: application/json, text/html;q=0.9'],CURLOPT_ENCODING=>'',CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body,$limit){if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);}]);
        $ok=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok!==false&&$code===200)return $body;
        if($ok!==false&&in_array($code,[301,302,303,307,308],true)&&$location){$target=resolveIssuerUrl($target,$location);continue;}
        throw new RuntimeException('Źródło danych niedostępne (HTTP '.$code.').');
    }
    throw new RuntimeException('Źródło przekierowuje zbyt wiele razy.');
}
function resolveIssuerUrl(string $base,string $href): string {
    if(str_starts_with($href,'https://'))return $href;
    $u=parse_url($base);$origin='https://'.($u['host']??'');
    if(str_starts_with($href,'//'))return 'https:'.$href;
    if(preg_match('/^[a-z][a-z0-9+.-]*:/i',$href))return '';
    if(str_starts_with($href,'/'))return $origin.$href;
    return $origin.rtrim(dirname($u['path']??'/'),'/').'/'.$href;
}
function issuerAnchor(string $html,string $base,string $pattern): string {
    libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    foreach($doc->getElementsByTagName('a') as $a){$href=$a->getAttribute('href');$text=trim(preg_replace('/\s+/u',' ',$a->textContent));if(!preg_match($pattern,$text))continue;$link=resolveIssuerUrl($base,$href);if(str_starts_with($link,'https://')&&!preg_match('/\.(pdf|zip|xlsx?)(?:[?#]|$)/i',$link))return $link;}
    return '';
}
function secIssuer(PDO $db,string $symbol): ?array {
    // SEC tickers are US listing identifiers. Never remove a foreign exchange suffix.
    if(!preg_match('/^[A-Z0-9-]{1,15}$/D',$symbol))return null;
    $data=cached($db,'sec-tickers-exchange-v1',86400,fn()=>json_decode(researchGet('https://www.sec.gov/files/company_tickers_exchange.json'),true,64,JSON_THROW_ON_ERROR));
    $fields=$data['fields']??[];$matches=[];
    foreach($data['data']??[] as $row){if(count($row)!==count($fields))continue;$r=array_combine($fields,$row);if(strtoupper($r['ticker']??'')===$symbol&&in_array($r['exchange']??'',['Nasdaq','NYSE','AMEX','Cboe'],true))$matches[]=$r;}
    return count($matches)===1?$matches[0]:null;
}
function wikiIssuer(string $symbol,string $name): array {
    if(!$name)return [];
    $search=json_decode(researchGet('https://www.wikidata.org/w/api.php?action=wbsearchentities&language=en&format=json&limit=5&search='.rawurlencode($name),1000000,true),true,64,JSON_THROW_ON_ERROR);
    $ids=array_column($search['search']??[],'id');if(!$ids)return [];
    $data=json_decode(researchGet('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=claims|labels&languages=en|pl&ids='.rawurlencode(implode('|',$ids)),2000000,true),true,64,JSON_THROW_ON_ERROR);
    $ticker=preg_replace('/\.[A-Z]{1,3}$/','',$symbol);$matches=[];
    foreach($data['entities']??[] as $id=>$entity){$claims=$entity['claims']??[];$ticks=array_map(fn($v)=>$v['mainsnak']['datavalue']['value']??'', $claims['P249']??[]);if(!in_array($ticker,$ticks,true)&&!in_array($symbol,$ticks,true))continue;
        // Exchange qualification for the Polish market protects against same-ticker foreign issuers.
        if(str_ends_with($symbol,'.WA')){ $countries=array_map(fn($v)=>$v['mainsnak']['datavalue']['value']['id']??'', $claims['P17']??[]);if(!in_array('Q36',$countries,true))continue; }
        $sites=array_values(array_unique(array_filter(array_map(fn($v)=>$v['mainsnak']['datavalue']['value']??'', $claims['P856']??[]),fn($s)=>is_string($s)&&str_starts_with($s,'https://'))));
        if(count($sites)!==1)continue;
        $label=$entity['labels']['en']['value']??$entity['labels']['pl']['value']??'';
        $tokens=fn($s)=>array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($s)),fn($s)=>mb_strlen($s)>2&&!in_array($s,['inc','corp','corporation','limited','ltd','group','plc','holding','holdings','the'],true)));
        $a=$tokens($label);$b=$tokens($name);if(!$a||!$b||count(array_intersect($a,$b))/min(count($a),count($b))<0.5)continue;
        $matches[]=['website'=>$sites[0],'issuer'=>$label,'identityUrl'=>'https://www.wikidata.org/wiki/'.$id];
    }
    return count($matches)===1?$matches[0]:[];
}
function companyProfile(PDO $db,string $symbol,string $name,string $type): array {
    return cached($db,'issuer-v2-'.hash('sha256',$symbol.'|'.$name.'|'.$type),86400,function()use($db,$symbol,$name,$type){
        $profile=['symbol'=>$symbol,'issuer'=>'','irUrl'=>'','reportUrl'=>'','source'=>'','checkedAt'=>date(DATE_ATOM),'warning'=>'','cik'=>null];
        if($type!=='stock'){$profile['warning']='Automatyczne relacje inwestorskie dotyczą spółek. Dla funduszu lub innego instrumentu przypisz stronę emitenta ręcznie.';return $profile;}
        $row=issuerRegistry()[$symbol]??null;
        if($row){$profile=array_replace($profile,['issuer'=>$row[0],'irUrl'=>$row[1],'reportUrl'=>$row[2],'source'=>'Zweryfikowana oficjalna strona emitenta','verifiedOn'=>'2026-10-07']);}
        try{$sec=secIssuer($db,$symbol);if($sec){$profile['cik']=(int)$sec['cik'];$profile['issuer']=$sec['name'];if(!$profile['reportUrl'])$profile['reportUrl']='https://www.sec.gov/edgar/browse/?CIK='.$sec['cik'].'&owner=exclude';$profile['source'].=($profile['source']?' + ':'').'SEC: ticker / CIK';}}catch(Throwable $e){$profile['warning']='Katalog SEC jest niedostępny. Dostępne zweryfikowane linki zachowano.';}
        if(!$profile['irUrl']){
            try{$wiki=wikiIssuer($symbol,$name);if($wiki){$html=researchGet($wiki['website'],2000000,true);$ir=issuerAnchor($html,$wiki['website'],'/^(?:relacje\s+inwestorskie|inwestorzy|investor\s+relations|investors|investor\s+information|investor\s+center)$/iu');if($ir){$profile['irUrl']=$ir;$profile['issuer']=$profile['issuer']?:$wiki['issuer'];$profile['identityUrl']=$wiki['identityUrl'];$profile['source'].=($profile['source']?' + ':'').'Wikidata: zgodny ticker i nazwa → odnośnik na stronie emitenta';if(!$profile['reportUrl']){$html=researchGet($ir,2000000,true);$profile['reportUrl']=issuerAnchor($html,$ir,'/^(?:ESPI(?:\s*\/\s*EBI)?|raporty\s+bieżące|raporty\s+giełdowe|SEC\s+filings|regulatory\s+announcements|current\s+reports|financial\s+reports)$/iu');}}}}catch(Throwable $e){/* An unconfirmed identity must never produce a guessed URL. */}
        }
        if(!$profile['irUrl']||!$profile['reportUrl'])$profile['warning']=trim($profile['warning'].' Nie znaleziono wszystkich potwierdzonych linków. Brakujące pola pozostają puste.');
        return $profile;
    });
}
function factValid(array $r): bool {
    return isset($r['val'],$r['end'],$r['filed'],$r['accn'])&&is_numeric($r['val'])&&is_finite((float)$r['val'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$r['end'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$r['filed'])&&$r['end']<=date('Y-m-d')&&$r['filed']<=date('Y-m-d')&&preg_match('/^\d{10}-\d{2}-\d{6}$/D',$r['accn'])&&in_array($r['form']??'',['10-K','10-K/A'],true);
}
function annualDuration(array $r): bool {if(!isset($r['start']))return false;$days=(strtotime($r['end'])-strtotime($r['start']))/86400;return $days>=300&&$days<=400;}
function extractSecFacts(array $data,int $cik): array {
    if((int)($data['cik']??0)!==$cik)throw new RuntimeException('Identyfikator emitenta w sprawozdaniu nie pasuje.');
    $facts=$data['facts']['us-gaap']??[];
    $specs=[
        'revenue'=>['Przychody',['RevenueFromContractWithCustomerExcludingAssessedTax','RevenueFromContractWithCustomerIncludingAssessedTax','Revenues','SalesRevenueNet'],true,'USD'],
        'netIncome'=>['Zysk / strata netto',['NetIncomeLoss'],true,'USD'],
        'operatingIncome'=>['Wynik operacyjny',['OperatingIncomeLoss'],true,'USD'],
        'operatingCash'=>['Przepływy operacyjne',['NetCashProvidedByUsedInOperatingActivities'],true,'USD'],
        'assets'=>['Aktywa',['Assets'],false,'USD'],
        'liabilities'=>['Zobowiązania ogółem',['Liabilities'],false,'USD'],
        'equity'=>['Kapitał własny',['StockholdersEquity'],false,'USD'],
        'cash'=>['Gotówka i ekwiwalenty',['CashAndCashEquivalentsAtCarryingValue'],false,'USD'],
        'eps'=>['Zysk rozwodniony na akcję',['EarningsPerShareDiluted'],true,'USD/shares'],
    ];
    // The newest annual income period is the anchor. All rows must use its exact dates and filing.
    $candidates=[];foreach($specs['netIncome'][1] as $tag)foreach($facts[$tag]['units']['USD']??[] as $r)if(factValid($r)&&annualDuration($r))$candidates[]=$r;
    usort($candidates,fn($a,$b)=>strcmp($b['end'],$a['end'])?:strcmp($b['filed'],$a['filed']));
    if(!$candidates)return ['rows'=>[],'ratios'=>[],'warning'=>'Brak obsługiwanych rocznych danych US GAAP. Sprawozdania IFRS i tagi niestandardowe nie są zgadywane.'];
    $anchor=$candidates[0];$rows=[];$values=[];$missing=[];
    foreach($specs as $key=>[$label,$tags,$duration,$unit]){
        $match=null;$chosen='';foreach($tags as $tag){$set=array_values(array_filter($facts[$tag]['units'][$unit]??[],fn($r)=>factValid($r)&&$r['end']===$anchor['end']&&$r['accn']===$anchor['accn']&&($duration?annualDuration($r)&&$r['start']===$anchor['start']:!isset($r['start']))));if(!$set)continue;$unique=array_unique(array_column($set,'val'));if(count($unique)!==1)continue;$match=$set[0];$chosen=$tag;break;}
        if(!$match){$missing[]=$label;continue;}
        $link='https://www.sec.gov/Archives/edgar/data/'.$cik.'/'.str_replace('-','',$match['accn']).'/'.$match['accn'].'-index.html';
        $rows[]=['key'=>$key,'label'=>$label,'value'=>(float)$match['val'],'unit'=>$unit,'start'=>$match['start']??null,'end'=>$match['end'],'filed'=>$match['filed'],'tag'=>$chosen,'sourceUrl'=>$link];$values[$key]=(float)$match['val'];
    }
    $ratios=[];
    if(isset($values['netIncome'],$values['revenue'])&&$values['revenue']>0)$ratios[]=['label'=>'Marża netto','value'=>$values['netIncome']/$values['revenue']*100,'unit'=>'%','formula'=>'Zysk netto / przychody × 100; identyczny okres i raport.'];
    if(isset($values['liabilities'],$values['assets'])&&$values['assets']>0)$ratios[]=['label'=>'Zobowiązania / aktywa','value'=>$values['liabilities']/$values['assets']*100,'unit'=>'%','formula'=>'Zobowiązania ogółem / aktywa × 100; ten sam dzień bilansowy. Nie jest to wskaźnik samego długu odsetkowego.'];
    return ['issuer'=>$data['entityName']??'','period'=>$anchor['start'].' → '.$anchor['end'],'form'=>$anchor['form'],'filed'=>$anchor['filed'],'rows'=>$rows,'ratios'=>$ratios,'missing'=>$missing,'source'=>'SEC EDGAR · XBRL · roczny raport US GAAP','sourceUrl'=>'https://data.sec.gov/api/xbrl/companyfacts/CIK'.str_pad((string)$cik,10,'0',STR_PAD_LEFT).'.json','warning'=>'Dane roczne, nie TTM. Raport może zawierać okresy porównawcze; wybrano najnowszy pełny okres roczny. Brakujące tagi nie są szacowane.'];
}
function extractCdResults(string $html): array {
    libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);$rows=[];$period='';$filed='';
    foreach($xp->query('//article') as $article){$head=$xp->query('.//h2',$article)->item(0);if(!$head)continue;$title=trim($head->textContent);if(!preg_match('/^(H1|FY|Q[1-3]) (20\d{2}) earnings$/D',$title,$m))continue;
        $year=(int)$m[2];if($m[1]!=='H1')continue;$end=$year.'-'.(['H1'=>'06-30','FY'=>'12-31','Q1'=>'03-31','Q2'=>'06-30','Q3'=>'09-30'][$m[1]]);if($end>date('Y-m-d'))continue;
        $byline=$xp->query('.//*[contains(@class,"byline")]',$article)->item(0);$stamp=$byline?strtotime(trim($byline->textContent)):false;if(!$stamp||date('Y-m-d',$stamp)>date('Y-m-d'))continue;
        $text=preg_replace('/\s+/u',' ', $article->textContent);
        // Strict publisher-specific wording; unknown wording yields no number.
        if(str_contains($text,'first half of '.$year)&&preg_match('/generated ([0-9]+(?:\.[0-9]+)?) million PLN in revenues, and posted ([0-9]+(?:\.[0-9]+)?) million PLN in consolidated net profit/i',$text,$v)){
            $period=$title;$filed=date('Y-m-d',$stamp);$source='https://www.cdprojekt.com/en/investors/result-center/';
            foreach([['revenue','Przychody',(float)$v[1]],['netIncome','Zysk netto skonsolidowany',(float)$v[2]]] as [$key,$label,$val])$rows[]=['key'=>$key,'label'=>$label,'value'=>$val*1000000,'unit'=>'PLN','start'=>$year.'-01-01','end'=>$end,'filed'=>$filed,'tag'=>'Komunikat wynikowy emitenta · kwota zaokrąglona do mln PLN','sourceUrl'=>$source];break;
        }
    }
    return ['issuer'=>'CD PROJEKT RED','period'=>$period,'filed'=>$filed,'rows'=>$rows,'ratios'=>[],'source'=>'Oficjalny komunikat wynikowy emitenta','sourceUrl'=>'https://www.cdprojekt.com/en/investors/result-center/','warning'=>$rows?'Wartości zaokrąglone przez emitenta do mln PLN. Pozostałe dane: w pełnym sprawozdaniu. Nie wyliczamy wskaźników z niepełnego komunikatu.':'Struktura komunikatu nie jest obsługiwana. Brak danych zamiast zgadywania.'];
}
function issuerFundamentals(PDO $db,string $symbol,string $name,string $type): array {
    $base=['rows'=>[],'ratios'=>[],'source'=>'','warning'=>'Brak podłączonego źródła sprawozdań liczbowych dla tego instrumentu. Otwórz oficjalne raporty; nie uzupełniamy danych szacunkami.','fetchedAt'=>date(DATE_ATOM)];
    if($type!=='stock'){$base['warning']='Sprawozdania spółki nie opisują tego typu instrumentu. Analiza techniczna jest dostępna przy wystarczającej historii.';return $base;}
    try{
        if($symbol==='CDR.WA')return cached($db,'fundamental-cdr-v1',21600,fn()=>extractCdResults(researchGet('https://www.cdprojekt.com/en/investors/result-center/',3000000))+['fetchedAt'=>date(DATE_ATOM)]);
        $sec=secIssuer($db,$symbol);if(!$sec)return $base;$cik=(int)$sec['cik'];
        return cached($db,'fundamental-sec-v1-'.$cik,21600,fn()=>extractSecFacts(json_decode(researchGet('https://data.sec.gov/api/xbrl/companyfacts/CIK'.str_pad((string)$cik,10,'0',STR_PAD_LEFT).'.json'),true,64,JSON_THROW_ON_ERROR),$cik)+['fetchedAt'=>date(DATE_ATOM)]);
    }catch(Throwable $e){$base['warning']='Nie pobrano potwierdzonych danych fundamentalnych. '.$e->getMessage();return $base;}
}
