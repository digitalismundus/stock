"""Check cloud publication without copying private configuration into Actions."""
from pathlib import Path
import contextlib
import io
import json
import sys
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from deploy_stock import publish, public_files
from ftp_stock import verify_account


class Remote:
    def __init__(self, failure=None, wrong_account=None):
        self.files = {'/config.php': b'private-config', '/storage/portfolio.sqlite': b'private-data', '/public_html/index.php': b'old-public'}
        self.directories = {'/', '/public_html', '/storage'}
        self.failure = failure
        self.wrong_account = wrong_account
        self.renames = []
        self.uploads = []

    def nlst(self, path):
        return ['/public_html', '/config.php', '/storage'] + ([self.wrong_account] if self.wrong_account else [])

    def mkd(self, path):
        assert path not in self.directories
        self.directories.add(path)

    def sendcmd(self, command):
        assert command.startswith('SITE CHMOD ')

    def storbinary(self, command, stream):
        path = command.removeprefix('STOR ')
        assert path.startswith('/public_html.release-')
        if self.failure == 'upload':
            raise OSError('fixture upload failure')
        self.files[path] = stream.read()
        self.uploads.append(path)

    def retrbinary(self, command, writer):
        path = command.removeprefix('RETR ')
        data = self.files[path]
        writer(data + b'corrupt' if self.failure == 'checksum' and path != '/config.php' else data)

    def rename(self, source, destination):
        self.renames.append((source, destination))
        if self.failure == 'activate' and source.startswith('/public_html.release-'):
            raise OSError('fixture activation failure')
        assert source in self.directories and destination not in self.directories
        self.directories = {destination + p[len(source):] if p == source or p.startswith(source + '/') else p for p in self.directories}
        self.files = {destination + p[len(source):] if p.startswith(source + '/') else p: value for p, value in self.files.items()}


def fixture(root):
    public = root / 'public'
    public.mkdir()
    for name in ['index.php', 'index.html', 'api.php', 'auth.php', 'validation.php', 'app.js', 'core.js', 'history.js', 'styles.css', '.htaccess']:
        (public / name).write_text('fixture ' + name)
    (public / 'nested').mkdir()
    (public / 'nested/file.js').write_text('fixture nested')


for failure in [None, 'upload', 'checksum', 'activate', 'metadata']:
    with tempfile.TemporaryDirectory(prefix='stock-cloud-publish-') as tmp:
        root = Path(tmp)
        fixture(root)
        remote = Remote(failure)
        with contextlib.redirect_stdout(io.StringIO()):
            try:
                with patch.object(Path, 'replace', side_effect=OSError('fixture metadata failure')) if failure == 'metadata' else contextlib.nullcontext():
                    report = publish(remote, root)
            except (OSError, RuntimeError):
                assert failure is not None
            else:
                assert failure is None
                assert json.loads((root / 'private-deploy/release.json').read_text()) == report
                assert remote.files[report['backup'] + '/index.php'] == b'old-public'
                assert remote.files['/public_html/index.php'] == b'fixture index.php'
        assert remote.files['/config.php'] == b'private-config'
        assert remote.files['/storage/portfolio.sqlite'] == b'private-data'
        if failure:
            assert remote.files['/public_html/index.php'] == b'old-public'
        if failure in {'upload', 'checksum'}:
            assert remote.renames == []

for other in ['/rescuemed-private', '/ekoprof-private']:
    remote = Remote(wrong_account=other)
    try:
        verify_account(remote)
        raise AssertionError('Wrong site account accepted')
    except RuntimeError:
        assert remote.uploads == [] and remote.renames == []

with tempfile.TemporaryDirectory(prefix='stock-config-check-') as tmp:
    root = Path(tmp)
    fixture(root)
    (root / 'private-deploy').mkdir()
    (root / 'private-deploy/config.php').write_bytes(b'different-private-config')
    remote = Remote()
    try:
        publish(remote, root)
        raise AssertionError('Mismatched configuration accepted')
    except RuntimeError:
        assert remote.uploads == [] and remote.renames == []
    (root / 'public/config.php').write_text('fixture private')
    try:
        public_files(root)
        raise AssertionError('Private file accepted')
    except RuntimeError:
        pass

print('PASS: cloud deployment, unchanged private files, failed upload/checksum/activation/metadata recovery, account isolation and private-package rejection.')
