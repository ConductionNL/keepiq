<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# OpenConnector integration — machine secret-store API

Doriath exposes a **machine-to-machine secret-store API** so a sister app such
as **OpenConnector** can hold a *reference* to a credential instead of the
credential value. Connector configurations embed a `doriath://` reference;
the actual secret lives encrypted in the Doriath vault, is rotatable in one
place, and every retrieval is auditable.

This is the **Doriath side** of the contract. The OpenConnector-side resolver
(an authentication / value-resolver plugin) lives in the OpenConnector repo
with its own OpenSpec change, built and CI-verified against the shared Newman
collection shipped here:
`tests/integration/machine-secret-api.postman_collection.json`.

## Zero-knowledge model (ADR-003)

The always-end-to-end model extends to machines. The **consumer process that
holds the application private key plays the role the browser plays for users.**

- The server **stores and serves ciphertext only**. It can never decrypt a
  machine secret — the application's suite private key is either never stored
  (CSR registration) or AES-wrapped.
- **Reads** return the encrypted envelope; the consumer decrypts locally.
- **Write-back** payloads arrive **pre-encrypted with the application's own
  public certificate** (which the application trivially holds). The server
  validates only the shape.

The contract guarantees machine endpoints never return a decrypted value
under any condition.

## The reference format

```
doriath://{applicationId}/{folderPath}/{name}
```

- `applicationId` — the **stable** application id (the JWT `iss`/`sub`). Not the
  application name, which is a mutable display string.
- `folderPath` — optional, slash-separated (e.g. `infra/zgw`). Omit for a
  root-level secret.
- `name` — the exact, case-sensitive plaintext secret name.

Examples:

```
doriath://3f2a.../zgw-api-token
doriath://3f2a.../infra/zgw/zgw-api-token
```

Treat machine-consumed secret **names as API**: renaming a referenced secret
breaks every config that points at it (the failure is an immediate, explicit
404 — never a silent wrong-credential).

## Resolution algorithm

1. **Discover** (cache the result): `GET /apps/doriath/api/v1/app/.well-known/doriath`
   (public, no auth). Returns `apiVersion`, the token endpoint, the grant type
   (`urn:ietf:params:oauth:grant-type:jwt-bearer`), assertion requirements
   (`alg: RS256`, `maxLifetime: 300`, `audience`), the secret endpoint paths,
   and `envelopeFormats: ["doriath-machine-secret-v1"]`.
2. **Get a token** (cache until expiry minus a skew): sign an RS256 JWT bearer
   assertion with the application private key and `POST` it to the token
   endpoint as `grant_type` + `assertion`. The assertion claims:
   `iss` = application id, `aud` = `doriath`, `iat` = now, `exp` ≤ `iat + 300`,
   and a **unique `jti`** (single-use within the assertion lifetime). The
   response is an **opaque 5-minute Bearer token** scoped to exactly one
   application's vault.
3. **Fetch by name**: `GET /apps/doriath/api/v1/app/secrets/by-name/{name}`
   with `Authorization: Bearer <token>` and an optional `?folder={path}` to
   scope the reference's `folderPath`. Outcomes:
   - **one match** → the `doriath-machine-secret-v1` envelope (with an `ETag`),
   - **zero matches** → `404`,
   - **multiple matches** → `409 Conflict` with a `candidates` list
     (`{id, name, folderPath, updatedAt}`). Treat a 409 as a **loud
     configuration error**: rename a secret or use a folder-scoped reference.
4. **Decrypt locally** with the application private key (see below). Use the
   value **in memory only**.

## The `doriath-machine-secret-v1` envelope

```json
{
  "format": "doriath-machine-secret-v1",
  "secret": {
    "id": "…", "name": "zgw-api-token", "url": "…",
    "folderPath": "infra/zgw", "type": "api_key",
    "createdAt": "…", "updatedAt": "…", "keyUpdatedAt": "…"
  },
  "encryption": {
    "suiteId": "…",
    "certificateFingerprint": "sha256:…",
    "scheme": "rsa-oaep-sha256-chunked-v1"
  },
  "ciphertext": { "key": "<base64>", "login": "<base64|null>", "additionalFields": "<base64|null>" }
}
```

