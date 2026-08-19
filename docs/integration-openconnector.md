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

### Fetching in-process instead of over HTTP

A same-instance PHP caller can resolve a secret without the loopback request or
a bearer token:

```php
$secret = $secretService->getByNameForApplication(
    name: 'ZGW source credentials',
    applicationId: $applicationId,
);
```

It returns the entity with the ciphertext intact — the server decrypts nothing
(ADR-003), so the caller still decrypts `key` / `login` / `additionalFields`
with its own private key. Scoping matches the HTTP route: the query is keyed by
the application id, and a name belonging to another vault is indistinguishable
from one that does not exist. Both return `null`, which is deliberate — a
distinguishable "exists but not yours" would be an existence oracle.

A hit dispatches exactly one `application.secret_retrieved` audit event with the
application as actor, the same as the HTTP full read. A `null` dispatches
nothing, so probing for names leaves no audit trail to mine.

Note the asymmetry with the create seam below: this method takes an application
id, because the CALLER has already been authenticated by whatever put it in the
process. Creating a request takes a signed assertion instead, because a mutation
should not be reachable from an id alone.

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

## Asking a human to fill in a credential

A connector importing a source usually knows *which* credentials it needs and
not *what they are* — an API key belongs to whoever operates the far end. A
secret request turns that into a link: the connector creates the request, a human
opens the link and submits the values, and the connector reads them back
afterwards. The connector never handles the plaintext, and neither does the
server.

Create one in the application's **own** vault:

- `POST /apps/doriath/api/v1/app/secret-requests`

```json
{
  "requestedFields": [
    { "field": "url",              "visibility": "public" },
    { "field": "api-key",          "visibility": "secret" },
    { "field": "api-interface-id", "visibility": "additional" }
  ],
  "name": "ZGW source credentials",
  "folderPath": "infra/zgw",
  "expiresAt": "2026-12-31T00:00:00+00:00"
}
```

`requestedFields` also accepts a bare list of names. `name`, `folderPath` and
`expiresAt` are optional; an unknown `folderPath` is refused rather than filed at
the vault root, and it is walked through the **caller's own** folders so it can
never resolve into another vault.

`201` returns the request with the two things worth keeping:

```json
{
  "id": "…",
  "secretId": "…",
  "status": "pending",
  "requestedFields": ["url", "api-key", "api-interface-id"],
  "token": "…",
  "fillLinkUrl": "https://<host>/index.php/apps/doriath/public#/share/request/<token>",
  "fillApiUrl": "https://<host>/index.php/apps/doriath/api/v1/public/secret-requests/<token>",
  "expiresAt": "2026-12-31T00:00:00+00:00"
}
```

Send `fillLinkUrl` to the human — it is the anonymous page they can open
without a Nextcloud account. `fillApiUrl` is the JSON endpoint behind it, for
polling from code; do not send that one to a person.

> **Known limitation (verified 2026-08-17).** The anonymous page currently
> renders blank in a headless browser. This is not specific to secret requests:
> every route on the `/public` shell — link shares and ephemeral sends included —
> renders empty for an anonymous visitor, and it reproduces on a freshly built
> bundle. The API surface documented here is unaffected and fully working; the
> human hand-off leg is not, and is tracked separately.

There is no need to create the Secret first —
the shell is created for you, owned by the calling application, and removed again
if the request itself fails.

Lost the response? The fill-link is retrievable:

- `GET /apps/doriath/api/v1/app/secret-requests` — the caller's own PENDING
  requests, each with its `token`, `fillLinkUrl` and `fillApiUrl`.

Requests created by a **user** against the application's vault are deliberately
not listed here: they are the user's to manage.

### Which field names to ask for

A request may name any field a secret supports, and the name decides where the
submitted value lands:

| Requested name | Ends up in | Encrypted |
|---|---|---|
| `key` | the secret's `key` | yes |
| `login` | the secret's `login` | yes |
| `url` | the secret's `url` | **no** — plaintext searchable metadata |
| anything else | a member of the encrypted `additionalFields` blob | yes |

So `api-key` and `api-interface-id` above are not columns; they are members
inside one encrypted blob, and their names live on the request rather than on the
secret. Read them back the way you read any other field — through the envelope,
decrypting `additionalFields` with your own private key and taking the members
out of the resulting JSON object.

One limitation stated plainly: the server cannot verify that a named additional
member was actually filled, because it never decrypts the blob (ADR-003). It can
confirm only that a blob arrived. If a connector needs per-member certainty, it
must check after decrypting.

### In-process instead of HTTP

Same-instance PHP callers can skip the loopback request:

```php
$requestService->createForApplicationBySignedProof(
    assertion: $jwtAssertion,   // the same RS256 assertion the token route takes
    requestedFields: ['api-key'],
);
```

The seam takes a **signed assertion**, not an application id. An id is a public
identifier, so accepting one would let any code in the process create requests in
any application's vault. Verification runs through the same path as the Bearer
token — signature against the registered certificate, `jti` replay refusal, the
≤300 s assertion lifetime, and issuer-must-be-active — and the vault is taken
from the verified `iss`.

### Refusals

Creation is refused, with nothing written and no shell left behind, when the
application is pending, rejected or deleted, when its EncryptionSuite is revoked
or compromised, and while a compromise-recovery rotation is in progress in that
vault. Each successful creation emits one `application.secret_request_created`
audit event with the application as actor; its metadata records how many fields
were asked for, never their names.

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
