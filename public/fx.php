<?php
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }

function nbpPeriod(string $currency, string $day): array {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if (!preg_match('/^[A-Z]{3}$/D', $currency) || $currency === 'PLN' || !$date || $date->format('Y-m-d') !== $day || $day < '2002-01-02' || $day > date('Y-m-d')) throw new InvalidArgumentException('Wybierz walutę obcą i poprawną datę od 02.01.2002 do dziś.');
    return [max('2002-01-02', $date->modify('-31 days')->format('Y-m-d')), $day];
}
function nbpReference(string $currency, string $day, string $table, string $number, string $date, float $rate): array {
    return ['currency'=>$currency,'requestedDate'=>$day,'date'=>$date,'rate'=>$rate,'table'=>$table,'number'=>$number,'source'=>'NBP · tabela '.$table,'sourceUrl'=>'https://api.nbp.pl/api/exchangerates/rates/'.strtolower($table).'/'.strtolower($currency).'/'.$date.'/?format=json'];
}
function nbpValidateReference(mixed $ref, string $currency, string $day, mixed $rate): void {
    [$start] = nbpPeriod($currency, $day);
    $date = is_array($ref) && is_string($ref['date']??null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $ref['date']) : false;
    if (!is_array($ref) || ($ref['currency']??null)!==$currency || ($ref['requestedDate']??null)!==$day || !$date || $date->format('Y-m-d')!==$ref['date'] || $ref['date']<$start || $ref['date']>$day || !in_array($ref['table']??null,['A','B'],true) || !is_string($ref['number']??null) || !preg_match('/^\d{3}\/'.$ref['table'].'\/NBP\/\d{4}$/D',$ref['number']) || !is_numeric($ref['rate']??null) || !is_int($ref['rate'])&&!is_float($ref['rate']) || !is_finite((float)$ref['rate']) || $ref['rate']<=0 || (float)$ref['rate']!==(float)$rate || ($ref['source']??null)!=='NBP · tabela '.$ref['table'] || ($ref['sourceUrl']??null)!==nbpReference($currency,$day,$ref['table'],$ref['number'],$ref['date'],(float)$ref['rate'])['sourceUrl']) throw new InvalidArgumentException('Nieprawidłowe źródło historycznego kursu NBP.');
}
function nbpNormalize(array $data, string $currency, string $day, string $table): array {
    nbpPeriod($currency, $day);
    if (($data['code']??null)!==$currency || ($data['table']??null)!==$table || !is_array($data['rates']??null) || !$data['rates']) throw new RuntimeException('NBP zwrócił nieprawidłowe dane kursu.');
    $rows=[];
    foreach ($data['rates'] as $row) {
        if (!is_array($row) || !is_string($row['no']??null) || !is_string($row['effectiveDate']??null) || (!is_int($row['mid']??null)&&!is_float($row['mid']??null))) throw new RuntimeException('NBP zwrócił nieprawidłowe dane kursu.');
        $ref=nbpReference($currency,$day,$table,$row['no'],$row['effectiveDate'],(float)$row['mid']);
        try { nbpValidateReference($ref,$currency,$day,$row['mid']); } catch (InvalidArgumentException $e) { throw new RuntimeException('NBP zwrócił nieprawidłowe dane kursu.'); }
        $rows[]=$ref;
    }
    usort($rows,fn($a,$b)=>strcmp($a['date'],$b['date']));return end($rows);
}
function nbpRemote(string $url): ?array {
    $curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_USERAGENT=>'STOCK-PersonalPortfolio/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if ($body===false || strlen($body)>1000000) throw new RuntimeException('NBP jest niedostępny. Ponów pobranie lub wpisz kurs ręcznie.');
    if ($status===404) return null;
    if ($status!==200) throw new RuntimeException('NBP jest niedostępny. Ponów pobranie lub wpisz kurs ręcznie.');
    try { $data=json_decode($body,true,64,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new RuntimeException('NBP zwrócił nieprawidłową odpowiedź.'); }
    if (!is_array($data)) throw new RuntimeException('NBP zwrócił nieprawidłową odpowiedź.');return $data;
}
function nbpHistorical(string $currency, string $day, ?callable $fetcher=null): array {
    [$start]=nbpPeriod($currency,$day);$fetcher??='nbpRemote';
    foreach (['A','B'] as $table) {
        $url='https://api.nbp.pl/api/exchangerates/rates/'.strtolower($table).'/'.strtolower($currency).'/'.$start.'/'.$day.'/?format=json';
        $data=$fetcher($url);
        if ($data===null) continue;
        return nbpNormalize($data,$currency,$day,$table);
    }
    throw new RuntimeException('Brak kursu NBP dla tej waluty w ciągu 31 dni do daty operacji. Wpisz kurs ręcznie.');
}
