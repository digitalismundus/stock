"""Browser fixture: Strict cookie survives a cross-site Duo-like redirect, no production auth bypass.

Start this localhost-only fixture, open http://127.0.0.1:8082/seed,
click the external-origin link and its return link. Real callback JS must show PASS.
"""
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[1]/'public'
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def send(self,body,status=200,kind='text/html',cookie=False):
  self.send_response(status);self.send_header('Content-Type',kind+'; charset=utf-8');self.send_header('Cache-Control','no-store')
  if cookie:self.send_header('Set-Cookie','STRICT_DUO_FIXTURE=synthetic; Path=/; HttpOnly; SameSite=Strict')
  self.end_headers();self.wfile.write(body.encode() if isinstance(body,str) else body)
 def has_cookie(self):return 'STRICT_DUO_FIXTURE=synthetic' in self.headers.get('Cookie','')
 def do_GET(self):
  path=self.path.split('?')[0]
  if path=='/seed':self.send('<a href="http://localhost:8082/external">Otwórz drugi origin</a>',cookie=True)
  elif path=='/external':self.send('<a href="http://127.0.0.1:8082/duo-callback.html?state=fixture-state&duo_code=fixture-code">Wróć do STOCK</a>')
  elif path=='/api.php':self.send(json.dumps({'csrf':'fixture-csrf' if self.has_cookie() else 'missing-cookie'}),kind='application/json')
  elif path in ['/duo-callback.html','/duo-callback.js','/styles.css','/icon.svg']:
   kinds={'.html':'text/html','.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml'}
   self.send((ROOT/path[1:]).read_bytes(),kind=kinds[Path(path).suffix])
  else:self.send('Not found',404)
 def do_POST(self):
  data=json.loads(self.rfile.read(int(self.headers.get('Content-Length',0))))
  ok=self.has_cookie() and self.headers.get('X-CSRF-Token')=='fixture-csrf' and data=={'state':'fixture-state','duo_code':'fixture-code'}
  self.send(json.dumps({'error':'PASS: cookie SameSite=Strict i CSRF zachowane po powrocie z innego originu.' if ok else 'FAIL: cookie, CSRF or callback payload missing.'}),400,'application/json')
if __name__=='__main__':
 print('Browser callback fixture: http://127.0.0.1:8082/seed',flush=True)
 ThreadingHTTPServer(('127.0.0.1',8082),Handler).serve_forever()
