## 1. Machine HTTP route + controller

- [ ] 1.1 Register `POST /api/v1/app/secret-requests` and `GET /api/v1/app/secret-requests` in `appinfo/routes.php`.
- [ ] 1.2 Add an `ApplicationSecretRequests` controller extending `ApplicationApiController` (`#[PublicPage]`, `JwtAuthMiddleware`), resolving the principal via `getApplication()`; create returns `201` with `token` + `fillLinkUrl`, index returns the caller's pending requests.

## 2. Service — app-scoped creation without userId

- [ ] 2.1 Add a `SecretRequestService` operation that creates a request keyed to an application id (no `userId`), auto-creating the application-owned Secret shell via `SecretService::createByApplication` and minting the token through the existing `create()` path (atomic — no orphan shell on failure).
- [ ] 2.2 Add an own-vault pending-request list for an application (each row carrying `token` + derived fill-link URL), mirroring the owner-keyed `getByNameForApplication` scoping.

## 3. DI seam — signed-proof authentication

- [ ] 3.1 Add the signed-proof-authenticated create seam that verifies the assertion through the `JwtAuthService` path (signature vs registered cert, `jti` replay, ≤300 s lifetime), derives `applicationId` from the verified `iss`, and rejects `userId` / appId-only / invalid-signature / replayed-jti / wrong-cert.

## 4. Guards + audit

- [ ] 4.1 Enforce token-issuance-parity guards (refuse pending/rejected/deleted applications and revoked/compromised suites) and preserve no-existence-oracle semantics on both entrypoints.
- [ ] 4.2 Emit an application-actor audit event on creation via `AuditEvent::forApplication`, wiring the event type in `AuditEventTypes`.

## 5. Tests

- [ ] 5.1 Add unit tests covering the machine route (create → token + fill-link; index lists own pending), the DI seam (valid proof; rejects appId-only / invalid-signature / replayed-jti / wrong-cert), cross-vault refusal, revoked-suite / non-approved refusal, and that a created request is fillable via the existing public fill flow.

## Acceptance Criteria

- An authenticated application can create a `SecretRequest` in its own vault over `POST /api/v1/app/secret-requests` and receives the `token` and public fill-link URL.
- The DI seam creates a request only when given a valid signed proof and rejects appId-only, invalid-signature, replayed-jti, and wrong-certificate proofs.
- A request can never be created in another application's or a user's vault via either entrypoint.
- Creation is refused for pending/rejected/deleted applications and revoked/compromised suites.
- `GET /api/v1/app/secret-requests` lists only the caller's pending requests, each with its fill-link, and reveals no other vault's requests.
- A created request is fillable through the existing `POST /api/v1/public/secret-requests/{token}/fill` flow and its values are encrypted under the application's certificate.
- Exactly one application-actor audit event is emitted per successful creation.
- No existing route, response shape, or DB schema changes (additive only).

## Verification

- `openspec validate application-secret-request-creation --strict` passes.
- New routes reach their handlers only with a valid Bearer token (JwtAuthMiddleware), verified by the route-auth / semantic-auth Hydra gates.
- No orphan Secret shell remains after a failed creation.

## Tests

- PHPUnit unit tests for the controller and `SecretRequestService` cover cases (a)-(f) from the change brief.
- Extend the machine-secret-api Newman collection with the create + list requests and their negative cases (replay, non-approved app, cross-vault).

## Documentation

- Update `docs/integration-openconnector.md` to document the new creation surface (`POST /api/v1/app/secret-requests`) and the in-process DI fetch/create seam — it currently omits both.

## i18n

- No new user-facing strings (machine API only); no translation work required.