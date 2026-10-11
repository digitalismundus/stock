<?php
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }
function base32Encode(string $bytes): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits='';
    foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)),8,'0',STR_PAD_LEFT);
    $out=''; foreach (str_split($bits,5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk,5,'0'))];
    return $out;
}
function base32Decode(string $value): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits='';
    foreach (str_split($value) as $c) { $i=strpos($alphabet,$c); if($i===false) throw new RuntimeException('Invalid secret'); $bits.=str_pad(decbin($i),5,'0',STR_PAD_LEFT); }
    $out=''; foreach(str_split($bits,8) as $chunk) if(strlen($chunk)===8) $out.=chr(bindec($chunk));
    return $out;
}
function totp(string $secret, int $counter, int $digits=6): string {
    $digest=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),base32Decode($secret),true);
    $offset=ord($digest[19])&15; $number=unpack('N',substr($digest,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($number%(10**$digits)),$digits,'0',STR_PAD_LEFT);
}
function totpCounter(string $secret, string $code, int $last=-1): int|false {
    if(!preg_match('/^[0-9]{6}$/D',$code)) return false;
    $now=intdiv(time(),30);
    foreach([$now,$now-1,$now+1] as $c) if($c>$last && hash_equals(totp($secret,$c),$code)) return $c;
    return false;
}
function authKey(array $config): string {
    $key=base64_decode(getenv('PORTFOLIO_AUTH_KEY') ?: ($config['auth_key']??''),true);
    if($key===false || strlen($key)!==32) throw new RuntimeException('Configure private 32-byte auth_key');
    return $key;
}
function sealSecret(string $secret, array $config, string $context='STOCK-TOTP'): string {
    $iv=random_bytes(12);$tag=''; $encrypted=openssl_encrypt($secret,'aes-256-gcm',authKey($config),OPENSSL_RAW_DATA,$iv,$tag,$context);
    if($encrypted===false) throw new RuntimeException('Cannot encrypt secret');
    return base64_encode($iv.$tag.$encrypted);
}
function openSecret(string $sealed, array $config, string $context='STOCK-TOTP'): string {
    $raw=base64_decode($sealed,true); if($raw===false || strlen($raw)<29) throw new RuntimeException('Invalid encrypted secret');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',authKey($config),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$context);
    if($plain===false) throw new RuntimeException('Cannot decrypt secret'); return $plain;
}
function recentFactor(): bool { return max((int)($_SESSION['key_verified_at']??0),(int)($_SESSION['duo_verified_at']??0),(int)($_SESSION['factor_verified_at']??0)) >= time()-300; }
function securityStatus(array $account): array { return ['keys'=>$account['keys']??[],'keyCount'=>count($account['keys']??[]),'keyReauthRequired'=>!empty($account['keys'])&&!recentFactor(),'totpEnrollmentMfaRequired'=>$account['secret']===''&&(!empty($account['keys'])||!empty($account['duo']['enabled']))&&!recentFactor(),'username'=>$account['username'],'totpEnabled'=>$account['secret']!=='','mustChange'=>(bool)$account['must_change'],'duo'=>duoSummary($account['duo']??null)]; }
function throttle(PDO $db,string $ip): void {
    $q=$db->prepare('SELECT count,until FROM attempts WHERE ip=?');$q->execute([$ip]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if($row && (int)$row['until']>time() && (int)$row['count']>=5) fail('Zbyt wiele prób. Spróbuj ponownie za 15 minut.',429);
}
function authFailure(PDO $db,string $ip): never {
    $q=$db->prepare('INSERT INTO attempts VALUES(?,1,?) ON CONFLICT(ip) DO UPDATE SET count=CASE WHEN until>? THEN count+1 ELSE 1 END,until=excluded.until');$q->execute([$ip,time()+900,time()]);
    fail('Nieprawidłowy login, hasło lub kod uwierzytelniający.',401);
}
function acceptFactor(PDO $db,array $account,string $code,array $config): bool {
    // UPDATE conditions make code consumption atomic across concurrent PHP workers.
    if($account['secret']===''&&empty($account['keys'])) return true;
    $counter=$account['secret']!==''?totpCounter(openSecret($account['secret'],$config),$code,(int)$account['last_counter']):false;
    if($counter!==false) {
        $q=$db->prepare('UPDATE account SET last_counter=? WHERE id=1 AND version=? AND last_counter<? AND secret=?');$q->execute([$counter,$account['version'],$counter,$account['secret']]);
        return $q->rowCount()===1;
    }
    return acceptRecoveryCode($db,$account,$code);
}
function acceptRecoveryCode(PDO $db,array $account,string $code): bool {
    $code=strtoupper(str_replace('-','',trim($code)));
    if(!preg_match('/^[A-F0-9]{16}$/D',$code)) return false;
    $old=$account['recovery'];$codes=json_decode($old,true,32,JSON_THROW_ON_ERROR);$hash=hash('sha256',$code);
    $index=array_search($hash,$codes,true);if($index===false) return false;
    unset($codes[$index]);$q=$db->prepare('UPDATE account SET recovery=? WHERE id=1 AND version=? AND recovery=?');$q->execute([json_encode(array_values($codes)),$account['version'],$old]);return $q->rowCount()===1;
}
function updateSession(array $account): array {
    session_regenerate_id(true);$_SESSION['authenticated']=true;$_SESSION['last']=time();$_SESSION['started']=time();$_SESSION['account_version']=(int)$account['version'];$_SESSION['csrf']=bin2hex(random_bytes(32));
    return ['csrf'=>$_SESSION['csrf'],'security'=>securityStatus($account)];
}
function securityActions(PDO $db,array &$account,array $config,string $action,string $method,bool $authenticated,bool $development): void {
    $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
    duoActions($db,$account,$config,$action,$method,$authenticated,$development,$ip);
    webAuthnActions($db,$account,$action,$method,$authenticated,$development,$ip);
    if($action==='login' && $method==='POST') {
        throttle($db,$ip);$v=payload();$supplied=$v['password']??'';$username=$v['username']??'';$code=$v['code']??'';
        $correct=is_string($supplied)&&strlen($supplied)<=72&&password_verify($supplied,$account['hash']);
        if(!$correct || !is_string($username) || !hash_equals($account['username'],$username) || !is_string($code)) authFailure($db,$ip);
        unset($_SESSION['duo_pending'],$_SESSION['key_login']);
        $factor=$v['factor']??'auto';requireThat(in_array($factor,['auto','duo','local'],true),'Nieprawidłowa metoda logowania.');
        if($factor==='duo'||($factor==='auto'&&!empty($account['duo']['enabled'])&&$code==='')){requireThat(!empty($account['duo']['enabled']),'Duo nie jest aktywne.');respond(duoBegin($account,$account['duo'],$config,'login'));}
        if(!empty($account['duo']['enabled'])&&$account['secret']===''&&empty($account['keys']))authFailure($db,$ip);
        if(!empty($account['keys'])&&$code==='')respond(['keyRequired'=>true]+keyOptions($db,$account,'login'));
        if(!acceptFactor($db,$account,$code,$config))authFailure($db,$ip);
        if($account['secret']!==''||!empty($account['keys']))$_SESSION['factor_verified_at']=time();
        $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]); respond(updateSession($account));
    }
    if(!$authenticated) respond(['error'=>'Zaloguj się do portfela.','code'=>'session_expired'],401);
    if($action==='security' && $method==='GET') respond(securityStatus($account));
    if(!in_array($action,['password','totp-begin','totp-confirm','totp-disable'],true)) return;
    if($method!=='POST') fail('Niedozwolona metoda.',405);
    if($development) fail('Zarządzanie kontem wymaga normalnego logowania.',400);
    throttle($db,$ip);$v=payload();
    if($action!=='totp-confirm') {
        $pw=$v['currentPassword']??'';
        if(!is_string($pw)||strlen($pw)>72||!password_verify($pw,$account['hash'])) authFailure($db,$ip);
    }
    if($action==='password') {
        $new=$v['newPassword']??'';
        requireThat(is_string($new)&&strlen($new)>=14&&strlen($new)<=72,'Nowe hasło musi mieć od 14 do 72 znaków (bajtów).');
        requireThat(!password_verify($new,$account['hash']),'Wybierz inne hasło niż obecne.');
        if($account['secret']!==''&&(!is_string($v['code']??'')||!acceptFactor($db,$account,$v['code']??'',$config))) authFailure($db,$ip);
        $q=$db->prepare('UPDATE account SET hash=?,must_change=0,version=version+1 WHERE id=1 AND version=?');$q->execute([password_hash($new,PASSWORD_DEFAULT),$account['version']]);
        if($q->rowCount()!==1) fail('Konto zmieniło się. Zaloguj się ponownie.',409);
        $account['must_change']=0;$account['version']++;$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);respond(updateSession($account));
    }
    if($account['must_change']) fail('Najpierw zmień hasło tymczasowe.',403);
    if($action==='totp-begin') {
        requireThat($account['secret']==='','2FA jest już aktywne.');
        authKey($config);
        if ((!empty($account['keys']) || !empty($account['duo']['enabled'])) && !recentFactor()) {
            $code = $v['code'] ?? '';
            if ($code === '') fail('Przed dodaniem TOTP potwierdź istniejący klucz, zaloguj się ponownie przez Duo lub wpisz jednorazowy kod odzyskiwania. Potwierdzenie jest ważne 5 minut.',403);
            if (!is_string($code) || !acceptRecoveryCode($db,$account,$code)) authFailure($db,$ip);
            $_SESSION['factor_verified_at'] = time();
        }
        $secret=base32Encode(random_bytes(20));$_SESSION['totp_pending']=['secret'=>$secret,'until'=>time()+600,'version'=>$account['version']];
        $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);
        respond(['secret'=>$secret,'uri'=>'otpauth://totp/'.rawurlencode('STOCK:'.$account['username']).'?secret='.$secret.'&issuer=STOCK&algorithm=SHA1&digits=6&period=30']);
    }
    if($action==='totp-confirm') {
        $pending=$_SESSION['totp_pending']??null;requireThat(is_array($pending)&&$pending['until']>time()&&(int)$pending['version']===(int)$account['version']&&$account['secret']==='','Konfiguracja wygasła. Rozpocznij ponownie.');
        $code=$v['code']??'';$counter=is_string($code)?totpCounter($pending['secret'],$code):false;if($counter===false) authFailure($db,$ip);
        $codes=[];for($i=0;$i<10;$i++) $codes[]=strtoupper(bin2hex(random_bytes(8)));
        $q=$db->prepare('UPDATE account SET secret=?,last_counter=?,recovery=?,version=version+1 WHERE id=1 AND version=? AND secret=\'\'');$q->execute([sealSecret($pending['secret'],$config),$counter,json_encode(array_map(fn($c)=>hash('sha256',$c),$codes)),$account['version']]);
        if($q->rowCount()!==1) fail('Konto zmieniło się. Zaloguj się ponownie.',409);
        unset($_SESSION['totp_pending']);$account['secret']='enabled';$account['version']++;$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);
        respond(updateSession($account)+['recoveryCodes'=>array_map(fn($c)=>substr($c,0,8).'-'.substr($c,8),$codes)]);
    }
    if($action==='totp-disable') {
        requireThat($account['secret']!=='','2FA nie jest aktywne.');
        if(!is_string($v['code']??'')||!acceptFactor($db,$account,$v['code']??'',$config)) authFailure($db,$ip);
        $q=$db->prepare('UPDATE account SET secret=\'\',last_counter=-1,recovery=CASE WHEN (SELECT COUNT(*) FROM credentials)=0 THEN \'[]\' ELSE recovery END,version=version+1 WHERE id=1 AND version=?');$q->execute([$account['version']]);if($q->rowCount()!==1) fail('Konto zmieniło się. Zaloguj się ponownie.',409);
        $account['secret']='';$account['version']++;$db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);respond(updateSession($account));
    }
}
