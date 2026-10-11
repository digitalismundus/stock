<?php
declare(strict_types=1);
define('STOCK_API',true);
require __DIR__.'/../public/auth.php';require __DIR__.'/../public/duo.php';
function requireThat(bool $test,string $message): void {if(!$test)throw new InvalidArgumentException($message);}
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,string $label): void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted: '.$label);}
// Only the transport is mocked: verification uses the actual official SDK and signed JWTs.
class FixtureDuo extends StockDuoClient {
    public array $response=[];
    protected function makeHttpsCall(string $endpoint,array $request,?string $user_agent=null): array {return $this->response;}
}
$id=str_repeat('A',20);$secret=str_repeat('s',40);$host='api-12345678.duosecurity.com';$config=['auth_key'=>base64_encode(str_repeat('k',32))];
$client=new FixtureDuo($id,$secret,$host,duoCallbackURL());
$requestState=str_repeat('n',36);$authUrl=$client->createAuthUrl('fixture',$requestState);parse_str(parse_url($authUrl,PHP_URL_QUERY),$query);
$requestClaims=Firebase\JWT\JWT::decode($query['request'],new Firebase\JWT\Key(str_pad($secret,64,"\0"),'HS512'));
check($requestClaims->prompt==='login'&&$requestClaims->max_age===0&&$requestClaims->nonce===$requestState,'fresh interactive auth and request binding');
check($requestClaims->state===$requestState&&$requestClaims->duo_uname==='fixture'&&$requestClaims->redirect_uri===duoCallbackURL()&&$requestClaims->use_duo_code_attribute===true,'SDK request preserved');
$claims=['iss'=>'https://'.$host.'/oauth/v1/token','aud'=>$id,'iat'=>time(),'exp'=>time()+300,'preferred_username'=>'fixture','auth_result'=>['result'=>'allow','status'=>'allow'],'amr'=>['pwd','mfa'],'auth_time'=>time(),'auth_context'=>['factor'=>'duo_push']];
function tokenResponse(array $claims,string $key): array {return ['id_token'=>Firebase\JWT\JWT::encode($claims,str_pad($key,64,"\0"),'HS512'),'access_token'=>'fixture','expires_in'=>300,'token_type'=>'Bearer'];}
$client->response=tokenResponse($claims,$secret);$valid=$client->exchangeAuthorizationCodeFor2FAResult('code','fixture');
$pending=['purpose'=>'setup','created'=>time(),'until'=>time()+300,'state'=>str_repeat('x',36),'username'=>'fixture','accountVersion'=>1,'duoVersion'=>0,'settings'=>['client_id'=>$id,'api_host'=>$host,'secret'=>sealSecret($secret,$config,'STOCK-DUO')]];
duoValidateResult($valid,$pending);
foreach([time(),(float)time(),time()+0.25,(string)time(),time().'.250000'] as $authTime){$actual=$claims;$actual['auth_time']=$authTime;$actual['nonce']=$requestState;$client->response=tokenResponse($actual,$secret);duoValidateResult($client->exchangeAuthorizationCodeFor2FAResult('code','fixture',$requestState),$pending);}
$wrongNonce=$claims;$wrongNonce['nonce']='different';$client->response=tokenResponse($wrongNonce,$secret);rejects(fn()=>$client->exchangeAuthorizationCodeFor2FAResult('code','fixture',$requestState),'nonce mismatch');
foreach([null,true,false,[],INF,NAN,-1,'1e9','not-a-time','999999999999999999999'] as $badTime)check(duoTimestamp($badTime)===false,'invalid time format');
foreach([time()-120,time()+120] as $badTime){$bad=$valid;$bad['auth_time']=$badTime;rejects(fn()=>duoValidateResult($bad,$pending),'non-fresh time');}
// Actual Cisco factors, each passed through the official SDK's signature verification.
foreach(['duo_push','verified_duo_push','Verified Duo Push (Bluetooth Autofill)','Verified Duo Push (Proximity Verification)'] as $factor){$push=$claims;$push['auth_context']['factor']=$factor;$client->response=tokenResponse($push,$secret);duoValidateResult($client->exchangeAuthorizationCodeFor2FAResult('code','fixture'),$pending);}
foreach(['iss'=>'https://evil.example/token','aud'=>'other','preferred_username'=>'other','exp'=>time()-120,'iat'=>time()+120] as $field=>$value){$bad=$claims;$bad[$field]=$value;$client->response=tokenResponse($bad,$secret);rejects(fn()=>$client->exchangeAuthorizationCodeFor2FAResult('code','fixture'),$field);}
$client->response=tokenResponse($claims,str_repeat('z',40));rejects(fn()=>$client->exchangeAuthorizationCodeFor2FAResult('code','fixture'),'signature');
foreach(['amr'=>['pwd'],'auth_result'=>['result'=>'deny','status'=>'allow'],'auth_time'=>time()-120,'auth_context'=>['factor'=>'bypass']] as $field=>$value){$bad=$valid;$bad[$field]=$value;rejects(fn()=>duoValidateResult($bad,$pending),$field);}
foreach(['remembered_device','passcode','u2f_token','verified_duo_push_fake'] as $factor){$bad=$valid;$bad['auth_context']['factor']=$factor;rejects(fn()=>duoValidateResult($bad,$pending),'not an interactive push');}
$bypass=$valid;$bypass['auth_context']['factor']='verified_duo_push';unset($bypass['amr']);rejects(fn()=>duoValidateResult($bypass,$pending),'bypass cannot claim verified push without MFA');
$account=['username'=>'fixture','version'=>1,'duo'=>null];
$_SESSION=['duo_pending'=>$pending];check(duoConsumePending($account,$pending['state'],'code')===$pending,'valid pending');rejects(fn()=>duoConsumePending($account,$pending['state'],'code'),'replay');
foreach(['state'=>'wrong','until'=>time()-1,'accountVersion'=>2,'duoVersion'=>1,'username'=>'other'] as $field=>$value){$bad=$pending;$bad[$field]=$value;$_SESSION=['duo_pending'=>$bad];rejects(fn()=>duoConsumePending($account,$pending['state'],'code'),$field);check(!isset($_SESSION['duo_pending']),'pending consumed');}
foreach(['http://'.$host,$host.'/path','api-12345678.duosecurity.com.evil.test','localhost'] as $bad)rejects(fn()=>duoValidateCredentials($id,$secret,$bad),'host injection');
check(openSecret($pending['settings']['secret'],$config,'STOCK-DUO')===$secret,'encrypted roundtrip');rejects(fn()=>openSecret($pending['settings']['secret'],$config),'AAD isolation');
$totp=sealSecret('TOTPFIXTURE',$config);check(openSecret($totp,$config)==='TOTPFIXTURE','TOTP compatibility');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE account(id INTEGER PRIMARY KEY,version INTEGER,hash TEXT);INSERT INTO account VALUES(1,1,'unchanged');CREATE TABLE portfolio(body TEXT);INSERT INTO portfolio VALUES('unchanged');");
check(duoInit($db)===null,'empty configuration');duoActivate($db,$account,$pending);$row=duoInit($db);
duoAuthorizeManagement($db,['hash'=>password_hash('fixture-password',PASSWORD_DEFAULT),'secret'=>'unreadable-totp','keys'=>[['id'=>'fixture']],'duo'=>['enabled'=>1]],[],['currentPassword'=>'fixture-password'],'fixture',true);
check($row['enabled']===1&&$row['version']===1&&$account['version']===2,'activation');check($db->query('SELECT hash FROM account')->fetchColumn()==='unchanged'&&$db->query('SELECT body FROM portfolio')->fetchColumn()==='unchanged','private data preserved');
check(!str_contains(json_encode(duoSummary($row)),$secret)&&!array_key_exists('secret',duoSummary($row)),'safe summary');
rejects(function()use($db,&$account,$pending){duoActivate($db,$account,$pending);},'stale account version');check((int)$db->query('SELECT version FROM account')->fetchColumn()===2,'rollback');
$stale=$pending;$stale['accountVersion']=2;rejects(function()use($db,&$account,$stale){duoActivate($db,$account,$stale);},'stale Duo version');check((int)$db->query('SELECT version FROM account')->fetchColumn()===2,'atomic rollback of account');
check(duoRecordFailure($db,'token',new RuntimeException('sensitive token '.$secret))==='token_exchange_failed','safe error category');check(!str_contains(json_encode($db->query('SELECT * FROM duo_events')->fetchAll(PDO::FETCH_ASSOC)),$secret),'diagnostics never record raw exception');
$timingError=new StockDuoResultException('authentication_time_invalid','fixture');duoRecordFailure($db,'mfa',$timingError,['auth_time'=>$secret,'jwt'=>$secret]);$safe=json_decode($db->query('SELECT details FROM duo_events ORDER BY id DESC LIMIT 1')->fetchColumn(),true);check($safe===['authTimeType'=>'string','authTimeDifferenceSeconds'=>null],'only type and bounded difference recorded');
$legacy=new PDO('sqlite::memory:');$legacy->exec('CREATE TABLE duo_events(id INTEGER PRIMARY KEY,created TEXT NOT NULL,stage TEXT NOT NULL,reason TEXT NOT NULL)');duoInit($legacy);check(in_array('details',array_column($legacy->query('PRAGMA table_info(duo_events)')->fetchAll(PDO::FETCH_ASSOC),'name'),true),'existing diagnostic table migrated');
echo "PASS: signed Duo token, issuer/audience/user/signature/time rejection, real MFA/push requirement, state/session/version/expiry/replay rejection, host restriction, AES-GCM isolation, atomic activation, stale-update rollback, private data preserved.\n";
