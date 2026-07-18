# Design: Short-lived credential leases for the machine secret-store API

## Context

Doriath's machine API (`openspec/specs/secret-store-api/spec.md`, `docs/integration-openconnector.md`) authenticates applications with RFC 7523 JWT-bearer assertions exchanged for a 5-minute opaque bearer token (`lib/Service/JwtAuthService.php:253`), then serves versioned encrypted envelopes that only the application can decrypt (ADR-003). Every full read already emits an `application.secret_retrieved` audit event (`lib/Controller/ApplicationSecretsController.php:361`). What is missing is any durable, bounded **grant record**: the token proves identity for 5 minutes, but nothing says "app X's grant to secret Y runs until Z", nothing an admin can revoke, and nothing that ties grant lifetime to rotation. This change adds that record — a lease — as an additive layer that leaves the existing token exchange, envelope, and `doriath://` resolution untouched.

## Goals / Non-Goals

**Goals:**
- A server-recorded, TTL-bounded, renewable, revocable, audited access-grant lease per `(application, secret)`, layered on the existing bearer-authed fetch path.
- Admin policy for default and maximum lease TTL (plus renewable flag), with an optional per-application override.
- Lease expiry that drives a rotation/reminder trigger, and a background job that ages leases out — mirroring existing job patterns.
- Zero change to the `doriath-machine-secret-v1` envelope and the `doriath://` resolution algorithm (lease data rides in headers + discovery, both additive).

**Non-Goals:**
- Dynamic / per-lease credential minting (impossible for static zero-knowledge secrets — the honest boundary vs. Vault dynamic engines).
- A Kubernetes operator, CSI driver, or agent sidecar.
- Clawing back a value a consumer already decrypted (physically impossible under ADR-003; revocation is a governance/rotation signal, optionally blocking future re-fetch).

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns its tables and does not use OpenRegister. The lease record and per-application policy are new **own Doctrine entities** with `ISchemaWrapper` migrations; no register/schema seed-data step.

## Data model (own tables per ADR-001)

