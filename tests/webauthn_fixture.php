<?php
// Generates synthetic P-256 authenticator messages for isolated integration tests.
$v=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
function u(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function head(int $type,int $n):string{return $n<24?chr($type*32+$n):($n<256?chr($type*32+24).chr($n):chr($type*32+25).pack('n',$n));}
function integer(int $x):string{return $x>=0?head(0,$x):head(1,-1-$x);}
function bytes(string $s):string{return head(2,strlen($s)).$s;}
function text(string $s):string{return head(3,strlen($s)).$s;}
if($v['op']==='generate'){
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($key,$private);$d=openssl_pkey_get_details($key);echo json_encode(['private'=>$private,'x'=>u($d['ec']['x']),'y'=>u($d['ec']['y']),'id'=>u(random_bytes(32))]);exit;
}
function d(string $s):string{return base64_decode(strtr($s,'-_','+/'));}
$client=json_encode(['type'=>$v['op']==='create'?'webauthn.create':'webauthn.get','challenge'=>$v['challenge'],'origin'=>$v['origin'],'crossOrigin'=>$v['crossOrigin']??false],JSON_UNESCAPED_SLASHES);
$auth=hash('sha256',$v['rp'],true).chr($v['flags']??($v['op']==='create'?0x41:0x01)).pack('N',$v['counter']??0);
if($v['op']==='create'){
 $cose=head(5,5).integer(1).integer(2).integer(3).integer(-7).integer(-1).integer(1).integer(-2).bytes(d($v['x'])).integer(-3).bytes(d($v['y']));
 $auth.=str_repeat("\0",16).pack('n',strlen(d($v['id']))).d($v['id']).$cose;
 $att=head(5,3).text('fmt').text('none').text('attStmt').head(5,0).text('authData').bytes($auth);
 echo json_encode(['id'=>$v['id'],'clientDataJSON'=>u($client),'attestationObject'=>u($att)]);
}else{
 openssl_sign($auth.hash('sha256',$client,true),$signature,$v['private'],OPENSSL_ALGO_SHA256);
 echo json_encode(['id'=>$v['id'],'clientDataJSON'=>u($client),'authenticatorData'=>u($auth),'signature'=>u($signature),'userHandle'=>null]);
}
