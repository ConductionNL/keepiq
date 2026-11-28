"""Golden test of the client's observable behaviour over a scripted transport.

Every scenario drives the public surface (constructor, reads, list, writes)
against a fake ``urlopen`` and records the requests sent and the outcome:
the decrypted secret or the raised error. The record is compared with
tests/golden/client_golden.json. KEEPIQ_RECORD_GOLDEN=1 rewrites that file.
"""

from __future__ import annotations

import base64
import copy
import dataclasses
import email.message
import io
import json
import os
import sys
import unittest
import urllib.error
import urllib.parse
from datetime import datetime, timedelta, timezone
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
TESTDATA = os.path.normpath(os.path.join(HERE, "..", "..", "testdata"))
GOLDEN = os.path.join(HERE, "golden", "client_golden.json")
sys.path.insert(0, os.path.join(HERE, "..", "src"))

from cryptography.hazmat.primitives import hashes, serialization  # noqa: E402
from cryptography.hazmat.primitives.asymmetric import padding, rsa  # noqa: E402

from keepiq_sdk import Client, ClientConfig, Credentials, crypto  # noqa: E402
from keepiq_sdk import client as client_module  # noqa: E402

with open(os.path.join(TESTDATA, "machine_envelope.json"), encoding="utf-8") as _fh:
    FIXTURE = json.load(_fh)

BASE = "https://cloud.example.org/index.php"
DISCOVERY = BASE + "/apps/keepiq/api/v1/app/.well-known/keepiq"
TOKEN = "https://cloud.example.org/index.php/apps/keepiq/api/v1/app/token"
SECRETS = BASE + "/apps/keepiq/api/v1/app/secrets"
DISCOVERY_DOC = {"tokenEndpoint": "/index.php/apps/keepiq/api/v1/app/token"}
KEY = crypto.load_private_key(FIXTURE["privateKeyPem"])


def envelope(**changes) -> dict:
    env = copy.deepcopy(FIXTURE["envelope"])
    for path, value in changes.items():
        section, _, name = path.partition("__")
        if name:
            if value is None:
                env[section].pop(name, None)
            else:
                env[section][name] = value
        elif value is None:
            env.pop(section, None)
        else:
            env[section] = value
    return env


def ok_token(**extra) -> tuple:
    return (200, {}, json.dumps({"access_token": "tok-1", **extra}).encode())


def answer(status: int, payload=None, headers=None) -> tuple:
    if isinstance(payload, bytes):
        body = payload
    else:
        body = b"" if payload is None else json.dumps(payload).encode()
    return (status, headers or {}, body)


SECRET_HEADERS = {"ETag": '"e1"', "Doriath-Lease-Id": "lease-1", "Doriath-Lease-Expires": "2026-11-01T10:00:00Z"}


class _Answer:
    def __init__(self, status: int, headers: email.message.Message, body: bytes) -> None:
        self.status = status
        self.headers = headers
        self._body = body

    def read(self) -> bytes:
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc) -> None:
        return None


class Transport:
    """A scripted ``urlopen``: answers per (method, url), the last answer repeats."""

    def __init__(self, script: dict) -> None:
        self.script = {k: list(v) for k, v in script.items()}
        self.sent: list = []

    def __call__(self, req, timeout=None):
        method, url = req.get_method(), req.full_url
        self.sent.append(self.describe(req, timeout))
        queue = self.script.get((method, url))
        if not queue:
            raise urllib.error.URLError(f"unscripted {method} {url}")
        status, headers, body = queue.pop(0) if len(queue) > 1 else queue[0]
        msg = email.message.Message()
        for k, v in headers.items():
            msg[k] = v
        if status >= 400 or status == 304:
            raise urllib.error.HTTPError(url, status, "scripted", msg, io.BytesIO(body))
        return _Answer(status, msg, body)

    @staticmethod
    def describe(req, timeout) -> dict:
        return {
            "method": req.get_method(),
            "url": req.full_url,
            "timeout": timeout,
            "headers": sorted([k, v] for k, v in req.header_items()),
            "data": describe_body(req.full_url, req.data),
        }


