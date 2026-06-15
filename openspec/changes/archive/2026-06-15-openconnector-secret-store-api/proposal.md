## Why

`docs/FEATURES.md` names "Sister app integration" with OpenConnector at **V1** and lists Doriath-as-Nextcloud-native-secret-store as a core competitive advantage — yet no integration contract is specced anywhere. `implement-application-mgmt` builds the raw machinery (application registration, EncryptionSuites via CSR, RFC 7523 JWT bearer auth, `GET /api/v1/app/secrets`), but machinery is not a contract: a machine consumer that wants "give me the secret called `zgw-api-token`" today has to list everything and match client-side, gets no stable response envelope to code against, no way to discover endpoints, no cheap rotation polling, and no documented recipe for how an OpenConnector source/synchronization config should reference a Doriath secret.

Without this contract, every OpenConnector deployment keeps doing what it does now: API keys and passwords pasted into connector configuration fields, stored wherever OpenConnector stores them. The whole point of the sister-app story is that machine credentials live in the vault — encrypted to the consuming application's own key, rotatable in one place, every retrieval auditable — and connectors hold only a *reference*.

This is a contract-first change: it defines the Doriath-side machine API surface precisely enough that the OpenConnector-side consumer (an authentication/value-resolver plugin living in openconnector's own repo and openspec) can be built and contract-tested against it independently, with a shared Newman collection as the executable handshake between the two repos.

## What Changes

- Implement a **discovery document** (`GET /api/v1/app/.well-known/doriath`): unauthenticated, machine-readable JSON declaring the API version, token endpoint, supported grant type (`urn:ietf:params:oauth:grant-type:jwt-bearer`), assertion requirements, and secret endpoints — so consumers configure one base URL and derive the rest
- Implement **name-addressable secret retrieval**: `GET /api/v1/app/secrets/by-name/{name}` (optional `folder` path query) resolving within the authenticated application's own vault; ambiguous names return a 409 listing candidate ids, unknown names a 404 — machine consumers reference secrets by stable name, not UUID
- Define the **versioned encrypted response envelope** for all machine secret reads: `format`/`version`, secret metadata (id, name, url, folder path, type, `key_updated_at`-equivalent timestamps), the ciphertext fields, and encryption context (suite id, certificate fingerprint, cipher format) — the server returns ciphertext only, per ADR-003; the consumer decrypts with its own private key
- Implement **rotation polling**: strong `ETag` on single-secret reads honoring `If-None-Match` (304), and `?updated_since=` filtering on the application's secret list — a connector cron can cheaply detect rotated credentials
- Implement the **machine write path**: `POST /api/v1/app/secrets` and `PUT /api/v1/app/secrets/{id}` accepting payloads the application encrypted with its *own* public certificate — a connector that rotates a downstream token can write the new value back to the vault it reads from
- Specify **token endpoint hardening** as testable requirements (the application-mgmt design sketches these; the contract makes them normative): `jti` replay rejection, assertion expiry bounds, brute-force throttling on the token endpoint, opaque 5-minute bearer tokens, and strict own-vault scoping of every `/api/v1/app/*` route
- Define the **secret reference format** `doriath://{applicationId}/{folderPath}/{name}` that OpenConnector (and any other consumer) embeds in configuration instead of credential values, plus the resolution algorithm (discovery → token → by-name fetch → local decrypt)
- Ship the **contract artifacts**: a Newman collection (`tests/integration/machine-secret-api.postman_collection.json`) exercising discovery, token exchange, by-name resolution, envelope shape, 304/updated_since rotation flow, write-back, and the negative auth cases — the executable contract the openconnector-side change consumes; plus `docs/integration-openconnector.md` with the consumption recipe and key-custody guidance

## Capabilities

### New Capabilities
- `secret-store-api`: The versioned machine-to-machine secret-store contract on top of application JWT auth — discovery document, name-addressable retrieval with the encrypted response envelope, ETag/updated_since rotation polling, application write-back, normative token-endpoint hardening, the `doriath://` reference format, and the cross-repo contract test collection

### Modified Capabilities
_(none in delta form — application registration, approval, CSR/key handling, and the JWT mechanism stay exactly as the application-mgmt spec defines them; this capability layers a stable consumer contract on the endpoints that change introduces)_

## Impact

- **Database**: No new tables, no schema changes. By-name resolution queries existing plaintext metadata (`name`, folder tree)
- **Backend**: Extends `ApplicationApiController` (by-name endpoint, ETag handling, updated_since filter, write endpoints), new `DiscoveryController` (well-known document), throttling annotations/brute-force protection on the token endpoint, `jti` replay cache (per application-mgmt design D9, made normative here)
- **Frontend**: None — this is a headless machine API. (The human write-without-read UI for application vaults belongs to `implement-application-mgmt`)
- **API**: `GET /api/v1/app/.well-known/doriath` (public), `GET /api/v1/app/secrets/by-name/{name}`, `POST /api/v1/app/secrets`, `PUT /api/v1/app/secrets/{id}` (all bearer-token, own-vault scoped); ETag/`updated_since` semantics on the existing reads
- **Dependencies**: Depends on `implement-application-mgmt` (Application entity, JWT auth service/middleware, `/api/v1/token`, base `/api/v1/app/secrets` endpoints) and `implement-secrets` (Secret/Folder entities). Coordinates with `add-secret-audit-trail` (`application.token_issued` / `application.secret_retrieved` events give every machine read a trail). The OpenConnector-side resolver is explicitly out of scope — it lives in the openconnector repo with its own openspec change, built against the Newman contract
- **Security**: The server never sees plaintext machine secrets (write payloads arrive pre-encrypted to the app's own certificate; reads return ciphertext) — the E2E model extends to machines, where the "browser" is the consumer process holding the private key. Bearer tokens are opaque, 5-minute, and scope to exactly one application's vault; cross-application access is structurally impossible (the token resolves to one application id and every query is keyed by it). Token endpoint throttled and replay-protected
- **Cross-app**: OpenConnector holds the application private key (its own credential storage) and embeds only `doriath://` references in connector configs; the recipe documents key custody, rotation, and the re-registration recovery path when a key is lost
