"""Isolated checks for the release probe and public directory rollback."""
import copy
import json
import os
from pathlib import Path
import socket
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import deploy_audit


class FakeFTP:
    def __init__(self, fail_restore=False):
        self.paths = {"/public_html": "new", "/public_html.backup-20261011T150000Z-aabbcc": "old"}
        self.fail_restore = fail_restore

    def __enter__(self):
        return self

    def __exit__(self, *_):
        pass

    def rename(self, source, destination):
        assert source in self.paths and destination not in self.paths
        if self.fail_restore and source.startswith("/public_html.backup-"):
            raise OSError("fixture restore failure")
        self.paths[destination] = self.paths.pop(source)


release = {"release": "20261011T150000Z-aabbcc", "backup": "/public_html.backup-20261011T150000Z-aabbcc"}
original_connect = deploy_audit.connect
try:
    ftp = FakeFTP()
    deploy_audit.connect = lambda: ftp
    failed = deploy_audit.rollback(release)
    assert ftp.paths == {"/public_html": "old", failed: "new"}
    ftp = FakeFTP(fail_restore=True)
    deploy_audit.connect = lambda: ftp
    try:
        deploy_audit.rollback(release)
        raise AssertionError("restore failure was ignored")
    except OSError:
        assert ftp.paths == {"/public_html": "new", release["backup"]: "old"}
finally:
    deploy_audit.connect = original_connect


with tempfile.TemporaryDirectory(prefix="stock-release-test-") as private:
    directory = Path(private)
    public = directory / "public_html"
    public.mkdir()
    storage = directory / "storage"
    storage.mkdir(mode=0o700)
    (directory / "config.php").write_text("<?php return ['storage'=>__DIR__.'/storage'];")
    state = {"version": 1, "settings": {"name": "Fixture", "feePercent": 0.29, "minFee": 0}, "assets": [], "ledger": [], "fx": {"PLN": {"rate": 1, "date": "2026-01-02", "source": "Fixture"}}, "retirement": []}
    database = storage / "portfolio.sqlite"
    with sqlite3.connect(database) as db:
        db.executescript("CREATE TABLE portfolio(id INTEGER PRIMARY KEY,revision INTEGER,body TEXT); CREATE TABLE account(id INTEGER PRIMARY KEY,username TEXT,hash TEXT); CREATE TABLE credentials(id TEXT PRIMARY KEY); CREATE TABLE duo_settings(id INTEGER PRIMARY KEY);")
        db.execute("INSERT INTO portfolio VALUES(1,7,?)", [json.dumps(state)])
        db.execute("INSERT INTO account VALUES(1,'fixture','fixture-hash')")
    token = "fixture-only-token"
    (public / "check.php").write_bytes(deploy_audit.probe_source(token, int(time.time()) + 300))
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(public)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f"http://127.0.0.1:{port}/check.php"

    def request(mode="before", header=token, method="POST"):
        req = Request(base, data=json.dumps({"mode": mode}).encode() if method == "POST" else None, headers={"Content-Type": "application/json", "X-Release-Check": header}, method=method)
        try:
            response = urlopen(req, timeout=5)
        except HTTPError as error:
            response = error
        with response:
            body = response.read()
            return response.status, json.loads(body) if body else None

    try:
        for _ in range(50):
            try:
                assert request(method="GET")[0] == 404
                break
            except URLError:
                time.sleep(0.05)
        assert request(header="wrong-fixture-token")[0] == 404
        code, before = request()
        assert code == 200 and before["backupCreated"] and before["portfolioCompatible"] and before["calculationCompatible"]
        backup = storage / "backups" / before["databaseBackup"]
        assert stat.S_IMODE(backup.stat().st_mode) == 0o600
        assert stat.S_IMODE(backup.parent.stat().st_mode) == 0o700
        with sqlite3.connect(backup) as db:
            assert db.execute("PRAGMA quick_check").fetchone()[0] == "ok"
            assert db.execute("SELECT revision,body FROM portfolio").fetchone() == (7, json.dumps(state))
        code, after = request("after")
        assert code == 200 and after["databaseIntegrity"]
        for field in ["portfolioFingerprint", "authFingerprint", "configFingerprint", "revision"]:
            assert before[field] == after[field]
        assert len(list(backup.parent.iterdir())) == 1
        bad = copy.deepcopy(state)
        bad["version"] = 2
        with sqlite3.connect(database) as db:
            db.execute("UPDATE portfolio SET body=?", [json.dumps(bad)])
        assert request()[0] == 500
        assert len(list(backup.parent.iterdir())) == 1
        (public / "expired.php").write_bytes(deploy_audit.probe_source(token, int(time.time()) - 1))
        base = f"http://127.0.0.1:{port}/expired.php"
        assert request()[0] == 404
    finally:
        server.terminate()
        server.wait(timeout=5)

print("PASS: protected expiring probe, compatible state, private intact SQLite backup, stable fingerprints, invalid state rejection and reversible public rollback.")
