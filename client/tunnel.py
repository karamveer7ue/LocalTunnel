#!/usr/bin/env python3
"""tunnel.py: share local ports through your own Tunnel server.

    python3 tunnel.py login https://tunnel.example.com      # once; asks for the token
    python3 tunnel.py 3000                                  # share localhost:3000
    python3 tunnel.py myapp=3000 api=8080                   # several, with chosen names

Needs Python 3.8 or newer and nothing outside the standard library.
"""

from __future__ import annotations

import argparse
import base64
import contextlib
import getpass
import http.client
import io
import json
import os
import queue
import random
import re
import secrets
import shutil
import signal
import socket
import ssl
import sys
import tempfile
import threading
import time
import urllib.parse
from typing import IO, Iterator

VERSION = "1.0.0"
# Must match App::PROTOCOL on the server.
PROTOCOL = "1"

# Largest piece of a response uploaded in one call.
CHUNK_SIZE = 512 * 1024
# The first piece waits this long for more data, so most responses go up in a single call.
FIRST_PIECE_WAIT = 0.05
LOCAL_CONNECT_TIMEOUT = 10
# Longest silence tolerated from the local app, including slow streaming responses.
LOCAL_READ_TIMEOUT = 300
# The server answers a poll within about 25 seconds.
POLL_TIMEOUT = 60
# Request bodies bigger than this are buffered on disk instead of in memory.
SPOOL_IN_MEMORY = 8 * 1024 * 1024
NAME_PATTERN = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")


class Fatal(Exception):
    """A problem that retrying will not fix."""


