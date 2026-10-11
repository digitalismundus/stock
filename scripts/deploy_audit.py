"""Publish audited STOCK files with a private backup, checks and public rollback.

Uses the existing scoped FTPS configuration. Private portfolio/authentication
content stays on the host; only fingerprints and check results are returned.
"""
from datetime import datetime, timezone
from pathlib import Path
import io
import json
import re
import secrets
import subprocess
import sys
import urllib.request

from ftp_stock import connect

ROOT = Path(__file__).resolve().parents[1]
BASE = "https://stock.mateuszrak.com/"


def probe_source(token, expiry):
    api = (ROOT / "public/api.php").read_text()
    definitions = api[api.index("function respond("):api.index("function payload(")]
    validation = (ROOT / "public/validation.php").read_text()
    validation = validation[validation.index("function stockFinite("):]
    fx = (ROOT / "public/fx.php").read_text()
    fx = fx[fx.index("function nbpPeriod("):]
    template = r'''<?php
declare(strict_types=1);
ini_set('display_errors','0');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_RELEASE_CHECK']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');date_default_timezone_set('Europe/Warsaw');
__DEFINITIONS__
__VALIDATION__
__FX__
try {
 $input=json_decode(file_get_contents('php://input'),true,4,JSON_THROW_ON_ERROR);
 $mode=$input['mode']??'';requireThat(in_array($mode,['before','after'],true),'Invalid check mode.');
 $configPath=dirname(__DIR__).'/config.php';$config=require $configPath;
 $storage=$config['storage']??dirname(__DIR__).'/storage';
 $db=new PDO('sqlite:'.$storage.'/portfolio.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=5000');
 $state=$db->query('SELECT revision,body FROM portfolio WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 if($state)validateState(json_decode($state['body'],true,64,JSON_THROW_ON_ERROR));
 $auth=[];foreach(['account','credentials','duo_settings'] as $table){$exists=$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name=".$db->quote($table))->fetchColumn();$auth[$table]=$exists?$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC):[];}
 $result=['portfolioCompatible'=>true,'databaseIntegrity'=>$db->query('PRAGMA quick_check')->fetchColumn()==='ok','phpCompatible'=>version_compare(PHP_VERSION,'8.2','>='),'extensions'=>['curl'=>function_exists('curl_init'),'openssl'=>function_exists('openssl_encrypt'),'dom'=>class_exists('DOMDocument'),'mbstring'=>function_exists('mb_strlen')],'portfolioFingerprint'=>hash_hmac('sha256',$state['body']??'','__TOKEN__'),'authFingerprint'=>hash_hmac('sha256',json_encode($auth,JSON_THROW_ON_ERROR),'__TOKEN__'),'configFingerprint'=>hash_hmac('sha256',file_get_contents($configPath),'__TOKEN__'),'revision'=>(int)($state['revision']??0)];
 requireThat($result['databaseIntegrity']&&$result['phpCompatible']&&!in_array(false,$result['extensions'],true),'Host preflight failed.');
 requireThat(stockPercentFee(12750,.29,0)===36.98&&stockPercentFeeMatches(12750,.29,0,36.97),'Host calculation check failed.');
 $result['calculationCompatible']=true;
 if($mode==='before'){
  $directory=$storage.'/backups';if(!is_dir($directory))requireThat(mkdir($directory,0700,true),'Backup directory unavailable.');
  $name='before-audit-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';$file=$directory.'/'.$name;
  $db->exec('VACUUM INTO '.$db->quote($file));requireThat(chmod($file,0600)&&is_file($file),'Backup failed.');
  $copy=new PDO('sqlite:'.$file);requireThat($copy->query('PRAGMA quick_check')->fetchColumn()==='ok','Backup integrity failed.');
  $result['backupCreated']=true;$result['databaseBackup']=$name;
 }
 echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $error){http_response_code(500);echo json_encode(['error'=>'Private release verification failed.']);}
'''
    return template.replace("__EXPIRY__", str(expiry)).replace("__TOKEN__", token).replace("__DEFINITIONS__", definitions).replace("__VALIDATION__", validation).replace("__FX__", fx).encode()


def upload_probe(name, source):
    with connect() as ftp:
        ftp.storbinary("STOR /public_html/" + name, io.BytesIO(source))
        ftp.sendcmd("SITE CHMOD 644 /public_html/" + name)


