"""Atomic FTPS release for the existing, scoped STOCK installation."""
from ftp_stock import connect, verify_account
from pathlib import Path
from datetime import datetime, timezone
import argparse
import hashlib
import io
import json
import secrets

ROOT = Path(__file__).resolve().parents[1]


def public_files(root=ROOT):
    public = root / 'public'
    files = []
    for path in sorted(public.rglob('*')):
        if path.is_symlink():
            raise RuntimeError('Deployment package contains a symlink')
        if path.is_file():
            if path.name in {'config.php', 'portfolio.sqlite'} or path.suffix in {'.sqlite', '.zip', '.pyc'}:
                raise RuntimeError('Private or unsupported file in public package')
            files.append(path)
    required = {'index.php', 'index.html', 'api.php', 'auth.php', 'validation.php', 'app.js', 'core.js', 'history.js', 'styles.css', '.htaccess'}
    if not required.issubset({p.relative_to(public).as_posix() for p in files}):
        raise RuntimeError('Incomplete public deployment package')
    return files


def send(ftp, remote, data):
    ftp.storbinary('STOR ' + remote, io.BytesIO(data))
    received = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, received.write)
    if hashlib.sha256(data).digest() != hashlib.sha256(received.getvalue()).digest():
        raise RuntimeError('FTP checksum mismatch: ' + remote)


def publish(ftp, root=ROOT):
    verify_account(ftp)
    files = public_files(root)
    private = root / 'private-deploy'
    private.mkdir(mode=0o700, exist_ok=True)
    config = private / 'config.php'
    # Actions uses the existing host configuration; it never needs its secrets.
    if config.is_file():
        prior = io.BytesIO()
        ftp.retrbinary('RETR /config.php', prior.write)
        if prior.getvalue() != config.read_bytes():
            raise RuntimeError('Existing remote configuration differs; no credentials were replaced.')
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3)
    stage = '/public_html.release-' + stamp
    backup = '/public_html.backup-' + stamp
    ftp.mkd(stage)
    ftp.sendcmd('SITE CHMOD 755 ' + stage)
    for directory in sorted((p for p in (root / 'public').rglob('*') if p.is_dir()), key=lambda p: len(p.parts)):
        remote = stage + '/' + directory.relative_to(root / 'public').as_posix()
        ftp.mkd(remote)
        ftp.sendcmd('SITE CHMOD 755 ' + remote)
    manifest = {}
    for file in files:
        relative = file.relative_to(root / 'public').as_posix()
        data = file.read_bytes()
        send(ftp, stage + '/' + relative, data)
        ftp.sendcmd('SITE CHMOD 644 ' + stage + '/' + relative)
        manifest[relative] = hashlib.sha256(data).hexdigest()
    report = {'domain': 'stock.mateuszrak.com', 'release': stamp, 'backup': backup, 'files': manifest}
    pending = private / 'release.pending.json'
    pending.write_text(json.dumps(report, indent=2) + '\n')
    print('Verified', len(manifest), 'public files by SHA-256.', flush=True)
    ftp.rename('/public_html', backup)
    try:
        ftp.rename(stage, '/public_html')
    except BaseException:
        ftp.rename(backup, '/public_html')
        raise
    try:
        pending.replace(private / 'release.json')
    except BaseException:
        ftp.rename('/public_html', stage)
        ftp.rename(backup, '/public_html')
        raise
    print('Release activated:', stamp, '; previous public directory retained:', backup, flush=True)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Validate public files without connecting')
    args = parser.parse_args()
    if args.check:
        print('Validated', len(public_files()), 'public files; private data is excluded.')
        return
    with connect() as ftp:
        publish(ftp)


if __name__ == '__main__':
    main()
