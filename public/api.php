<?php
declare(strict_types=1);
define('STOCK_API', true);
require __DIR__ . '/auth.php';
require __DIR__ . '/webauthn.php';
require __DIR__ . '/research.php';
require __DIR__ . '/recommendations.php';
require __DIR__ . '/fx.php';
require __DIR__ . '/duo.php';
require __DIR__ . '/validation.php';
/* Private, single-owner portfolio API. Serve ONLY this public/ directory. */
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Warsaw');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function respond(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function fail(string $message, int $status = 400): never { respond(['error'=>$message], $status); }
function requireThat(bool $test, string $message): void { if (!$test) throw new InvalidArgumentException($message); }
function validNumber(mixed $v, bool $positive = false): bool { return (is_int($v) || is_float($v)) && is_finite((float)$v) && ($positive ? $v > 0 : $v >= 0); }
function validDate(mixed $v): bool { if (!is_string($v)) return false; $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }
function validCurrency(mixed $v): bool { return is_string($v) && preg_match('/^[A-Z]{3}$/D', $v) === 1; }
function shortString(mixed $v, int $length, bool $empty = true): bool { return is_string($v) && strlen($v) <= $length && ($empty || strlen($v) > 0); }
function validateState(array $s): void {
    requireThat(($s['version'] ?? null) === 1 && isset($s['assets'], $s['ledger'], $s['fx'], $s['settings']) && is_array($s['assets']) && is_array($s['ledger']) && is_array($s['fx']) && is_array($s['settings']), 'Nieprawidłowy format portfela.');
    requireThat(array_is_list($s['assets']) && array_is_list($s['ledger']) && count($s['assets']) <= 1000 && count($s['ledger']) <= 20000 && count($s['fx']) <= 100, 'Nieprawidłowa liczba rekordów.');
    requireThat(shortString($s['settings']['name'] ?? null, 100) && validNumber($s['settings']['feePercent'] ?? null) && validNumber($s['settings']['minFee'] ?? null), 'Nieprawidłowe ustawienia.');
    $assets = []; $quantities = [];
    foreach ($s['assets'] as $a) {
        requireThat(is_array($a) && shortString($a['id'] ?? null, 100, false) && !isset($assets[$a['id']]), 'Nieprawidłowy identyfikator instrumentu.');
        requireThat(shortString($a['symbol'] ?? null, 30, false) && shortString($a['name'] ?? null, 200, false) && shortString($a['market'] ?? null, 100) && validCurrency($a['currency'] ?? null), 'Nieprawidłowy instrument.');
        requireThat(in_array($a['type'] ?? '', ['stock','etf','bond','fund','crypto','forex','commodity','other'], true), 'Nieprawidłowy typ instrumentu.');
        foreach (['description'=>20000,'providerSymbol'=>100,'irUrl'=>2000,'reportUrl'=>2000,'website'=>2000] as $key=>$limit) if (isset($a[$key])) requireThat(shortString($a[$key], $limit), 'Nieprawidłowe pole instrumentu.');
        if (isset($a['quote'])) {
            $q = $a['quote']; requireThat(is_array($q) && validNumber($q['price'] ?? null) && validDate($q['date'] ?? null) && $q['date'] <= date('Y-m-d') && isset($q['history']) && is_array($q['history']) && array_is_list($q['history']) && count($q['history']) <= 5000, 'Nieprawidłowa wycena.');
            foreach ($q['history'] as $p) requireThat(is_array($p) && validDate($p['date'] ?? null) && $p['date'] <= date('Y-m-d') && validNumber($p['close'] ?? null), 'Nieprawidłowa historia cen.');
            foreach (['historyBasis'=>500,'providerSymbol'=>100] as $key=>$limit) if (isset($q[$key])) requireThat(shortString($q[$key], $limit), 'Nieprawidłowe źródło historii cen.');
            if (isset($q['historyArchives'])) {
                requireThat(is_array($q['historyArchives']) && array_is_list($q['historyArchives']) && count($q['historyArchives']) <= 20, 'Nieprawidłowe archiwum historii cen.');
                foreach ($q['historyArchives'] as $archive) {
                    requireThat(is_array($archive) && shortString($archive['source'] ?? '', 500) && shortString($archive['basis'] ?? '', 500) && shortString($archive['providerSymbol'] ?? '', 100) && shortString($archive['archivedAt'] ?? null, 100, false) && isset($archive['history']) && is_array($archive['history']) && array_is_list($archive['history']) && count($archive['history']) <= 5000, 'Nieprawidłowe archiwum historii cen.');
                    foreach ($archive['history'] as $p) requireThat(is_array($p) && validDate($p['date'] ?? null) && $p['date'] <= date('Y-m-d') && validNumber($p['close'] ?? null), 'Nieprawidłowa archiwalna cena.');
                }
            }
        }
        $assets[$a['id']] = $a; $quantities[$a['id']] = 0.0;
    }
    requireThat(isset($s['fx']['PLN']) && (float)($s['fx']['PLN']['rate'] ?? 0) === 1.0, 'Kurs PLN musi wynosić 1.');
    foreach ($s['fx'] as $c=>$r) {
        requireThat(validCurrency($c) && is_array($r) && validNumber($r['rate'] ?? null, true) && validDate($r['date'] ?? null) && $r['date'] <= date('Y-m-d'), 'Nieprawidłowy kurs waluty.');
        if (isset($r['history'])) {
            requireThat(is_array($r['history']) && count($r['history']) <= 5000, 'Nieprawidłowa historia FX.');
            foreach ($r['history'] as $p) requireThat(is_array($p) && validDate($p['date'] ?? null) && $p['date'] <= date('Y-m-d') && validNumber($p['rate'] ?? null, true) && ($c !== 'PLN' || (float)$p['rate'] === 1.0), 'Nieprawidłowa historia FX. Kurs PLN musi wynosić 1.');
        }
    }
    $retirement=$s['retirement']??[];
    requireThat(is_array($retirement)&&array_is_list($retirement)&&count($retirement)<=100,'Nieprawidłowe rachunki emerytalne.');$retIds=[];
    foreach($retirement as $r){
        requireThat(is_array($r)&&shortString($r['id']??null,100,false)&&!isset($retIds[$r['id']])&&in_array($r['kind']??'', ['zus','ofe','ppk'],true),'Nieprawidłowy rachunek emerytalny.');$retIds[$r['id']]=true;
        requireThat(shortString($r['name']??null,100,false)&&($r['currency']??'')==='PLN'&&isset($r['history'])&&is_array($r['history'])&&array_is_list($r['history'])&&count($r['history'])>0&&count($r['history'])<=1000,'Nieprawidłowa nazwa lub historia rachunku.');
        foreach(['provider','note'] as $key)if(isset($r[$key]))requireThat(shortString($r[$key],4000),'Opis rachunku jest zbyt długi.');
        $days=[];foreach($r['history'] as $x){requireThat(is_array($x)&&validDate($x['date']??null)&&$x['date']<=date('Y-m-d')&&!isset($days[$x['date']])&&validNumber($x['amount']??null),'Nieprawidłowe saldo lub data rachunku.');$days[$x['date']]=true;}
    }
    $ids = [];
    foreach ($s['ledger'] as $t) {
        requireThat(is_array($t) && shortString($t['id'] ?? null, 100, false) && !isset($ids[$t['id']]), 'Nieprawidłowy identyfikator operacji.'); $ids[$t['id']] = true;
        requireThat(in_array($t['type'] ?? '', ['buy','sell','deposit','withdrawal','dividend','exchange','fee'], true) && validDate($t['date'] ?? null) && $t['date'] <= date('Y-m-d') && validCurrency($t['currency'] ?? null) && validNumber($t['fx'] ?? null, true) && validNumber($t['fee'] ?? null), 'Nieprawidłowa operacja.');
        requireThat($t['currency'] !== 'PLN' || (float)$t['fx'] === 1.0, 'Kurs PLN musi wynosić 1.');
        if(array_key_exists('fxReference',$t))nbpValidateReference($t['fxReference'],$t['currency'],$t['date'],$t['fx']);
        if(array_key_exists('targetFxReference',$t)){requireThat($t['type']==='exchange'&&validCurrency($t['targetCurrency']??null)&&validNumber($t['targetFx']??null,true),'Nieprawidłowe źródło kursu docelowego.');nbpValidateReference($t['targetFxReference'],$t['targetCurrency'],$t['date'],$t['targetFx']);}
        if(isset($t['feeMode']))requireThat(in_array($t['feeMode'],['percent','amount'],true)&&validNumber($t['feePercent']??null)&&validNumber($t['feeMinimum']??null),'Nieprawidłowy sposób lub wartość prowizji.');
        if(($t['feeMode']??'')==='percent'){$trade=in_array($t['type'],['buy','sell'],true);requireThat($trade?(validNumber($t['quantity']??null,true)&&validNumber($t['price']??null,true)):validNumber($t['amount']??null,true),'Nieprawidłowa podstawa prowizji.');$base=$trade?$t['quantity']*$t['price']:$t['amount'];requireThat(is_finite((float)$base)&&stockPercentFeeMatches($base,$t['feePercent'],$t['feeMinimum'],$t['fee']),'Kwota prowizji nie odpowiada stawce i minimum.');}
        if (isset($t['note'])) requireThat(shortString($t['note'], 4000), 'Notatka jest zbyt długa.');
        if (in_array($t['type'], ['buy','sell','dividend'], true)) requireThat(isset($assets[$t['assetId'] ?? '']), 'Nieistniejący instrument.');
        if (in_array($t['type'], ['buy','sell'], true)) requireThat(validNumber($t['quantity'] ?? null, true) && validNumber($t['price'] ?? null, true) && $assets[$t['assetId']]['currency'] === $t['currency'], 'Nieprawidłowy zakup lub sprzedaż.');
        else requireThat(validNumber($t['amount'] ?? null, true) && validNumber($t['tax'] ?? 0), 'Nieprawidłowa kwota.');
        if ($t['type'] === 'dividend') requireThat(($t['tax'] ?? 0) + $t['fee'] <= $t['amount'], 'Koszty przekraczają kwotę dywidendy.');
        if ($t['type'] === 'exchange') {
            requireThat(validCurrency($t['targetCurrency'] ?? null) && $t['targetCurrency'] !== $t['currency'] && validNumber($t['targetAmount'] ?? null, true) && validNumber($t['targetFx'] ?? null, true), 'Nieprawidłowe przewalutowanie.');
            requireThat($t['targetCurrency'] !== 'PLN' || (float)$t['targetFx'] === 1.0, 'Kurs PLN musi wynosić 1.');
        }
    }
    $ordered = $s['ledger']; foreach ($ordered as $i=>&$t) $t['_order'] = $i; unset($t);
    usort($ordered, fn($a,$b) => strcmp($a['date'], $b['date']) ?: ($a['_order'] <=> $b['_order']));
    foreach ($ordered as $t) {
        if ($t['type'] === 'buy') $quantities[$t['assetId']] += $t['quantity'];
        if ($t['type'] === 'sell') { $held=$quantities[$t['assetId']];$tolerance=max($held,$t['quantity'])*PHP_FLOAT_EPSILON*8;requireThat($held >= $t['quantity'] || ($held>0&&$t['quantity']-$held<=$tolerance), 'Sprzedaż przekracza posiadaną liczbę jednostek.'); $quantities[$t['assetId']]=max(0,$held-$t['quantity']); }
    }
    stockValidateFinancialRange($s, $ordered);
}
function payload(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8000000) fail('Żądanie jest zbyt duże.', 413);
    $raw = file_get_contents('php://input', false, null, 0, 8000001);
    if (strlen($raw ?: '') > 8000000) fail('Żądanie jest zbyt duże.', 413);
    try { $obj = json_decode($raw ?: '{}', true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fail('Nieprawidłowy JSON.'); }
    if (!is_array($obj)) fail('Nieprawidłowe żądanie.');
    return $obj;
}

