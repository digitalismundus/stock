"""Isolated integration test: real PHP HTTP API, private temporary SQLite database."""
import base64, hashlib, hmac, struct, sqlite3, copy, http.cookiejar, json, os, pathlib, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PASSWORD = 'test-only-password-492!'

def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]

class Client:
    def __init__(self, port):
        self.base = f'http://127.0.0.1:{port}/api.php?action='
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''
    def req(self, action, method='GET', data=None, token=True):
        headers = {'Content-Type': 'application/json'}
        if token: headers['X-CSRF-Token'] = self.csrf
        req = urllib.request.Request(self.base+action, data=json.dumps(data).encode() if data is not None else None, headers=headers, method=method)
        try:
            with self.http.open(req, timeout=5) as r: return r.status, json.load(r), dict(r.headers)
        except urllib.error.HTTPError as e: return e.code, json.load(e), dict(e.headers)
    def login(self):
        code, body, headers = self.req('session')
        assert code == 200 and not body['authenticated']
        self.csrf = body['csrf']
        code, body, headers = self.req('login', 'POST', {'username':'mateusz','password':PASSWORD})
        assert code == 200, body
        assert 'HttpOnly' in headers.get('Set-Cookie','') and 'SameSite=Strict' in headers.get('Set-Cookie','')
        self.csrf = body['csrf']