- `format` — the versioned format identifier. A breaking change to the
  addressing or envelope is published as a **new `apiVersion`** in the
  discovery document, never as an in-place mutation. Pin against `apiVersion`.
- `secret` — plaintext-safe metadata (already non-sensitive per the secrets
  spec). `keyUpdatedAt` is **nullable**; absence is valid in v1.
- `encryption.certificateFingerprint` — the `sha256` of the suite certificate
  DER. **Fail fast** when it does not match the public certificate paired with
  your private key (e.g. after re-registration) instead of surfacing a bare
  decrypt exception.
- `encryption.scheme` = `rsa-oaep-sha256-chunked-v1` — the existing ADR-003
  encrypt path. Decryption procedure:
  1. base64-decode each ciphertext field;
  2. read a leading **4-byte big-endian chunk count** (`pack('N', …)`);
  3. for each 512-byte block, **RSA-decrypt raw** with the private key and
     remove **OAEP padding with SHA-256** as the OAEP/MGF1 hash (NOT SHA-1 —
     PHP's `OPENSSL_PKCS1_OAEP_PADDING` hard-codes SHA-1, so the server pads
     OAEP-SHA256 by hand to stay aligned with the browser's WebCrypto
     `RSA-OAEP` `hash: 'SHA-256'` keys; a consumer must do the same);
  4. concatenate the de-padded chunks to recover the plaintext field.

## Rotation polling

- **`ETag` / `304`** — single reads (`by-id`, `by-name`) return a strong
  `ETag`. Re-fetch with `If-None-Match: <etag>`; an unchanged secret yields
  `304 Not Modified` with no body (one cheap indexed lookup).
- **`updated_since`** — `GET .../app/secrets?updated_since={ISO 8601}` returns
  only secrets changed after that instant. A connector cron polls one cheap
  call per cycle and fetches only changed envelopes. An invalid timestamp is a
  `400`.

Rotation is **poll-based** (no webhooks); a rotated credential propagates at
the consumer's poll cadence.

## Write-back

A connector that rotates a downstream token can persist the new value where it
reads it from:

- `POST /apps/doriath/api/v1/app/secrets` — create;
- `PUT /apps/doriath/api/v1/app/secrets/{id}` — replace the ciphertext
  (advances `updatedAt`, and `keyUpdatedAt` when the `key` blob changes).

Both accept plaintext-safe metadata plus fields the application encrypted with
its **own** public certificate. **There is no delete on the machine surface** —
deletion is a human / administrative operation (a compromised 5-minute bearer
token must not be able to destroy credentials; an overwrite is at least visible
via `updatedAt` and the audit trail).

## Key custody and recovery

- The application **private key is the consumer's credential**. OpenConnector
  stores it in its own credential storage. **Never** embed it in a synced /
  shareable / exported connector config.
- **Re-registration after key loss**: register a new key pair + suite (per the
  application-mgmt flow). Old envelopes become undecryptable, but `doriath://`
  references **keep working** because they are name-based — the next fetch
  returns envelopes encrypted to the new certificate (detectable via the
  changed `certificateFingerprint`).
- **Never log** decrypted secret values or bearer tokens.

## Operator playbook — the 409 ambiguity

A `409` on a by-name fetch means two or more secrets in the application's vault
share that name. Resolve by either:

- **renaming** one of the duplicate secrets so the name is unique, or
- **scoping** the reference to a folder
  (`doriath://{appId}/infra/zgw/zgw-api-token` → the fetch sends
  `?folder=infra/zgw`), so exactly one candidate matches.

The connector run fails loudly with the candidate list rather than silently
authenticating with the wrong credential.

## Security summary

- Bearer tokens are **opaque, 5-minute, single-vault scoped**; there are no
  refresh tokens to steal.
- Token issuance is **brute-force throttled**, **`jti`-replay protected**, and
  refused for pending / rejected / deleted applications and revoked /
  compromised suites; assertion lifetime is bounded to ≤ 300 s.
- Every `/api/v1/app/*` query is keyed by the token's application id —
  requesting another vault's secret returns the **same 404** as a nonexistent
  one (no existence oracle).
- Token issuance and machine reads emit `application.token_issued` /
  `application.secret_retrieved` audit events.