try {
    $configFile = dirname(__DIR__) . '/config.php';
    $config = is_file($configFile) ? require $configFile : [];
    if (!is_array($config)) throw new RuntimeException('Invalid private configuration');
    $development = getenv('PORTFOLIO_DEV') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    $password = getenv('PORTFOLIO_PASSWORD') ?: '';
    $hash = $config['password_hash'] ?? '';
    $configured = $development || strlen($password) >= 12 || (is_string($hash) && (password_get_info($hash)['algo'] ?? null) !== null);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('PORTFOLIO_SECURE_COOKIE') === '1' || ($config['secure_cookie'] ?? false);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name('STOCKSESSION');
    session_set_cookie_params(['lifetime'=>0, 'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/', 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Strict']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];
    $authenticated = $development || (($_SESSION['authenticated'] ?? false) && ($_SESSION['last'] ?? 0) > time() - 1800);
    if ($authenticated) $_SESSION['last'] = time();
    $action = $_GET['action'] ?? ''; $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['POST','PUT','DELETE'], true)) {
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail('Niedozwolone źródło żądania.', 403);
        if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Sesja formularza wygasła. Odśwież stronę.', 403);
    }
    if (!$configured && $action === 'session' && $method === 'GET') respond(['configured'=>false,'authenticated'=>false,'csrf'=>$csrf,'development'=>false]);
    if (!$configured) fail('Serwer wymaga prywatnego hasła. Ustaw PORTFOLIO_PASSWORD lub config.php zgodnie z README.', 503);

    $storage = getenv('PORTFOLIO_STORAGE') ?: ($config['storage'] ?? dirname(__DIR__) . '/storage');
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) throw new RuntimeException('Cannot create private storage');
    // Reject a data directory inside the actual HTTP document root.
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__); $realStorage = realpath($storage);
    if ($docroot && $realStorage && ($realStorage === $docroot || str_starts_with($realStorage, $docroot . DIRECTORY_SEPARATOR))) throw new RuntimeException('Storage must be outside public document root');
    $db = new PDO('sqlite:' . $storage . '/portfolio.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS portfolio(id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, body TEXT NOT NULL); CREATE TABLE IF NOT EXISTS cache(key TEXT PRIMARY KEY, until INTEGER NOT NULL, body TEXT NOT NULL); CREATE TABLE IF NOT EXISTS attempts(ip TEXT PRIMARY KEY, count INTEGER NOT NULL, until INTEGER NOT NULL)');
    @chmod($storage . '/portfolio.sqlite', 0600);
    $db->exec("CREATE TABLE IF NOT EXISTS account(id INTEGER PRIMARY KEY CHECK(id=1),username TEXT NOT NULL,hash TEXT NOT NULL,version INTEGER NOT NULL,must_change INTEGER NOT NULL,secret TEXT NOT NULL DEFAULT '',last_counter INTEGER NOT NULL DEFAULT -1,recovery TEXT NOT NULL DEFAULT '[]')");
    $db->exec("CREATE TABLE IF NOT EXISTS credentials(id TEXT PRIMARY KEY,label TEXT NOT NULL,public_key TEXT NOT NULL,sign_count INTEGER NOT NULL,created TEXT NOT NULL,last_used TEXT)");
    $account=$db->query('SELECT * FROM account WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$account){
        $initialHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : ($hash ?: password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT));
        $q=$db->prepare('INSERT OR IGNORE INTO account(id,username,hash,version,must_change) VALUES(1,?,?,1,?)');
        $q->execute([getenv('PORTFOLIO_USERNAME') ?: ($config['username']??'mateusz'),$initialHash,(int)($config['must_change_password']??false)]);
        $account=$db->query('SELECT * FROM account WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    }
    $account['keys']=keyList($db);
    $account['duo']=duoInit($db);
    $authenticated=$development || ($authenticated && ($_SESSION['started']??0)>time()-28800 && (int)($_SESSION['account_version']??0)===(int)$account['version']);
    if ($action === 'session' && $method === 'GET') respond(['configured'=>true,'authenticated'=>$authenticated,'csrf'=>$csrf,'development'=>$development,'duoEnabled'=>(bool)($account['duo']['enabled']??false),'security'=>$authenticated?securityStatus($account):null]);
    if ($action === 'logout' && $method === 'POST') { $_SESSION=[]; session_destroy(); respond(['ok'=>true]); }
    securityActions($db,$account,$config,$action,$method,$authenticated,$development);
    if (!$development && $account['must_change']) fail('Najpierw zmień hasło tymczasowe.',403);
    // Release the PHP session lock before remote HTTP calls and concurrent saves.
    session_write_close();
    if ($action === 'state' && $method === 'GET') {
        $row = $db->query('SELECT revision,body FROM portfolio WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $initial = ['version'=>1,'settings'=>['name'=>'Mój portfel','feePercent'=>0.29,'minFee'=>0],'assets'=>[],'ledger'=>[],'retirement'=>[],'fx'=>['PLN'=>['rate'=>1,'date'=>date('Y-m-d'),'source'=>'Waluta bazowa']]];
        respond(['revision'=>$row ? (int)$row['revision'] : 0,'state'=>$row ? json_decode($row['body'], true, 64, JSON_THROW_ON_ERROR) : $initial]);
    }
    if ($action === 'state' && $method === 'PUT') {
        $v = payload(); requireThat(isset($v['state']) && is_array($v['state']) && isset($v['revision']) && is_int($v['revision']) && $v['revision'] >= 0, 'Nieprawidłowa wersja zapisu.');
        validateState($v['state']);
        $json = json_encode($v['state'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $current = $db->query('SELECT revision,body FROM portfolio WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if (($current === false ? 0 : (int)$current['revision']) !== $v['revision']) { $db->exec('ROLLBACK'); fail('Konflikt wersji. Pobierz aktualny portfel.', 409); }
            stockValidateNewTrades($v['state'], $current ? json_decode($current['body'], true, 64, JSON_THROW_ON_ERROR) : []);
            $q = $db->prepare('INSERT INTO portfolio VALUES(1,?,?) ON CONFLICT(id) DO UPDATE SET revision=excluded.revision, body=excluded.body'); $q->execute([$v['revision']+1,$json]); $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        respond(['revision'=>$v['revision']+1]);
    }
    function remote(string $url): string {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_USERAGENT=>'STOCK-PersonalPortfolio/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json, application/xml;q=0.9'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
        $body = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $code !== 200 || strlen($body) > 5000000) throw new RuntimeException('Źródło danych jest niedostępne (HTTP ' . $code . '). Ostatnia wycena została zachowana.');
        return $body;
    }
    function cached(PDO $db, string $key, int $ttl, callable $fetch): array {
        $q = $db->prepare('SELECT body FROM cache WHERE key=? AND until>?'); $q->execute([$key,time()]); $old = $q->fetchColumn();
        if ($old !== false) return json_decode($old, true, 64, JSON_THROW_ON_ERROR);
        $result = $fetch(); $q = $db->prepare('INSERT INTO cache VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET until=excluded.until,body=excluded.body'); $q->execute([$key,time()+$ttl,json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    }
    if($action==='technicals'&&$method==='GET'){
        $symbol=$_GET['symbol']??'';requireThat(is_string($symbol)&&preg_match('/^[A-Za-z0-9.^=_-]{1,40}$/D',$symbol),'Nieprawidłowy symbol.');
        try{respond(cached($db,'technical-adjusted-v2-'.$symbol,900,function()use($symbol){
            $obj=json_decode(remote('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?interval=1d&range=2y'),true,64,JSON_THROW_ON_ERROR);
            $r=$obj['chart']['result'][0]??null;if(!$r)throw new RuntimeException('Brak historii instrumentu.');
            return stockTechnicalHistory($r)+['source'=>'Yahoo Finance · adjclose','sourceUrl'=>'https://finance.yahoo.com/quote/'.rawurlencode($symbol).'/history/','basis'=>'Skorygowane ceny dzienne dostawcy (adjclose). Pomijamy dzisiejszą sesję według strefy rynku; nie interpolujemy brakujących cen.','fetchedAt'=>date(DATE_ATOM)];
        }));}catch(Throwable $e){fail($e->getMessage(),502);}
    }
    if(in_array($action,['profile','fundamentals','recommendations'],true)&&$method==='GET'){
        $rawSymbol=$_GET['symbol']??'';requireThat(is_string($rawSymbol),'Nieprawidłowy symbol.');$symbol=strtoupper($rawSymbol);$name=$_GET['name']??'';$type=$_GET['type']??'stock';
        requireThat(is_string($symbol)&&preg_match('/^[A-Z0-9.^=_-]{1,40}$/D',$symbol)&&is_string($name)&&mb_strlen($name)<=200&&in_array($type,['stock','etf','fund','bond','crypto','forex','commodity','other'],true),'Nieprawidłowy instrument analizy.');
        respond(match($action){'profile'=>companyProfile($db,$symbol,$name,$type),'fundamentals'=>issuerFundamentals($db,$symbol,$name,$type),'recommendations'=>brokerRecommendations($db,$symbol,$type)});
    }
    if($action==='instruments'&&$method==='GET'){
        $query=$_GET['q']??'';requireThat(is_string($query),'Nieprawidłowe zapytanie.');$query=trim($query);requireThat(mb_strlen($query)>=2&&mb_strlen($query)<=80,'Wpisz od 2 do 80 znaków.');
        $db->exec('CREATE TABLE IF NOT EXISTS instruments(symbol TEXT PRIMARY KEY,name TEXT NOT NULL,market TEXT NOT NULL,type TEXT NOT NULL,updated TEXT NOT NULL)');
        try{
            $data=cached($db,'search-'.hash('sha256',mb_strtolower($query)),900,function()use($query,$db){
                $obj=json_decode(remote('https://query1.finance.yahoo.com/v1/finance/search?q='.rawurlencode($query).'&quotesCount=25&newsCount=0&listsCount=0'),true,64,JSON_THROW_ON_ERROR);
                $types=['EQUITY'=>'stock','ETF'=>'etf','MUTUALFUND'=>'fund','CRYPTOCURRENCY'=>'crypto','CURRENCY'=>'forex'];$items=[];$at=date(DATE_ATOM);
                foreach($obj['quotes']??[] as $q){
                    $symbol=$q['symbol']??'';$type=$types[$q['quoteType']??'']??null;$name=$q['longname']??$q['shortname']??'';
                    if(!$type||!is_string($symbol)||!preg_match('/^[A-Za-z0-9.^=_-]{1,30}$/D',$symbol)||!is_string($name)||$name==='')continue;
                    $market=($q['exchange']??'')==='WSE'?'GPW':($q['exchDisp']??$q['exchange']??'');
                    $items[]=['symbol'=>$symbol,'name'=>mb_substr($name,0,200),'market'=>mb_substr($market,0,100),'type'=>$type,'updated'=>$at];
                    $st=$db->prepare('INSERT INTO instruments VALUES(?,?,?,?,?) ON CONFLICT(symbol) DO UPDATE SET name=excluded.name,market=excluded.market,type=excluded.type,updated=excluded.updated');$st->execute([$symbol,mb_substr($name,0,200),mb_substr($market,0,100),$type,$at]);
                }
                return ['items'=>$items,'source'=>'Yahoo Finance','updated'=>$at,'stale'=>false];
            });$data['items']=array_values(array_filter($data['items'],fn($item)=>($item['type']??'')!=='commodity'&&!str_ends_with(strtoupper($item['symbol']??''),'=F')));respond($data);
        }catch(Throwable $e){
            $safe=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query);$st=$db->prepare("SELECT * FROM instruments WHERE type!='commodity' AND symbol NOT LIKE '%=F' AND (symbol LIKE ? ESCAPE '\\' OR name LIKE ? ESCAPE '\\') ORDER BY symbol LIMIT 25");$st->execute(['%'.$safe.'%','%'.$safe.'%']);$items=$st->fetchAll(PDO::FETCH_ASSOC);
            respond(['items'=>$items,'source'=>'Zapisany katalog Yahoo Finance','stale'=>true,'warning'=>'Źródło jest niedostępne. Pokazujemy zapisane wyniki; możesz dodać instrument ręcznie.']);
        }
    }
    if ($action === 'fx-history' && $method === 'GET') {
        $currency=$_GET['currency']??null;$day=$_GET['date']??null;
        requireThat(is_string($currency)&&is_string($day),'Nieprawidłowa waluta lub data kursu.');
        $currency=strtoupper($currency);nbpPeriod($currency,$day);
        try { $ref=cached($db,'fx-history-v1-'.$currency.'-'.$day,$day===date('Y-m-d')?300:86400,fn()=>nbpHistorical($currency,$day));nbpValidateReference($ref,$currency,$day,$ref['rate']??null);respond($ref); }
        catch (RuntimeException $e) { fail($e->getMessage(),502); }
    }
    if ($action === 'fx' && $method === 'GET') {
        $currency = strtoupper($_GET['currency'] ?? ''); if (!validCurrency($currency) || $currency === 'PLN') fail('Nieprawidłowa waluta.');
        try { $data = cached($db, 'fx-'.$currency, 3600, function() use ($currency) {
            $result = json_decode(remote('https://api.nbp.pl/api/exchangerates/rates/a/'.$currency.'/last/93/?format=json'), true, 64, JSON_THROW_ON_ERROR); $rates = $result['rates'] ?? [];
            if (!$rates) throw new RuntimeException('Brak kursu NBP w tabeli A. Ustaw kurs ręcznie.');
            $last = end($rates); return ['rate'=>$last['mid'],'date'=>$last['effectiveDate'],'source'=>'NBP · tabela A','history'=>array_map(fn($p)=>['date'=>$p['effectiveDate'],'rate'=>$p['mid']],$rates)];
        }); respond($data); } catch (RuntimeException $e) { fail($e->getMessage(), 502); }
    }
    if (in_array($action, ['quote','news'], true) && $method === 'GET') {
        $symbol = $_GET['symbol'] ?? ''; if (!is_string($symbol) || !preg_match('/^[A-Za-z0-9.^=_-]{1,40}$/D', $symbol)) fail('Nieprawidłowy symbol źródła.');
        try { $data = cached($db, $action.'-'.$symbol, 900, function() use ($symbol,$action) {
            if ($action === 'news') {
                $body = remote('https://feeds.finance.yahoo.com/rss/2.0/headline?s='.rawurlencode($symbol).'&region=US&lang=en-US');
                libxml_use_internal_errors(true); $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
                if (!$xml) throw new RuntimeException('Kanał wiadomości jest niedostępny.');
                $items = []; foreach ($xml->channel->item ?? [] as $item) {
                    $link = (string)$item->link; if (!preg_match('~^https?://~', $link)) continue;
                    $items[] = ['title'=>(string)$item->title,'link'=>$link,'date'=>date('Y-m-d H:i', strtotime((string)$item->pubDate) ?: time()),'source'=>'Yahoo Finance RSS']; if (count($items) >= 8) break;
                } return ['items'=>$items];
            }
            // Public chart endpoint, not a contracted or guaranteed market-data API.
            $obj = json_decode(remote('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?interval=1d&range=1y'), true, 64, JSON_THROW_ON_ERROR);
            $r = $obj['chart']['result'][0] ?? null; if (!$r || empty($r['meta']['regularMarketPrice'])) throw new RuntimeException('Brak notowań dla symbolu. Ustaw własną cenę.');
            $m = $r['meta']; $currency = $m['currency'] ?? ''; $factor = $currency === 'GBp' || $currency === 'GBX' ? 0.01 : 1; if ($factor !== 1) $currency = 'GBP';
            if (!validCurrency($currency)) throw new RuntimeException('Źródło nie podaje obsługiwanej waluty.');
            $history = []; $zone = new DateTimeZone($m['exchangeTimezoneName'] ?? 'UTC');
            foreach ($r['timestamp'] ?? [] as $i=>$timestamp) {
                $close = $r['indicators']['quote'][0]['close'][$i] ?? null;
                if (!validNumber($close, true)) continue;
                $day = (new DateTimeImmutable('@'.(int)$timestamp))->setTimezone($zone)->format('Y-m-d');
                if ($day <= date('Y-m-d')) $history[] = ['date'=>$day,'close'=>$close*$factor];
            }
            $price = $m['regularMarketPrice']*$factor;
            $previous = $m['previousClose'] ?? $m['regularMarketPreviousClose'] ?? (count($history)>1 ? $history[count($history)-2]['close']/$factor : null);
            $timestamp = (int)($m['regularMarketTime'] ?? 0); if ($timestamp <= 0) throw new RuntimeException('Brak daty aktualnego kursu.');
            $day = (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone)->format('Y-m-d');
            if ($day > date('Y-m-d')) throw new RuntimeException('Źródło zwróciło przyszłą datę kursu.');
            return ['price'=>$price,'date'=>$day,'timestamp'=>date(DATE_ATOM,$timestamp),'source'=>'Yahoo Finance','currency'=>$currency,'change'=>isset($m['regularMarketChangePercent']) ? $m['regularMarketChangePercent'] : ($previous ? ($price/($previous*$factor)-1)*100 : null),'low52'=>isset($m['fiftyTwoWeekLow'])?$m['fiftyTwoWeekLow']*$factor:null,'high52'=>isset($m['fiftyTwoWeekHigh'])?$m['fiftyTwoWeekHigh']*$factor:null,'volume'=>$m['regularMarketVolume']??null,'history'=>$history,'fetchedAt'=>date(DATE_ATOM)];
        }); respond($data); } catch (RuntimeException $e) { fail($e->getMessage(), 502); }
    }
    fail('Nieznana operacja API.', 404);
} catch (InvalidArgumentException $e) { fail($e->getMessage(), 400); }
catch (Throwable $e) { error_log('STOCK API: ' . $e->getMessage()); fail('Błąd konfiguracji serwera lub danych. Sprawdź prywatny katalog, rozszerzenia PHP i log serwera.', 500); }
