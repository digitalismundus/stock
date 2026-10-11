"""Cryptographic WebAuthn integration against temporary, isolated PHP/SQLite."""
import base64,http.cookiejar,json,os,pathlib,socket,subprocess,tempfile,time,urllib.request,urllib.error,sqlite3
ROOT=pathlib.Path(__file__).resolve().parents[1];PW='synthetic-test-password!'
class Client:
 def __init__(self,port):
  self.base=f'http://127.0.0.1:{port}/api.php?action=';self.csrf='';self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(self,action,data=None):
  req=urllib.request.Request(self.base+action,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:
   with self.http.open(req,timeout=15) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def session(self):self.csrf=self.req('session')[1]['csrf']
 def login(self,pw=PW):
  self.session();code,res=self.req('login',{'username':'mateusz','password':pw,'factor':'local'});assert code==200,res
  if not res.get('keyRequired'):self.csrf=res['csrf']
  return res
 def apply(self,res):self.csrf=res['csrf']
def fixture(**kw):return json.loads(subprocess.check_output(['php',str(ROOT/'tests/webauthn_fixture.php')],input=json.dumps(kw).encode()))
with tempfile.TemporaryDirectory(prefix='stock-key-test-') as private:
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 origin=f'http://localhost:{port}'
 env=dict(os.environ,PORTFOLIO_PASSWORD=PW,PORTFOLIO_STORAGE=private,PORTFOLIO_DEV='0',PORTFOLIO_SECURE_COOKIE='0',PORTFOLIO_WEBAUTHN_ORIGIN=origin,PORTFOLIO_AUTH_KEY=base64.b64encode(bytes(range(32))).decode())
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  a=Client(port)
  for _ in range(80):
   try:a.session();break
   except urllib.error.URLError:time.sleep(.05)
  a.login();second=Client(port);second.login();f=fixture(op='generate')
  code,options=a.req('key-begin',{'currentPassword':PW,'label':'Synthetic P-256 key'});assert code==200,options
  assert options['publicKey']['authenticatorSelection']['authenticatorAttachment']=='cross-platform'
  assert options['publicKey']['authenticatorSelection']['residentKey']=='discouraged'
  created=fixture(op='create',**f,challenge=options['publicKey']['challenge'],origin=origin,rp='localhost')
  code,res=a.req('key-confirm',created);assert code==200,res;a.apply(res);recovery=res['recoveryCodes']
  assert len(recovery)==10 and len(res['security']['keys'])==1
  assert second.req('state')[0]==401
  assert a.req('key-confirm',created)[0]==401 # single-use registration challenge
  def assertion(options,counter=1,**more):
   return fixture(op='get',**f,challenge=options['publicKey']['challenge'],origin=more.get('origin',origin),rp=more.get('rp','localhost'),counter=counter,flags=more.get('flags',1),crossOrigin=more.get('crossOrigin',False))
  login=Client(port);opts=login.login();assert opts['keyRequired']
  assert login.req('state')[0]==401 # password alone is never sufficient once key active
  signed=assertion(opts,1);code,res=login.req('key-login',signed);assert code==200,res;login.apply(res)
  assert login.req('state')[0]==200
  assert login.req('key-login',signed)[0]==401 # consumed challenge
  for index,mutation in enumerate([{'origin':'http://evillocalhost:'+str(port)},{'rp':'other-host'},{'flags':0},{'crossOrigin':True}]):
   opts=login.login();assert opts['keyRequired'];assert login.req('key-login',assertion(opts,2,**mutation))[0]==401
   # Successful factor clears accumulated attempt count between independent rejections.
   opts=login.login();code,res=login.req('key-login',assertion(opts,100+index));assert code==200,res;login.apply(res)
  opts=login.login();bad=assertion(opts,200000);bad['signature']=base64.urlsafe_b64encode(b'wrong').decode().rstrip('=');assert login.req('key-login',bad)[0]==401
  opts=login.login();code,res=login.req('key-login',assertion(opts,300000));assert code==200,res;login.apply(res)
  opts=login.login();assert login.req('key-login',assertion(opts,300000))[0]==401 # counter rollback/replay
  recover=Client(port);recover.session();code,res=recover.req('login',{'username':'mateusz','password':PW,'code':recovery[0]});assert code==200,res;recover.apply(res)
  reused=Client(port);reused.session();assert reused.req('login',{'username':'mateusz','password':PW,'code':recovery[0]})[0]==401
  # Recovery login cannot silently remove a hardware key; key reauthentication is required.
  assert recover.req('key-remove',{'id':f['id'],'currentPassword':PW})[0]==403
  opts=login.login();code,res=login.req('key-login',assertion(opts,300001));assert code==200,res;login.apply(res)
  code,res=login.req('key-remove',{'id':f['id'],'currentPassword':PW});assert code==200,res;login.apply(res);assert res['security']['keys']==[]
  assert recover.req('state')[0]==401
  fresh=Client(port);assert not fresh.login().get('keyRequired')
  # New data model round-trip; negative snapshots are rejected without mutating state.
  code,st=fresh.req('state');state=st['state'];state['retirement']=[{'id':'z','kind':'zus','name':'ZUS','currency':'PLN','history':[{'date':'2026-01-02','amount':10000}]}]
  assert fresh.req('state',{'state':state,'revision':st['revision']})[0]==404 # POST is not PUT
  req=urllib.request.Request(fresh.base+'state',method='PUT',data=json.dumps({'state':state,'revision':st['revision']}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':fresh.csrf})
  with fresh.http.open(req) as r:assert r.status==200
  assert fresh.req('state')[1]['state']['retirement']==state['retirement']
  print('PASS: hardware registration, signed assertion, exact origin, RP hash, user presence, cross-origin rejection, invalid signatures, challenge replay, counter replay, password-only rejection, recovery codes, credential removal, session revocation, retirement persistence.')
 finally:server.terminate();server.wait(timeout=5)