def describe_body(url: str, data):
    if data is None:
        return None
    if url == TOKEN or url.endswith("/token"):
        form = dict(urllib.parse.parse_qsl(data.decode()))
        form["assertion"] = describe_assertion(form.get("assertion", ""))
        return form
    doc = json.loads(data)
    return {k: (crypto.decrypt_field(v, KEY) if k in ("key", "login", "additionalFields") else v) for k, v in doc.items()}


def describe_assertion(jwt: str) -> dict:
    header, claims, sig = jwt.split(".")

    def unb64(part: str) -> bytes:
        return base64.urlsafe_b64decode(part + "=" * (-len(part) % 4))

    body = json.loads(unb64(claims))
    jti = body.pop("jti")
    try:
        KEY.public_key().verify(unb64(sig), (header + "." + claims).encode(), padding.PKCS1v15(), hashes.SHA256())
        verified = True
    except Exception:  # noqa: BLE001
        verified = False
    return {"header": json.loads(unb64(header)), "claims": body, "jti_len": len(jti), "verified": verified}


def outcome(value):
    if isinstance(value, BaseException):
        attrs = {}
        for k, v in sorted(vars(value).items()):
            if k == "candidates":
                v = [dataclasses.asdict(c) for c in v]
            attrs[k] = v
        return {"error": type(value).__name__, "text": str(value), "attrs": attrs}
    if isinstance(value, list):
        return [outcome(v) for v in value]
    if dataclasses.is_dataclass(value):
        return dataclasses.asdict(value)
    return value


class Clock:
    def __init__(self, start: float = 1_700_000_000.0) -> None:
        self.now = start

    def __call__(self) -> float:
        return self.now


def other_key_pem() -> str:
    other = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    return other.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()
    ).decode()


def standard(extra: dict | None = None, doc: dict | None = None) -> dict:
    script = {
        ("GET", DISCOVERY): [answer(200, doc or DISCOVERY_DOC)],
        ("POST", TOKEN): [ok_token(expires_in=3600)],
    }
    script.update(extra or {})
    return script


def run(script: dict, steps, ctor=None) -> dict:
    """Run ``steps(client, clock)`` (a list of thunks) and record each outcome."""
    transport = Transport(script)
    clock = Clock()
    record: dict = {}
    with mock.patch.object(client_module.urllib.request, "urlopen", transport):
        try:
            args, kwargs = ctor or ((BASE, "billing", FIXTURE["privateKeyPem"]), {})
            (url, app, pem), options = args, dict(kwargs)
            credentials = Credentials(app, pem, options.pop("certificate_pem", None))
            c = Client(ClientConfig(url, credentials, clock=clock, **options))
        except Exception as err:  # noqa: BLE001
            # Error text can come from cryptography and varies per version.
            record["ctor"] = {"error": type(err).__name__}
            return record
        results = []
        for step in steps:
            try:
                results.append(outcome(step(c, clock)))
            except Exception as err:  # noqa: BLE001
                results.append(outcome(err))
        record["results"] = results
    record["sent"] = transport.sent
    return record


def get_id(sid: str = "sec-cli-fixture"):
    return lambda c, clock: c.get_by_id(sid)


def advance(seconds: float):
    def step(c, clock):
        clock.now += seconds

    return step


