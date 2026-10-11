"""Duo HTTP access guards, private storage, fail-closed login and local MFA fallback."""
import base64,http.cookiejar,json,os,pathlib,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1];PW='synthetic-duo-test-password!'
class Client:
 def __init__(self,port):
  self.base=f'http://127.0.0.1:{port}/api.php?action=';self.csrf='';self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,action,data=None,csrf=True,method=None):
  headers={'Content-Type':'application/json'}
  if csrf:headers['X-CSRF-Token']=self.csrf
  req=urllib.request.Request(self.base+action,data=json.dumps(data).encode() if data is not None else None,headers=headers,method=method)
  try:
   with self.http.open(req,timeout=10) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def session(self):
  res=self.req('session')[1];self.csrf=res['csrf'];return res
 def login(self,**more):
  self.session();status,res=self.req('login',{'username':'mateusz','password':PW,**more})
  if status==200 and 'csrf' in res:self.csrf=res['csrf']
  return status,res
with tempfile.TemporaryDirectory(prefix='stock-duo-test-') as private:
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 env=dict(os.environ,PORTFOLIO_PASSWORD=PW,PORTFOLIO_STORAGE=private,PORTFOLIO_DEV='0',PORTFOLIO_SECURE_COOKIE='0',PORTFOLIO_AUTH_KEY=base64.b64encode(bytes(range(32))).decode())
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  a=Client(port)
  for _ in range(80):
   try:a.session();break
   except urllib.error.URLError:time.sleep(.05)
  assert not a.session()['duoEnabled']
  assert a.req('duo-setup',{})[0]==401
  assert a.req('duo-setup',{},csrf=False)[0]==403
  assert a.req('duo-finish',{'state':'made-up','duo_code':'code'})[0]==400
  assert a.login()[0]==200
  state=a.req('state')[1]
  assert a.req('duo-setup')[0]==405
  assert a.req('duo-setup',{'currentPassword':'wrong'})[0]==401
  assert a.req('duo-setup',{'currentPassword':PW,'clientId':'bad','clientSecret':'bad','apiHost':'localhost'})[0]==400
  assert a.req('duo-disable',{'currentPassword':PW})[0]==400
  db=sqlite3.connect(pathlib.Path(private,'portfolio.sqlite'))
  assert db.execute('SELECT COUNT(*) FROM duo_settings').fetchone()[0]==0
  assert a.req('state')[1]==state
  # A deliberately unreadable credential emulates Duo failure without any network calls.
  db.execute('INSERT INTO duo_settings VALUES(1,1,1,?,?,?,?)',('A'*20,'api-12345678.duosecurity.com','bad-ciphertext','2026-01-01'));db.commit()
  b=Client(port);assert b.session()['duoEnabled'] and b.session()['security'] is None
  assert b.login()[0]==502 # Duo cannot fail open to password-only access.
  assert b.req('state')[0]==401
  assert b.login(factor='local')[0]==401
  assert b.login(factor='local',code='arbitrary')[0]==401
  assert b.req('state')[0]==401
  assert a.req('duo-disable',{'currentPassword':PW})[0]==400 # no second factor, no recent MFA
  # Enable a real local TOTP in the isolated database, proving the existing alternative still works.
  php="define('STOCK_API',true);require 'public/auth.php';$secret=base32Encode(str_repeat('t',20));echo json_encode(['sealed'=>sealSecret($secret,[]),'code'=>totp($secret,intdiv(time(),30))]);"
  fixture=json.loads(subprocess.check_output(['php','-r',php],cwd=ROOT,env=env))
  db.execute('UPDATE account SET secret=?,last_counter=-1',(fixture['sealed'],));db.execute('DELETE FROM attempts');db.commit()
  status,res=b.login(factor='local',code=fixture['code']);assert status==200,res
  assert res['security']['duo']['enabled'] and 'secret' not in res['security']['duo']
  assert b.req('state')[0]==200
  before_counter=db.execute('SELECT last_counter FROM account').fetchone()[0]
  # Setup uses only the password inside an authenticated MFA session, no second TOTP/recovery entry.
  status,res=b.req('duo-setup',{'currentPassword':PW,'clientId':'bad','clientSecret':'bad','apiHost':'localhost'})
  assert status==400 and 'Client ID' in res['error'],res
  assert db.execute('SELECT last_counter FROM account').fetchone()[0]==before_counter
  assert b.req('duo-setup',{'currentPassword':'wrong','clientId':'bad'})[0]==401
  assert b.req('duo-disable',{'currentPassword':PW})[0]==401 # disabling retains its existing MFA check
  assert b.req('duo-finish',{'state':'wrong','duo_code':'wrong'})[0]==400
  assert b.req('state')[1]==state
  assert db.execute('SELECT secret FROM duo_settings').fetchone()[0]=='bad-ciphertext'
  print('PASS: Duo auth/CSRF/method/password guards, no activation before push, callback without pending denied, Duo failure rejects password-only login, alternate TOTP works, no credential in responses, portfolio preserved.')
 finally:server.terminate();server.wait(timeout=5)
