## Context

`implement-application-mgmt` D9 establishes the auth substrate: an application signs a JWT with the RSA private key it got at registration, exchanges it at `POST /api/v1/token` for an opaque 5-minute bearer token (with a `jti` replay cache and certificate-based signature verification), then calls `GET /api/v1/app/secrets` / `GET /api/v1/app/secrets/{id}` which return its secrets encrypted under its own EncryptionSuite certificate. FEATURES.md promises OpenConnector integration at V1 on top of that, but the consumer-facing contract — addressing, envelope, discovery, rotation, write-back, hardening guarantees — exists only as implementation sketches inside another change's design doc. A consumer cannot be built against a design sketch in a different repo.

The shape of the consumer matters: OpenConnector resolves credentials inside synchronization/source configurations, typically from a cron context, against potentially many secrets, repeatedly. That dictates name-based addressing (configs reference `zgw-api-token`, not a UUID that differs per environment), cheap change detection (polling hundreds of secrets every 5 minutes must be near-free), and a self-describing envelope (the consumer must know *how* to decrypt without out-of-band knowledge).

ADR-003's always-E2E model extends naturally to machines: the consumer process holding the application private key plays the role the browser plays for users. The server stores and serves ciphertext; decryption happens at the consumer. Nothing in this change weakens that — it makes it contractual.

## Goals / Non-Goals

**Goals:**
- A versioned, discoverable, documented machine contract a consumer in another repo can build against without reading Doriath source
- Name-addressable retrieval with defined ambiguity semantics
- Self-describing encrypted response envelope (consumer knows what to decrypt with, and how)
- Cheap rotation detection (ETag/304 + `updated_since`)
- Application write-back to its own vault (pre-encrypted payloads)
- Normative hardening requirements on the token endpoint and own-vault scoping
- The `doriath://` reference format + resolution algorithm + Newman contract collection as the cross-repo handshake

**Non-Goals:**
- The OpenConnector-side resolver/plugin — lives in the openconnector repo with its own openspec change, contract-tested against the Newman collection shipped here
- New auth mechanisms (OAuth scopes, mTLS, static tokens) — RFC 7523 as chosen by application-mgmt stands; scopes are meaningless while a token grants exactly one app's vault anyway
- Push-based rotation (webhooks/events to consumers) — polling with 304s is sufficient for connector cron cadences; webhook delivery to arbitrary consumer URLs is an SSRF/retry/queue problem deferred until someone demonstrates polling pain
- Cross-application secret access or sharing user secrets to applications via this API — the human write-without-read path (application-mgmt) is how values get *into* an app vault from users
- Per-secret ACLs within one application's vault — the application is the principal; subdividing it means registering another application
- A Doriath PHP/JS client SDK — the contract is small enough that the recipe + Newman collection suffice for v1

## Decisions

### D1: Contract-First — a Capability Spec, Not Just Endpoints

The deliverable is the `secret-store-api` capability spec + the Newman collection, treated as the public, versioned surface. The discovery document carries `apiVersion` (starts at `1`); breaking envelope or addressing changes require a version bump and a new well-known entry, never an in-place mutation. **Why:** the consumer is developed in a different repo on a different timeline; the only thing protecting it from drift is an executable contract both CIs run (this is also the fleet's Playwright-UI/Newman-API division of labor — machine API behaviour belongs in Newman).

### D2: Discovery via a Well-Known Document

`GET /api/v1/app/.well-known/doriath` (public, no auth — it reveals only endpoint shapes, nothing instance-private):

```json
{
  "apiVersion": 1,
  "tokenEndpoint": "/apps/doriath/api/v1/token",
  "grantType": "urn:ietf:params:oauth:grant-type:jwt-bearer",
  "assertion": { "alg": "RS256", "maxLifetime": 300, "audience": "<tokenEndpoint absolute URL>" },
  "secrets": {
    "list": "/apps/doriath/api/v1/app/secrets",
    "byId": "/apps/doriath/api/v1/app/secrets/{id}",
    "byName": "/apps/doriath/api/v1/app/secrets/by-name/{name}"
  },
  "envelopeFormats": ["doriath-machine-secret-v1"]
}
```

**Why not RFC 8414 (`/.well-known/oauth-authorization-server`):** Doriath is not an authorization server for third parties and must not squat instance-root well-known paths owned by Nextcloud; an app-scoped document with the same spirit is honest and routable. The consumer configures `{nextcloudBaseUrl}` + `{applicationId}` + private key, and derives everything else.

### D3: Name Addressing with Explicit Ambiguity Semantics

`GET /api/v1/app/secrets/by-name/{name}?folder={path}` resolves by exact (case-sensitive) plaintext `name` within the application's vault, optionally constrained to a folder path (slash notation, consistent with the secrets spec's derived paths).

