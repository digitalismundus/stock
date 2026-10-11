import ftplib,ssl,re,os,pathlib

def verify_account(ftp):
 names={pathlib.PurePosixPath(x.rstrip('/')).name for x in ftp.nlst('/')}
 if not {'public_html','config.php','storage'}.issubset(names) or names.intersection({'rescuemed-private','ekoprof-private'}):
  raise RuntimeError('Use the dedicated STOCK FTP account with existing private configuration and storage.')

class FTPS(ftplib.FTP_TLS):
 def ntransfercmd(self,cmd,rest=None):
  conn,size=ftplib.FTP.ntransfercmd(self,cmd,rest)
  if self._prot_p:
   try: conn=self.context.wrap_socket(conn,server_hostname=self.host,session=self.sock.session)
   except: conn.close();raise
  return conn,size

def connect():
 host=os.environ.get('STOCK_FTP_HOST','makarecki.ftp.dhosting.pl')
 user=os.environ.get('STOCK_FTP_USER','')
 password=os.environ.get('STOCK_FTP_PASSWORD','')
 if not user or not password:
  source=pathlib.Path('/private/tmp/stock-host-access.txt')
  if not source.exists(): raise RuntimeError('Set STOCK_FTP_USER and STOCK_FTP_PASSWORD in a private environment.')
  text=source.read_text()
  host=re.search(r'listitem: ([\w.-]+\.ftp\.dhosting\.pl)',text).group(1)
  user=re.search(r'listitem: Login\n\s*- text: (\S+)',text).group(1)
  password=re.search(r'listitem: Hasło\n\s*- text: (\S+)',text).group(1)
 ftp=FTPS(context=ssl.create_default_context(),timeout=30)
 try:
  ftp.connect(host,int(os.environ.get('STOCK_FTP_PORT','21')));ftp.login(user,password);ftp.prot_p()
  verify_account(ftp)
 except BaseException:
  ftp.close()
  raise
 return ftp
if __name__=='__main__':
 with connect() as f:
  print('FTP root:',f.pwd());f.retrlines('LIST')