**`doriath_machine_leases`** (index on `(application_id, status)`, index on `secret_id`, index on `expires_at`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key — the lease id (returned to the consumer) |
| `application_id` | string | Owning application (the JWT `iss`/`sub`) |
| `secret_id` | string | The leased secret |
| `scope` | string | Human-readable scope, e.g. `by-name:zgw-api-token@infra/zgw` — audit/readability |
| `granted_at` | datetime | When first granted |
| `expires_at` | datetime | Grant expiry (`granted_at + ttl`, bounded by policy max) |
| `renewed_count` | int | Number of renewals |
| `last_renewed_at` | datetime, nullable | — |
| `status` | enum `active` \| `expired` \| `revoked` | Lifecycle |
| `revoked_at` | datetime, nullable | — |
| `revoked_by` | string, nullable | uid (admin/owner) or `application:{id}` for self-revoke |

**`doriath_application_lease_policies`** (optional per-application override; unique on `application_id`):

| Column | Type | Notes |
|--------|------|-------|
| `application_id` | string | PK |
| `default_ttl_seconds` | int | — |
| `max_ttl_seconds` | int | — |
| `renewable` | bool | — |

**Admin instance defaults** (extend `SettingsService`, `IAppConfig` — no table): `lease_default_ttl_seconds` (default 900 — one bearer-token lifetime), `lease_max_ttl_seconds` (default 86400), `lease_renewable` (default true), `lease_revocation_blocks_refetch` (default false).

## Lease semantics

- **Grant on fetch.** A successful `by-name`/`by-id` fetch resolves the effective policy and, if no live lease exists for `(application, secret)`, creates one with `ttl = min(requested, policy.max)` (`requested` via optional `Doriath-Lease-TTL` request header, default = policy default). An existing live lease is reused (its window is not silently extended on every poll — extension is explicit renewal). The response adds `Doriath-Lease-Id` and `Doriath-Lease-Expires` headers. The envelope body is unchanged.
- **Renewal.** `POST /api/v1/app/leases/{leaseId}/renew` (bearer, own-application) sets `expires_at = min(now + policy.default_ttl, granted_at + policy.max_ttl)` and increments `renewed_count`, refused once `granted_at + max_ttl` is reached or when `renewable` is false.
- **Revocation.** Admin/owner (session-authed UI) or the application itself (bearer) marks the lease `revoked`. Revocation emits `lease.revoked` + a rotation trigger. When `lease_revocation_blocks_refetch` is on, a subsequent fetch of that secret by that application returns `403` until an admin re-grants; when off (default), revocation is audit/rotation-only and the app can re-fetch (creating a new lease), because the consumer already holds the private key and blocking availability by default would break poll-based connectors.
- **Expiry.** The background job transitions `active` leases past `expires_at` to `expired`, emits `lease.expired`, and fires the rotation/reminder trigger.

## Discovery advertisement

`DiscoveryController` gains an additive `lease` object in the document — `{ supported: true, defaultTtl, maxTtl, renewable }` — so a consumer configures itself without reading Doriath source, consistent with the discovery-document requirement (`openspec/specs/secret-store-api/spec.md:6`). This is additive within the existing `apiVersion`; the envelope and addressing are untouched, so no version bump is required.

## Background job (mirrors existing `TimedJob` patterns)

`ExpireMachineLeasesJob extends TimedJob`, registered in `appinfo/info.xml` `<background-jobs>` next to `ApproveElapsedEmergencyRequests` (`appinfo/info.xml:73`). Interval 3600s (leases measured in minutes-to-days, matching the emergency job's hourly sweep, `lib/BackgroundJob/ApproveElapsedEmergencyRequests.php:58`). Each run finds `active` leases past `expires_at`, transitions them to `expired`, dispatches `lease.expired`, and emits the rotation/reminder trigger. Like the emergency job, a fetch/renew path also lazily ages a stale lease so the job is a safety net, not the only transition path.

## Audit

Add `lease.granted`, `lease.renewed`, `lease.revoked`, `lease.expired` to `lib/Event/Audit/AuditEventTypes.php` (string types, migration-free). Whitelist only non-sensitive keys (`leaseId`, `secretId`, `expiresAt`, `ttl`, `renewedCount`) and inherit `FORBIDDEN_KEYS` so no ciphertext/value can leak. `application.secret_retrieved` continues to fire on reads — the lease events add lifetime, not a replacement.

## Rate limiting & auth

The bearer-authed lease routes are `#[PublicPage]` (anonymous traffic reaches the controller before `JwtAuthMiddleware` validates the token, exactly as `ApplicationSecretsController::index`) and carry `#[AnonRateLimit]`, added to the documented rate-limit table in `docs/ARCHITECTURE.md:566` (proposed: `MachineLeaseController::renew` 30/60s, matching the secrets read limit). Session-authed admin/owner revoke endpoints carry the usual owner/admin per-object guard so `hydra-gate-no-admin-idor` stays satisfied.

## Decisions made under uncertainty

- **Lease data rides in headers + discovery, not the envelope.** The `doriath-machine-secret-v1` envelope requirement mandates that breaking addressing/shape changes ship as a new `apiVersion` (`openspec/specs/secret-store-api/spec.md:6`). Embedding lease fields in the envelope body would be a breaking mutation; `Doriath-Lease-*` response headers + an additive discovery field keep every existing consumer byte-compatible and let a lease-unaware consumer ignore leases entirely. Alternative (bump `apiVersion` and enrich the envelope) rejected as needless churn for additive metadata.
- **Revocation is a governance/rotation signal by default, not a value clawback.** Under ADR-003 the consumer holds the private key, so no server action can un-decrypt an already-served value — stated plainly. Default `lease_revocation_blocks_refetch = false` keeps poll-based connectors available (revoke → audit + rotate); high-security operators opt into block-on-revoke, accepting the availability trade. Pretending revocation instantly kills access would be dishonest.
- **A poll does not silently extend a lease.** Rotation polling (`If-None-Match`, `updated_since`) is frequent; auto-extending `expires_at` on every poll would make leases effectively immortal and defeat the bound. Extension requires an explicit `renew` call, and even then is capped at `granted_at + max_ttl`.
- **Default TTL = one bearer-token lifetime (900s).** Anchoring the default to the existing 5-minute token cadence (with 15-min default here to reduce renew chatter, `max` 24h) gives a conservative JIT default that admins widen consciously, rather than shipping a long-lived grant by default.
- **Leases are per `(application, secret)`, keyed off the fetch, not per bearer token.** A bearer token is a 5-minute auth artifact; a lease is the durable grant an admin reasons about and revokes. Tying the lease to the secret grant (not the disposable token) is what makes revocation and rotation-triggering meaningful.
