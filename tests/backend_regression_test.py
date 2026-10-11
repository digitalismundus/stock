"""Audit regressions against isolated PHP, private temporary DB/session files and synthetic FIDO key."""
import base64, copy, hashlib, hmac, http.cookiejar, json, os, pathlib, socket, sqlite3, struct, subprocess, tempfile, time, urllib.error, urllib.request

ROOT=pathlib.Path(__file__).resolve().parents[1]
PW='synthetic-backend-regression!'
class Client:
    def __init__(self,port):
        self.base=f'http://127.0.0.1:{port}/api.php?action=';self.csrf='';self.cookies=http.cookiejar.CookieJar()
        self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
    def req(self,action,data=None,method=None):
        request=urllib.request.Request(self.base+action,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf},method=method)
        try:
            with self.http.open(request,timeout=20) as result:return result.status,json.load(result)
        except urllib.error.HTTPError as error:return error.code,json.load(error)
    def login(self,**extra):
        self.csrf=self.req('session')[1]['csrf'];status,res=self.req('login',{'username':'mateusz','password':PW,**extra})
        if 'csrf' in res:self.csrf=res['csrf']
        return status,res
def fixture(**kwargs):return json.loads(subprocess.check_output(['php',str(ROOT/'tests/webauthn_fixture.php')],input=json.dumps(kwargs).encode()))
def otp(secret,counter):
    digest=hmac.new(base64.b32decode(secret),struct.pack('>Q',counter),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)