def remove_probe(name):
    with connect() as ftp:
        try:
            ftp.delete("/public_html/" + name)
        except Exception as error:
            # Missing is expected after rollback to a backup without the probe.
            if not str(error).startswith("550"):
                raise


def probe(name, token, mode):
    request = urllib.request.Request(BASE + name, data=json.dumps({"mode": mode}).encode(), headers={"Content-Type": "application/json", "X-Release-Check": token}, method="POST")
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.load(response)


def rollback(release):
    backup = release["backup"]
    if not re.fullmatch(r"/public_html\.backup-\d{8}T\d{6}Z-[a-f0-9]{6}", backup):
        raise RuntimeError("Unexpected rollback path")
    failed = "/public_html.failed-" + release["release"]
    with connect() as ftp:
        ftp.rename("/public_html", failed)
        try:
            ftp.rename(backup, "/public_html")
        except BaseException:
            ftp.rename(failed, "/public_html")
            raise
    return failed


def main():
    (ROOT / 'private-deploy').mkdir(mode=0o700, exist_ok=True)
    token = secrets.token_hex(32)
    name = "release-check-" + secrets.token_hex(12) + ".php"
    source = probe_source(token, int(datetime.now(timezone.utc).timestamp()) + 1800)
    metadata = ROOT / "private-deploy/release.json"
    previous = metadata.read_bytes() if metadata.is_file() else None
    previous_id = json.loads(previous)["release"] if previous else None
    activated = None
    uploaded = False
    try:
        upload_probe(name, source)
        uploaded = True
        before = probe(name, token, "before")
        if not before.get("backupCreated"):
            raise RuntimeError("Private backup unavailable")
        remove_probe(name)
        uploaded = False
        print("Private database backup and compatibility checks passed.", flush=True)
        try:
            subprocess.run([sys.executable, str(ROOT / "scripts/deploy_stock.py")], check=True)
        finally:
            if metadata.is_file():
                candidate = json.loads(metadata.read_bytes())
                if candidate["release"] != previous_id:
                    activated = candidate
        if activated is None:
            raise RuntimeError("No new release recorded")
        upload_probe(name, source)
        uploaded = True
        after = probe(name, token, "after")
        unchanged = {label: before[key] == after[key] for label, key in [("portfolioUnchanged", "portfolioFingerprint"), ("authenticationUnchanged", "authFingerprint"), ("configurationUnchanged", "configFingerprint")]}
        if not all(unchanged.values()) or before["revision"] != after["revision"]:
            raise RuntimeError("Private state changed during release; no private data will be restored")
        remove_probe(name)
        uploaded = False
        output = ROOT / "test-results/audit-fixes-2026-10-11/public-release.json"
        checked = subprocess.run([sys.executable, str(ROOT / "tests/public_release_check.py"), "--output", str(output)], capture_output=True, text=True)
        if checked.returncode:
            raise RuntimeError("Public release checks failed; inspect the local public check report")
        public = json.loads(output.read_text())
        report = {"domain": "stock.mateuszrak.com", "release": activated["release"], "publicBackup": activated["backup"], "databaseBackup": before["databaseBackup"], **unchanged, "portfolioCompatible": after["portfolioCompatible"], "databaseIntegrity": after["databaseIntegrity"], "publicChecksPassed": public["passed"], "publicCheckCount": len(public["checks"]), "deployed": True}
        (ROOT / "private-deploy/audit-check.json").write_text(json.dumps(report, indent=2) + "\n")
        print(json.dumps(report, indent=2), flush=True)
        return 0
    except Exception as error:
        if uploaded:
            try:
                remove_probe(name)
                uploaded = False
            except Exception:
                print("Temporary check cleanup requires retry.", file=sys.stderr)
        if activated:
            failed = rollback(activated)
            if previous is not None:
                metadata.write_bytes(previous)
            print("Previous public release restored; rejected release retained at " + failed, flush=True)
        print("Deployment stopped: " + type(error).__name__ + ": " + str(error), file=sys.stderr)
        return 1
    finally:
        if uploaded:
            try:
                remove_probe(name)
            except Exception:
                print("Temporary check expires automatically; cleanup could not be confirmed.", file=sys.stderr)


if __name__ == "__main__":
    raise SystemExit(main())