def scenarios() -> dict:
    by_id = ("GET", SECRETS + "/sec-cli-fixture")
    env = envelope()
    out: dict = {}

    for url in ("", "cloud.example.org", "https://", "https://h", "https://h/index.php/", "ftp://x/y", "http://h:8080/a//"):
        for app in ("", "billing"):
            out[f"ctor url={url!r} app={app!r}"] = run(
                standard({("GET", url.rstrip("/") + "/apps/keepiq/api/v1/app/secrets/sec-cli-fixture"): [answer(404)]}),
                [get_id()],
                ctor=((url, app, FIXTURE["privateKeyPem"]), {}),
            )
    out["ctor bad pem"] = run(standard(), [], ctor=((BASE, "billing", "not a pem"), {}))
    out["ctor cert matches"] = run(
        standard({by_id: [answer(200, env, SECRET_HEADERS)]}),
        [get_id()],
        ctor=((BASE, "billing", FIXTURE["privateKeyPem"]), {"certificate_pem": FIXTURE["certificatePem"], "timeout": 4.5}),
    )
    out["ctor cert of another key"] = run(
        standard(), [], ctor=((BASE, "billing", other_key_pem()), {"certificate_pem": FIXTURE["certificatePem"]})
    )
    out["ctor empty cert"] = run(
        standard({by_id: [answer(200, envelope(encryption__certificateFingerprint="sha256:00"))]}),
        [get_id()],
        ctor=((BASE, "billing", FIXTURE["privateKeyPem"]), {"certificate_pem": ""}),
    )

    cert = {"certificate_pem": FIXTURE["certificatePem"]}
    fp = env["encryption"]["certificateFingerprint"]
    envs = {
        "plain": env,
        "no encryption": envelope(encryption=None),
        "empty encryption": envelope(encryption={}),
        "wrong scheme": envelope(encryption__scheme="rsa-v0"),
        "fp upper": envelope(encryption__certificateFingerprint=fp.upper()),
        "fp other": envelope(encryption__certificateFingerprint="sha256:00"),
        "fp missing": envelope(encryption__certificateFingerprint=None),
        "fp empty": envelope(encryption__certificateFingerprint=""),
        "no secret meta": envelope(secret=None),
        "folder null": envelope(secret__folderPath=None),
        "no ciphertext": envelope(ciphertext=None),
        "partial ciphertext": envelope(ciphertext={"key": env["ciphertext"]["key"], "login": ""}),
        "extra meta": envelope(secret__extra="x"),
    }
    for name, body in envs.items():
        for with_cert in (False, True):
            out[f"open {name} cert={with_cert}"] = run(
                standard({by_id: [answer(200, body, SECRET_HEADERS)]}),
                [get_id()],
                ctor=((BASE, "billing", FIXTURE["privateKeyPem"]), cert if with_cert else {}),
            )

    errors = {
        "304": answer(304, None, {"ETag": '"e1"'}),
        "400 text": answer(400, b"  bad request \n"),
        "401 then 200": None,
        "401 twice": answer(401, {"message": "revoked"}),
        "403 message": answer(403, {"message": "denied"}),
        "403 empty": answer(403, b""),
        "403 list": answer(403, [1, 2]),
        "404": answer(404, {"message": "gone"}),
        "409 candidates": answer(
            409,
            {"message": "dup", "candidates": [{"id": "a", "name": "n", "folderPath": None, "updatedAt": "t"}, {}]},
        ),
        "409 empty candidates": answer(409, {"candidates": []}),
        "409 no candidates": answer(409, {"message": "conflict"}),
        "409 list body": answer(409, ["candidates"]),
        "500 json message": answer(500, {"message": "boom"}),
        "500 json no message": answer(500, {"error": "boom"}),
        "500 empty message": answer(500, {"message": ""}),
        "500 number message": answer(500, {"message": 7}),
        "500 invalid json": answer(500, b"{not json"),
        "500 bad utf8": answer(500, b"\xff\xfe oops"),
        "500 empty": answer(500, b""),
        "500 null": answer(500, b"null"),
        "502 string json": answer(502, b'"candidates"'),
    }
    for name, resp in errors.items():
        if resp is None:
            script = standard({by_id: [answer(401), answer(200, env, SECRET_HEADERS)]})
            script[("POST", TOKEN)] = [ok_token(expires_in=3600), (200, {}, json.dumps({"access_token": "tok-2"}).encode())]
        else:
            script = standard({by_id: [resp]})
        named = ("GET", SECRETS + "/by-name/ci-fixture-db-password")
        script[named] = script[by_id]
        script[("GET", SECRETS)] = script[by_id]
        script[("POST", SECRETS)] = script[by_id]
        script[("PUT", SECRETS + "/sec-cli-fixture")] = script[by_id]
        out[f"status {name}"] = run(
            script,
            [
                get_id(),
                lambda c, clock: c.get_by_name("ci-fixture-db-password"),
                lambda c, clock: c.list(),
                lambda c, clock: c.create({"name": "n", "key": "k"}),
                lambda c, clock: c.update("sec-cli-fixture", {"login": "l"}),
            ],
        )

    out["etag read twice"] = run(
        standard({by_id: [answer(200, env, SECRET_HEADERS), answer(304, None, SECRET_HEADERS), answer(200, env, {})]}),
        [get_id(), get_id(), get_id(), get_id()],
    )
    out["etag empty is not sent"] = run(
        standard({by_id: [answer(200, env, {"ETag": ""}), answer(200, env, {"ETag": ""})]}),
        [get_id(), get_id()],
    )
    out["lease without expiry"] = run(
        standard({by_id: [answer(200, env, {"Doriath-Lease-Id": "L"})]}), [get_id()]
    )

    names = ("GET", SECRETS + "/by-name/x%2Fy%20z?folder=a%20b%2Fc")
    out["by name quoting and folder"] = run(
        standard({names: [answer(200, env)], ("GET", SECRETS + "/by-name/x%2Fy%20z"): [answer(200, env)]}),
        [
            lambda c, clock: c.get_by_name("x/y z", "a b/c"),
            lambda c, clock: c.get_by_name("x/y z", ""),
            lambda c, clock: c.get_by_name("x/y z"),
        ],
    )
    out["by id quoting"] = run(
        standard({("GET", SECRETS + "/a%2Fb%3Fc"): [answer(200, env)]}), [get_id("a/b?c")]
    )

    advertised = {
        "tokenEndpoint": "https://auth.example.org/token",
        "grantType": "urn:custom",
        "assertion": {"audience": "aud-x"},
        "secrets": {
            "byName": "/index.php/s/by-name/{name}/{name}",
            "byId": "https://other.example.org/s/{id}",
            "list": "/index.php/s",
            "create": "http://plain.example.org/s",
            "update": "/index.php/s/{id}",
        },
    }
    out["advertised endpoints"] = run(
        {
            ("GET", DISCOVERY): [answer(200, advertised)],
            ("POST", "https://auth.example.org/token"): [ok_token(expires_in=120)],
            ("GET", "https://cloud.example.org/index.php/s/by-name/a%20b/{name}"): [answer(200, env)],
            ("GET", "https://other.example.org/s/i%2F1"): [answer(200, env)],
            ("GET", "https://cloud.example.org/index.php/s"): [answer(200, {"items": [env]})],
            ("POST", "http://plain.example.org/s"): [answer(201, env)],
            ("PUT", "https://cloud.example.org/index.php/s/i%201"): [answer(200, env)],
        },
        [
            lambda c, clock: c.get_by_name("a b"),
            get_id("i/1"),
            lambda c, clock: c.list(),
            lambda c, clock: c.create({"name": "n", "key": "k", "url": "u", "typeId": "t", "login": "", "additionalFields": "a"}),
            lambda c, clock: c.update("i 1", {}),
        ],
    )
    out["advertised partial and empty"] = run(
        standard(
            {("GET", SECRETS + "/sec-cli-fixture"): [answer(200, env)], ("GET", "https://cloud.example.org/x"): [answer(200, {})]},
            doc={**DISCOVERY_DOC, "secrets": {"byId": "", "list": "/x"}, "assertion": {}},
        ),
        [get_id(), lambda c, clock: c.list()],
    )
    out["secrets null and grant empty"] = run(
        standard(
            {by_id: [answer(200, env)]},
            doc={**DISCOVERY_DOC, "secrets": None, "grantType": "", "assertion": None},
        ),
        [get_id()],
    )

    out["discovery 500"] = run({("GET", DISCOVERY): [answer(500, b" down \n")]}, [get_id(), get_id()])
    out["discovery 404 empty"] = run({("GET", DISCOVERY): [answer(404, b"")]}, [get_id()])
    out["discovery without token endpoint"] = run(
        {("GET", DISCOVERY): [answer(200, {"tokenEndpoint": ""})]}, [get_id()]
    )
    out["discovery invalid json"] = run({("GET", DISCOVERY): [answer(200, b"<html>")]}, [get_id()])
    out["discovery cached after success"] = run(
        standard({by_id: [answer(200, env)]}), [get_id(), get_id(), get_id()]
    )

    tokens = {
        "no expires_in": ok_token(),
        "expires_in 0": ok_token(expires_in=0),
        "expires_in 30": ok_token(expires_in=30),
        "expires_in 60": ok_token(expires_in=60),
        "expires_in 61": ok_token(expires_in=61),
        "expires_in string": ok_token(expires_in="45"),
        "expires_in null": ok_token(expires_in=None),
        "expires_in bad": ok_token(expires_in="soon"),
        "400": answer(400, b"invalid_grant"),
        "401": answer(401, b" nope "),
        "403": answer(403, b""),
        "404": answer(404, b"missing"),
        "500": answer(500, b"\xffboom"),
        "no access_token": answer(200, {"token_type": "bearer"}),
        "empty access_token": answer(200, {"access_token": ""}),
        "invalid json": answer(200, b"nope"),
    }
    for name, resp in tokens.items():
        script = standard({by_id: [answer(200, env)]})
        script[("POST", TOKEN)] = [resp, ok_token(expires_in=1000)]
        out[f"token {name}"] = run(
            script,
            [
                get_id(),
                advance(14),
                get_id(),
                advance(1),
                get_id(),
                advance(15),
                get_id(),
                advance(1000),
                get_id(),
            ],
        )

    out["list updated_since"] = run(
        standard(
            {
                ("GET", SECRETS): [answer(200, {"items": [env, envelope(secret__id="b")]})],
                ("GET", SECRETS + "?updated_since=2026-10-01T12%3A30%3A05Z"): [answer(200, {"items": []})],
                ("GET", SECRETS + "?updated_since=2026-10-01T10%3A30%3A05Z"): [answer(200, {})],
            }
        ),
        [
            lambda c, clock: c.list(),
            lambda c, clock: c.list(datetime(2026, 10, 1, 12, 30, 5, 999)),
            lambda c, clock: c.list(datetime(2026, 10, 1, 12, 30, 5, tzinfo=timezone(timedelta(hours=2)))),
            lambda c, clock: c.list(datetime(2026, 10, 1, 12, 30, 5, tzinfo=timezone.utc)),
        ],
    )
    out["list status 200 needed"] = run(standard({("GET", SECRETS): [answer(201, {"items": []})]}), [lambda c, clock: c.list()])
    out["create needs 201"] = run(standard({("POST", SECRETS): [answer(200, env)]}), [lambda c, clock: c.create({"name": "n"})])
    out["update needs 200"] = run(
        standard({("PUT", SECRETS + "/s"): [answer(201, env)]}), [lambda c, clock: c.update("s", {"name": "n"})]
    )
    out["unknown field"] = run(
        standard(),
        [
            lambda c, clock: c.create({"name": "n", "password": "p"}),
            lambda c, clock: c.update("s", {"folder": "f"}),
        ],
    )
    out["create empty payload"] = run(standard({("POST", SECRETS): [answer(201, env, SECRET_HEADERS)]}), [lambda c, clock: c.create({})])
    return out


class ClientGoldenTest(unittest.TestCase):
    def test_matches_golden(self) -> None:
        got = json.loads(json.dumps(scenarios(), default=repr))
        if os.environ.get("KEEPIQ_RECORD_GOLDEN") == "1":
            os.makedirs(os.path.dirname(GOLDEN), exist_ok=True)
            with open(GOLDEN, "w", encoding="utf-8") as fh:
                json.dump(got, fh, indent=1, sort_keys=True, ensure_ascii=False)
                fh.write("\n")
        with open(GOLDEN, encoding="utf-8") as fh:
            want = json.load(fh)
        self.assertEqual(sorted(want), sorted(got))
        for name in want:
            self.assertEqual(want[name], got[name], name)


if __name__ == "__main__":
    unittest.main()