with tempfile.TemporaryDirectory(prefix='stock-backend-regression-') as private:
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    origin=f'http://localhost:{port}'
    env=dict(os.environ,PORTFOLIO_USERNAME='mateusz',PORTFOLIO_PASSWORD=PW,PORTFOLIO_STORAGE=private,PORTFOLIO_DEV='0',PORTFOLIO_SECURE_COOKIE='0',PORTFOLIO_WEBAUTHN_ORIGIN=origin,PORTFOLIO_AUTH_KEY=base64.b64encode(bytes(range(32))).decode())
    server=subprocess.Popen(['php','-d',f'session.save_path={private}','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        a=Client(port)
        for _ in range(80):
            try:a.req('session');break
            except urllib.error.URLError:time.sleep(.05)
        assert a.req('state')[1]['code']=='session_expired'
        assert a.req('key-reauth-begin',{})[0]==403  # no CSRF, no information exposure
        assert a.login()[0]==200
        state=a.req('state')[1]['state'];revision=0
        state['assets']=[{'id':'__proto__','symbol':'TEST','name':'Regression asset','type':'stock','market':'','currency':'PLN','quote':{'price':0,'date':'2026-01-03','history':[{'date':'2026-01-02','close':10},{'date':'2026-01-03','close':0}],'historyBasis':'manual','providerSymbol':'','historyArchives':[{'source':'CSV','basis':'manual','providerSymbol':'','history':[{'date':'2025-01-02','close':0}],'archivedAt':'2026-10-11T10:00:00+02:00'}]}}]
        state['ledger']=[{'id':'b','type':'buy','date':'2026-01-02','assetId':'__proto__','currency':'PLN','quantity':1,'price':12750,'fee':36.98,'fx':1,'feeMode':'percent','feePercent':.29,'feeMinimum':0}]
        def save(s):return a.req('state',{'state':s,'revision':revision},method='PUT')
        assert save(state)==(200,{'revision':1});revision=1
        assert a.req('state')[1]['state']==state
        for edit in ['overflow','negativequote','badarchive','future','wrongfee','sumoverflow','plnhistory']:
            bad=copy.deepcopy(state)
            if edit=='overflow':bad['ledger'][0].update(quantity=1e308,price=1e308,fee=0,feeMode='amount')
            if edit=='negativequote':bad['assets'][0]['quote']['price']=-1
            if edit=='badarchive':bad['assets'][0]['quote']['historyArchives'][0]['history'][0]['close']=-1
            if edit=='future':bad['assets'][0]['providerSymbol']='GC=F'
            if edit=='wrongfee':bad['ledger'][0]['fee']=37
            if edit=='sumoverflow':bad['ledger']=[{'id':'d1','type':'deposit','date':'2026-01-02','currency':'PLN','amount':1e308,'fee':0,'fx':1},{'id':'d2','type':'deposit','date':'2026-01-03','currency':'PLN','amount':1e308,'fee':0,'fx':1}]
            if edit=='plnhistory':bad['fx']['PLN']['history']=[{'date':'2026-01-03','rate':2}]
            assert save(bad)[0]==400,edit
        assert a.req('state')[1]['revision']==1
        legacy=copy.deepcopy(state);legacy['ledger'][0]['fee']=36.97
        assert save(legacy)==(200,{'revision':2});revision=2
        assert a.req('state')[1]['state']['ledger'][0]['fee']==36.97  # preserve actual historical fees
        with sqlite3.connect(pathlib.Path(private,'portfolio.sqlite')) as db:
            query='gold';cache={'items':[{'symbol':'GC=F','name':'Gold future','type':'commodity'},{'symbol':'BAD=F','name':'Wrong source type','type':'stock'},{'symbol':'GLD','name':'Gold ETF','type':'etf'}],'source':'Fixture','updated':'2026-01-03','stale':False}
            db.execute('INSERT INTO cache VALUES(?,?,?)',('search-'+hashlib.sha256(query.encode()).hexdigest(),int(time.time())+3600,json.dumps(cache)))
        assert [item['symbol'] for item in a.req('instruments&q=gold')[1]['items']]==['GLD']
        f=fixture(op='generate');status,options=a.req('key-begin',{'currentPassword':PW,'label':'Regression key'});assert status==200,options
        created=fixture(op='create',**f,challenge=options['publicKey']['challenge'],origin=origin,rp='localhost')
        status,res=a.req('key-confirm',created);assert status==200,res;a.csrf=res['csrf'];recovery=res['recoveryCodes']
        def age_session():
            sid=next(cookie.value for cookie in a.cookies if cookie.name=='STOCKSESSION')
            audit_env=dict(env,AUDIT_SESSION_ID=sid,AUDIT_SESSION_PATH=private)
            code="ini_set('session.save_path',getenv('AUDIT_SESSION_PATH'));session_name('STOCKSESSION');session_id(getenv('AUDIT_SESSION_ID'));session_start();$_SESSION['key_verified_at']=time()-600;$_SESSION['factor_verified_at']=time()-600;$_SESSION['duo_verified_at']=0;session_write_close();"
            subprocess.check_call(['php','-r',code],env=audit_env,stdout=subprocess.DEVNULL)
        age_session()
        security=a.req('security')[1];assert security['keyCount']==1 and security['keyReauthRequired'] and security['totpEnrollmentMfaRequired']
        assert a.req('totp-begin',{'currentPassword':PW})[0]==403
        status,res=a.req('totp-begin',{'currentPassword':PW,'code':'bad'});assert status==401 and 'code' not in res
        assert a.req('totp-begin',{'currentPassword':PW,'code':recovery[0]})[0]==200
        age_session();assert a.req('totp-begin',{'currentPassword':PW,'code':recovery[0]})[0]==401
        status,options=a.req('key-reauth-begin',{});assert status==200,options
        signed=fixture(op='get',**f,challenge=options['publicKey']['challenge'],origin=origin,rp='localhost',counter=1)
        status,res=a.req('key-reauth-finish',signed);assert status==200,res;a.csrf=res['csrf'];assert not res['security']['keyReauthRequired']
        assert a.req('key-reauth-finish',signed)[0]==401  # single-use challenge
        status,enrollment=a.req('totp-begin',{'currentPassword':PW});assert status==200,enrollment
        counter=int(time.time())//30
        status,res=a.req('totp-confirm',{'code':otp(enrollment['secret'],counter)});assert status==200,res;a.csrf=res['csrf']
        b=Client(port);assert b.login(factor='local',code=otp(enrollment['secret'],counter+1))[0]==200
        assert b.req('state')[0]==200
        print('PASS: HTTP zero/archives/__proto__ roundtrip, decimal fee, product/sum rejection, futures guard/catalog, session-expiry code, stale MFA blocks TOTP, one-time recovery, signed key step-up, challenge replay and existing login.')
    finally:server.terminate();server.wait(timeout=5)