class ServerError(Exception):
    """The tunnel server answered with an error."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


# ----- Configuration -------------------------------------------------------------------------


def config_path() -> str:
    base = os.environ.get("XDG_CONFIG_HOME") or os.path.join(os.path.expanduser("~"), ".config")
    return os.path.join(base, "tunnel", "config.json")


def load_config() -> dict:
    try:
        with open(config_path(), encoding="utf-8") as file:
            data = json.load(file)
    except (OSError, ValueError):
        return {}
    return data if isinstance(data, dict) else {}


def save_config(data: dict) -> None:
    path = config_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as file:
        json.dump(data, file, indent=2)
        file.write("\n")
    os.chmod(path, 0o600)


def normalize_server(url: str) -> str:
    url = url.strip()
    if "://" not in url:
        url = "https://" + url
    return url.rstrip("/")


# ----- Targets -------------------------------------------------------------------------------


class Target:
    """A local address that requests are forwarded to."""

    def __init__(self, scheme: str, host: str, port: int):
        self.scheme = scheme
        self.host = host
        self.port = port

    @property
    def netloc(self) -> str:
        host = f"[{self.host}]" if ":" in self.host else self.host
        return host if self.port == (443 if self.scheme == "https" else 80) else f"{host}:{self.port}"

    def __str__(self) -> str:
        return f"{self.scheme}://{self.netloc}"

    def connection(self) -> http.client.HTTPConnection:
        if self.scheme == "https":
            # Local development servers mostly use self-signed certificates.
            context = ssl.create_default_context()
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
            return http.client.HTTPSConnection(self.host, self.port, timeout=LOCAL_CONNECT_TIMEOUT, context=context)
        return http.client.HTTPConnection(self.host, self.port, timeout=LOCAL_CONNECT_TIMEOUT)


def parse_port(text: str) -> int:
    if not text.isdigit() or not 0 < int(text) < 65536:
        raise ValueError(f"{text!r} is not a port number")
    return int(text)


def parse_target(text: str) -> Target:
    if text.isdigit():
        return Target("http", "localhost", parse_port(text))
    if "://" in text:
        parts = urllib.parse.urlsplit(text)
        if parts.scheme not in ("http", "https") or not parts.hostname or parts.path not in ("", "/") or parts.query:
            raise ValueError(f"{text!r} must look like http://host:port")
        return Target(parts.scheme, parts.hostname, parts.port or (443 if parts.scheme == "https" else 80))
    host, colon, port = text.rpartition(":")
    if not colon or not host:
        raise ValueError(f"{text!r} is not a port, HOST:PORT, or URL")
    return Target("http", host.strip("[]"), parse_port(port))


class Tunnel:
    def __init__(self, name: str | None, target: Target):
        # The name to ask for; after registering, the name the server granted.
        self.name = name
        self.target = target
        self.urls: list[str] = []


def parse_spec(text: str) -> Tunnel:
    """Parses PORT, NAME=PORT, NAME=HOST:PORT, or NAME=URL."""
    name, equals, target = text.partition("=")
    if not equals:
        return Tunnel(None, parse_target(text.strip()))
    name = name.strip().lower()
    if not NAME_PATTERN.match(name):
        raise ValueError(f"{name!r} is not a valid tunnel name; use lowercase letters, digits, and dashes")
    return Tunnel(name, parse_target(target.strip()))


# ----- Talking to the server -----------------------------------------------------------------


class Api:
    """Calls to the tunnel server. Each thread keeps its own keep-alive connection."""

    def __init__(self, server: str, token: str, insecure: bool = False):
        parts = urllib.parse.urlsplit(server)
        if parts.scheme not in ("http", "https") or not parts.hostname:
            raise Fatal(f"{server!r} is not a server address like https://tunnel.example.com")
        self.server = server
        self._https = parts.scheme == "https"
        self._host = parts.hostname
        self._port = parts.port or (443 if self._https else 80)
        self._base = parts.path.rstrip("/") + "/_tunnel/client/"
        self._token = token
        self._context: ssl.SSLContext | None = None
        if self._https:
            self._context = ssl.create_default_context()
            if insecure:
                self._context.check_hostname = False
                self._context.verify_mode = ssl.CERT_NONE
        self._local = threading.local()

    def request(self, method: str, action: str, query: dict | None = None, body: bytes | None = None,
                headers: dict | None = None, timeout: float = 30, sink: IO[bytes] | None = None,
                ) -> tuple[int, http.client.HTTPMessage, bytes]:
        """Makes one call. A 200 answer is copied into sink when one is given."""
        path = self._base + action + ("?" + urllib.parse.urlencode(query) if query else "")
        all_headers = {"X-Tunnel-Token": self._token, "X-Tunnel-Protocol": PROTOCOL, "User-Agent": f"tunnel.py/{VERSION}"}
        all_headers.update(headers or {})
        for attempt in range(2):
            connection = self._connection(timeout)
            reused = connection.sock is not None
            copying = False
            try:
                connection.request(method, path, body=body, headers=all_headers)
                response = connection.getresponse()
                if sink is not None and response.status == 200:
                    copying = True
                    shutil.copyfileobj(response, sink, 64 * 1024)
                    data = b""
                else:
                    data = response.read()
                return response.status, response.headers, data
            except BaseException as error:
                # Never reuse a connection left mid-request, not even after Ctrl+C.
                self._discard()
                # Servers close idle keep-alive connections (Apache after 5 seconds), which then
                # fail on first use. One fresh try, unless part of an answer was already kept.
                if reused and attempt == 0 and not copying and isinstance(error, ConnectionError):
                    continue
                raise
        raise AssertionError("unreachable")

    def call(self, method: str, action: str, query: dict | None = None, payload: dict | None = None,
             timeout: float = 30) -> dict:
        """Makes a JSON call and returns the answer, raising Fatal or ServerError for errors."""
        body = None if payload is None else json.dumps(payload).encode()
        headers = {"Content-Type": "application/json"} if body is not None else None
        status, response_headers, data = self.request(method, action, query, body, headers, timeout)
        if 300 <= status < 400:
            location = response_headers.get("Location", "another address")
            raise Fatal(f"{self.server} redirects to {location}. Use the address it redirects to.")
        try:
            answer = json.loads(data.decode("utf-8")) if data.strip() else {}
        except ValueError:
            answer = None
        if not isinstance(answer, dict):
            raise ServerError(status, f"{self.server} answered with something other than a Tunnel server would (status {status})")
        message = str(answer.get("error") or f"The server answered with status {status}")
        if status in (400, 401, 403, 404):
            raise Fatal(message)
        if status >= 300:
            raise ServerError(status, message)
        return answer

    def download(self, request_id: str, sink: IO[bytes]) -> None:
        status, _, _ = self.request("GET", "body", {"id": request_id}, timeout=120, sink=sink)
        if status != 200:
            raise ServerError(status, "the request body is no longer available")

    def respond(self, request_id: str, seq: int, data: bytes, head: dict | None = None, end: bool = False) -> bool:
        """Uploads one piece of a response. Returns False once the visitor no longer waits for it."""
        query: dict = {"id": request_id, "seq": seq}
        body = data
        if head is not None:
            encoded = json.dumps(head, separators=(",", ":")).encode()
            query["head"] = len(encoded)
            body = encoded + data
        if end:
            query["end"] = 1
        give_up = time.monotonic() + 300
        delay = 0.25
        while True:
            try:
                status, headers, answer = self.request("POST", "respond", query, body,
                                                       {"Content-Type": "application/octet-stream"}, timeout=60)
            except (OSError, http.client.HTTPException):
                status, headers, answer = 0, None, b""
            if status == 200:
                return True
            if status == 410:
                return False
            if status not in (0, 429, 500, 502, 503, 504):
                raise ServerError(status, answer.decode("utf-8", "replace")[:200])
            if time.monotonic() > give_up:
                return False
            # 503 means the visitor downloads slowly and the server asked us to wait.
            retry_after = headers.get("Retry-After", "") if headers is not None else ""
            time.sleep(float(retry_after) if retry_after.isdigit() else delay)
            delay = min(delay * 2, 5)

    def _connection(self, timeout: float) -> http.client.HTTPConnection:
        connection = getattr(self._local, "connection", None)
        if connection is None:
            if self._https:
                connection = http.client.HTTPSConnection(self._host, self._port, timeout=timeout, context=self._context)
            else:
                connection = http.client.HTTPConnection(self._host, self._port, timeout=timeout)
            self._local.connection = connection
        connection.timeout = timeout
        if connection.sock is not None:
            connection.sock.settimeout(timeout)
        return connection

    def _discard(self) -> None:
        connection = getattr(self._local, "connection", None)
        if connection is not None:
            connection.close()
            self._local.connection = None


def describe(error: BaseException) -> str:
    if isinstance(error, (ServerError, Fatal)):
        return str(error)
    if isinstance(error, socket.timeout):
        return "timed out"
    if isinstance(error, ssl.SSLCertVerificationError):
        return f"its HTTPS certificate is not valid: {error.verify_message}"
    if isinstance(error, ConnectionRefusedError):
        return "connection refused"
    if isinstance(error, socket.gaierror):
        return "could not look up its address"
    return str(error) or type(error).__name__


# ----- Moving response bytes -----------------------------------------------------------------


class Pipe:
    """Carries a response from the thread reading the local app to the thread uploading it.

    Whatever piles up while an upload is in flight goes out with the next one, so streams
    stay responsive and large downloads travel in large pieces.
    """

    def __init__(self, limit: int):
        self._buffer = bytearray()
        self._limit = limit
        self._finished = False
        self._closed = False
        self._lock = threading.Condition()
        self.error: str | None = None

    def put(self, data: bytes) -> bool:
        """Adds data, waiting while the buffer is full. False once the reader should stop."""
        with self._lock:
            while len(self._buffer) >= self._limit and not self._closed:
                self._lock.wait()
            if self._closed:
                return False
            self._buffer += data
            self._lock.notify_all()
            return True

    def finish(self, error: str | None = None) -> None:
        with self._lock:
            self._finished = True
            self.error = self.error or error
            self._lock.notify_all()

    def close(self) -> None:
        with self._lock:
            self._closed = True
            self._lock.notify_all()

    def take(self, size: int, linger: float = 0.0) -> tuple[bytes, bool]:
        """Waits for data and returns (up to size bytes, whether that was the end).

        With linger, keeps collecting until size bytes, the end, or linger seconds pass,
        whichever comes first. The result may then be empty.
        """
        deadline = time.monotonic() + linger
        with self._lock:
            while not self._finished and len(self._buffer) < size:
                if linger > 0:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        break
                    self._lock.wait(remaining)
                elif self._buffer:
                    break
                else:
                    self._lock.wait()
            data = bytes(self._buffer[:size])
            del self._buffer[:size]
            self._lock.notify_all()
            return data, self._finished and not self._buffer


def pump(response: http.client.HTTPResponse, pipe: Pipe) -> None:
    error = None
    try:
        while True:
            data = response.read1(CHUNK_SIZE)
            if not data or not pipe.put(data):
                break
    except (OSError, http.client.HTTPException, ValueError) as problem:
        error = f"the local app's response broke off ({describe(problem)})"
    finally:
        pipe.finish(error)


# Everything printable in ASCII stays as it is; spaces, control characters, and non-ASCII
# characters are percent-encoded, as browsers do, because http.client refuses them raw.
URI_SAFE = "".join(chr(code) for code in range(0x21, 0x7F))


def request_target(uri: str) -> str:
    return urllib.parse.quote(uri, safe=URI_SAFE)


def header_text(value: str) -> str:
    """http.client decodes header bytes as Latin-1. Hand on the original UTF-8 where it was UTF-8."""
    try:
        return value.encode("latin-1").decode("utf-8")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return value


# ----- Terminal output -----------------------------------------------------------------------


class Console:
    """Terminal output, safe to use from many threads."""

    def __init__(self, color: bool, quiet: bool):
        self.color = color
        self.quiet = quiet
        self._lock = threading.Lock()

    def style(self, text: str, *codes: str) -> str:
        return f"\033[{';'.join(codes)}m{text}\033[0m" if self.color and codes else text

    def print(self, text: str = "") -> None:
        with self._lock:
            print(text, flush=True)

    def note(self, text: str) -> None:
        self.print(self.style(text, "2"))

    def good(self, text: str) -> None:
        self.print(self.style("✓ ", "32") + text)

    def warn(self, text: str) -> None:
        self.print(self.style("! ", "33") + self.style(text, "33"))

    def error(self, text: str) -> None:
        self.print(self.style("✕ ", "31") + self.style(text, "31"))

    def banner(self, server: str, tunnels: list[Tunnel]) -> None:
        width = max(len(t.name or "") for t in tunnels)
        lines = ["", self.style("Tunnel", "1") + "  " + self.style(f"connected to {server}", "2"), ""]
        for tunnel in tunnels:
            for index, url in enumerate(tunnel.urls):
                label = (tunnel.name or "") if index == 0 else ""
                line = "  " + self.style(label.ljust(width), "1") + "  " + self.style(url, "36")
                if index == 0:
                    line += self.style("  →  ", "2") + str(tunnel.target)
                lines.append(line)
        lines += ["", self.style("Ctrl+C stops sharing.", "2"), ""]
        self.print("\n".join(lines))

    def request(self, tunnel: str | None, method: str, uri: str, status: int, seconds: float, size: int,
                note: str | None) -> None:
        if self.quiet:
            return
        millis = seconds * 1000
        took = f"{millis:.0f} ms" if millis < 1000 else f"{seconds:.1f} s"
        fields = [
            (time.strftime("%H:%M:%S"), ("2",)),
            *([(tunnel, ("36",))] if tunnel else []),
            (method.ljust(7), ("1",)),
            (str(status), (STATUS_COLORS.get(status // 100, "2"),)),
            (took.rjust(8), ("2",)),
            (format_size(size).rjust(8), ("2",)),
        ]
        used = sum(len(text) + 2 for text, _ in fields)
        room = max(20, shutil.get_terminal_size((100, 20)).columns - used)
        path = uri if len(uri) <= room else uri[: room - 1] + "…"
        line = "  ".join(self.style(text, *codes) for text, codes in fields) + "  " + path
        if note:
            line += "  " + self.style(note, "31")
        self.print(line)


STATUS_COLORS = {2: "32", 3: "36", 4: "33", 5: "31"}


def format_size(size: int) -> str:
    if size == 0:
        return "-"
    if size < 1024:
        return f"{size} B"
    for unit in ("KB", "MB", "GB"):
        size /= 1024
        if size < 1024 or unit == "GB":
            return f"{size:.1f} {unit}" if size < 10 else f"{size:.0f} {unit}"
    return ""


# ----- The client ----------------------------------------------------------------------------


class Client:
    def __init__(self, api: Api, tunnels: list[Tunnel], console: Console, auth: str | None,
                 rewrite_host: bool, workers: int):
        self.api = api
        self.console = console
        self.auth = auth
        self.rewrite_host = rewrite_host
        self.client_id = secrets.token_urlsafe(18)
        self.client_name = (socket.gethostname() or "unknown").split(".")[0][:64]
        self.active: dict[str, Tunnel] = {}
        # Tunnels waiting to be registered: at start, and after the server forgot them.
        self.waiting: list[Tunnel] = list(tunnels)
        self._jobs: queue.Queue = queue.Queue()
        for _ in range(workers):
            threading.Thread(target=self._work, daemon=True).start()

    def run(self) -> int:
        self._connect()
        self.console.banner(self.api.server, list(self.active.values()))
        delay = Backoff()
        lost = False
        while self.active or self.waiting:
            try:
                if self.waiting:
                    self._register(self.waiting)
                answer = self.api.call("GET", "poll", {"client": self.client_id, "tunnels": ",".join(self.active)},
                                       timeout=POLL_TIMEOUT) if self.active else {}
            except Fatal:
                raise
            except (OSError, http.client.HTTPException, ServerError) as error:
                if not lost:
                    lost = True
                    self.console.warn(f"Lost the connection to the server: {describe(error)}. Reconnecting.")
                time.sleep(delay.next())
                continue
            if lost:
                lost = False
                delay.reset()
                self.console.good("Reconnected.")
            for name, reason in answer.get("gone", {}).items():
                if self.active.pop(name, None) is not None:
                    self.console.error(f'Stopped sharing "{name}": {reason}.')
            for name in answer.get("missing", []):
                tunnel = self.active.pop(name, None)
                if tunnel is not None:
                    self.waiting.append(tunnel)
            for item in answer.get("requests", []):
                self._jobs.put(item)
        self.console.note("Nothing left to share.")
        return 0

    def close(self) -> None:
        """Takes the tunnels offline right away instead of letting them time out."""
        if not self.active:
            return
        with contextlib.suppress(Exception):
            self.api.call("POST", "unregister", payload={"client_id": self.client_id, "tunnels": list(self.active)}, timeout=5)

    def _connect(self) -> None:
        """Registers the tunnels for the first time, waiting out network trouble."""
        delay = Backoff()
        while True:
            try:
                self._register(self.waiting)
                break
            except (OSError, http.client.HTTPException, ServerError) as error:
                wait = delay.next()
                self.console.warn(f"Cannot reach {self.api.server}: {describe(error)}. Trying again in {wait:.0f} s.")
                time.sleep(wait)
        if not self.active:
            raise Fatal("No tunnel could be opened.")

    def _register(self, tunnels: list[Tunnel]) -> None:
        payload = {
            "client_id": self.client_id,
            "client_name": self.client_name,
            "tunnels": [{"name": t.name, "target": str(t.target), "auth": self.auth} for t in tunnels],
        }
        answer = self.api.call("POST", "register", payload=payload)
        results = answer.get("tunnels", [])
        if len(results) != len(tunnels):
            raise ServerError(200, "the server's answer to registering did not match the request")
        for tunnel, result in zip(tunnels, results):
            if result.get("error"):
                self.console.error(f"Cannot share {tunnel.target}: {result['error']}")
                continue
            tunnel.name = result["name"]
            tunnel.urls = result.get("urls") or []
            self.active[tunnel.name] = tunnel
            if result.get("replaced"):
                previous = "another tunnel.py on this computer" if result["replaced"] == self.client_name else result["replaced"]
                self.console.warn(f'"{tunnel.name}" was being served by {previous}; it is served from here now.')
        self.waiting = [t for t in self.waiting if t not in tunnels]

    def _work(self) -> None:
        while True:
            item = self._jobs.get()
            try:
                self._handle(item)
            except Exception as error:  # One bad request must not take a worker down.
                self.console.error(f"Could not handle {item.get('method')} {item.get('uri')}: {describe(error)}")

    def _handle(self, item: dict) -> None:
        started = time.monotonic()
        tunnel = self.active.get(item["tunnel"])
        if tunnel is None:
            self.api.respond(item["id"], 0, b"", head={"error": "This tunnel is no longer served."}, end=True)
            return
        try:
            body, size = self._request_body(item)
        except (OSError, http.client.HTTPException, ServerError) as error:
            status, sent, note = self._fail(item, f"The tunnel client could not fetch the request body: {describe(error)}.")
        else:
            try:
                status, sent, note = self._forward(tunnel, item, body, size)
            finally:
                body.close()
        # With several tunnels, each line names its tunnel, padded so the columns line up.
        label = tunnel.name.ljust(max(map(len, self.active))) if len(self.active) > 1 else None
        self.console.request(label, item["method"], item["uri"], status, time.monotonic() - started, sent, note)

    def _request_body(self, item: dict) -> tuple[IO[bytes], int]:
        if "body" in item:
            data = base64.b64decode(item["body"])
            return io.BytesIO(data), len(data)
        size = int(item.get("body_size") or 0)
        if not size:
            return io.BytesIO(), 0
        spool = tempfile.SpooledTemporaryFile(max_size=SPOOL_IN_MEMORY)  # noqa: SIM115 - the caller closes it
        try:
            self.api.download(item["id"], spool)
        except BaseException:
            spool.close()
            raise
        spool.seek(0)
        return spool, size

    def _forward(self, tunnel: Tunnel, item: dict, body: IO[bytes], size: int) -> tuple[int, int, str | None]:
        """Asks the local app and relays its answer. Returns (status, bytes sent, note)."""
        target = tunnel.target
        connection = target.connection()
        try:
            try:
                connection.connect()
            except ConnectionRefusedError:
                return self._fail(item, f"Nothing is listening on {target.netloc}.")
            except socket.timeout:
                return self._fail(item, f"{target} did not accept the connection within {LOCAL_CONNECT_TIMEOUT} seconds.")
            except OSError as error:
                return self._fail(item, f"Could not connect to {target}: {describe(error)}.")
            try:
                connection.sock.settimeout(LOCAL_READ_TIMEOUT)
                connection.putrequest(item["method"], request_target(item["uri"]), skip_host=True, skip_accept_encoding=True)
                for name, value in self._local_headers(tunnel, item["headers"]):
                    connection.putheader(name, value)
                if size or item["method"] in ("POST", "PUT", "PATCH"):
                    connection.putheader("Content-Length", str(size))
                connection.endheaders()
                for block in iter(lambda: body.read(64 * 1024), b""):
                    connection.send(block)
            except ValueError as error:
                return self._fail(item, f"The request could not be passed on to {target}: {describe(error)}.")
            except OSError as error:
                return self._fail(item, f"{target} dropped the connection while receiving the request: {describe(error)}.")
            try:
                response = connection.getresponse()
            except socket.timeout:
                return self._fail(item, f"{target} did not answer within {LOCAL_READ_TIMEOUT} seconds.")
            except (OSError, http.client.HTTPException) as error:
                return self._fail(item, f"{target} did not send a valid response: {describe(error)}.")
            head = {"status": response.status, "headers": [[name, header_text(value)] for name, value in response.getheaders()]}
            return self._relay(item["id"], head, response)
        finally:
            connection.close()

    def _local_headers(self, tunnel: Tunnel, headers: list) -> Iterator[tuple[str, bytes]]:
        has_host = False
        for name, value in headers:
            if name.lower() == "host":
                has_host = True
                if self.rewrite_host:
                    value = tunnel.target.netloc
            yield name, value.encode("utf-8")
        if not has_host:
            yield "Host", tunnel.target.netloc.encode("ascii")

    def _relay(self, request_id: str, head: dict, response: http.client.HTTPResponse) -> tuple[int, int, str | None]:
        pipe = Pipe(4 * CHUNK_SIZE)
        threading.Thread(target=pump, args=(response, pipe), daemon=True).start()
        unsent_head: dict | None = head
        seq = 0
        sent = 0
        try:
            while True:
                data, finished = pipe.take(CHUNK_SIZE, FIRST_PIECE_WAIT if unsent_head else 0)
                if not self.api.respond(request_id, seq, data, unsent_head, finished):
                    return head["status"], sent, "The visitor left before the response finished."
                unsent_head = None
                sent += len(data)
                if data:
                    seq += 1
                if finished:
                    return head["status"], sent, pipe.error
        finally:
            pipe.close()

    def _fail(self, item: dict, message: str) -> tuple[int, int, str]:
        self.api.respond(item["id"], 0, b"", head={"error": message}, end=True)
        return 502, 0, message


class Backoff:
    """Waits that double up to half a minute, with jitter so many clients do not retry in step."""

    def __init__(self) -> None:
        self._attempt = 0

    def next(self) -> float:
        wait = min(30.0, 2.0 ** self._attempt)
        self._attempt += 1
        return wait * random.uniform(0.8, 1.2)

    def reset(self) -> None:
        self._attempt = 0


# ----- Commands ------------------------------------------------------------------------------


EXAMPLES = """\
targets:
  3000                          localhost:3000 under a random name
  myapp=3000                    localhost:3000 as "myapp"
  myapp=192.168.1.20:8080       another machine on your network
  myapp=https://localhost:8443  a local HTTPS server (its certificate is not checked)

