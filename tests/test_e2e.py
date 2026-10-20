"""End-to-end tests: a real PHP server, a real local app, and real tunnel.py clients.

    python3 -m unittest discover -s tests -v
    TUNNEL_E2E_STACK=apache python3 -m unittest discover -s tests -v
    TUNNEL_E2E_STACK=nginx python3 -m unittest discover -s tests -v

The first runs the server on PHP's built-in web server. The others run it the way hosting
does, on PHP-FPM behind Apache (honoring the app's .htaccess) or nginx, configured like the
files in deploy/; they need php-fpm and httpd or nginx. All need php 8.1+ on PATH and use
only the Python standard library.
"""

from __future__ import annotations

import base64
import email.parser
import email.policy
import gzip
import hashlib
import http.client
import http.server
import json
import os
import shutil
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.parse
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CLIENT = ROOT / "client" / "tunnel.py"
SERVER = ROOT / "server" / "index.php"
STACK = os.environ.get("TUNNEL_E2E_STACK", "builtin")
TOKEN = "e2e-token-0123456789abcdef"
BASE_DOMAIN = "tunnel.test"
RESPONSE_TIMEOUT = 5
MAX_UPLOAD_MB = 8


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def pattern(size: int) -> bytes:
    """Deterministic bytes covering every byte value."""
    block = bytes(range(256)) * 4096
    return (block * (size // len(block) + 1))[:size]


# ----- The local app behind the tunnel -------------------------------------------------------


class LocalApp(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args) -> None:
        pass

    def do_GET(self) -> None:
        self.route()

    do_POST = do_PUT = do_PATCH = do_DELETE = do_OPTIONS = do_HEAD = do_GET

    def route(self) -> None:
        url = urllib.parse.urlsplit(self.path)
        query = dict(urllib.parse.parse_qsl(url.query))
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""
        path = url.path

        if path.startswith("/echo"):
            self.reply(200, json.dumps({
                "method": self.command,
                "path": self.path,
                "headers": self.headers.items(),
                "body_len": len(body),
                "body_sha256": hashlib.sha256(body).hexdigest(),
                "body_b64": base64.b64encode(body).decode() if len(body) < 65536 else None,
            }).encode(), [("Content-Type", "application/json")])
        elif path == "/download":
            self.reply(200, pattern(int(query["size"])), [("Content-Type", "application/octet-stream")])
        elif path == "/stream":
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Transfer-Encoding", "chunked")
            self.end_headers()
            for index, word in enumerate((b"first\n", b"second\n", b"third\n")):
                if index:
                    time.sleep(0.6)
                self.wfile.write(b"%x\r\n%s\r\n" % (len(word), word))
                self.wfile.flush()
            self.wfile.write(b"0\r\n\r\n")
        elif path == "/redirect":
            self.reply(302, b"", [("Location", "/echo?from=redirect")])
        elif path == "/absolute-redirect":
            self.reply(302, b"", [("Location", f"http://localhost:{self.server.server_port}/echo?from=absolute")])
        elif path == "/cookies":
            self.reply(200, b"ok", [
                ("Set-Cookie", "first=1; Path=/; HttpOnly"),
                ("Set-Cookie", "second=2; Domain=localhost; Path=/admin"),
            ])
        elif path == "/gzip":
            self.reply(200, gzip.compress(b"compressed hello" * 100, mtime=0),
                       [("Content-Encoding", "gzip"), ("Content-Type", "text/plain")])
        elif path.startswith("/status/"):
            code = int(path.rsplit("/", 1)[1])
            self.reply(code, b"" if code in (204, 304) else b"teapot")
        elif path == "/slow":
            time.sleep(float(query["seconds"]))
            self.reply(200, b"finally")
        elif path == "/utf8-header":
            self.reply(200, b"ok")
        else:
            self.reply(404, b"not found")

    def send_header(self, keyword: str, value: str) -> None:
        if keyword == "Content-Length" and self.path == "/utf8-header":
            # BaseHTTPRequestHandler only writes Latin-1 headers; add raw UTF-8 bytes by hand.
            self._headers_buffer.append("X-Name: Zoë ✓\r\n".encode())
        super().send_header(keyword, value)

    def reply(self, status: int, body: bytes, headers: list | None = None) -> None:
        self.send_response(status)
        for name, value in headers or []:
            self.send_header(name, value)
        if status not in (204, 304):
            self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD" and status not in (204, 304):
            self.wfile.write(body)


class LocalServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, request, client_address) -> None:
        # Visitors who leave early (as some tests do on purpose) break the pipe; that is expected.
        if not isinstance(sys.exc_info()[1], ConnectionError):
            super().handle_error(request, client_address)

    def stop(self) -> None:
        self.shutdown()
        self.server_close()


# ----- Processes -----------------------------------------------------------------------------


class PhpServer:
    def __init__(self, workdir: Path, config: Path, post_data_reading: bool = False):
        self.port = free_port()
        self.log = open(workdir / f"php-{self.port}.log", "w")  # noqa: SIM115 - closed in stop()
        env = dict(os.environ, TUNNEL_CONFIG=str(config), PHP_CLI_SERVER_WORKERS="32")
        self.process = subprocess.Popen(
            ["php", "-d", f"enable_post_data_reading={int(post_data_reading)}", "-d", "display_errors=stderr",
             "-S", f"127.0.0.1:{self.port}", str(SERVER)],
            env=env, stdout=self.log, stderr=subprocess.STDOUT,
        )
        wait_for_port(self.port, "php -S")

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def stop(self) -> None:
        self.process.terminate()
        self.process.wait(5)
        self.log.close()


class WebStack:
    """PHP-FPM behind Apache or nginx, configured with the directives from deploy/apache.conf
    and deploy/nginx.conf. Apache also applies the app's .htaccess; both apply its .user.ini."""

    APACHE_MODULES = ("mpm_event", "authz_core", "unixd", "dir", "mime", "log_config", "rewrite", "proxy",
                      "proxy_fcgi", "env", "setenvif", "headers", "deflate", "filter", "alias")

    def __init__(self, workdir: Path, config: Path, server: str):
        self.port = free_port()
        self.log = open(workdir / "stack.log", "w")  # noqa: SIM115 - closed in stop()
        fpm_socket = workdir / "fpm.sock"
        (workdir / "fpm.conf").write_text(f"""\
[global]
error_log = {workdir}/fpm.log
daemonize = no

[www]
listen = {fpm_socket}
pm = static
pm.max_children = 32
env[TUNNEL_CONFIG] = {config}
catch_workers_output = yes
php_admin_flag[log_errors] = on
""")
        fpm = shutil.which("php-fpm") or "/opt/homebrew/opt/php/sbin/php-fpm"
        self.fpm = subprocess.Popen([fpm, "--nodaemonize", "--fpm-config", str(workdir / "fpm.conf")],
                                    stdout=self.log, stderr=subprocess.STDOUT)
        if server == "apache":
            command = ["httpd", "-f", str(self._apache_conf(workdir, fpm_socket)), "-DFOREGROUND"]
        else:
            command = ["nginx", "-c", str(self._nginx_conf(workdir, fpm_socket)), "-g", "daemon off;"]
        self.web = subprocess.Popen(command, stdout=self.log, stderr=subprocess.STDOUT)
        try:
            wait_for_port(self.port, server)
        except RuntimeError:
            self.stop()
            raise RuntimeError(f"{server} or PHP-FPM did not start; see {workdir}/stack.log") from None

    def _apache_conf(self, workdir: Path, fpm_socket: Path) -> Path:
        docroot = SERVER.parent
        modules = "\n".join(f"LoadModule {m}_module libexec/apache2/mod_{m}.so" for m in self.APACHE_MODULES)
        path = workdir / "httpd.conf"
        path.write_text(f"""\
ServerRoot "/usr"
Listen 127.0.0.1:{self.port}
ServerName 127.0.0.1
PidFile "{workdir}/httpd.pid"
DefaultRuntimeDir "{workdir}"
ErrorLog "{workdir}/web-error.log"
LogLevel warn
{modules}
TypesConfig /private/etc/apache2/mime.types
DocumentRoot "{docroot}"
<Directory "{docroot}">
    AllowOverride All
    Require all granted
</Directory>
<FilesMatch "\\.php$">
    SetHandler "proxy:unix:{fpm_socket}|fcgi://localhost/"
</FilesMatch>
<Proxy "fcgi://localhost/">
    ProxySet flushpackets=on timeout=300
</Proxy>
""")
        return path

    def _nginx_conf(self, workdir: Path, fpm_socket: Path) -> Path:
        version = subprocess.run(["nginx", "-V"], capture_output=True, text=True).stderr
        conf_dir = Path(version.split("--conf-path=")[1].split()[0]).parent
        temp = {name: workdir / f"nginx-{name}" for name in ("client_body", "fastcgi", "proxy", "uwsgi", "scgi")}
        path = workdir / "nginx.conf"
        path.write_text(f"""\
worker_processes 2;
pid {workdir}/nginx.pid;
error_log {workdir}/web-error.log warn;
events {{ worker_connections 512; }}
http {{
    access_log off;
{"".join(f"    {name}_temp_path {directory};{chr(10)}" for name, directory in temp.items())}
    server {{
        listen 127.0.0.1:{self.port};
        server_name _;
        root {SERVER.parent};
        client_max_body_size 0;
        client_body_buffer_size 1m;
        location / {{
            include {conf_dir}/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param HTTP_PROXY "";
            fastcgi_buffering off;
            fastcgi_read_timeout 300s;
            fastcgi_pass unix:{fpm_socket};
        }}
    }}
}}
""")
        return path

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def stop(self) -> None:
        # PHP-FPM first, so the web server's workers are not left waiting on long polls.
        for process in (self.fpm, self.web):
            process.terminate()
            try:
                process.wait(10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(5)
        self.log.close()


def wait_for_port(port: int, name: str) -> None:
    deadline = time.time() + 10
    while time.time() < deadline:
        try:
            socket.create_connection(("127.0.0.1", port), timeout=1).close()
            return
        except OSError:
            time.sleep(0.05)
    raise RuntimeError(f"{name} did not start")


class ClientProcess:
    def __init__(self, workdir: Path, server: PhpServer | WebStack, *args: str):
        self.output = workdir / f"client-{time.monotonic_ns()}.log"
        self._file = open(self.output, "w")  # noqa: SIM115 - closed in stop()
        env = dict(os.environ, XDG_CONFIG_HOME=str(workdir / "client-config"), NO_COLOR="1")
        self.process = subprocess.Popen(
            [sys.executable, str(CLIENT), "--server", server.url, "--token", TOKEN, *args],
            env=env, stdout=self._file, stderr=subprocess.STDOUT,
        )

    def text(self) -> str:
        return self.output.read_text()

    def wait_for_output(self, needle: str, timeout: float = 15) -> str:
        deadline = time.time() + timeout
        while time.time() < deadline:
            if needle in self.text():
                return self.text()
            time.sleep(0.05)
        raise AssertionError(f"client never printed {needle!r}; output:\n{self.text()}")

    def stop(self, sig: int = signal.SIGINT) -> int:
        if self.process.poll() is None:
            self.process.send_signal(sig)
        code = self.process.wait(10)
        self._file.close()
        return code


# ----- Test fixture --------------------------------------------------------------------------

WORKDIR: Path
PHP: PhpServer | WebStack
LOCAL: LocalServer
CLIENTS: list[ClientProcess] = []


def setUpModule() -> None:
    global WORKDIR, PHP, LOCAL
    if shutil.which("php") is None:
        raise unittest.SkipTest("php is not installed")
    if STACK not in ("builtin", "apache", "nginx"):
        raise unittest.SkipTest(f"unknown TUNNEL_E2E_STACK {STACK!r}")
    WORKDIR = Path(tempfile.mkdtemp(prefix="tunnel-e2e-"))
    config = WORKDIR / "config.php"
    config.write_text(php_config({
        "token": TOKEN,
        "data_dir": str(WORKDIR / "data"),
        "base_domain": BASE_DOMAIN,
        "response_timeout": RESPONSE_TIMEOUT,
        "max_upload_mb": MAX_UPLOAD_MB,
    }))
    PHP = PhpServer(WORKDIR, config) if STACK == "builtin" else WebStack(WORKDIR, config, STACK)
    LOCAL = LocalServer(("127.0.0.1", 0), LocalApp)
    threading.Thread(target=LOCAL.serve_forever, daemon=True).start()
    start_client("app", f"app={LOCAL.server_port}", f"down=127.0.0.1:{free_port()}", "--workers", "24")


def tearDownModule() -> None:
    for client in CLIENTS:
        client.stop()
    PHP.stop()
    LOCAL.stop()
    if os.environ.get("KEEP_TUNNEL_E2E"):
        print(f"\nkept {WORKDIR}")
    else:
        shutil.rmtree(WORKDIR, ignore_errors=True)


def php_config(settings: dict) -> str:
    def literal(value: object) -> str:
        if isinstance(value, str):
            return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
        return str(value)

    items = ", ".join(f"{literal(key)} => {literal(value)}" for key, value in settings.items())
    return f"<?php return [{items}];\n"


def start_client(ready_name: str, *args: str) -> ClientProcess:
    client = ClientProcess(WORKDIR, PHP, *args)
    CLIENTS.append(client)
    client.wait_for_output("Ctrl+C stops sharing.")
    wait_until(lambda: fetch("GET", f"/t/{ready_name}/").header("X-Tunnel-Error") not in ("tunnel_not_found", "tunnel_offline"),
               message=f"tunnel {ready_name} to open")
    return client


def wait_until(predicate, timeout: float = 15, message: str = "condition") -> None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        if predicate():
            return
        time.sleep(0.1)
    raise AssertionError(f"timed out waiting for {message}")


class Reply:
    def __init__(self, response: http.client.HTTPResponse, body: bytes):
        self.status = response.status
        self.headers = response.getheaders()
        self.body = body

    def header(self, name: str) -> str | None:
        values = self.all(name)
        return values[0] if values else None

    def all(self, name: str) -> list[str]:
        return [value for key, value in self.headers if key.lower() == name.lower()]

    def json(self) -> dict:
        return json.loads(self.body)


def fetch(method: str, path: str, host: str | None = None, headers: dict | None = None,
          body: bytes | None = None, server: PhpServer | WebStack | None = None, timeout: float = 30) -> Reply:
    server = server or PHP
    connection = http.client.HTTPConnection("127.0.0.1", server.port, timeout=timeout)
    try:
        all_headers = {"Host": host or f"127.0.0.1:{server.port}"}
        all_headers.update(headers or {})
        connection.request(method, path, body=body, headers=all_headers)
        response = connection.getresponse()
        return Reply(response, response.read())
    finally:
        connection.close()


def subdomain(name: str) -> str:
    return f"{name}.{BASE_DOMAIN}:{PHP.port}"


def query(sql: str) -> sqlite3.Row | None:
    """Runs one statement against the server's database and returns its first row."""
    connection = sqlite3.connect(WORKDIR / "data" / "tunnel.sqlite", timeout=15)
    connection.row_factory = sqlite3.Row
    try:
        with connection:
            return connection.execute(sql).fetchone()
    finally:
        connection.close()


# ----- Tests ---------------------------------------------------------------------------------


class ForwardingTest(unittest.TestCase):
    def test_path_mode_forwards_path_query_and_headers(self) -> None:
        reply = fetch("GET", "/t/app/echo?x=1&y=%C3%A9", headers={"X-Custom": "kept"})
        self.assertEqual(reply.status, 200)
        seen = reply.json()
        headers = {k.lower(): v for k, v in seen["headers"]}
        self.assertEqual(seen["path"], "/echo?x=1&y=%C3%A9")
        self.assertEqual(headers["x-custom"], "kept")
        self.assertEqual(headers["host"], f"127.0.0.1:{PHP.port}")
        self.assertEqual(headers["x-forwarded-prefix"], "/t/app")
        self.assertEqual(headers["x-forwarded-host"], f"127.0.0.1:{PHP.port}")
        self.assertEqual(headers["x-forwarded-proto"], "http")
        self.assertEqual(headers["x-forwarded-for"], "127.0.0.1")

    def test_subdomain_mode_owns_every_path(self) -> None:
        for path in ("/echo", "/echo/_tunnel/client/poll", "/echo/t/other/", "/index.php"):
            reply = fetch("GET", path, host=subdomain("app"))
            if path == "/index.php":
                self.assertEqual(reply.status, 404)
                self.assertEqual(reply.body, b"not found")
                continue
            self.assertEqual(reply.status, 200, path)
            seen = reply.json()
            self.assertEqual(seen["path"], path)
            self.assertNotIn("x-forwarded-prefix", {k.lower() for k, _ in seen["headers"]})

    def test_encoded_paths_arrive_untouched(self) -> None:
        reply = fetch("GET", "/t/app/echo/a%20b/caf%C3%A9?q=a+b&r=%26")
        self.assertEqual(reply.json()["path"], "/echo/a%20b/caf%C3%A9?q=a+b&r=%26")

    @unittest.skipIf(STACK == "apache", "Apache refuses encoded slashes unless AllowEncodedSlashes is on")
    def test_encoded_slashes_arrive_untouched(self) -> None:
        self.assertEqual(fetch("GET", "/t/app/echo/a%2Fb").json()["path"], "/echo/a%2Fb")

    @unittest.skipIf(STACK == "builtin", "PHP's built-in server rejects raw non-ASCII request lines itself")
    def test_raw_non_ascii_paths_are_percent_encoded(self) -> None:
        sock = socket.create_connection(("127.0.0.1", PHP.port), timeout=10)
        sock.sendall(b"GET /t/app/echo/caf\xc3\xa9 HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nConnection: close\r\n\r\n" % PHP.port)
        response = http.client.HTTPResponse(sock)
        response.begin()
        seen = json.loads(response.read())
        response.close()
        sock.close()
        self.assertEqual(seen["path"], "/echo/caf%C3%A9")

    def test_missing_trailing_slash_redirects(self) -> None:
        reply = fetch("GET", "/t/app?x=1")
        self.assertEqual(reply.status, 301)
        self.assertEqual(reply.header("Location"), "/t/app/?x=1")

    def test_small_and_large_request_bodies(self) -> None:
        for size in (0, 1, 1000, 300_000, 5_000_000):
            data = pattern(size)
            reply = fetch("POST", "/t/app/echo", body=data, headers={"Content-Type": "application/octet-stream"})
            seen = reply.json()
            self.assertEqual(seen["method"], "POST")
            self.assertEqual(seen["body_len"], size)
            self.assertEqual(seen["body_sha256"], hashlib.sha256(data).hexdigest())

    def test_upload_limit(self) -> None:
        too_big = pattern(MAX_UPLOAD_MB * 1024 * 1024 + 1)
        reply = fetch("POST", "/t/app/echo", body=too_big)
        self.assertEqual((reply.status, reply.header("X-Tunnel-Error")), (413, "body_too_large"))

        # Without a Content-Length the limit is enforced while the body streams in.
        step = 1024 * 1024
        pieces = [too_big[i:i + step] for i in range(0, len(too_big), step)]
        connection = http.client.HTTPConnection("127.0.0.1", PHP.port, timeout=30)
        connection.request("POST", "/t/app/echo", body=iter(pieces), encode_chunked=True,
                           headers={"Transfer-Encoding": "chunked"})
        response = connection.getresponse()
        response.read()
        connection.close()
        self.assertEqual(response.status, 413)

    def test_chunked_request_bodies(self) -> None:
        connection = http.client.HTTPConnection("127.0.0.1", PHP.port, timeout=30)
        data = pattern(300_000)
        connection.request("POST", "/t/app/echo", body=iter([data[:100_000], data[100_000:]]), encode_chunked=True,
                           headers={"Transfer-Encoding": "chunked"})
        response = connection.getresponse()
        seen = json.loads(response.read())
        connection.close()
        self.assertEqual(seen["body_sha256"], hashlib.sha256(data).hexdigest())

    def test_other_methods(self) -> None:
        for method in ("PUT", "PATCH", "DELETE", "OPTIONS"):
            reply = fetch(method, "/t/app/echo", body=b"payload" if method != "OPTIONS" else None)
            self.assertEqual(reply.json()["method"], method)

    def test_large_download(self) -> None:
        size = 25_000_000
        reply = fetch("GET", f"/t/app/download?size={size}", timeout=120)
        self.assertEqual(reply.status, 200)
        # Apache's proxy_fcgi drops Content-Length from PHP responses and sends them chunked.
        self.assertIn(reply.header("Content-Length"), (str(size), None))
        self.assertEqual(hashlib.sha256(reply.body).hexdigest(), hashlib.sha256(pattern(size)).hexdigest())

    def test_streamed_response_arrives_piece_by_piece(self) -> None:
        connection = http.client.HTTPConnection("127.0.0.1", PHP.port, timeout=10)
        started = time.monotonic()
        connection.request("GET", "/t/app/stream")
        response = connection.getresponse()
        first = response.readline()
        first_at = time.monotonic() - started
        rest = response.read()
        total = time.monotonic() - started
        connection.close()
        self.assertEqual(first, b"first\n")
        self.assertEqual(rest, b"second\nthird\n")
        self.assertLess(first_at, 0.6, "the first piece waited for the whole response")
        self.assertGreater(total, 1.1)

    def test_head_and_bodiless_statuses(self) -> None:
        head = fetch("HEAD", "/t/app/download?size=1234")
        self.assertEqual((head.status, head.body), (200, b""))
        self.assertIn(head.header("Content-Length"), ("1234", None))
        self.assertEqual(fetch("GET", "/t/app/status/204").status, 204)
        self.assertEqual(fetch("GET", "/t/app/status/304").status, 304)
        teapot = fetch("GET", "/t/app/status/418")
        self.assertEqual((teapot.status, teapot.body), (418, b"teapot"))

    def test_compressed_bodies_pass_through(self) -> None:
        reply = fetch("GET", "/t/app/gzip", headers={"Accept-Encoding": "gzip"})
        self.assertEqual(reply.header("Content-Encoding"), "gzip")
        self.assertEqual(gzip.decompress(reply.body), b"compressed hello" * 100)

    def test_utf8_header_values_survive(self) -> None:
        reply = fetch("GET", "/t/app/utf8-header")
        raw = reply.header("X-Name")
        self.assertEqual(raw.encode("latin-1").decode("utf-8"), "Zoë ✓")

    def test_many_requests_at_once(self) -> None:
        def one(index: int) -> tuple[int, str]:
            reply = fetch("GET", f"/t/app/echo?n={index}")
            return reply.status, reply.json()["path"]

        with ThreadPoolExecutor(24) as pool:
            results = list(pool.map(one, range(60)))
        self.assertEqual(results, [(200, f"/echo?n={i}") for i in range(60)])


class RewritingTest(unittest.TestCase):
    def test_relative_redirect_keeps_the_prefix(self) -> None:
        self.assertEqual(fetch("GET", "/t/app/redirect").header("Location"), "/t/app/echo?from=redirect")
        self.assertEqual(fetch("GET", "/redirect", host=subdomain("app")).header("Location"), "/echo?from=redirect")

    def test_redirect_to_the_local_address_points_to_the_public_one(self) -> None:
        self.assertEqual(fetch("GET", "/t/app/absolute-redirect").header("Location"),
                         f"http://127.0.0.1:{PHP.port}/t/app/echo?from=absolute")
        self.assertEqual(fetch("GET", "/absolute-redirect", host=subdomain("app")).header("Location"),
                         f"http://{subdomain('app')}/echo?from=absolute")

    def test_cookies_are_scoped_to_the_tunnel(self) -> None:
        self.assertEqual(fetch("GET", "/t/app/cookies").all("Set-Cookie"),
                         ["first=1; Path=/t/app/; HttpOnly", "second=2; Path=/t/app/admin"])
        self.assertEqual(fetch("GET", "/cookies", host=subdomain("app")).all("Set-Cookie"),
                         ["first=1; Path=/; HttpOnly", "second=2; Path=/admin"])

    def test_absolute_links_find_their_tunnel_through_the_referer(self) -> None:
        reply = fetch("GET", "/assets/app.js?v=2", headers={"Referer": f"http://127.0.0.1:{PHP.port}/t/app/page"})
        self.assertEqual(reply.status, 307)
        self.assertEqual(reply.header("Location"), "/t/app/assets/app.js?v=2")
        self.assertEqual(fetch("GET", "/assets/app.js").status, 404)


class UploadTest(unittest.TestCase):
    BOUNDARY = "----TestBoundary7MA4YWxkTrZu0gW"

    def form(self, data: bytes) -> bytes:
        b = self.BOUNDARY
        return (
            f"--{b}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nholiday\r\n"
            f"--{b}\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"beach.bin\"\r\n"
            f"Content-Type: application/octet-stream\r\n\r\n"
        ).encode() + data + f"\r\n--{b}--\r\n".encode()

    def test_multipart_upload_arrives_byte_for_byte(self) -> None:
        body = self.form(pattern(3_000_000))
        reply = fetch("POST", "/t/app/echo", body=body,
                      headers={"Content-Type": f"multipart/form-data; boundary={self.BOUNDARY}"})
        self.assertEqual(reply.status, 200, reply.body[:500])
        self.assertEqual(reply.json()["body_sha256"], hashlib.sha256(body).hexdigest())

    def test_multipart_is_rebuilt_when_php_parses_forms_itself(self) -> None:
        php = PhpServer(WORKDIR, WORKDIR / "config.php", post_data_reading=True)
        try:
            data = pattern(50_000)
            reply = fetch("POST", "/t/app/echo", server=php, body=self.form(data),
                          headers={"Content-Type": f"multipart/form-data; boundary={self.BOUNDARY}"})
        finally:
            php.stop()
        seen = reply.json()
        content_type = {k.lower(): v for k, v in seen["headers"]}["content-type"]
        message = email.parser.BytesParser(policy=email.policy.HTTP).parsebytes(
            f"Content-Type: {content_type}\r\n\r\n".encode() + base64.b64decode(seen["body_b64"]))
        parts = {part.get_param("name", header="content-disposition"): part for part in message.iter_parts()}
        self.assertEqual(parts["title"].get_payload(decode=True), b"holiday")
        self.assertEqual(parts["photo"].get_filename(), "beach.bin")
        self.assertEqual(parts["photo"].get_payload(decode=True), data)


class FailureTest(unittest.TestCase):
    def test_unknown_tunnel(self) -> None:
        reply = fetch("GET", "/t/nobody/", headers={"Accept": "text/html"})
        self.assertEqual(reply.status, 404)
        self.assertEqual(reply.header("X-Tunnel-Error"), "tunnel_not_found")
        self.assertIn(b"Tunnel not found", reply.body)
        self.assertIn(b"<code>python3 tunnel.py nobody=3000</code>", reply.body)
        plain = fetch("GET", "/", host=subdomain("nobody"))
        self.assertEqual(plain.header("Content-Type"), "text/plain; charset=utf-8")

    def test_local_app_not_running(self) -> None:
        reply = fetch("GET", "/t/down/")
        self.assertEqual(reply.status, 502)
        self.assertEqual(reply.header("X-Tunnel-Error"), "local_unreachable")
        self.assertIn(b"Nothing is listening on 127.0.0.1:", reply.body)

    def test_local_app_too_slow(self) -> None:
        started = time.monotonic()
        reply = fetch("GET", f"/t/app/slow?seconds={RESPONSE_TIMEOUT + 2}")
        self.assertEqual(reply.status, 504)
        self.assertEqual(reply.header("X-Tunnel-Error"), "response_timeout")
        self.assertLess(time.monotonic() - started, RESPONSE_TIMEOUT + 2)

    def test_websocket_upgrade_is_refused_clearly(self) -> None:
        reply = fetch("GET", "/t/app/echo", headers={"Upgrade": "websocket", "Connection": "Upgrade"})
        self.assertEqual(reply.status, 501)
        self.assertEqual(reply.header("X-Tunnel-Error"), "upgrade_unsupported")

    def test_optional_upgrades_are_ignored(self) -> None:
        reply = fetch("GET", "/t/app/echo", headers={"Upgrade": "h2c", "Connection": "Upgrade, HTTP2-Settings"})
        self.assertEqual(reply.status, 200)
        self.assertNotIn("upgrade", {k.lower() for k, _ in reply.json()["headers"]})

    def test_offline_tunnel(self) -> None:
        client = start_client("brief", "brief=9")
        client.stop(signal.SIGKILL)
        query("UPDATE tunnels SET last_seen = last_seen - 600 WHERE name = 'brief'")
        reply = fetch("GET", "/t/brief/")
        self.assertEqual(reply.status, 503)
        self.assertEqual(reply.header("X-Tunnel-Error"), "tunnel_offline")
        self.assertIn(b"10 minutes ago", reply.body)

    def test_visitor_leaving_stops_the_upload(self) -> None:
        sock = socket.create_connection(("127.0.0.1", PHP.port), timeout=10)
        sock.sendall(f"GET /t/app/download?size=300000000 HTTP/1.1\r\nHost: 127.0.0.1:{PHP.port}\r\n\r\n".encode())
        received = 0
        while received < 2_000_000:
            received += len(sock.recv(65536))
        sock.close()

        def abandoned() -> bool:
            row = query("SELECT state FROM requests WHERE uri = '/download?size=300000000'")
            return row is not None and row["state"] == "abandoned"

        wait_until(abandoned, timeout=20, message="the request to be marked abandoned")
        # Backpressure held the client back to roughly what the visitor took, not all 300 MB.
        uploaded = query("SELECT res_bytes FROM requests WHERE uri = '/download?size=300000000'")["res_bytes"]
        self.assertLess(uploaded, 40_000_000)


class AuthTest(unittest.TestCase):
    def test_password_protected_tunnel(self) -> None:
        start_client("locked", f"locked={LOCAL.server_port}", "--auth", "ann:s3cret")
        self.assertEqual(fetch("GET", "/t/locked/echo").status, 401)
        self.assertIn("Basic", fetch("GET", "/t/locked/echo").header("WWW-Authenticate"))
        wrong = base64.b64encode(b"ann:nope").decode()
        self.assertEqual(fetch("GET", "/t/locked/echo", headers={"Authorization": f"Basic {wrong}"}).status, 401)
        right = base64.b64encode(b"ann:s3cret").decode()
        reply = fetch("GET", "/t/locked/echo", headers={"Authorization": f"Basic {right}"})
        self.assertEqual(reply.status, 200)
        self.assertNotIn("authorization", {k.lower() for k, _ in reply.json()["headers"]})

    def test_authorization_reaches_unprotected_apps(self) -> None:
        reply = fetch("GET", "/t/app/echo", headers={"Authorization": "Bearer abc"})
        self.assertIn(["Authorization", "Bearer abc"], reply.json()["headers"])

    def test_client_api_needs_the_token(self) -> None:
        reply = fetch("GET", "/_tunnel/client/ping", headers={"X-Tunnel-Token": "wrong", "X-Tunnel-Protocol": "1"})
        self.assertEqual(reply.status, 401)


class LifecycleTest(unittest.TestCase):
    def test_close_from_dashboard(self) -> None:
        client = start_client("temp", f"temp={LOCAL.server_port}")
        cookie = DashboardTest.login()
        reply = fetch("POST", "/_tunnel/api/close", body=json.dumps({"name": "temp"}).encode(),
                      headers={"Cookie": cookie, "X-Tunnel-Csrf": "1", "Content-Type": "application/json"})
        self.assertEqual(reply.status, 200)
        self.assertEqual(fetch("GET", "/t/temp/").status, 404)
        client.wait_for_output('Stopped sharing "temp": closed from the dashboard.')
        self.assertEqual(client.process.wait(15), 0)

    def test_second_client_takes_over_a_name(self) -> None:
        first = start_client("shared", f"shared={LOCAL.server_port}")
        other = LocalServer(("127.0.0.1", 0), LocalApp)
        threading.Thread(target=other.serve_forever, daemon=True).start()
        try:
            second = ClientProcess(WORKDIR, PHP, f"shared={other.server_port}")
            CLIENTS.append(second)
            second.wait_for_output("it is served from here now")
            first.wait_for_output('Stopped sharing "shared": now served by')
            self.assertEqual(first.process.wait(15), 0)
            self.assertEqual(fetch("GET", "/t/shared/echo").status, 200)
        finally:
            other.stop()

    def test_client_re_registers_when_the_server_forgets_it(self) -> None:
        client = start_client("phoenix", f"phoenix={LOCAL.server_port}")
        query("DELETE FROM tunnels WHERE name = 'phoenix'")
        self.assertEqual(fetch("GET", "/t/phoenix/echo").status, 404)
        wait_until(lambda: fetch("GET", "/t/phoenix/echo").status == 200, timeout=15, message="re-registration")
        self.assertIsNone(client.process.poll())

    def test_ctrl_c_takes_tunnels_offline_at_once(self) -> None:
        client = start_client("bye", f"bye={LOCAL.server_port}")
        self.assertEqual(client.stop(signal.SIGINT), 0)
        self.assertEqual(fetch("GET", "/t/bye/").status, 404)

    def test_random_names(self) -> None:
        client = start_client("app", str(LOCAL.server_port))
        line = next(line for line in client.text().splitlines() if "→" in line)
        name = line.split()[0]
        self.assertRegex(name, r"^[a-z]+-[a-z]+-\d{2}$")
        self.assertEqual(fetch("GET", f"/t/{name}/echo").status, 200)


class ClientOptionsTest(unittest.TestCase):
    def test_host_header_rewrite(self) -> None:
        start_client("rewritten", f"rewritten={LOCAL.server_port}", "--host-header", "rewrite")
        headers = {k.lower(): v for k, v in fetch("GET", "/t/rewritten/echo").json()["headers"]}
        self.assertEqual(headers["host"], f"localhost:{LOCAL.server_port}")
        self.assertEqual(headers["x-forwarded-host"], f"127.0.0.1:{PHP.port}")

    def test_wrong_token_stops_the_client(self) -> None:
        env = dict(os.environ, NO_COLOR="1", XDG_CONFIG_HOME=str(WORKDIR / "nowhere"))
        result = subprocess.run([sys.executable, str(CLIENT), "--server", PHP.url, "--token", "not-the-token", "3000"],
                                env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 1)
        self.assertIn("rejected the token", result.stdout)

    def test_login_saves_the_server_and_token(self) -> None:
        home = WORKDIR / "login-home"
        env = dict(os.environ, NO_COLOR="1", XDG_CONFIG_HOME=str(home))
        refused = subprocess.run([sys.executable, str(CLIENT), "login", PHP.url, "wrong-token"],
                                 env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(refused.returncode, 1)
        self.assertFalse((home / "tunnel" / "config.json").exists())

        accepted = subprocess.run([sys.executable, str(CLIENT), "login", PHP.url, TOKEN],
                                  env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(accepted.returncode, 0, accepted.stderr)
        saved = home / "tunnel" / "config.json"
        self.assertEqual(json.loads(saved.read_text()), {"server": PHP.url, "token": TOKEN})
        self.assertEqual(saved.stat().st_mode & 0o777, 0o600)


class DashboardTest(unittest.TestCase):
    @staticmethod
    def login() -> str:
        reply = fetch("POST", "/_tunnel/api/login", body=json.dumps({"token": TOKEN}).encode(),
                      headers={"X-Tunnel-Csrf": "1", "Content-Type": "application/json"})
        assert reply.status == 200, reply.body
        return reply.header("Set-Cookie").split(";")[0]

    def test_page_and_assets(self) -> None:
        page = fetch("GET", "/")
        self.assertEqual(page.status, 200)
        self.assertIn("default-src 'self'", page.header("Content-Security-Policy"))
        for asset in ("app.js", "app.css", "icon.svg"):
            self.assertEqual(fetch("GET", f"/_tunnel/assets/{asset}").status, 200)
        self.assertEqual(fetch("GET", "/_tunnel/assets/../config.php").status, 404)
        script = fetch("GET", "/_tunnel/tunnel.py")
        self.assertEqual(script.body, CLIENT.read_bytes())

    def test_sign_in(self) -> None:
        self.assertEqual(fetch("GET", "/_tunnel/api/state").status, 401)
        no_csrf = fetch("POST", "/_tunnel/api/login", body=json.dumps({"token": TOKEN}).encode())
        self.assertEqual(no_csrf.status, 403)
        cross_site = fetch("POST", "/_tunnel/api/login", body=json.dumps({"token": TOKEN}).encode(),
                           headers={"X-Tunnel-Csrf": "1", "Origin": "https://evil.example"})
        self.assertEqual(cross_site.status, 403)
        wrong = fetch("POST", "/_tunnel/api/login", body=b'{"token":"nope"}', headers={"X-Tunnel-Csrf": "1"})
        self.assertEqual(wrong.status, 401)
        cookie = self.login()
        attributes = fetch("POST", "/_tunnel/api/login", body=json.dumps({"token": TOKEN}).encode(),
                           headers={"X-Tunnel-Csrf": "1"}).header("Set-Cookie").lower()
        for attribute in ("path=/_tunnel/api/", "httponly", "samesite=strict"):
            self.assertIn(attribute, attributes)
        self.assertTrue(fetch("GET", "/_tunnel/api/session", headers={"Cookie": cookie}).json()["authenticated"])
        forged = cookie.split(".")[0] + ".forged"
        self.assertFalse(fetch("GET", "/_tunnel/api/session", headers={"Cookie": forged}).json()["authenticated"])

    def test_state(self) -> None:
        fetch("GET", "/t/app/echo?from=dashboard-test")
        state = fetch("GET", "/_tunnel/api/state", headers={"Cookie": self.login()}).json()
        app = next(t for t in state["tunnels"] if t["name"] == "app")
        self.assertTrue(app["online"])
        self.assertEqual(app["target"], f"http://localhost:{LOCAL.server_port}")
        self.assertEqual(app["urls"], [f"http://app.{BASE_DOMAIN}:{PHP.port}/", f"http://127.0.0.1:{PHP.port}/t/app/"])
        self.assertTrue(any(r["uri"] == "/echo?from=dashboard-test" and r["status"] == 200 for r in state["requests"]))


class SetupTest(unittest.TestCase):
    def test_setup_page_without_configuration(self) -> None:
        php = PhpServer(WORKDIR, WORKDIR / "missing-config.php")
        try:
            page = fetch("GET", "/", server=php)
            api = fetch("GET", "/_tunnel/client/ping", server=php)
        finally:
            php.stop()
        self.assertEqual(page.status, 503)
        self.assertIn(b"Almost ready", page.body)
        self.assertEqual(api.status, 503)
        self.assertIn("not configured", api.json()["error"])


if __name__ == "__main__":
    unittest.main()
