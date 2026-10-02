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
from typing import Any, Callable, Dict, List, Mapping, Optional, Tuple

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
    key: str = ""
    login: str = ""
    additional_fields: str = ""
    etag: Optional[str] = None
    lease: Optional[Lease] = None
    _extra: Dict[str, Any] = field(default_factory=dict, repr=False)


class Client:
    """A Keepiq instance seen by one application.

    ``url`` is the Nextcloud address (include ``/index.php`` when the instance
    has no pretty URLs). ``certificate_pem`` is optional: when given, it must
    belong to the key, and every envelope encrypted to another certificate is
    refused before decryption.
    """

    def __init__(
        self,
        url: str,
        application_id: str,
        private_key_pem: str,
        *,
        certificate_pem: Optional[str] = None,
        timeout: float = 30.0,
        clock: Callable[[], float] = time.time,
    ) -> None:
        parsed = urllib.parse.urlparse(url)
        if not parsed.scheme or not parsed.netloc:
            raise ValueError(f"invalid base URL {url!r}")
        if not application_id:
            raise ValueError("application id is required")
        self._base = url.rstrip("/")
        self._origin = f"{parsed.scheme}://{parsed.netloc}"
        self._app = application_id
        self._key = crypto.load_private_key(private_key_pem)
        self._fingerprint: Optional[str] = None
        if certificate_pem:
            if not crypto.certificate_matches_key(certificate_pem, self._key):
                raise KeyMismatchError("the certificate does not belong to the private key")
            self._fingerprint = crypto.certificate_fingerprint(certificate_pem)
        self._timeout = timeout
        self._clock = clock
        self._lock = threading.Lock()
        self._discovery: Optional[dict] = None
        self._token: Optional[str] = None
        self._token_expiry = 0.0
        self._etags: Dict[str, str] = {}

    # --- public surface ---

    def get_by_name(self, name: str, folder: Optional[str] = None) -> Secret:
        """Read the secret with exactly this name, optionally inside a folder path."""
        addr = self._endpoint("byName", _SECRETS + "/by-name/{name}", "{name}", name)
        if folder:
            addr += "?folder=" + urllib.parse.quote(folder, safe="")
        return self._read(addr, name)

    def get_by_id(self, secret_id: str) -> Secret:
        return self._read(self._endpoint("byId", _SECRETS + "/{id}", "{id}", secret_id), secret_id)

    def list(self, updated_since: Optional[datetime] = None) -> List[Secret]:
        """Every secret, or only those updated strictly after ``updated_since``."""
        addr = self._endpoint("list", _SECRETS)
        if updated_since is not None:
            if updated_since.tzinfo is None:
                updated_since = updated_since.replace(tzinfo=timezone.utc)
            stamp = updated_since.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            addr += "?updated_since=" + urllib.parse.quote(stamp, safe="")
        status, headers, body = self._request("GET", addr)
        if status != 200:
            raise self._status_error(status, body, "")
        page = json.loads(body)
        return [self._open(env) for env in page.get("items", [])]

    def create(self, fields: Mapping[str, str]) -> Secret:
        """File a new secret. ``name`` and ``key`` are required."""
        payload = self._payload(fields)
        status, headers, body = self._request("POST", self._endpoint("create", _SECRETS), payload)
        if status != 201:
            raise self._status_error(status, body, "")
        return self._decode(headers, body)

    def update(self, secret_id: str, fields: Mapping[str, str]) -> Secret:
        """Replace the named fields of one secret; others stay as they are."""
        payload = self._payload(fields)
        addr = self._endpoint("update", _SECRETS + "/{id}", "{id}", secret_id)
        status, headers, body = self._request("PUT", addr, payload)
        if status != 200:
            raise self._status_error(status, body, "")
        return self._decode(headers, body)

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
        status, headers, body = self._request("GET", addr, etag=etag)
        if status == 304:
            raise NotModifiedError(label)
        if status != 200:
            raise self._status_error(status, body, label)
        secret = self._decode(headers, body)
        if secret.etag:
            with self._lock:
                self._etags[addr] = secret.etag
        return secret

    def _decode(self, headers: Mapping[str, str], body: bytes) -> Secret:
        secret = self._open(json.loads(body))
        secret.etag = headers.get("etag")
        lease_id = headers.get("doriath-lease-id")
        if lease_id:
            secret.lease = Lease(id=lease_id, expires=headers.get("doriath-lease-expires", ""))
        return secret

    def _open(self, env: dict) -> Secret:
        enc = env.get("encryption") or {}
        if enc.get("scheme") != crypto.SCHEME:
            raise ApiError(0, f"unsupported encryption scheme {enc.get('scheme')!r}")
        fp = enc.get("certificateFingerprint")
        if self._fingerprint and fp and fp.lower() != self._fingerprint.lower():
            raise KeyMismatchError("the envelope is encrypted to a different certificate")
        meta = env.get("secret") or {}
        ct = env.get("ciphertext") or {}
        values = {}
        for name in SECRET_FIELDS:
            blob = ct.get(name)
            values[name] = crypto.decrypt_field(blob, self._key) if blob else ""
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

    @staticmethod
    def _status_error(status: int, body: bytes, label: str) -> Exception:
        try:
            data = json.loads(body) if body else {}
        except ValueError:
            data = {}
        message = data.get("message") if isinstance(data, dict) else None
        if status == 404:
            return NotFoundError(label)
        if status in (401, 403):
            return UnauthorizedError(message or "unauthorized")
        if status == 409 and isinstance(data, dict) and "candidates" in data:
            cands = [
                Candidate(id=c.get("id", ""), name=c.get("name", ""), folder_path=c.get("folderPath") or "", updated_at=c.get("updatedAt"))
                for c in data["candidates"]
            ]
            return AmbiguousNameError(label, cands)
        return ApiError(status, message or body.decode("utf-8", "replace").strip())

    def _endpoint(self, kind: str, fallback: str, placeholder: str = "", value: str = "") -> str:
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
        status, _, body = self._raw("GET", self._base + DISCOVERY_PATH)
        if status != 200:
            raise ApiError(status, "discovery: " + body.decode("utf-8", "replace").strip())
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
        form = urllib.parse.urlencode(
            {
                "grant_type": doc.get("grantType") or "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion": self._assertion(doc, int(now)),
            }
        ).encode()
        status, _, body = self._raw(
            "POST", self._resolve(doc["tokenEndpoint"]), form, {"Content-Type": "application/x-www-form-urlencoded"}
        )
        if status in (400, 401, 403):
            raise UnauthorizedError(f"token exchange answered {status}: {body.decode('utf-8', 'replace').strip()}")
        if status != 200:
            raise ApiError(status, "token exchange: " + body.decode("utf-8", "replace").strip())
        tok = json.loads(body)
        token = tok.get("access_token")
        if not token:
            raise ApiError(status, "token exchange returned no access_token")
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

    def _request(
        self, method: str, addr: str, payload: Optional[dict] = None, etag: Optional[str] = None
    ) -> Tuple[int, Dict[str, str], bytes]:
        data = json.dumps(payload).encode() if payload is not None else None
        for attempt in (0, 1):
            headers = {"Authorization": "Bearer " + self._bearer(), "Accept": "application/json"}
            if data is not None:
                headers["Content-Type"] = "application/json"
            if etag:
                headers["If-None-Match"] = etag
            status, resp_headers, body = self._raw(method, addr, data, headers)
            if status == 401 and attempt == 0:
                # A token revoked or expired early: drop it and retry once.
                with self._lock:
                    self._token = None
                continue
            return status, resp_headers, body
        raise AssertionError("unreachable")

    def _raw(
        self, method: str, addr: str, data: Optional[bytes] = None, headers: Optional[Dict[str, str]] = None
    ) -> Tuple[int, Dict[str, str], bytes]:
        req = urllib.request.Request(addr, data=data, method=method, headers=headers or {})
        try:
            with urllib.request.urlopen(req, timeout=self._timeout) as resp:
                return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read()
        except urllib.error.HTTPError as err:
            return err.code, {k.lower(): v for k, v in err.headers.items()}, err.read()
