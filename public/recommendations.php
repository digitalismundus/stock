<?php
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }

function recommendationDate(mixed $v,string $format='Y-m-d'): ?string {
    if(!is_string($v))return null;
    $d=DateTimeImmutable::createFromFormat('!'.$format,$v);
    return $d&&$d->format($format)===$v&&$d->format('Y-m-d')<=date('Y-m-d')?$d->format('Y-m-d'):null;
}
function recommendationPrice(mixed $v): ?float {
    if(!is_string($v)||!preg_match('/^\d+(?:\.\d{1,4})?$/D',$v))return null;
    return (float)$v>0&&is_finite((float)$v)?(float)$v:null;
}
function extractBossaRecommendations(array $data,?string $expectedIsin=null): array {
    if(($data['success']??false)!==true||!is_array($data['list']??null))throw new RuntimeException('Niepoprawna lista DM BOŚ.');
    $rows=[];
    foreach($data['list'] as $r){
        if(!is_array($r))continue;
        $ticker=$r['symbol_short']??'';$isin=$r['isin']??'';
        if(!is_string($ticker)||!preg_match('/^[A-Z0-9]{1,10}$/D',$ticker)||!is_string($isin)||!preg_match('/^[A-Z]{2}[A-Z0-9]{10}$/D',$isin)||($expectedIsin!==null&&$isin!==$expectedIsin))continue;
        $day=recommendationDate($r['release_date']??null,'d.m.Y');if(!$day)continue;
        $available=recommendationDate($r['publication_date']??null);
        // Protected values are opaque publisher tokens, never ratings or prices to decode.
        $blocked=($r['isBlocked']??false)===true||!$available;
        $rating=$blocked?null:($r['type']??null);
        if(!$blocked&&!in_array($rating,['Kupuj','Trzymaj','Sprzedaj','Bez rekomendacji','Przerwana','Zawieszona'],true))continue;
        $relative=$blocked?null:($r['relation']??null);
        if(!in_array($relative,['Przeważaj','Neutralnie','Niedoważaj'],true))$relative=null;
        $pdf='';if(!$blocked&&is_string($r['related_pdf_url']??null)&&preg_match('~^/analizy/rekomendacje/'.preg_quote($isin,'~').'/'.preg_quote($day,'~').'/pobierz/related$~D',$r['related_pdf_url']))$pdf='https://bossa.pl'.$r['related_pdf_url'];
        $rows[]=['symbol'=>$ticker.'.WA','company'=>mb_substr((string)($r['company']??$ticker),0,100),'isin'=>$isin,'broker'=>'DM BOŚ','author'=>is_string($r['author']??null)?mb_substr($r['author'],0,120):null,'date'=>$day,'publicDate'=>$available,'rating'=>$rating,'relativeRating'=>$relative,'target'=>$blocked?null:recommendationPrice($r['target_price']??null),'issuePrice'=>$blocked?null:recommendationPrice($r['current_rate']??null),'currency'=>'PLN','status'=>$blocked?'pending':'published','sourceUrl'=>'https://bossa.pl/analizy/rekomendacje?isin='.$isin,'reportUrl'=>$pdf,'note'=>$blocked?'Ocena i cena docelowa nie są jeszcze publicznie dostępne.':'Ocena fundamentalna i relatywna pochodzą z oddzielnych skal DM BOŚ.'];
    }
    return $rows;
}
function extractBdmRecommendations(string $html): array {
    libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);$rows=[];
    foreach($xp->query('//table') as $table){
        $header=$xp->query('.//tr[1]',$table)->item(0);
        if(!$header)continue;$heads=[];foreach($xp->query('./th',$header) as $th)$heads[]=preg_replace('/\s+/u',' ',trim($th->textContent));
        if($heads!==['Data','Opis treści dokumentu','Pobierz'])continue;
        foreach($xp->query('.//tr[td]',$table) as $tr){
            $cells=$xp->query('./td',$tr);if($cells->length!==3)continue;
            $day=recommendationDate(trim($cells->item(0)->textContent),'d-m-Y');$anchor=$xp->query('.//h3/a',$cells->item(1))->item(0);if(!$day||!$anchor)continue;
            $title=preg_replace('/\s+/u',' ',trim($anchor->textContent));
            if(!preg_match('/^(.+?)\s*-\s*(KUPUJ|AKUMULUJ|TRZYMAJ|REDUKUJ|SPRZEDAJ)(?:\s*\(.*\))?$/uD',$title,$m))continue;
            $href=$anchor->getAttribute('href');$parts=parse_url($href);$query=[];parse_str($parts['query']??'',$query);$file=$query['file']??'';
            if(!is_string($file)||!preg_match('~^files/bdm/analizy_i_informacje/(?:rekomendacje|pwpa-gpw)/[A-Za-z0-9_./-]+\.pdf$~D',$file)||str_contains($file,'..')||!preg_match('/_([A-Z0-9]{1,10})_PL_RA\.pdf$/D',$file,$t))continue;
            $rows[]=['symbol'=>$t[1].'.WA','company'=>$m[1],'isin'=>null,'broker'=>'DM BDM','author'=>null,'date'=>$day,'publicDate'=>$day,'rating'=>mb_convert_case($m[2],MB_CASE_TITLE,'UTF-8'),'relativeRating'=>null,'target'=>null,'issuePrice'=>null,'currency'=>'PLN','status'=>'published','sourceUrl'=>'https://www.bdm.pl/analizy-i-informacje/analizy/rekomendacje','reportUrl'=>'https://www.bdm.pl/?file='.rawurlencode($file),'note'=>'Ocena z tytułu publicznego raportu BDM. Cena docelowa i autor: w PDF; nie odczytujemy ich automatycznie.'];
        }
    }
    if(!$rows)throw new RuntimeException('Nie rozpoznano publicznej tabeli BDM.');
    return $rows;
}
function latestRecommendations(array $rows): array {
    usort($rows,fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($a['broker'],$b['broker']));$seen=[];$out=[];
    foreach($rows as $r){$key=$r['broker'].'|'.$r['symbol'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$r;}
    return $out;
}
function brokerRecommendations(PDO $db,string $symbol,string $type): array {
    $rows=[];$warnings=[];$sources=[];$history=[];
    foreach(['bossa','bdm'] as $provider){
        try{
            $data=cached($db,'recommendations-'.$provider.'-v1',3600,function()use($provider){
                $list=$provider==='bossa'?extractBossaRecommendations(json_decode(researchGet('https://bossa.pl/analizy/rekomendacje/active',2000000),true,64,JSON_THROW_ON_ERROR)):extractBdmRecommendations(researchGet('https://www.bdm.pl/analizy-i-informacje/analizy/rekomendacje',2000000));
                return ['rows'=>$list,'fetchedAt'=>date(DATE_ATOM)];
            });
            $rows=array_merge($rows,$data['rows']);$sources[]=['broker'=>$provider==='bossa'?'DM BOŚ':'DM BDM','fetchedAt'=>$data['fetchedAt'],'count'=>count($data['rows'])];
        }catch(Throwable $e){$warnings[]=($provider==='bossa'?'DM BOŚ':'DM BDM').': nie pobrano potwierdzonej listy. '.$e->getMessage();}
    }
    $market=latestRecommendations($rows);
    $selected=$type==='stock'?array_values(array_filter($market,fn($r)=>$r['symbol']===$symbol)):[];
    foreach($selected as $row){if($row['broker']!=='DM BOŚ')continue;$isin=$row['isin'];
        try{$data=cached($db,'recommendations-history-bossa-v1-'.$isin,3600,fn()=>['rows'=>extractBossaRecommendations(json_decode(researchGet('https://bossa.pl/analizy/rekomendacje/isin/'.$isin,2000000),true,64,JSON_THROW_ON_ERROR),$isin)]);$history=array_merge($history,$data['rows']);}
        catch(Throwable $e){$warnings[]='Historia DM BOŚ jest niedostępna. Dostępne wpisy zachowano.';}
    }
    // Only actual publication dates determine chronology, never the fetch time.
    usort($history,fn($a,$b)=>strcmp($b['date'],$a['date']));
    return ['rows'=>$selected,'marketRows'=>$market,'history'=>$history,'sources'=>$sources,'warnings'=>$warnings,'coverage'=>'Publiczne listy GPW: DM BOŚ (zwykle po 7 dniach kalendarzowych) i ostatnia strona raportów DM BDM. Lista może być niepełna. Dla NYSE, NASDAQ i innych rynków nie podłączono źródła indywidualnych rekomendacji.','cacheSeconds'=>3600];
}
