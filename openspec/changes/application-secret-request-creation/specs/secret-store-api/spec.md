## ADDED Requirements

### Requirement: Machine Secret-Request Creation

The system MUST allow a JWT-Bearer-authenticated application to create a `SecretRequest` in its OWN vault, session-lessly, via `POST /api/v1/app/secret-requests`, with the same authentication posture as the other `/api/v1/app/*` routes (the `Application` principal is resolved from the validated Bearer token by `JwtAuthMiddleware`; no Nextcloud user session is involved). The request body MUST carry a non-empty typed `requestedFields` list and MAY carry `name`, `folderPath`, and `expiresAt`. The system MUST auto-create the underlying application-owned Secret shell (owner_type `application`, owner_id = the authenticated application) so the caller never needs a pre-existing secret id, and MUST encrypt later-submitted values under the application's own registered certificate. The response MUST return the created request including its `token` and the derived public fill-link URL. The endpoint MUST NOT accept a `userId` or any actor other than the authenticated application.

#### Scenario: Application creates a request in its own vault via the machine route
@e2e exclude Machine-to-machine JWT-Bearer API with no UI surface; covered by ApplicationSecretRequestsControllerTest (create → 201 with token + fill-link) and the machine-secret-api Newman collection.
- **GIVEN** an approved application with an active EncryptionSuite presents a valid Bearer token
- **WHEN** it POSTs `requestedFields` (and optional `name`/`folderPath`/`expiresAt`) to `/api/v1/app/secret-requests`
- **THEN** the system MUST create an application-owned Secret shell and a pending `SecretRequest` with a unique token
- **AND** the response MUST include the `token` and the derived public fill-link URL

#### Scenario: Created request is fillable via the existing public fill flow
@e2e exclude Machine-to-machine API plus token-based public fill flow; covered by ApplicationSecretRequestsControllerTest + SecretRequestFillControllerTest, not Playwright.
- **GIVEN** an application created a request through the machine route and holds its `token`
- **WHEN** an external party opens `GET /api/v1/public/secret-requests/{token}` and submits values via `POST /api/v1/public/secret-requests/{token}/fill`
- **THEN** the submitted values MUST be encrypted with the application's public certificate and stored in the linked Secret
- **AND** the request status MUST become fulfilled

#### Scenario: userId or foreign actor is refused on the machine route
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testUserIdInBodyIgnoredOrRejected.
- **WHEN** a machine-route request body attempts to set an actor (`userId`) other than the authenticated application
- **THEN** the request MUST still be created only in the authenticated application's vault, never for the supplied actor

### Requirement: Machine Pending-Request Listing

The system MUST allow a JWT-Bearer-authenticated application to list its own PENDING secret requests / open fill-links via `GET /api/v1/app/secret-requests`, scoped strictly to the caller's vault, so a fill-link is retrievable after creation. The listing MUST include, per request, the `token` and the derived public fill-link URL alongside the request metadata. It MUST NEVER include requests belonging to another application's or a user's vault.

#### Scenario: Application lists its own pending fill-links
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testIndexReturnsOwnPendingRequests and the Newman list request.
- **GIVEN** an application created one or more pending requests
- **WHEN** it GETs `/api/v1/app/secret-requests` with a valid Bearer token
- **THEN** the response MUST list those pending requests each with its `token` and fill-link URL
- **AND** MUST NOT list any request outside the authenticated application's vault

### Requirement: Machine Request Creation Own-Vault Scoping

Every machine secret-request operation MUST be keyed by the authenticated application's identity. A created request MUST be created ONLY in the authenticated application's vault; the surface MUST NOT expose any parameter that lets it target another application's or a user's vault. Listing another vault's requests MUST return the same empty/404 semantics as the read path — no response may reveal the existence of requests outside the authenticated application's vault (no existence oracle).

#### Scenario: Request creation cannot target another vault
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testCannotCreateInAnotherVault and SecretRequestService application-scoping unit tests.
- **GIVEN** application A holds a valid Bearer token
- **WHEN** A attempts, by any body parameter, to create a request in application B's or a user's vault
- **THEN** the request MUST be created only in A's vault, or refused — never created for B or a user
- **AND** the outcome MUST NOT reveal whether B's vault or the named foreign secret exists

### Requirement: Machine Request Creation Hardening and Audit

Machine secret-request creation MUST enforce the same guards as token issuance: it MUST be refused for pending, rejected, or deleted applications and for applications whose EncryptionSuite is revoked or compromised. Creation MUST emit an audit event with the application as actor (parity with `application.token_issued` / `application.secret_retrieved`). The machine surface MUST NOT expose any way to read back submitted plaintext — the write-without-read property of `SecretRequest` is preserved.

#### Scenario: Creation refused for a revoked suite or non-approved application
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testCreationRefusedForRevokedSuite / testCreationRefusedForPendingApplication.
- **GIVEN** an application that is pending/rejected/deleted OR whose EncryptionSuite is revoked/compromised
- **WHEN** it attempts to create a secret request on the machine surface
- **THEN** the creation MUST be refused

#### Scenario: Creation emits an audit event
@e2e exclude Machine-to-machine API contract with no UI surface; covered by ApplicationSecretRequestsControllerTest::testCreationEmitsAudit asserting the dispatched typed audit event.
- **WHEN** an application successfully creates a request on the machine surface
- **THEN** exactly one audit event MUST be dispatched with the application as actor