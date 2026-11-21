"""Keepiq machine API client.

Every value is decrypted and encrypted in this process. Only ciphertext and a
signed RFC 7523 assertion cross the network; the private key never does.
"""

from __future__ import annotations

import base64
import json
import secrets as _random
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Callable, Dict, List, Mapping, NamedTuple, Optional, Tuple

from . import crypto
from .errors import (
    AmbiguousNameError,
    ApiError,
    Candidate,
    KeyMismatchError,
    NotFoundError,
    NotModifiedError,
    UnauthorizedError,
)

DISCOVERY_PATH = "/apps/keepiq/api/v1/app/.well-known/keepiq"
SECRET_FIELDS = ("key", "login", "additionalFields")
METADATA_FIELDS = ("name", "url", "typeId")
_SECRETS = "/apps/keepiq/api/v1/app/secrets"


@dataclass
class Lease:
    id: str
    expires: str


@dataclass
class Secret:
    """One decrypted secret with its metadata."""

    id: str
    name: str
    url: Optional[str] = None
    folder_path: str = ""
    type: Optional[str] = None
    created_at: Optional[str] = None
    updated_at: Optional[str] = None
    key_updated_at: Optional[str] = None
    key: str = field(default="", repr=False)
    login: str = field(default="", repr=False)
    additional_fields: str = field(default="", repr=False)
    etag: Optional[str] = None
    lease: Optional[Lease] = None
    _extra: Dict[str, Any] = field(default_factory=dict, repr=False)


# Fallback path and its placeholder per endpoint, used when discovery advertises none.
_FALLBACKS = {
    "byName": (_SECRETS + "/by-name/{name}", "{name}"),
    "byId": (_SECRETS + "/{id}", "{id}"),
    "list": (_SECRETS, ""),
    "create": (_SECRETS, ""),
    "update": (_SECRETS + "/{id}", "{id}"),
}


@dataclass
class _Call:
    """One HTTP request as sent on the wire."""

    method: str
    addr: str
    data: Optional[bytes] = field(default=None, repr=False)
    headers: Dict[str, str] = field(default_factory=dict, repr=False)


class _Response(NamedTuple):
    """One HTTP answer; ``repr()`` leaves out the body, which can hold an access token."""

    status: int
    headers: Dict[str, str]
    body: bytes

    def __repr__(self) -> str:
        return f"_Response(status={self.status!r}, body=<{len(self.body)} bytes>)"


def _split_base(url: str) -> Tuple[str, str]:
    """The base URL without a trailing slash, and its origin."""
    parsed = urllib.parse.urlparse(url)
    if not parsed.scheme or not parsed.netloc:
        raise ValueError(f"invalid base URL {url!r}")
    return url.rstrip("/"), f"{parsed.scheme}://{parsed.netloc}"


def _required(value: str, what: str) -> str:
    if not value:
        raise ValueError(f"{what} is required")
    return value


def _fingerprint_for(certificate_pem: Optional[str], key: Any) -> Optional[str]:
    """The certificate's fingerprint after checking it belongs to ``key``; None without a certificate."""
    if not certificate_pem:
        return None
    if not crypto.certificate_matches_key(certificate_pem, key):
        raise KeyMismatchError("the certificate does not belong to the private key")
    return crypto.certificate_fingerprint(certificate_pem)


def _text(body: bytes) -> str:
    return body.decode("utf-8", "replace").strip()


def _json_object(body: bytes) -> Dict[str, Any]:
    """The body as a JSON object; {} when it is empty, invalid or not an object."""
    try:
        data = json.loads(body) if body else {}
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def _message(data: Mapping[str, Any], default: Any) -> Any:
    return data.get("message") or default


def _candidates(rows: List[Dict[str, Any]]) -> List[Candidate]:
    return [
        Candidate(id=c.get("id", ""), name=c.get("name", ""), folder_path=c.get("folderPath") or "", updated_at=c.get("updatedAt"))
        for c in rows
    ]


def _check_scheme(enc: Mapping[str, Any]) -> None:
    if enc.get("scheme") != crypto.SCHEME:
        raise ApiError(0, f"unsupported encryption scheme {enc.get('scheme')!r}")


def _secret_from(meta: Mapping[str, Any], values: Mapping[str, str]) -> Secret:
    return Secret(
        id=meta.get("id", ""),
        name=meta.get("name", ""),
        url=meta.get("url"),
        folder_path=meta.get("folderPath") or "",
        type=meta.get("type"),
        created_at=meta.get("createdAt"),
        updated_at=meta.get("updatedAt"),
        key_updated_at=meta.get("keyUpdatedAt"),
        key=values["key"],
        login=values["login"],
        additional_fields=values["additionalFields"],
    )


@dataclass(frozen=True)
class Credentials:
    """Who the client acts as: the application id, its private key and, optionally, its certificate.

    ``repr()`` shows only the application id. ``certificate_pem`` is optional: when given, it must belong to the key, and
    every envelope encrypted to another certificate is refused before decryption.
    """

    application_id: str
    private_key_pem: str = field(repr=False)
    certificate_pem: Optional[str] = field(default=None, repr=False)


