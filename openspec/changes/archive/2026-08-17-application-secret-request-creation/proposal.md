---
kind: code
---

## Why

A registered application (e.g. OpenConnector, auto-creating a request when a
source is imported) needs to ask a human to fill in a source's connection
secrets through Doriath's write-without-read fill-link — without the
application ever handling raw secret values. Today that is impossible
session-lessly:

- `SecretRequestService::createForApplication(...)` **requires a `userId`** (a
  human session), so an unattended app cannot call it.
- The only request-creation HTTP route is the user-authenticated OCS
  `POST /api/v1/secret-requests` (`SecretRequestController`).
- The machine surface `/api/v1/app/*` (JWT-Bearer) exposes discovery plus
  secret read/write-back, but **no secret-request creation**.

This gap blocks the OpenConnector integration (consumer-side discovery in
`openconnector/openspec/changes/doriath-secret-sources/discovery.md`). The
application identity problem is already solved cryptographically by
JWT-Bearer (`JwtAuthService` verifies an RS256 assertion against the
application's registered suite certificate, with `jti` replay protection and
≤300 s assertion lifetime) — the same proof used by the existing
`/api/v1/app/*` routes. This change reuses that proof to let an application
create a request in its OWN vault.

## What Changes

- **New machine HTTP endpoint** `POST /api/v1/app/secret-requests` (JWT-Bearer,
  same auth as the other `/api/v1/app/*` routes). The authenticated
  application creates a `SecretRequest` in ITS OWN vault: body carries
  `requestedFields` (typed field list — e.g. `url`/public, `api-key`/secret,
  `api-interface-id`/additional), optional `name`, `folderPath`, `expiresAt`.
  The response returns the created request including the `token` and the
  derived public fill-link URL. The endpoint auto-creates the underlying
  Secret shell owned by the application (reusing the application-vault write
  path), resolving the current requirement that `createForApplication` be
  handed a pre-existing `secretId`.
- **New machine HTTP endpoint** `GET /api/v1/app/secret-requests` (JWT-Bearer)
  listing the caller's own PENDING requests / open fill-links, scoped to the
  caller's vault, so the fill-link is retrievable after creation.
- **New in-process DI seam** on `SecretRequestService` for the same operation
  (for same-instance callers that prefer DI over loopback HTTP). It
  authenticates the application by a **signed proof verified against the app's
  registered certificate** (reusing the `JwtAuthService` verification path) —
  NOT a `userId`, NOT `applicationId` alone.
- **App-vault scoping**: a request is created ONLY in the authenticated
  application's vault; it can never target another application's or a user's
  vault (mirrors the owner-keyed read seam `getByNameForApplication`).
- **Security parity** with token issuance: reject creation for
  pending/rejected/deleted applications and revoked/compromised suites;
  enforce `jti`-replay / assertion-lifetime bounds on the DI seam's signed
  proof; emit an audit event on creation; preserve the no-existence-oracle
  404/empty semantics of the read path.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `secret-store-api`: adds the machine request-creation surface (`POST` /
  `GET /api/v1/app/secret-requests`) under the existing JWT-Bearer machine
  API, with own-vault scoping and audit parity.
- `secret-requests`: adds session-less, application-initiated request creation
  — an in-process DI seam keyed to a signed application proof (no `userId`)
  plus auto-creation of the application-owned Secret shell.

## Impact

- Code: `appinfo/routes.php` (two new `/api/v1/app/secret-requests` routes),
  a new controller action set on the machine surface (extending
  `ApplicationApiController`, guarded by `JwtAuthMiddleware`),
  `SecretRequestService` (new signed-proof-authenticated create + own-vault
  pending list), reuse of `SecretService::createByApplication` for the shell
  and `JwtAuthService` for proof verification, and `AuditEventTypes` for the
  creation event.
- APIs: additive only — no existing route or signature changes; the public
  fill flow (`GET/POST /api/v1/public/secret-requests/{token}[/fill]`) and the
  user-authenticated OCS route are untouched.
- Consumers: unblocks the OpenConnector source-import integration.
- Docs: `docs/integration-openconnector.md` must document the new creation
  surface and the DI seam (it currently omits both).
- No database schema change — the `SecretRequest` table already exists.
- Out of scope here: the human-facing admin SCREEN that lists open fill-links
  per application (a companion frontend follow-up).