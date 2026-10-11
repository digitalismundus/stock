"""Read-only HTTPS smoke check for the public STOCK release; never logs in.

Run after publication: python3 tests/public_release_check.py
Optional: --output test-results/public-release.json
Only public HTML/assets and anonymous session/401 responses are read. Cookies,
CSRF tokens, and response bodies are never included in the report.
"""
import argparse
import hashlib
import http.cookiejar
import json
from html.parser import HTMLParser
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urljoin, urlparse
from urllib.request import HTTPCookieProcessor, Request, build_opener

ROOT = Path(__file__).resolve().parents[1]
BASE = "https://stock.mateuszrak.com/"
MAX_BYTES = 2_000_000


class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.assets = []
        self.scripts = []

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "script" and attributes.get("src"):
            self.assets.append(attributes["src"])
            self.scripts.append(attributes["src"])
        if tag == "link" and attributes.get("rel") == "stylesheet":
            self.assets.append(attributes["href"])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    jar = http.cookiejar.CookieJar()
    opener = build_opener(HTTPCookieProcessor(jar))
    checks = []

    def check(name, passed, detail=None):
        row = {"check": name, "passed": bool(passed)}
        if detail is not None:
            row["detail"] = detail
        checks.append(row)

    def fetch(target, expected_status=200):
        request = Request(target, headers={"User-Agent": "STOCK-Public-Release-Check/1.0", "Cache-Control": "no-cache"})
        try:
            response = opener.open(request, timeout=20)
        except HTTPError as error:
            response = error
        with response:
            status = response.code
            # In particular, never read a portfolio body when anonymous state
            # unexpectedly returns 200 instead of the required 401.
            body = response.read(MAX_BYTES + 1) if status == expected_status else b""
            if len(body) > MAX_BYTES:
                raise RuntimeError("Public response exceeded the size limit.")
            return status, response.headers, body, response.geturl()

    def security_headers(name, headers):
        check(name + ": nosniff", headers.get("X-Content-Type-Options", "").lower() == "nosniff")
        check(name + ": frame protection", headers.get("X-Frame-Options", "").upper() == "DENY")
        check(name + ": referrer", headers.get("Referrer-Policy", "") == "same-origin")
        check(name + ": no indexing", "noindex" in headers.get("X-Robots-Tag", "").lower())
        check(name + ": HSTS", "max-age=31536000" in headers.get("Strict-Transport-Security", ""))
        directives = {}
        for directive in headers.get("Content-Security-Policy", "").split(";"):
            tokens = directive.strip().split()
            if tokens:
                directives[tokens[0]] = set(tokens[1:])
        expected = {"default-src": {"'self'"}, "script-src": {"'self'"}, "style-src": {"'self'", "'unsafe-inline'"}, "img-src": {"'self'", "data:"}, "connect-src": {"'self'", "https://api.nbp.pl"}, "base-uri": {"'self'"}, "frame-ancestors": {"'none'"}, "form-action": {"'self'"}}
        check(name + ": CSP", all(directives.get(key) == value for key, value in expected.items()))

    try:
        status, _, _, final_url = fetch("http://stock.mateuszrak.com/")
        check("HTTP redirects to canonical HTTPS", status == 200 and final_url == BASE)
        local_html = (ROOT / "public/index.html").read_bytes()
        expected_assets = Assets()
        expected_assets.feed(local_html.decode("utf-8"))
        for name, target in [("root HTML", BASE), ("index.php HTML", urljoin(BASE, "index.php")), ("index.html", urljoin(BASE, "index.html"))]:
            status, headers, body, final_url = fetch(target)
            check(name + ": HTTPS and 200", status == 200 and final_url.startswith(BASE))
            check(name + ": deployed bytes", hashlib.sha256(body).digest() == hashlib.sha256(local_html).digest())
            security_headers(name, headers)
            if name != "index.html":
                check(name + ": no-store", "no-store" in headers.get("Cache-Control", "").lower())
            actual_assets = Assets()
            actual_assets.feed(body.decode("utf-8"))
            check(name + ": asset versions and order", actual_assets.assets == expected_assets.assets and actual_assets.scripts == expected_assets.scripts)
        for asset in expected_assets.assets + ["icon.svg", "manifest.webmanifest", "duo-callback.html", "duo-callback.js"]:
            relative = urlparse(asset).path
            target = urljoin(BASE, asset)
            if not target.startswith(BASE) or relative.startswith("/") or ".." in Path(relative).parts:
                raise RuntimeError("Unexpected public asset path in the local shell.")
            status, headers, body, _ = fetch(target)
            check(relative + ": deployed bytes", status == 200 and hashlib.sha256(body).digest() == hashlib.sha256((ROOT / "public" / relative).read_bytes()).digest())
            content_type = headers.get_content_type()
            if relative.endswith(".js"):
                check(relative + ": JavaScript MIME", content_type in {"application/javascript", "text/javascript", "application/x-javascript"})
            elif relative.endswith(".css"):
                check(relative + ": CSS MIME", content_type == "text/css")
        status, headers, body, _ = fetch(urljoin(BASE, "api.php?action=session"))
        check("anonymous session: 200 JSON", status == 200 and headers.get_content_type() == "application/json")
        session = json.loads(body)
        anonymous = session.get("configured") is True and session.get("authenticated") is False and session.get("development") is False and session.get("security") is None
        check("anonymous session: configured, private, no development bypass", anonymous)
        check("anonymous session: CSRF token available", isinstance(session.get("csrf"), str) and bool(session["csrf"]))
        check("anonymous session: no-store", "no-store" in headers.get("Cache-Control", "").lower())
        security_headers("anonymous session", headers)
        cookie_headers = headers.get_all("Set-Cookie", [])
        cookies = SimpleCookie()
        for header in cookie_headers:
            cookies.load(header)
        cookie = cookies.get("STOCKSESSION")
        check("session cookie: Secure, HttpOnly, SameSite Strict, root path", cookie is not None and bool(cookie["secure"]) and bool(cookie["httponly"]) and cookie["samesite"].lower() == "strict" and cookie["path"] == "/")
        if anonymous:
            status, headers, body, _ = fetch(urljoin(BASE, "api.php?action=state"), 401)
            check("anonymous portfolio: 401", status == 401)
            check("anonymous portfolio: no-store", "no-store" in headers.get("Cache-Control", "").lower())
            security_headers("anonymous portfolio", headers)
            error = json.loads(body) if status == 401 else {}
            check("anonymous portfolio: recoverable session code and no state", error.get("code") == "session_expired" and "state" not in error)
    except Exception as error:
        check("public verification completed", False, type(error).__name__)
    report = {"domain": "stock.mateuszrak.com", "passed": bool(checks) and all(row["passed"] for row in checks), "checks": checks}
    text = json.dumps(report, indent=2, ensure_ascii=False)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(text + "\n", encoding="utf-8")
    print(text)
    return 0 if report["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
