#!/usr/bin/env python3
"""A small in-memory Keepiq machine API for tests. TEST ONLY.

It serves discovery, the RFC 7523 token exchange (checking the assertion's
RS256 signature against the test key in machine_envelope.json), and the
secrets routes: list with ``updated_since``, by id and by name (404, envelope,
or 409 with candidates), create and update, with ETags, 304 and lease headers.
Every request body is recorded so a test can prove no plaintext was sent.

Used by the Python library's tests and by the GitHub Action and GitLab
template test workflow::

    python3 sdk/testdata/stub_server.py --port 8099 [--add NAME=VALUE ...]

``--add`` files an extra secret encrypted to the test key, so a pipeline test
can read a name of its choosing.
"""

from __future__ import annotations

import argparse
import base64
import copy
import hashlib
import json
import os
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

HERE = os.path.dirname(os.path.abspath(__file__))
WEBROOT = "/index.php"
API = WEBROOT + "/apps/keepiq/api/v1/app/secrets"
TOKEN = WEBROOT + "/apps/keepiq/api/v1/app/token"
DISCOVERY = WEBROOT + "/apps/keepiq/api/v1/app/.well-known/keepiq"


def load_fixture() -> dict:
    with open(os.path.join(HERE, "machine_envelope.json"), encoding="utf-8") as fh:
        return json.load(fh)


