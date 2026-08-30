---
kind: code
---

# Proposal: Short-lived credential leases for the machine secret-store API

## Why

Doriath is one of the very few secret stores that holds **both** halves of the credential world: a human vault (users, sharing, write-without-read) and a machine secret-store API (RFC 7523 JWT-bearer auth, `lib/Service/JwtAuthService.php`; the `doriath://` OpenConnector integration, `docs/integration-openconnector.md`). The machine/non-human-identity (NHI) secrets segment is the fastest-growing slice of the market — Mordor Intelligence's NHI-security report puts it at roughly **22% CAGR** — and it is currently owned by machine-only tools (HashiCorp Vault, OpenBao, Infisical) that have no end-user vault, while consumer password managers have no machine API. Only Bitwarden bridges both halves, and only as a paid Secrets-Manager bolt-on. Doriath already spans both halves; what it lacks to compete on the machine side is any notion of a **bounded, revocable, audited access grant**.

Today the machine API is grant-unaware. `JwtAuthService::exchangeAssertion` issues a 5-minute opaque bearer token (`lib/Service/JwtAuthService.php:253`) and every secret fetch emits a single `application.secret_retrieved` audit event (`lib/Controller/ApplicationSecretsController.php:361`), but nothing records that "application X now holds credential Y until instant Z", nothing lets an admin or owner **revoke** an in-flight grant, nothing bounds how long an application may keep re-reading a secret, and nothing lets grant expiry **drive** rotation. The OWASP Secrets Management Cheat Sheet's core guidance is to prefer **just-in-time, auto-expiring** credentials; leases move Doriath toward that JIT posture and are the differentiator for the unified human+machine story.

## Honest scoping — what a Doriath lease is and is not

Doriath stores **static, zero-knowledge secrets**. It **cannot** mint dynamic, per-lease database or cloud credentials the way Vault's dynamic secret engines do — the secret value is encrypted to the application's own key and the server can never generate or read it (ADR-003). A Doriath **lease** therefore bounds the **access-grant lifetime** and provides the governance surface around it — a server-recorded `{lease id, application, secret scope, expiry, renewal count}` grant, renewable within an admin-set policy, revocable by admin/owner, and fully audited — and lease expiry **drives triggers** (an expiry-reminder or a re-request, tying into the sibling `rotation-expiry-policies` change), **not** credential generation. Because the consumer holds the private key, a revoked or expired lease cannot claw back a value the consumer already decrypted; revocation is a governance/rotation signal (and optionally blocks *future* re-fetches), and this limit is stated plainly rather than papered over.

**Out of scope:** dynamic secret engines / per-lease credential minting; a Kubernetes operator or CSI driver.

## What Changes

- Add a **lease record** (`doriath_machine_leases`): on a machine secret fetch (`by-name`/`by-id`), the server creates or renews a lease for `(application, secret)` with a TTL bounded by policy and returns the lease id and expiry as response **headers** (keeping the versioned `doriath-machine-secret-v1` envelope byte-compatible — an additive, non-breaking change).
- Advertise lease support in the **discovery document** (`GET /api/v1/app/.well-known/doriath`) with a new additive field (default TTL, max TTL, renewable) so consumers self-configure without a breaking envelope change.
- Add **lease renewal** within policy: `POST /api/v1/app/leases/{leaseId}/renew` (bearer-authed, own-application-scoped) extends `expires_at` up to the policy max TTL and increments the renewal count.
- Add **lease revocation**: an admin or the secret owner can revoke an active lease from the Doriath UI; an application MAY self-revoke its own lease. Revocation records the withdrawal, emits audit + an optional rotation trigger, and — under an opt-in admin policy — blocks that application's subsequent re-fetch of the scoped secret until re-granted.
- Add **admin lease policy**: an instance-wide default/max TTL and renewable flag (new `SettingsService` keys), with an optional per-application override.
- Add a **lease-aware audit trail**: new `lease.granted` / `lease.renewed` / `lease.revoked` / `lease.expired` event types recording who fetched what, when, and the grant lifetime — alongside the existing `application.secret_retrieved` event.
- Add an **expiry background job** (`TimedJob`, mirroring `lib/BackgroundJob/ApproveElapsedEmergencyRequests.php`) that transitions past-expiry active leases to `expired`, emits the audit event, and fires the rotation/reminder trigger.
- Extend the shared Newman contract collection (`tests/integration/machine-secret-api.postman_collection.json`) with lease grant/renew/revoke/expiry cases.

## Capabilities

### New Capabilities
- `machine-secret-leases`: TTL-bounded, renewable, revocable, audited access-grant leases layered onto the existing RFC 7523 machine secret-store API, with an admin lease-TTL policy and a lease-expiry background job — access-grant lifetime governance for static secrets, not dynamic credential minting.

### Modified Capabilities
- _(none — leases are additive to `secret-store-api`: response headers, a new discovery field, and new lease routes, with no change to the existing `doriath-machine-secret-v1` envelope requirement or any existing scenario.)_

## Impact

- **New table** (own DB per ADR-001): `doriath_machine_leases`; optional `doriath_application_lease_policies` for per-application overrides. No OpenRegister.
- **Services**: new `LeaseService`; extends `ApplicationSecretsController` (grant/renew a lease on fetch, add lease headers), `DiscoveryController` (advertise lease policy), `SettingsService` (admin lease keys).
- **Routes/controllers**: new bearer-authed `MachineLeaseController` (`renew`, `list`, self-`revoke`) under `/api/v1/app/leases/*` with `#[PublicPage]` + `AnonRateLimit` per the documented rate-limit table (`docs/ARCHITECTURE.md:556`); new session-authed lease-management + revoke endpoints for the admin/owner UI.
- **Background jobs**: new `ExpireMachineLeasesJob` registered in `appinfo/info.xml` `<background-jobs>`.
- **Audit**: new `lease.*` event types in `AuditEventTypes` (no DB migration — string types).
- **Frontend**: an application-detail "active leases" panel with per-lease revoke.
- **OpenConnector / contract**: additive Newman cases; the `doriath://` resolution algorithm is unchanged — a lease-unaware consumer keeps working (headers are optional to read).
- **Cross-change**: lease expiry emits a rotation/reminder trigger consumed by `rotation-expiry-policies` when that change is present; the two changes are independently deployable.