@dataclass(frozen=True)
class ClientConfig:
    """Everything a ``Client`` needs.

    ``url`` is the Nextcloud address (include ``/index.php`` when the instance
    has no pretty URLs). ``timeout`` is in seconds per request; ``clock``
    returns the current time in seconds.
    """

    url: str
    credentials: Credentials
    timeout: float = 30.0
    clock: Callable[[], float] = time.time


_OLD_FORM = (
    "Client() takes one ClientConfig since keepiq-sdk 1.0: "
    "Client(ClientConfig(url, Credentials(application_id, private_key_pem, certificate_pem)))"
)


class Client:
    """A Keepiq instance seen by one application, built from one ``ClientConfig``."""

    def __init__(self, config: Optional[ClientConfig] = None, *legacy: object, **legacy_options: object) -> None:
        if legacy or legacy_options or not isinstance(config, ClientConfig):
            raise TypeError(_OLD_FORM)
        self._base, self._origin = _split_base(config.url)
        self._app = _required(config.credentials.application_id, "application id")
        self._key = crypto.load_private_key(config.credentials.private_key_pem)
        self._fingerprint = _fingerprint_for(config.credentials.certificate_pem, self._key)
        self._timeout, self._clock = config.timeout, config.clock
        self._init_session()

    def __repr__(self) -> str:
        return f"Client(url={self._base!r}, application_id={self._app!r})"

    def _init_session(self) -> None:
        self._lock = threading.Lock()
        self._discovery: Optional[dict] = None
        self._token: Optional[str] = None
        self._token_expiry = 0.0
        self._etags: Dict[str, str] = {}

    # --- public surface ---

    def get_by_name(self, name: str, folder: Optional[str] = None) -> Secret:
        """Read the secret with exactly this name, optionally inside a folder path."""
        addr = self._endpoint("byName", name)
        if folder:
            addr += "?folder=" + urllib.parse.quote(folder, safe="")
        return self._read(addr, name)

    def get_by_id(self, secret_id: str) -> Secret:
        return self._read(self._endpoint("byId", secret_id), secret_id)

    def list(self, updated_since: Optional[datetime] = None) -> List[Secret]:
        """Every secret, or only those updated strictly after ``updated_since``."""
        addr = self._endpoint("list")
        if updated_since is not None:
            if updated_since.tzinfo is None:
                updated_since = updated_since.replace(tzinfo=timezone.utc)
            stamp = updated_since.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            addr += "?updated_since=" + urllib.parse.quote(stamp, safe="")
        resp = self._request(_Call("GET", addr))
        if resp.status != 200:
            raise self._status_error(resp, "")
        page = json.loads(resp.body)
        return [self._open(env) for env in page.get("items", [])]

    def create(self, fields: Mapping[str, str]) -> Secret:
        """File a new secret. ``name`` and ``key`` are required."""
        data = json.dumps(self._payload(fields)).encode()
        resp = self._request(_Call("POST", self._endpoint("create"), data))
        if resp.status != 201:
            raise self._status_error(resp, "")
        return self._decode(resp)

    def update(self, secret_id: str, fields: Mapping[str, str]) -> Secret:
        """Replace the named fields of one secret; others stay as they are."""
        data = json.dumps(self._payload(fields)).encode()
        resp = self._request(_Call("PUT", self._endpoint("update", secret_id), data))
        if resp.status != 200:
            raise self._status_error(resp, "")
        return self._decode(resp)

    # --- internals ---

    def _payload(self, fields: Mapping[str, str]) -> Dict[str, str]:
        pub = self._key.public_key()
        out: Dict[str, str] = {}
        for name, value in fields.items():
            if name in SECRET_FIELDS:
                out[name] = crypto.encrypt_field(value, pub)
            elif name in METADATA_FIELDS:
                out[name] = value
            else:
                allowed = ", ".join(METADATA_FIELDS + SECRET_FIELDS)
                raise ValueError(f"unknown field {name!r} (allowed: {allowed})")
        return out

    def _read(self, addr: str, label: str) -> Secret:
        with self._lock:
            etag = self._etags.get(addr)
        resp = self._request(_Call("GET", addr, None, {"If-None-Match": etag} if etag else {}))
        if resp.status == 304:
            raise NotModifiedError(label)
        if resp.status != 200:
            raise self._status_error(resp, label)
        secret = self._decode(resp)
        if secret.etag:
            with self._lock:
                self._etags[addr] = secret.etag
        return secret

    def _decode(self, resp: _Response) -> Secret:
        secret = self._open(json.loads(resp.body))
        secret.etag = resp.headers.get("etag")
        lease_id = resp.headers.get("doriath-lease-id")
        if lease_id:
            secret.lease = Lease(id=lease_id, expires=resp.headers.get("doriath-lease-expires", ""))
        return secret

    def _open(self, env: dict) -> Secret:
        enc = env.get("encryption") or {}
        _check_scheme(enc)
        self._check_fingerprint(enc.get("certificateFingerprint"))
        return _secret_from(env.get("secret") or {}, self._decrypt_values(env.get("ciphertext") or {}))

    def _check_fingerprint(self, fp: Any) -> None:
        if not self._fingerprint:
            return
        if not fp or not isinstance(fp, str):
            raise KeyMismatchError("the envelope names no certificate fingerprint")
        if fp.lower() != self._fingerprint.lower():
            raise KeyMismatchError("the envelope is encrypted to a different certificate")

    def _decrypt_values(self, ct: Mapping[str, str]) -> Dict[str, str]:
        values = {}
        for name in SECRET_FIELDS:
            blob = ct.get(name)
            values[name] = crypto.decrypt_field(blob, self._key) if blob else ""
        return values

    @staticmethod
    def _status_error(resp: _Response, label: str) -> Exception:
        data = _json_object(resp.body)
        if resp.status == 404:
            return NotFoundError(label)
        if resp.status in (401, 403):
            return UnauthorizedError(_message(data, "unauthorized"))
        if resp.status == 409 and "candidates" in data:
            return AmbiguousNameError(label, _candidates(data["candidates"]))
        return ApiError(resp.status, _message(data, _text(resp.body)))

    def _endpoint(self, kind: str, value: str = "") -> str:
        fallback, placeholder = _FALLBACKS[kind]
        advertised = (self._discover().get("secrets") or {}).get(kind)
        path = advertised or fallback
        if placeholder:
            path = path.replace(placeholder, urllib.parse.quote(value, safe=""), 1)
        if not advertised:
            return self._base + path
        return self._resolve(path)

    def _resolve(self, path: str) -> str:
        # Discovery paths are absolute and already carry the web root.
        if path.startswith("http://") or path.startswith("https://"):
            return path
        return self._origin + path

    def _discover(self) -> dict:
        with self._lock:
            if self._discovery is not None:
                return self._discovery
        status, _, body = self._raw(_Call("GET", self._base + DISCOVERY_PATH))
        if status != 200:
            raise ApiError(status, "discovery: " + _text(body))
        doc = json.loads(body)
        if not doc.get("tokenEndpoint"):
            raise ApiError(status, "discovery has no tokenEndpoint")
        with self._lock:
            self._discovery = doc
        return doc

    def _bearer(self) -> str:
        doc = self._discover()
        now = self._clock()
        with self._lock:
            if self._token and now < self._token_expiry:
                return self._token
        return self._store_token(self._exchange(doc, now), now)

    def _exchange(self, doc: dict, now: float) -> Dict[str, Any]:
        """Trade a fresh assertion for a token; the answer holds a non-empty ``access_token``."""
        form = urllib.parse.urlencode(
            {
                "grant_type": doc.get("grantType") or "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion": self._assertion(doc, int(now)),
            }
        ).encode()
        call = _Call("POST", self._resolve(doc["tokenEndpoint"]), form, {"Content-Type": "application/x-www-form-urlencoded"})
        status, _, body = self._raw(call)
        if status in (400, 401, 403):
            raise UnauthorizedError(f"token exchange answered {status}: {_text(body)}")
        if status != 200:
            raise ApiError(status, "token exchange: " + _text(body))
        tok = json.loads(body)
        if not tok.get("access_token"):
            raise ApiError(status, "token exchange returned no access_token")
        return tok

    def _store_token(self, tok: Mapping[str, Any], now: float) -> str:
        token = tok["access_token"]
        life = float(tok.get("expires_in") or 60)
        margin = 30.0 if life > 60 else life / 2
        with self._lock:
            self._token = token
            self._token_expiry = now + life - margin
        return token

    def _assertion(self, doc: dict, now: int) -> str:
        aud = (doc.get("assertion") or {}).get("audience") or "keepiq"

        def b64(data: bytes) -> str:
            return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")

        header = b64(b'{"alg":"RS256","typ":"JWT"}')
        claims = b64(
            json.dumps(
                {"iss": self._app, "sub": self._app, "aud": aud, "iat": now, "exp": now + 300, "jti": _random.token_hex(16)},
                separators=(",", ":"),
            ).encode()
        )
        signing_input = header + "." + claims
        return signing_input + "." + crypto.sign_rs256(signing_input, self._key)

    def _request(self, call: _Call) -> _Response:
        for attempt in (0, 1):
            resp = self._raw(self._authorized(call))
            if resp.status == 401 and attempt == 0:
                # A token revoked or expired early: drop it and retry once.
                with self._lock:
                    self._token = None
                continue
            return resp
        raise AssertionError("unreachable")

    def _authorized(self, call: _Call) -> _Call:
        """The call with a bearer token, JSON accept and content headers before its own headers."""
        headers = {"Authorization": "Bearer " + self._bearer(), "Accept": "application/json"}
        if call.data is not None:
            headers["Content-Type"] = "application/json"
        headers.update(call.headers)
        return _Call(call.method, call.addr, call.data, headers)

    def _raw(self, call: _Call) -> _Response:
        req = urllib.request.Request(call.addr, data=call.data, method=call.method, headers=call.headers)
        try:
            with urllib.request.urlopen(req, timeout=self._timeout) as resp:
                return _Response(resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read())
        except urllib.error.HTTPError as err:
            return _Response(err.code, {k.lower(): v for k, v in err.headers.items()}, err.read())