class Stub:
    def __init__(self, application_id: str = "billing") -> None:
        fixture = load_fixture()
        self.application_id = application_id
        self.private_key = serialization.load_pem_private_key(fixture["privateKeyPem"].encode(), password=None)
        self.public_key = self.private_key.public_key()
        env = fixture["envelope"]
        self.fingerprint = env["encryption"]["certificateFingerprint"]
        self.envelopes = {env["secret"]["id"]: copy.deepcopy(env)}
        self.exchanges = 0
        self.bodies: list[str] = []
        self.revoke_next = False
        self.lock = threading.Lock()

    def add(self, name: str, value: str) -> dict:
        """File a secret whose key field is ``value`` encrypted to the test key."""
        from_bytes = value.encode("utf-8")
        size = self.public_key.key_size // 8
        chunk = size - 66
        chunks = [from_bytes[i : i + chunk] for i in range(0, len(from_bytes), chunk)] or [b""]
        oaep = padding.OAEP(mgf=padding.MGF1(algorithm=hashes.SHA256()), algorithm=hashes.SHA256(), label=None)
        raw = len(chunks).to_bytes(4, "big") + b"".join(self.public_key.encrypt(c, oaep) for c in chunks)
        env = self.new_envelope("sec-" + hashlib.sha256(name.encode()).hexdigest()[:12], {"name": name, "key": base64.b64encode(raw).decode()})
        self.envelopes[env["secret"]["id"]] = env
        return env

    def new_envelope(self, sid: str, fields: dict) -> dict:
        return {
            "format": "doriath-machine-secret-v1",
            "secret": {
                "id": sid,
                "name": fields.get("name"),
                "url": fields.get("url"),
                "folderPath": "",
                "type": fields.get("typeId"),
                "createdAt": "2026-10-02T10:00:00+00:00",
                "updatedAt": "2026-10-02T10:00:00+00:00",
                "keyUpdatedAt": "2026-10-02T10:00:00+00:00",
            },
            "encryption": {"suiteId": "suite-cli-fixture", "certificateFingerprint": self.fingerprint, "scheme": "rsa-oaep-sha256-chunked-v1"},
            "ciphertext": {k: fields.get(k) for k in ("key", "login", "additionalFields")},
        }

    def valid_assertion(self, assertion: str) -> bool:
        parts = assertion.split(".")
        if len(parts) != 3:
            return False

        def unb64(s: str) -> bytes:
            return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))

        try:
            self.public_key.verify(unb64(parts[2]), (parts[0] + "." + parts[1]).encode(), padding.PKCS1v15(), hashes.SHA256())
        except Exception:
            return False
        claims = json.loads(unb64(parts[1]))
        return (
            claims.get("iss") == self.application_id
            and claims.get("sub") == self.application_id
            and claims.get("aud") == "keepiq"
            and bool(claims.get("jti"))
        )

    def serve(self, port: int = 0) -> ThreadingHTTPServer:
        stub = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):  # quiet
                pass

            def _send(self, code: int, payload=None, headers=None):
                raw = json.dumps(payload).encode() if payload is not None else b""
                self.send_response(code)
                for k, v in (headers or {}).items():
                    self.send_header(k, v)
                if payload is not None:
                    self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                if raw:
                    self.wfile.write(raw)

            def _envelope(self, env: dict):
                raw = json.dumps(env, sort_keys=True).encode()
                etag = '"' + hashlib.sha256(raw).hexdigest()[:16] + '"'
                headers = {"ETag": etag, "Doriath-Lease-Id": "lease-7", "Doriath-Lease-Expires": "2026-10-02T13:00:00+00:00"}
                if self.headers.get("If-None-Match") == etag:
                    return self._send(304, None, headers)
                return self._send(200, env, headers)

            def _handle(self):
                length = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(length).decode() if length else ""
                url = urllib.parse.urlparse(self.path)
                path = url.path
                with stub.lock:
                    if body:
                        stub.bodies.append(body)
                    if path == DISCOVERY:
                        return self._send(200, {
                            "apiVersion": 1,
                            "tokenEndpoint": TOKEN,
                            "grantType": "urn:ietf:params:oauth:grant-type:jwt-bearer",
                            "assertion": {"alg": "RS256", "audience": "keepiq"},
                            "secrets": {"list": API, "byId": API + "/{id}", "byName": API + "/by-name/{name}", "create": API, "update": API + "/{id}"},
                            "lease": {"supported": True},
                        })
                    if path == TOKEN and self.command == "POST":
                        form = dict(urllib.parse.parse_qsl(body))
                        if form.get("grant_type") != "urn:ietf:params:oauth:grant-type:jwt-bearer" or not stub.valid_assertion(form.get("assertion", "")):
                            return self._send(401, {"error": "invalid_grant"})
                        stub.exchanges += 1
                        return self._send(200, {"access_token": f"tok-{stub.exchanges}", "token_type": "Bearer", "expires_in": 300})
                    if stub.exchanges == 0 or self.headers.get("Authorization") != f"Bearer tok-{stub.exchanges}" or stub.revoke_next:
                        stub.revoke_next = False
                        return self._send(401, {"message": "Bearer token required"})
                    query = dict(urllib.parse.parse_qsl(url.query))
                    if path == API and self.command == "GET":
                        since = query.get("updated_since")
                        items = [e for e in stub.envelopes.values() if not since or e["secret"]["updatedAt"] > since]
                        return self._send(200, {"format": "doriath-machine-secret-v1", "items": items, "total": len(items)})
                    if path.startswith(API + "/by-name/"):
                        name = urllib.parse.unquote(path[len(API + "/by-name/"):])
                        hits = [e for e in stub.envelopes.values() if e["secret"]["name"] == name]
                        if not hits:
                            return self._send(404, {"message": "Secret not found"})
                        if len(hits) > 1:
                            cands = [{k: e["secret"][k] for k in ("id", "name", "folderPath", "updatedAt")} for e in hits]
                            return self._send(409, {"message": "Multiple secrets match this name", "candidates": cands})
                        return self._envelope(hits[0])
                    if path == API and self.command == "POST":
                        fields = json.loads(body or "{}")
                        env = stub.new_envelope(f"sec-new-{len(stub.envelopes)}", fields)
                        stub.envelopes[env["secret"]["id"]] = env
                        return self._send(201, env)
                    if path.startswith(API + "/"):
                        sid = urllib.parse.unquote(path[len(API) + 1:])
                        env = stub.envelopes.get(sid)
                        if env is None:
                            return self._send(404, {"message": "Secret not found"})
                        if self.command == "PUT":
                            fields = json.loads(body or "{}")
                            for k in ("key", "login", "additionalFields"):
                                if k in fields:
                                    env["ciphertext"][k] = fields[k]
                            env["secret"]["updatedAt"] = "2026-10-02T12:00:00+00:00"
                        return self._envelope(env)
                    return self._send(404, {"message": "no route " + path})

            do_GET = do_POST = do_PUT = _handle

        server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        return server


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--port", type=int, default=8099)
    parser.add_argument("--application", default="billing")
    parser.add_argument("--add", action="append", default=[], metavar="NAME=VALUE")
    args = parser.parse_args()
    stub = Stub(args.application)
    for item in args.add:
        name, _, value = item.partition("=")
        stub.add(name, value)
    server = stub.serve(args.port)
    print(f"stub Keepiq on http://127.0.0.1:{server.server_address[1]}{WEBROOT}", flush=True)
    threading.Event().wait()


if __name__ == "__main__":
    main()