Secret names are not unique in the data model, and changing write validation to force uniqueness would ripple into the human write-without-read flow (two users could race-write the same name). Decision: **resolution defines the semantics, the schema stays untouched** — zero matches → 404; exactly one → the envelope; multiple → `409 Conflict` with a body listing candidate `{id, name, folderPath, updatedAt}` so an operator can disambiguate (rename or use folder-scoped reference). The recipe tells consumers to treat 409 as a configuration error, loudly. **Why not first-match/latest-match:** silently picking one of two same-named credentials is exactly how a connector ends up authenticating with the wrong key in production.

### D4: Self-Describing Encrypted Envelope

All machine reads return `doriath-machine-secret-v1`:

```json
{
  "format": "doriath-machine-secret-v1",
  "secret": {
    "id": "…", "name": "…", "url": "…", "folderPath": "infra/zgw",
    "type": "api_key", "createdAt": "…", "updatedAt": "…", "keyUpdatedAt": "…"
  },
  "encryption": {
    "suiteId": "…",
    "certificateFingerprint": "sha256:…",
    "scheme": "<the cipher/chunking scheme identifier used by the existing encrypt path>"
  },
  "ciphertext": { "key": "<base64>", "login": "<base64|null>", "additionalFields": "<base64|null>" }
}
```

`encryption.certificateFingerprint` lets the consumer fail fast with a clear error when it holds the wrong private key (e.g. after re-registration) instead of surfacing a bare decrypt exception. `keyUpdatedAt` rides along if/when the password-health change lands its column (field is nullable in the envelope; absence is valid v1). The `scheme` identifier names the existing ADR-003 RSA(+chunking) encrypt path — this change does not introduce a new cipher, it *names* the current one so a consumer can implement decryption from documentation. The plaintext fields `name`/`url`/`folderPath` are already non-sensitive per the secrets spec.

### D5: Rotation Polling — ETag + `updated_since`, No Webhooks

- Single reads (`byId`, `by-name`) return a strong `ETag` derived from the row's update state (e.g. hash of id + updated timestamp + ciphertext digest); `If-None-Match` hit → `304` with no body.
- The list endpoint accepts `?updated_since={ISO 8601}` returning only secrets whose `updated_at` is newer, so a connector polls one cheap call per cycle and fetches only changed envelopes.

**Why polling is enough:** connectors already run on cron cadences; a 304 costs one indexed lookup. Webhooks invert the trust direction (Doriath calling consumer-supplied URLs → SSRF surface, delivery state, retry queues) for latency nobody asked for. Revisit as a follow-up change if a consumer demonstrates need (open question).

### D6: Write-Back Is Client-Encrypted, Same as Everything Else

`POST /api/v1/app/secrets` / `PUT /api/v1/app/secrets/{id}` accept the same shape the human write-without-read path produces: plaintext-safe metadata (name, url, folder, type) + fields encrypted by the *sender* with the application's public certificate (which the application trivially holds — it is its own). The server validates metadata + ciphertext envelope shape only; it can never check the plaintext, exactly like the import batch endpoint. Use case driving this: a connector rotates a downstream API token and must persist the new value where it reads it from. Deletion stays human/admin-only (application-mgmt's cascade) — a compromised bearer token being able to *destroy* credentials is a worse failure mode than being able to overwrite them (overwrites are at least visible as `updatedAt` changes and audit entries).

### D7: Hardening Made Normative

Application-mgmt's design sketches the mechanisms; consumers need them as guarantees, so this spec states them as requirements:

- **Token endpoint**: assertion `exp` bounded (≤ 300 s lifetime), `jti` single-use within the assertion lifetime (replay → 400), signature verified against the application's registered certificate, pending/deleted/revoked-suite applications refused, and Nextcloud brute-force throttling (`@BruteForceProtection`-equivalent) on failures.
- **Bearer scope**: every `/api/v1/app/*` route resolves the token to exactly one application id and keys every query by it — requesting another application's secret id returns the same 404 as a nonexistent one (no existence oracle across vaults).
- **No plaintext, ever**: machine endpoints return ciphertext fields only, regardless of any session state. (The server cannot decrypt these anyway — application suites' private keys are either not stored (CSR) or AES-wrapped — but the contract states it so the consumer's threat model can rely on it.)

### D8: The `doriath://` Reference Format and Resolution Algorithm

Reference: `doriath://{applicationId}/{folderPath}/{name}` (folderPath optional, e.g. `doriath://3f2a…/infra/zgw/zgw-api-token`). Consumers store references in configuration; resolution = discovery (cached) → token exchange (cached until expiry minus skew) → `by-name` fetch with folder query → local decrypt → in-memory use only. The recipe (`docs/integration-openconnector.md`) additionally covers: key custody (the private key is the consumer's credential — OpenConnector stores it in its own credential storage, never in a synced config export), re-registration recovery when a key is lost (per application-mgmt: new key pair + new suite; old envelopes become undecryptable — references keep working because they are name-based), and the instruction never to log decrypted values or tokens.

**Why the application id and not a name in the reference:** application names are mutable display strings; the id is the stable principal the JWT `iss`/`sub` already carries.

## Risks / Trade-offs

- **[Risk] Name addressing makes renames breaking changes** — renaming `zgw-api-token` breaks every config referencing it. Inherent to human-readable addressing (same trade-off as Vault paths / K8s secret names); the recipe says treat machine-consumed secret names as API. The 404 is immediate and explicit, not silent.
- **[Risk] Bearer token theft** — 5-minute opaque tokens scoped to one vault bound the blast radius; audit events (`application.token_issued`, `application.secret_retrieved` via add-secret-audit-trail) make abuse visible. No refresh tokens exist to steal.
- **[Risk] 409 ambiguity surfaces late (first poll after a duplicate name appears)** — acceptable: it fails the connector run loudly with actionable candidates; preventing duplicates at write time was rejected in D3 for racing the human flow.
- **[Trade-off] Polling latency for rotation** — a rotated credential propagates at the consumer's poll cadence, not instantly. Accepted per D5.
- **[Trade-off] Envelope exposes metadata to the token holder** — name/url/folder of the app's own secrets; that is the app's own vault listing, already the case in application-mgmt.
- **[Trade-off] Two-repo coordination** — the consumer can drift from the contract between releases. Mitigated by the shared Newman collection running in both CIs and the versioned discovery document.

## Migration Plan

1. **No database migration** — metadata queries and ETag derivation use existing columns
2. **Backend**: DiscoveryController + ApplicationApiController extensions + token-endpoint throttling/replay tasks land behind the existing application-mgmt feature surface; routes registered before the SPA catch-all
3. **Contract artifacts**: Newman collection committed under `tests/integration/`; wired into CI alongside the existing collections
4. **Rollback**: endpoints are additive; removing routes restores the application-mgmt baseline
5. **OpenConnector side**: separate change in the openconnector repo (resolver plugin + config reference UI), developed against the published collection — tracked there, not here

## Open Questions

- **Rotation push**: if a consumer demonstrates that poll-cadence latency hurts (e.g. revoked credential must die NOW), a follow-up change could emit Nextcloud events that an *on-instance* consumer (OpenConnector is one) subscribes to without any webhook/SSRF surface. Deferred until demanded.
- **Reference format adoption fleet-wide**: `doriath://` could become the standard secret-reference scheme for other Conduction apps (openregister source configs, pipelinq integrations). If so, the scheme definition should be lifted into a hydra ADR; for now it lives in this capability + the recipe doc.
- **Assertion audience string**: the discovery document pins the token endpoint absolute URL as `aud`; multi-domain Nextcloud instances (trusted_domains) may need a canonical-URL decision at implementation time.
