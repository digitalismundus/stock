<?php
declare(strict_types=1);
if(!defined('STOCK_API')){http_response_code(404);exit;}
spl_autoload_register(function(string $class):void {
 $prefix='lbuchs\\WebAuthn\\';if(!str_starts_with($class,$prefix))return;
 $relative=str_replace('\\','/',substr($class,strlen($prefix)));
 if(!preg_match('~^[A-Za-z0-9_/]+$~D',$relative))return;
 $file=__DIR__.'/lib/webauthn/src/'.$relative.'.php';if(is_file($file))require $file;
});
function b64url(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function unb64(mixed $s,int $max=200000):string {
 requireThat(is_string($s)&&strlen($s)<=$max&&preg_match('/^[A-Za-z0-9_-]+$/D',$s)===1,'Nieprawidłowe dane klucza.');
 $raw=base64_decode(strtr($s,'-_','+/'),true);requireThat($raw!==false,'Nieprawidłowe kodowanie.');return $raw;
}
function keyOrigin():string {
 $test=getenv('PORTFOLIO_WEBAUTHN_ORIGIN');
 if($test&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))return $test;
 return 'https://stock.mateuszrak.com';
}
function webAuthn():lbuchs\WebAuthn\WebAuthn {
 return new lbuchs\WebAuthn\WebAuthn('STOCK',(string)parse_url(keyOrigin(),PHP_URL_HOST),['none','packed','fido-u2f'],true);
}
function ownerHandle():string{return hash('sha256','STOCK primary account 1 @ '.keyOrigin(),true);}
function checkClient(string $json):void {
 $c=json_decode($json,true,32,JSON_THROW_ON_ERROR);
 requireThat(is_array($c)&&($c['origin']??'')===keyOrigin()&&($c['crossOrigin']??false)===false,'Nieprawidłowa domena klucza.');
}
function keyOptions(PDO $db,array $account,string $purpose):array {
 $w=webAuthn();$rows=$db->query('SELECT id FROM credentials')->fetchAll(PDO::FETCH_COLUMN);$ids=array_map(fn($x)=>unb64($x,2048),$rows);
 if($purpose==='create'){
  $args=$w->getCreateArgs(ownerHandle(),$account['username'],$account['username'],120,'discouraged','preferred',true,$ids);
  $args->publicKey->attestation='none';$args->publicKey->pubKeyCredParams=array_values(array_filter($args->publicKey->pubKeyCredParams,fn($x)=>in_array($x->alg,[-7,-257],true)));
 }else $args=$w->getGetArgs($ids,120,true,true,true,false,false,'preferred');
 $_SESSION['key_'.$purpose]=['challenge'=>$w->getChallenge()->getBinaryString(),'until'=>time()+120,'version'=>(int)$account['version']];
 return json_decode(json_encode($args,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
}
function consumeChallenge(array $account,string $purpose):string {
 $pending=$_SESSION['key_'.$purpose]??null;unset($_SESSION['key_'.$purpose]);
 requireThat(is_array($pending)&&$pending['until']>time()&&$pending['version']===(int)$account['version'],'Potwierdzenie kluczem wygasło. Rozpocznij ponownie.');return $pending['challenge'];
}
function verifyKey(PDO $db,array $account,array $v,string $purpose):void {
 $challenge=consumeChallenge($account,$purpose);$id=$v['id']??'';
 requireThat(is_string($id)&&strlen($id)<=2048,'Nieprawidłowy klucz.');
 $q=$db->prepare('SELECT * FROM credentials WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);requireThat((bool)$row,'Klucz nie jest przypisany do tego konta.');
 $client=unb64($v['clientDataJSON']??'');checkClient($client);
 if(!empty($v['userHandle']))requireThat(hash_equals(ownerHandle(),unb64($v['userHandle'],1024)),'Klucz wskazuje inne konto.');
 $w=webAuthn();$w->processGet($client,unb64($v['authenticatorData']??'',8192),unb64($v['signature']??'',8192),$row['public_key'],$challenge,(int)$row['sign_count'],false,true);
 $count=$w->getSignatureCounter()??0;
 $q=$db->prepare('UPDATE credentials SET sign_count=?,last_used=? WHERE id=? AND sign_count=?');$q->execute([$count,date(DATE_ATOM),$id,$row['sign_count']]);
 requireThat($q->rowCount()===1,'Klucz został użyty równocześnie. Spróbuj ponownie.');
}
function webAuthnActions(PDO $db,array &$account,string $action,string $method,bool $authenticated,bool $development,string $ip):void {
 if(!in_array($action,['key-begin','key-confirm','key-remove','key-login','key-reauth-begin','key-reauth-finish'],true))return;
 if($method!=='POST')fail('Niedozwolona metoda.',405);
 throttle($db,$ip);$v=payload();
 try{
  if($action==='key-login'){
   verifyKey($db,$account,$v,'login');$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);$_SESSION['key_verified_at']=time();respond(updateSession($account));
  }
  if(!$authenticated)respond(['error'=>'Zaloguj się do portfela.','code'=>'session_expired'],401);
  if($development)fail('Rejestracja klucza wymaga normalnego logowania.',400);
  if($account['must_change'])fail('Najpierw zmień hasło tymczasowe.',403);
  if($action==='key-reauth-begin'){
   requireThat(!empty($account['keys']),'Brak zarejestrowanego klucza bezpieczeństwa.');respond(keyOptions($db,$account,'reauth'));
  }
  if($action==='key-reauth-finish'){
   verifyKey($db,$account,$v,'reauth');$_SESSION['key_verified_at']=time();$_SESSION['factor_verified_at']=time();$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);respond(updateSession($account));
  }
  if($action==='key-begin'||$action==='key-remove'){
   $pw=$v['currentPassword']??'';
   if(!is_string($pw)||strlen($pw)>72||!password_verify($pw,$account['hash']))authFailure($db,$ip);
   if($account['secret']!==''){
    global $config;
    if(!is_string($v['code']??'')||!acceptFactor($db,$account,$v['code']??'',$config))authFailure($db,$ip);
   }elseif(!empty($account['keys'])&&($_SESSION['key_verified_at']??0)<time()-300)fail('Zaloguj się ponownie kluczem, aby zarządzać kluczami. Potwierdzenie jest ważne 5 minut.',403);
  }
  if($action==='key-begin'){
   requireThat(count($account['keys'])<10,'Możesz przypisać maksymalnie 10 kluczy.');
   $label=trim($v['label']??'');requireThat(shortString($label,100,false),'Wpisz nazwę klucza (maks. 100 bajtów).');
   $args=keyOptions($db,$account,'create');$_SESSION['key_create']['label']=$label;respond($args);
  }
  if($action==='key-confirm'){
   $label=$_SESSION['key_create']['label']??'';$challenge=consumeChallenge($account,'create');
   $client=unb64($v['clientDataJSON']??'');checkClient($client);$w=webAuthn();$data=$w->processCreate($client,unb64($v['attestationObject']??''),$challenge,false,true);
   requireThat(b64url($data->credentialId)===($v['id']??''),'Nieprawidłowy identyfikator klucza.');
   $db->exec('BEGIN IMMEDIATE');
   try{
    $q=$db->prepare('INSERT INTO credentials(id,label,public_key,sign_count,created) VALUES(?,?,?,?,?)');$q->execute([b64url($data->credentialId),$label,$data->credentialPublicKey,$data->signatureCounter??0,date(DATE_ATOM)]);
    $codes=[];
    if(empty($account['keys'])&&$account['secret']===''){
     for($i=0;$i<10;$i++)$codes[]=strtoupper(bin2hex(random_bytes(8)));
     $q=$db->prepare('UPDATE account SET recovery=? WHERE id=1');$q->execute([json_encode(array_map(fn($x)=>hash('sha256',$x),$codes))]);
    }
    $q=$db->prepare('UPDATE account SET version=version+1 WHERE id=1 AND version=?');$q->execute([$account['version']]);requireThat($q->rowCount()===1,'Konto zmieniło się. Zaloguj się ponownie.');$db->exec('COMMIT');
   }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
   $account['version']++;$account['keys']=keyList($db);$_SESSION['key_verified_at']=time();
   $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);
   respond(updateSession($account)+['recoveryCodes'=>array_map(fn($x)=>substr($x,0,8).'-'.substr($x,8),$codes)]);
  }
  if($action==='key-remove'){
   $id=$v['id']??'';requireThat(is_string($id),'Nieprawidłowy klucz.');
   $db->exec('BEGIN IMMEDIATE');
   try{
    $q=$db->prepare('DELETE FROM credentials WHERE id=?');$q->execute([$id]);requireThat($q->rowCount()===1,'Nie znaleziono klucza.');
    $remaining=(int)$db->query('SELECT COUNT(*) FROM credentials')->fetchColumn();
    $q=$db->prepare("UPDATE account SET version=version+1,recovery=CASE WHEN secret='' AND ?=0 THEN '[]' ELSE recovery END WHERE id=1 AND version=?");$q->execute([$remaining,$account['version']]);requireThat($q->rowCount()===1,'Konto zmieniło się.');$db->exec('COMMIT');
   }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
   $account['version']++;$account['keys']=keyList($db);$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);respond(updateSession($account));
  }
 }catch(Throwable $e){error_log('STOCK key validation rejected: '.get_class($e));authFailure($db,$ip);}
}
function keyList(PDO $db):array{return $db->query('SELECT id,label,created,last_used AS lastUsed FROM credentials ORDER BY created')->fetchAll(PDO::FETCH_ASSOC);}