first time:
  python3 tunnel.py login https://tunnel.example.com
"""


def main(argv: list | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(errors="replace")
    if argv[:1] == ["login"]:
        return login(argv[1:])
    if argv[:1] == ["logout"]:
        return logout()

    parser = argparse.ArgumentParser(
        prog="tunnel.py",
        usage="python3 tunnel.py [options] TARGET [TARGET ...]\n       python3 tunnel.py login SERVER [TOKEN]",
        description="Share local ports through your own Tunnel server.",
        epilog=EXAMPLES,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("targets", nargs="+", metavar="TARGET", help="what to share: PORT, NAME=PORT, or NAME=HOST:PORT")
    parser.add_argument("--auth", metavar="USER:PASSWORD", help="make visitors sign in with this username and password")
    parser.add_argument("--host-header", choices=("preserve", "rewrite"), default="preserve",
                        help="send your app the public host name (preserve, the default) or the local one "
                             "(rewrite; for dev servers that reject unknown hosts, like Vite)")
    parser.add_argument("--server", metavar="URL", help="tunnel server (default: the one from login, or $TUNNEL_SERVER)")
    parser.add_argument("--token", help="server token (default: the one from login, or $TUNNEL_TOKEN)")
    parser.add_argument("--workers", type=int, default=16, metavar="N", help="requests handled at the same time (default: 16)")
    parser.add_argument("--insecure", action="store_true", help="accept an invalid HTTPS certificate on the tunnel server")
    parser.add_argument("-q", "--quiet", action="store_true", help="do not print each request")
    parser.add_argument("--version", action="version", version=f"tunnel.py {VERSION}")
    if not argv:
        parser.print_help()
        return 0
    args = parser.parse_args(argv)

    tunnels = []
    for text in args.targets:
        try:
            tunnels.append(parse_spec(text))
        except ValueError as error:
            parser.error(str(error))
    names = [t.name for t in tunnels if t.name]
    if len(names) != len(set(names)):
        parser.error("each tunnel needs its own name")
    if args.auth is not None and ":" not in args.auth:
        parser.error("--auth takes USER:PASSWORD")
    if args.workers < 1:
        parser.error("--workers must be at least 1")

    config = load_config()
    server = args.server or os.environ.get("TUNNEL_SERVER") or config.get("server")
    token = args.token or os.environ.get("TUNNEL_TOKEN") or config.get("token")
    if not server or not token:
        print("Sign in to your server first:\n\n    python3 tunnel.py login https://tunnel.example.com\n", file=sys.stderr)
        return 2

    color = sys.stdout.isatty() and "NO_COLOR" not in os.environ and os.environ.get("TERM") != "dumb" \
        and (os.name != "nt" or "WT_SESSION" in os.environ or "TERM" in os.environ)
    console = Console(color, args.quiet)
    try:
        api = Api(normalize_server(server), token, insecure=args.insecure or bool(config.get("insecure")))
        client = Client(api, tunnels, console, args.auth, args.host_header == "rewrite", args.workers)
    except Fatal as error:
        console.error(str(error))
        return 1

    # Treat `kill` and a closed terminal window like Ctrl+C, so the tunnels go offline cleanly.
    signal.signal(signal.SIGTERM, stop)
    if hasattr(signal, "SIGHUP"):
        signal.signal(signal.SIGHUP, stop)
    try:
        return client.run()
    except Fatal as error:
        console.error(str(error))
        return 1
    except KeyboardInterrupt:
        signal.signal(signal.SIGINT, signal.SIG_DFL)
        console.print()
        console.note("Closing tunnels.")
        client.close()
        return 0


def stop(signum: int, frame: object) -> None:
    raise KeyboardInterrupt


def login(argv: list) -> int:
    parser = argparse.ArgumentParser(prog="tunnel.py login", description="Remember your Tunnel server and token.")
    parser.add_argument("server", help="address of your Tunnel server, like https://tunnel.example.com")
    parser.add_argument("token", nargs="?", help="the token from the server's config.php (asked for when left out)")
    parser.add_argument("--insecure", action="store_true", help="accept an invalid HTTPS certificate on the server")
    args = parser.parse_args(argv)
    server = normalize_server(args.server)
    token = args.token or getpass.getpass("Token: ").strip()
    try:
        info = Api(server, token, args.insecure).call("GET", "ping", timeout=15)
    except Fatal as error:
        print(f"Could not sign in: {error}", file=sys.stderr)
        return 1
    except (OSError, http.client.HTTPException, ServerError) as error:
        print(f"Could not reach {server}: {describe(error)}", file=sys.stderr)
        return 1
    settings = {"server": server, "token": token}
    if args.insecure:
        settings["insecure"] = True
    save_config(settings)
    print(f"Signed in to {server} (server version {info.get('version', 'unknown')}).")
    print(f"Saved to {config_path()}.")
    print("\nShare a port with:\n\n    python3 tunnel.py 3000\n")
    return 0


def logout() -> int:
    with contextlib.suppress(FileNotFoundError):
        os.remove(config_path())
    print("Signed out.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
