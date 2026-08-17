## Context

Doriath already exposes a JWT-Bearer machine surface (`/api/v1/app/*`) for
discovery and secret read/write-back. Application identity there is proven
cryptographically: `JwtAuthService` verifies an RS256 assertion signed with the
application's private key against its registered EncryptionSuite certificate,
with `jti` replay protection (distributed cache, `JTI_CACHE_NS`) and an
`ACCESS_TOKEN_TTL` of 300 s. `ApplicationSecretsController` extends
`ApplicationApiController` (`#[PublicPage]` handlers; `JwtAuthMiddleware`
injects the resolved `Application` before any handler runs, retrievable via
`getApplication()`), and keys every query by that application's id so
cross-vault access is structurally impossible.

Secret-request creation, however, is only user-session bound:
`SecretRequestService::create()` and `createForApplication()` both **require a
`userId`**, and the only creation route is the user-authenticated OCS
`POST /api/v1/secret-requests`. There is no session-less, application-keyed
path — the blocker for OpenConnector auto-creating a request on source import.

Reusable building blocks already present:
- `SecretRequestService::create()` mints the fill-link token
  (`bin2hex(random_bytes(16))`) and stores `requestedFields`.
- `SecretService::createByApplication(array $data, string $applicationId)`
  creates an application-owned Secret shell (owner_type `application`) and
  resolves the app's active suite via `findActiveByOwner('application', …)`.
- The public fill flow (`GET/POST /api/v1/public/secret-requests/{token}[/fill]`,
  `getByToken()`) is unchanged and already encrypts submitted values under the
  suite certificate.
- `AuditEvent::forApplication(...)` and the audit type whitelist.

## Goals / Non-Goals

**Goals:**
- Let an authenticated application create a `SecretRequest` in its own vault
  session-lessly, over the machine HTTP surface and via an in-process DI seam.
- Auto-create the underlying application-owned Secret shell so no pre-existing
  `secretId` is required.
- Return the `token` and derived public fill-link URL; make it retrievable
  afterwards via a machine list of the caller's pending requests.
- Enforce token-issuance-parity guards, own-vault scoping, no existence
  oracle, and an audit event.

**Non-Goals:**
- The human-facing admin SCREEN that lists open fill-links per application —
  a companion frontend (Vue) follow-up, explicitly OUT OF SCOPE here and NOT
  specified in this change.
- Any change to the public fill flow, the user-authenticated OCS creation
  route, or the DB schema (the `SecretRequest` table already exists).
- Machine-surface deletion (the `/api/v1/app/*` no-DELETE stance is unchanged).

## Decisions

### Contract — machine HTTP surface

`POST /api/v1/app/secret-requests` (JWT-Bearer; `#[PublicPage]` +
`JwtAuthMiddleware`, same as sibling app routes). Request body:

```json
{
  "requestedFields": [
    { "field": "url", "visibility": "public" },
    { "field": "api-key", "visibility": "secret" },
    { "field": "api-interface-id", "visibility": "additional" }
  ],
  "name": "<PLACEHOLDER>",
  "folderPath": "infra/zgw",
  "expiresAt": "2026-12-31T00:00:00+00:00"
}
```

`name`, `folderPath`, `expiresAt` optional. Response `201`:

```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "secretId": "00000000-0000-0000-0000-000000000000",
  "status": "pending",
  "requestedFields": ["url", "api-key", "api-interface-id"],
  "token": "<PLACEHOLDER>",
  "fillLinkUrl": "https://<host>/index.php/apps/doriath/api/v1/public/secret-requests/<PLACEHOLDER>",
  "expiresAt": "2026-12-31T00:00:00+00:00"
}
```

`GET /api/v1/app/secret-requests` (JWT-Bearer) → array of the caller's PENDING
requests, each carrying `token` + `fillLinkUrl`. Both handlers resolve the
principal from `getApplication()`; no `userId` path exists. Backward-compat:
additive routes only — no existing route or response shape changes.

### Secret-shell handling

The controller/service auto-creates the Secret shell via the existing
application-vault write path (`SecretService::createByApplication`), passing
`name`/`folderPath` (folder resolved to `folderId`) and an empty key, then
calls the app-scoped request creation with the resulting `secretId`. This
resolves `createForApplication`'s current need for a pre-existing `secretId`
without inventing a new shell mechanism.

### DI seam — signed-proof authentication (no userId)

A new `SecretRequestService` operation authenticates the application by a
**signed proof verified against its registered certificate** rather than a
`userId`. Provisional signature:

```
createForApplicationBySignedProof(
    string $assertion,            // RS256 JWT assertion (reused JwtAuthService path)
    array  $requestedFields,
    ?string $name,
    ?string $folderPath,
    ?DateTime $expiresAt
): SecretRequest
```

It verifies the assertion through the `JwtAuthService` verification path
(signature vs registered cert, `jti` replay, ≤300 s lifetime, application/suite
status guards), derives the `applicationId` from the verified `iss`, creates
the shell, then persists the request. `userId` is never accepted; `applicationId`
alone is never sufficient. This keeps the "more than the application id" bar a
mutation requires.

### Audit

Emit an audit event with the application as actor on creation (parity with
`application.token_issued` / `application.secret_retrieved`). Reuse
`AuditEvent::forApplication(...)`. The event type is either the existing
`request.created` (with application actor) or a new
`application.secret_request_created` constant — see DEFERRED_QUESTIONS.

### Scoping and no existence oracle

Creation is keyed to the verified/injected application id only; no body
parameter can redirect it to another application's or a user's vault. The
pending list is filtered to the caller's vault. Foreign/nonexistent references
yield the same empty/404 semantics as the read seam `getByNameForApplication`.

## Risks / Trade-offs

- **Two authentication entrypoints (Bearer route + DI signed proof).** Both
  funnel through the single `JwtAuthService` verification path so the
  replay/lifetime/status guards are enforced once, not duplicated.
- **Auto-created Secret shell on a rejected request.** If creation fails after
  the shell is written, an empty orphan Secret could remain; the service MUST
  create the shell and the request atomically (or clean up the shell on
  failure) so a failed creation leaves no orphan.
- **Field-typing shape.** `requestedFields` is a typed list (field +
  visibility). Persisting only the field names (as `create()` does today) is
  the minimal path; richer typing can follow without a contract break because
  the envelope is versioned in the discovery document.