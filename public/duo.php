<?php
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }
spl_autoload_register(function(string $class): void {
    foreach (['Duo\\DuoUniversal\\'=>'duo','Firebase\\JWT\\'=>'jwt'] as $prefix=>$folder) {
        if (!str_starts_with($class,$prefix)) continue;
        $relative=str_replace('\\','/',substr($class,strlen($prefix)));
        if (!preg_match('~^[A-Za-z0-9_/]+$~D',$relative)) return;
        $file=__DIR__.'/lib/'.$folder.'/src/'.$relative.'.php';if(is_file($file))require $file;
    }
});
/* Keep the official SDK's token checks and pinned CA bundle, with bounded HTTP calls. */
class StockDuoClient extends Duo\DuoUniversal\Client {
    private string $requestSigningKey;
    public function __construct(string $client_id,string $client_secret,string $api_host,string $redirect_url){
        parent::__construct($client_id,$client_secret,$api_host,$redirect_url);$this->requestSigningKey=str_pad($client_secret,self::HS512_MIN_KEY_LENGTH,"\0");
    }
    public function createAuthUrl(string $username,string $state): string {
        // Extend the SDK's signed request with Cisco's documented fresh-auth parameters.
        $url=parent::createAuthUrl($username,$state);parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        $claims=Firebase\JWT\JWT::decode($query['request'],new Firebase\JWT\Key($this->requestSigningKey,self::SIG_ALGORITHM));
        $claims->prompt='login';$claims->max_age=0;$claims->nonce=$state;
        $query['request']=Firebase\JWT\JWT::encode((array)$claims,$this->requestSigningKey,self::SIG_ALGORITHM);
        return 'https://'.$this->api_host.self::AUTHORIZE_ENDPOINT.'?'.http_build_query($query);
    }
    protected function makeHttpsCall(string $endpoint,array $request,?string $user_agent=null): array {
        $curl=curl_init('https://'.$this->api_host.$endpoint);
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($request),CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>self::DUO_CERTS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>$user_agent??self::USER_AGENT,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded']];
        curl_setopt_array($curl,$options);
        if(!curl_setopt($curl,CURLOPT_CAPATH,'/dev/null/'.bin2hex(random_bytes(16))))throw new RuntimeException('Nie można zweryfikować certyfikatu Duo.');
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if(!is_string($body)||$status!==200||strlen($body)>1000000)throw new RuntimeException('Duo jest niedostępne lub odrzuciło dane aplikacji.');
        try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Nieprawidłowa odpowiedź Duo.');}
        if(!is_array($data))throw new RuntimeException('Nieprawidłowa odpowiedź Duo.');return $data;
    }
}
function duoInit(PDO $db): ?array {
    $db->exec('CREATE TABLE IF NOT EXISTS duo_settings(id INTEGER PRIMARY KEY CHECK(id=1),version INTEGER NOT NULL,enabled INTEGER NOT NULL,client_id TEXT NOT NULL,api_host TEXT NOT NULL,secret TEXT NOT NULL,verified_at TEXT NOT NULL)');
    $db->exec("CREATE TABLE IF NOT EXISTS duo_events(id INTEGER PRIMARY KEY,created TEXT NOT NULL,stage TEXT NOT NULL,reason TEXT NOT NULL,details TEXT NOT NULL DEFAULT '{}')");
    if(!in_array('details',array_column($db->query('PRAGMA table_info(duo_events)')->fetchAll(PDO::FETCH_ASSOC),'name'),true)){
        try{$db->exec("ALTER TABLE duo_events ADD COLUMN details TEXT NOT NULL DEFAULT '{}'");}
        catch(PDOException $e){if(!in_array('details',array_column($db->query('PRAGMA table_info(duo_events)')->fetchAll(PDO::FETCH_ASSOC),'name'),true))throw $e;}
    }
    $row=$db->query('SELECT * FROM duo_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);return $row?:null;
}
function duoSummary(?array $row): array {
    return ['enabled'=>(bool)($row['enabled']??false),'configured'=>(bool)$row,'clientId'=>$row['client_id']??'','apiHost'=>$row['api_host']??'','verifiedAt'=>$row['verified_at']??null];
}
function duoCallbackURL(): string { return 'https://stock.mateuszrak.com/duo-callback.html'; }
function duoValidateCredentials(mixed $id,mixed $secret,mixed $host): array {
    requireThat(is_string($id)&&preg_match('/^[A-Z0-9]{20}$/D',$id)===1,'Client ID musi zawierać 20 znaków z panelu Web SDK Duo.');
    requireThat(is_string($secret)&&preg_match('/^[A-Za-z0-9]{40}$/D',$secret)===1,'Client secret musi zawierać 40 znaków z panelu Duo.');
    requireThat(is_string($host)&&preg_match('/^api-[a-z0-9]{8,32}\.duosecurity\.com$/D',$host)===1,'API hostname musi mieć postać api-xxxxxxxx.duosecurity.com, bez https:// i ścieżki.');
    return ['client_id'=>$id,'api_host'=>$host,'plain_secret'=>$secret];
}
function duoClient(array $settings,array $config): StockDuoClient {
    $values=duoValidateCredentials($settings['client_id']??null,openSecret($settings['secret'],$config,'STOCK-DUO'),$settings['api_host']??null);
    return new StockDuoClient($values['client_id'],$values['plain_secret'],$values['api_host'],duoCallbackURL());
}
function duoBegin(array $account,array $settings,array $config,string $purpose): array {
    unset($_SESSION['duo_pending']);
    if($purpose==='login'){session_regenerate_id(true);unset($_SESSION['authenticated'],$_SESSION['started'],$_SESSION['last'],$_SESSION['key_login'],$_SESSION['duo_verified_at'],$_SESSION['factor_verified_at'],$_SESSION['key_verified_at']);}
    try{$client=duoClient($settings,$config);$client->healthCheck();$state=$client->generateState();$url=$client->createAuthUrl($account['username'],$state);}
    catch(Throwable $e){fail('Nie udało się połączyć z Duo. Sprawdź dane Web SDK; możesz użyć innej skonfigurowanej metody 2FA.',502);}
    $_SESSION['duo_pending']=['purpose'=>$purpose,'state'=>$state,'nonce'=>$state,'username'=>$account['username'],'accountVersion'=>(int)$account['version'],'duoVersion'=>(int)($account['duo']['version']??0),'until'=>time()+300,'created'=>time(),'settings'=>$settings];
    return ['duoRequired'=>true,'duoUrl'=>$url];
}
function duoConsumePending(array $account,mixed $state,mixed $code): array {
    $pending=$_SESSION['duo_pending']??null;unset($_SESSION['duo_pending']);
    requireThat(is_string($state)&&strlen($state)<=1024&&is_string($code)&&strlen($code)>0&&strlen($code)<=4096,'Nieprawidłowa odpowiedź Duo.');
    requireThat(is_array($pending)&&$pending['until']>time()&&$pending['accountVersion']===(int)$account['version']&&$pending['duoVersion']===(int)($account['duo']['version']??0)&&hash_equals($pending['username'],$account['username'])&&hash_equals($pending['state'],$state),'Potwierdzenie Duo wygasło lub nie odpowiada tej sesji. Rozpocznij ponownie.');
    return $pending;
}
function duoTimestamp(mixed $value): int|false {
    // JSON numbers can decode as float; accept only finite, nonnegative Unix seconds.
    if(is_string($value)){
        if(preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D',$value)!==1)return false;
        $value=(float)$value;
    }
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>=PHP_INT_MAX)return false;
    return (int)floor($value);
}
function duoValidateResult(array $token,array $pending): void {
    if(($token['auth_result']['result']??null)!=='allow'||($token['auth_result']['status']??null)!=='allow'||!is_array($token['amr']??null)||!in_array('mfa',$token['amr'],true))throw new StockDuoResultException('mfa_missing','Duo nie potwierdziło MFA. Sprawdź, czy użytkownik w Duo jest aktywny i czy aplikacja wymaga drugiego składnika zamiast Bypass 2FA.');
    $authTime=duoTimestamp($token['auth_time']??null);
    if($authTime===false)throw new StockDuoResultException('authentication_time_invalid','Duo zwróciło nieprawidłowy znacznik czasu potwierdzenia. Weryfikacja nie została zakończona.');
    if($authTime<$pending['created']-60)throw new StockDuoResultException('authentication_not_fresh','Duo zwróciło wcześniejsze potwierdzenie. Rozpocznij nową próbę w ustawieniach STOCK; serwis poprosi o nowe potwierdzenie.');
    if($authTime>time()+60)throw new StockDuoResultException('authentication_time_in_future','Czas potwierdzenia Duo jest późniejszy niż czas serwera. Weryfikacja nie została zakończona.');
    // Cisco records ordinary and Verified Duo Push as distinct authentication factors.
    $pushFactors=['duo_push','verified_duo_push','Verified Duo Push (Bluetooth Autofill)','Verified Duo Push (Proximity Verification)'];
    if($pending['purpose']==='setup'&&!in_array($token['auth_context']['factor']??null,$pushFactors,true))throw new StockDuoResultException('push_required','Do włączenia Duo wymagane jest potwierdzenie Duo Push lub Verified Duo Push. Wybierz tę metodę i zatwierdź na telefonie.');
}
class StockDuoResultException extends RuntimeException {
    public function __construct(public string $reason,string $message){parent::__construct($message);}
}
function duoRecordFailure(PDO $db,string $stage,Throwable $error,?array $token=null): string {
    $reason=$error instanceof StockDuoResultException?$error->reason:'token_exchange_failed';
    if($error instanceof Duo\DuoUniversal\DuoException)$reason=match($error->getMessage()){
        Duo\DuoUniversal\Client::JWT_DECODE_ERROR=>'token_signature_or_time_invalid',
        Duo\DuoUniversal\Client::USERNAME_ERROR=>'token_user_mismatch',
        Duo\DuoUniversal\Client::NONCE_ERROR=>'token_nonce_mismatch',
        Duo\DuoUniversal\Client::MALFORMED_RESPONSE=>'token_response_invalid',
        default=>'token_exchange_failed'
    };
    // Timing diagnostics contain a type and a bounded relative difference, never token values.
    $details=[];
    if($token!==null){$stamp=duoTimestamp($token['auth_time']??null);$details=['authTimeType'=>gettype($token['auth_time']??null),'authTimeDifferenceSeconds'=>$stamp===false?null:max(-86400,min(86400,$stamp-time()))];}
    $q=$db->prepare('INSERT INTO duo_events(created,stage,reason,details) VALUES(?,?,?,?)');$q->execute([date(DATE_ATOM),$stage,$reason,json_encode($details,JSON_THROW_ON_ERROR)]);
    $db->exec('DELETE FROM duo_events WHERE id NOT IN (SELECT id FROM duo_events ORDER BY id DESC LIMIT 20)');return $reason;
}
function duoActivate(PDO $db,array &$account,array $pending): void {
    $settings=$pending['settings'];$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('UPDATE account SET version=version+1 WHERE id=1 AND version=?');$q->execute([$pending['accountVersion']]);requireThat($q->rowCount()===1,'Konto zmieniło się podczas testu Duo.');
        $q=$db->prepare('INSERT INTO duo_settings VALUES(1,?,1,?,?,?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,enabled=1,client_id=excluded.client_id,api_host=excluded.api_host,secret=excluded.secret,verified_at=excluded.verified_at WHERE duo_settings.version=?');
        $q->execute([$pending['duoVersion']+1,$settings['client_id'],$settings['api_host'],$settings['secret'],date(DATE_ATOM),$pending['duoVersion']]);requireThat($q->rowCount()===1,'Konfiguracja Duo zmieniła się podczas testu.');$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    $account['version']++;$account['duo']=duoInit($db);
}
function duoAuthorizeManagement(PDO $db,array $account,array $config,array $values,string $ip,bool $passwordOnly=false): void {
    $password=$values['currentPassword']??null;
    if(!is_string($password)||strlen($password)>72||!password_verify($password,$account['hash']))authFailure($db,$ip);
    // Setup is already inside an authenticated session and still requires an actual Duo Push.
    if($passwordOnly)return;
    if($account['secret']!==''){
        if(!is_string($values['code']??null)||!acceptFactor($db,$account,$values['code'],$config))authFailure($db,$ip);
        $_SESSION['factor_verified_at']=time();
    }elseif(!empty($account['keys'])||!empty($account['duo']['enabled'])){
        requireThat(max((int)($_SESSION['key_verified_at']??0),(int)($_SESSION['duo_verified_at']??0),(int)($_SESSION['factor_verified_at']??0))>=time()-300,'Zaloguj się ponownie kluczem lub Duo, aby zmienić konfigurację. Potwierdzenie jest ważne 5 minut.');
    }
}
function duoActions(PDO $db,array &$account,array $config,string $action,string $method,bool $authenticated,bool $development,string $ip): void {
    if(!in_array($action,['duo-setup','duo-finish','duo-disable'],true))return;
    if($method!=='POST')fail('Niedozwolona metoda.',405);
    throttle($db,$ip);$values=payload();
    if($action==='duo-finish'){
        $pending=duoConsumePending($account,$values['state']??null,$values['duo_code']??null);
        if($pending['purpose']==='setup')requireThat($authenticated&&!$development,'Konfiguracja wymaga aktualnej zalogowanej sesji.');
        elseif($pending['purpose']==='login')requireThat(!empty($account['duo']['enabled']),'Duo nie jest aktywne.');
        else fail('Nieprawidłowa operacja Duo.');
        try{$client=duoClient($pending['settings'],$config);$token=$client->exchangeAuthorizationCodeFor2FAResult($values['duo_code'],$pending['username'],$pending['nonce']??null);}
        catch(Throwable $e){$reason=duoRecordFailure($db,'token',$e);fail('Nie udało się zweryfikować odpowiedzi Duo ('.$reason.'). Rozpocznij nową próbę w ustawieniach STOCK.',401);}
        try{duoValidateResult($token,$pending);}
        catch(StockDuoResultException $e){duoRecordFailure($db,'mfa',$e,$token);fail($e->getMessage(),401);}
        if($pending['purpose']==='setup')duoActivate($db,$account,$pending);
        else {
            // Configuration may change in another session while Duo verifies the token.
            $current=$db->query('SELECT version FROM account WHERE id=1')->fetchColumn();$duo=duoInit($db);
            requireThat((int)$current===$pending['accountVersion']&&!empty($duo['enabled'])&&(int)$duo['version']===$pending['duoVersion'],'Konto lub konfiguracja Duo zmieniły się. Zaloguj się ponownie.');
        }
        $_SESSION['duo_verified_at']=time();$_SESSION['factor_verified_at']=time();$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);respond(updateSession($account)+['duoActivated'=>$pending['purpose']==='setup','returnRoute'=>$pending['purpose']==='setup'?'settings':'dashboard']);
    }
    if(!$authenticated)respond(['error'=>'Zaloguj się do portfela.','code'=>'session_expired'],401);
    if($development)fail('Konfiguracja Duo wymaga normalnego logowania.',400);
    if($account['must_change'])fail('Najpierw zmień hasło tymczasowe.',403);
    duoAuthorizeManagement($db,$account,$config,$values,$ip,$action==='duo-setup');
    if($action==='duo-setup'){
        $new=duoValidateCredentials($values['clientId']??null,$values['clientSecret']??null,$values['apiHost']??null);
        $settings=['client_id'=>$new['client_id'],'api_host'=>$new['api_host'],'secret'=>sealSecret($new['plain_secret'],$config,'STOCK-DUO')];
        respond(duoBegin($account,$settings,$config,'setup'));
    }
    requireThat(!empty($account['duo']['enabled']),'Duo nie jest aktywne.');
    requireThat($account['secret']!==''||!empty($account['keys']),'Najpierw skonfiguruj TOTP lub klucz bezpieczeństwa jako inną metodę 2FA.');
    $db->exec('BEGIN IMMEDIATE');try{
        $q=$db->prepare('UPDATE account SET version=version+1 WHERE id=1 AND version=?');$q->execute([$account['version']]);requireThat($q->rowCount()===1,'Konto zmieniło się.');
        $q=$db->prepare('UPDATE duo_settings SET enabled=0,version=version+1 WHERE id=1 AND version=?');$q->execute([$account['duo']['version']]);requireThat($q->rowCount()===1,'Duo zmieniło się.');$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    unset($_SESSION['duo_pending']);$account['version']++;$account['duo']=duoInit($db);respond(updateSession($account));
}