with tempfile.TemporaryDirectory(prefix='stock-api-test-') as private:
    port = free_port()
    env = dict(os.environ, PORTFOLIO_PASSWORD=PASSWORD, PORTFOLIO_STORAGE=private, PORTFOLIO_SECURE_COOKIE='0', PORTFOLIO_DEV='0', PORTFOLIO_AUTH_KEY=base64.b64encode(bytes(range(32))).decode())
    server = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        a = Client(port)
        for _ in range(50):
            try: a.req('session'); break
            except urllib.error.URLError: time.sleep(.05)
        assert a.req('state')[0] == 401
        assert a.req('fx-history&currency=USD&date=2026-01-02')[0]==401
        assert a.req('recommendations&symbol=CDR.WA')[0] == 401
        assert a.req('login','POST',{'username':'mateusz','password':PASSWORD},token=False)[0] == 403
        a.login(); b = Client(port); b.login()
        code, original, _ = a.req('state'); assert code == 200 and original['revision'] == 0
        fixture={'symbol':'TEST.WA','company':'Fixture','broker':'DM BOŚ','isin':'PLTEST000001','date':'2026-01-02','rating':'Trzymaj','target':12.5,'status':'published'}
        with sqlite3.connect(pathlib.Path(private,'portfolio.sqlite')) as cache_db:
            for key, body in [('recommendations-bossa-v1',{'rows':[fixture],'fetchedAt':'2026-01-02T12:00:00+01:00'}),('recommendations-bdm-v1',{'rows':[],'fetchedAt':'2026-01-02T12:00:00+01:00'}),('recommendations-history-bossa-v1-PLTEST000001',{'rows':[]})]:
                cache_db.execute('INSERT INTO cache VALUES(?,?,?)',(key,int(time.time())+3600,json.dumps(body)))
        result=a.req('recommendations&symbol=TEST.WA');assert result[0]==200 and result[1]['rows']==[fixture]
        result=a.req('recommendations&symbol=MSFT');assert result[0]==200 and result[1]['rows']==[] and result[1]['marketRows']==[fixture]
        assert a.req('recommendations&symbol=TEST.WA&type=etf')[1]['rows']==[]
        for query in ['symbol[]=TEST.WA','symbol=https://example.com','symbol=TEST.WA&type[]=stock','symbol=TEST.WA&name[]=Fixture']:
            assert a.req('recommendations&'+query)[0]==400
        ref={'currency':'USD','requestedDate':'2026-01-04','date':'2026-01-02','rate':3.6,'table':'A','number':'001/A/NBP/2026','source':'NBP · tabela A','sourceUrl':'https://api.nbp.pl/api/exchangerates/rates/a/usd/2026-01-02/?format=json'}
        with sqlite3.connect(pathlib.Path(private,'portfolio.sqlite')) as cache_db:
            cache_db.execute('INSERT INTO cache VALUES(?,?,?)',('fx-history-v1-USD-2026-01-04',int(time.time())+3600,json.dumps(ref)))
        assert a.req('fx-history&currency=USD&date=2026-01-04')[:2]==(200,ref)
        for query in ['currency[]=USD&date=2026-01-04','currency=USD&date[]=2026-01-04','currency=PLN&date=2026-01-04','currency=USD&date=2026-02-30','currency=USD&date=2002-01-01','currency=USD&date=9999-01-01']:
            assert a.req('fx-history&'+query)[0]==400
        assert a.req('state')[1]==original, 'Read-only sources mutated private portfolio' 

        s = original['state']
        s['assets'] = [{'id':'a','symbol':'TEST','name':'Integration Test','type':'stock','market':'GPW','currency':'PLN','quote':{'price':20,'date':'2026-01-02','source':'Test','history':[]}}]
        s['ledger'] = [{'id':'d','type':'deposit','date':'2026-01-02','currency':'PLN','amount':1000,'fee':0,'fx':1},{'id':'b','type':'buy','date':'2026-01-02','assetId':'a','currency':'PLN','quantity':10,'price':10,'fee':1,'fx':1}]
        s['ledger'].append({'id':'usd','type':'deposit','date':'2026-01-04','currency':'USD','amount':100,'fx':3.6,'fee':0,'fxReference':ref})
        s['ledger'][1].update({'feeMode':'percent','feePercent':1,'feeMinimum':0})
        assert a.req('state','PUT',{'state':s,'revision':0})[:2] == (200,{'revision':1})
        assert b.req('state','PUT',{'state':s,'revision':0})[0] == 409
        assert b.req('state')[1]['state'] == s
        assert a.req('state','PUT',{'state':s,'revision':1},token=False)[0] == 403
        broken=copy.deepcopy(s)
        broken['ledger'].append({'id':'sell','type':'sell','date':'2026-01-03','assetId':'a','currency':'PLN','quantity':11,'price':10,'fee':0,'fx':1})
        assert a.req('state','PUT',{'state':broken,'revision':1})[0] == 400
        assert a.req('state')[1]['revision'] == 1
        broken=copy.deepcopy(s);broken['fx']['PLN']['rate']=2
        assert a.req('state','PUT',{'state':broken,'revision':1})[0] == 400
        for changes in [{'feeMode':'invalid'},{'feePercent':-1},{'feePercent':9},{'feeMinimum':100},{'quantity':'bad'},{'price':[]}]:
            broken=copy.deepcopy(s);broken['ledger'][1].update(changes)
            assert a.req('state','PUT',{'state':broken,'revision':1})[0]==400
        for change in [{'rate':4},{'date':'2026-01-05'},{'currency':'EUR'},{'number':'bad'},{'sourceUrl':'https://example.com'}]:
            broken=copy.deepcopy(s);broken['ledger'][2]['fxReference'].update(change)
            assert a.req('state','PUT',{'state':broken,'revision':1})[0]==400
        assert a.req('state')[1]['state']==s
        assert a.req('quote&symbol=https://example.com')[0] == 400
        assert a.req('security')[1]['username']=='mateusz'
        wronguser=Client(port);wronguser.csrf=wronguser.req('session')[1]['csrf']
        assert wronguser.req('login','POST',{'username':'stranger','password':PASSWORD})[0]==401
        def otp(secret, counter=None):
            c=int(time.time())//30 if counter is None else counter
            raw=hmac.new(base64.b32decode(secret),struct.pack('>Q',c),hashlib.sha1).digest();off=raw[-1]&15
            return str((struct.unpack('>I',raw[off:off+4])[0]&0x7fffffff)%1000000).zfill(6)
        code, enrollment, _=a.req('totp-begin','POST',{'currentPassword':PASSWORD});assert code==200,enrollment
        assert a.req('security')[1]['totpEnabled']==False
        # Validate a future counter within the permitted clock-skew window.
        counter=int(time.time())//30+1
        code, enabled, _=a.req('totp-confirm','POST',{'code':otp(enrollment['secret'],counter)});assert code==200,enabled
        a.csrf=enabled['csrf'];assert enabled['security']['totpEnabled']
        recoveries=enabled['recoveryCodes'];assert len(recoveries)==10 and len(set(recoveries))==10
        assert b.req('state')[0]==401 # pre-enrollment session revoked
        replay=Client(port);replay.csrf=replay.req('session')[1]['csrf']
        assert replay.req('login','POST',{'username':'mateusz','password':PASSWORD,'code':otp(enrollment['secret'],counter)})[0]==401
        code, logged, _=replay.req('login','POST',{'username':'mateusz','password':PASSWORD,'code':recoveries[0]});assert code==200,logged
        replay.csrf=logged['csrf']
        reused=Client(port);reused.csrf=reused.req('session')[1]['csrf']
        assert reused.req('login','POST',{'username':'mateusz','password':PASSWORD,'code':recoveries[0]})[0]==401
        db=sqlite3.connect(pathlib.Path(private,'portfolio.sqlite'))
        row=db.execute('SELECT secret,recovery FROM account').fetchone()
        assert enrollment['secret'] not in row[0] and recoveries[0].replace('-','') not in row[1]
        assert a.req('password','POST',{'currentPassword':PASSWORD,'newPassword':PASSWORD+'new','code':'000'})[0]==401
        code, changed, _=a.req('password','POST',{'currentPassword':PASSWORD,'newPassword':PASSWORD+'new','code':recoveries[1]});assert code==200,changed
        a.csrf=changed['csrf'];assert replay.req('state')[0]==401
        code, disabled, _=a.req('totp-disable','POST',{'currentPassword':PASSWORD+'new','code':recoveries[2]});assert code==200,disabled
        a.csrf=disabled['csrf'];assert disabled['security']['totpEnabled']==False
        # Force the initial-password state and prove that data stays inaccessible.
        db.execute('UPDATE account SET must_change=1');db.commit()
        assert a.req('state')[0]==403
        code, changed, _=a.req('password','POST',{'currentPassword':PASSWORD+'new','newPassword':PASSWORD+'final'});assert code==200,changed
        a.csrf=changed['csrf'];assert a.req('state')[0]==200
        assert a.req('logout','POST',{})[0] == 200
        assert a.req('state')[0] == 401
        bad=Client(port);bad.csrf=bad.req('session')[1]['csrf']
        for _ in range(5): assert bad.req('login','POST',{'password':'wrong'})[0] == 401
        assert bad.req('login','POST',{'password':'wrong'})[0] == 429
        assert pathlib.Path(private,'portfolio.sqlite').exists()
        print('PASS: auth, CSRF, secure session flags, shared persistence, revision conflicts, financial validation, logout, rate limiting, username, TOTP enrollment, replay rejection, recovery codes, secret encryption, session revocation, password change.')
    finally:
        server.terminate();server.wait(timeout=5)
