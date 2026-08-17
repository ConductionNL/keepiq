## ADDED Requirements

### Requirement: Session-less Application-Initiated Request Creation

The system MUST provide a session-less way for a registered application to create a `SecretRequest` in its OWN vault, keyed to the application identity and NOT to a Nextcloud user. The operation MUST authenticate the application by a **signed cryptographic proof verified against the application's registered EncryptionSuite certificate** (a JWT assertion or challenge signature, reusing the `JwtAuthService` verification path via `ApplicationSecretRequestService::createForApplicationBySignedProof`) — it MUST NOT accept a `userId`, and MUST NOT accept an `applicationId` alone as sufficient authority. On a valid proof, the operation MUST create the application-owned Secret shell as needed, mint the fill-link `token`, store the `requestedFields`, and return the request together with its token and the derived public fill-link URL. The submitted values MUST later be encrypted under the application's own certificate (write-without-read preserved).

#### Scenario: DI seam creates a request given a valid signed proof
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService application-create unit tests (valid proof → request + token + fill-link).
- **GIVEN** an approved application with an active EncryptionSuite and a valid signed proof over that suite's certificate
- **WHEN** a same-instance caller invokes the DI seam with the proof and a non-empty `requestedFields`
- **THEN** the system MUST create an application-owned Secret shell and a pending `SecretRequest` with a unique token
- **AND** return the request together with its token and the derived fill-link URL

#### Scenario: DI seam rejects appId-only, invalid signature, replayed jti, or wrong certificate
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService negative unit tests (appId-only / bad-signature / replayed-jti / wrong-cert).
- **GIVEN** a caller invokes the DI seam
- **WHEN** the proof is missing (application id supplied alone), has an invalid signature, replays a previously seen `jti` within the assertion lifetime, exceeds the ≤300 s assertion lifetime, or is signed by a key that does not match the application's registered certificate
- **THEN** the operation MUST reject the creation and MUST NOT create any Secret or SecretRequest

#### Scenario: DI seam cannot create in another vault
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService cross-vault unit test.
- **GIVEN** a valid proof for application A
- **WHEN** the caller attempts to direct the creation at application B's or a user's vault
- **THEN** the request MUST be created only in A's vault — never in B's or a user's vault

#### Scenario: Creation refused for a non-approved application or revoked suite
@e2e exclude In-process service seam with no UI surface; covered by ApplicationSecretRequestService guard unit tests (parity with token-issuance guards).
- **GIVEN** an application that is pending/rejected/deleted OR whose EncryptionSuite is revoked/compromised
- **WHEN** a proof is presented to the DI seam
- **THEN** the creation MUST be refused with the same guards enforced at token issuance