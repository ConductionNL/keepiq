## ADDED Requirements

### Requirement: Machine API Discovery Document
The system MUST serve an unauthenticated, machine-readable discovery document at `GET /api/v1/app/.well-known/doriath` declaring the API version, token endpoint, supported grant type (`urn:ietf:params:oauth:grant-type:jwt-bearer`), assertion requirements (algorithm, maximum lifetime, audience), the secret endpoint paths (list, by-id, by-name), and the supported envelope formats. The document MUST contain no instance-private data. Breaking changes to addressing or envelope shape MUST be published as a new API version in this document, never as an in-place mutation of an existing version.

#### Scenario: Consumer bootstraps from the base URL alone
- **WHEN** a machine consumer fetches the discovery document without authentication
- **THEN** the response MUST contain the token endpoint, grant type, assertion requirements, secret endpoints, and `apiVersion`
- **AND** the consumer can derive every contract URL without reading Doriath source or configuration

### Requirement: Name-Addressable Secret Retrieval
The system MUST allow an authenticated application to retrieve a secret from its own vault by exact plaintext name via `GET /api/v1/app/secrets/by-name/{name}`, optionally scoped by a `folder` query parameter using slash-separated path notation. Zero matches MUST return 404. Exactly one match MUST return the encrypted envelope. Multiple matches MUST return 409 Conflict with a body listing each candidate's id, name, folder path, and update timestamp — the system MUST NOT silently pick one of several same-named secrets.

#### Scenario: Resolve by name
- **WHEN** an application requests `by-name/zgw-api-token` and exactly one secret with that name exists in its vault
- **THEN** the response MUST be the encrypted envelope for that secret

#### Scenario: Ambiguous name rejected with candidates
- **WHEN** two secrets named `zgw-api-token` exist in the application's vault
- **THEN** the response MUST be 409 Conflict listing both candidates with id, name, folder path, and update timestamp
- **AND** a folder-scoped request matching exactly one of them MUST succeed

#### Scenario: Unknown name
- **WHEN** an application requests a name that matches no secret in its vault
- **THEN** the response MUST be 404

### Requirement: Versioned Encrypted Response Envelope
Every machine secret read MUST return the versioned `doriath-machine-secret-v1` envelope: a `format` identifier, plaintext-safe metadata (id, name, url, folder path, type, created/updated timestamps), an `encryption` block (suite id, certificate fingerprint, scheme identifier naming the existing ADR-003 encrypt path), and the ciphertext fields (key, login, additional fields) encrypted under the application's own certificate. The server MUST return ciphertext only — machine endpoints MUST never return decrypted secret values under any condition. The certificate fingerprint MUST allow a consumer to detect a key mismatch before attempting decryption.

#### Scenario: Envelope is self-describing
- **WHEN** an application fetches a secret through any machine read endpoint
- **THEN** the envelope MUST identify its format version, the encryption suite, the certificate fingerprint, and the encryption scheme
- **AND** the consumer can decrypt using only the envelope and its own private key

#### Scenario: Server never returns plaintext on machine endpoints
- **WHEN** any `/api/v1/app/secrets*` endpoint responds
- **THEN** the secret value, login, and additional fields appear only as ciphertext

### Requirement: Rotation Polling
Single-secret machine reads MUST return a strong `ETag` that changes whenever the secret's stored state changes, and MUST honor `If-None-Match` by returning 304 Not Modified without a body on a match. The application secret list MUST accept an `updated_since` ISO 8601 parameter and return only secrets updated after that instant, so consumers can detect rotated credentials with one cheap call per polling cycle.

#### Scenario: Unchanged secret returns 304
- **WHEN** an application re-fetches a secret presenting the previously returned ETag and the secret has not changed
- **THEN** the response MUST be 304 with no envelope body

#### Scenario: Rotated secret detected
- **WHEN** a secret's value is updated and the application polls the list with `updated_since` set to its last poll time
- **THEN** the list MUST include that secret
- **AND** a subsequent fetch with the stale ETag MUST return the new envelope with a new ETag

### Requirement: Application Write-Back
The system MUST allow an authenticated application to create and update secrets in its own vault (`POST /api/v1/app/secrets`, `PUT /api/v1/app/secrets/{id}`) by submitting plaintext-safe metadata plus fields already encrypted with the application's own public certificate. The server MUST validate metadata and the ciphertext envelope shape only — it can never inspect plaintext. The machine API MUST NOT allow secret deletion; deletion remains a human/administrative operation per the application-mgmt capability.

#### Scenario: Connector rotates a credential
- **WHEN** an application PUTs a new client-encrypted value for a secret it owns
- **THEN** the stored ciphertext MUST be replaced and the secret's update timestamp advanced
- **AND** no plaintext value appears in the request

#### Scenario: Machine deletion refused
- **WHEN** an application attempts to delete a secret through the machine API
- **THEN** no delete operation exists on the machine surface and the attempt MUST fail

### Requirement: Token Endpoint Hardening
The token endpoint MUST verify the JWT assertion's signature against the application's registered certificate, reject assertions with a lifetime over 300 seconds or an expired/future validity window, and reject any reuse of a `jti` within the assertion's lifetime (replay protection). Failed exchanges MUST be subject to Nextcloud brute-force throttling. Applications that are pending, rejected, deleted, or whose EncryptionSuite is revoked or compromised MUST be refused a token. Issued bearer tokens MUST be opaque, expire within 5 minutes, and grant access to exactly one application's vault.

#### Scenario: Replayed assertion rejected
- **WHEN** the same signed assertion (same `jti`) is presented twice within its lifetime
- **THEN** the second exchange MUST be rejected

#### Scenario: Pending application refused
- **WHEN** an application with status `pending` presents a validly signed assertion
- **THEN** the token exchange MUST be refused

#### Scenario: Failed exchanges throttled
- **WHEN** repeated invalid assertions are presented
- **THEN** the endpoint MUST apply brute-force throttling to subsequent attempts

### Requirement: Strict Own-Vault Scoping
Every machine secret endpoint MUST resolve the bearer token to exactly one application and key every query by that application's identity. A request for a secret id or name belonging to a different application's vault (or to a user vault) MUST return the same 404 as a nonexistent secret — no response may reveal the existence of secrets outside the authenticated application's vault.

#### Scenario: Cross-vault access is invisible
- **WHEN** application A requests, by id, a secret owned by application B
- **THEN** the response MUST be 404, indistinguishable from requesting a nonexistent id

### Requirement: Secret Reference Format and Consumption Contract
The system MUST document the canonical secret reference format `doriath://{applicationId}/{folderPath}/{name}` (folder path optional) and its resolution algorithm — discovery, token exchange, by-name fetch with folder scope, local decryption — in an OpenConnector consumption recipe covering private-key custody (the key is the consumer's credential, never embedded in shareable configuration), re-registration recovery after key loss, and the prohibition on logging decrypted values or bearer tokens. The contract MUST ship as an executable Newman collection (`tests/integration/machine-secret-api.postman_collection.json`) exercising discovery, token exchange (including replay and pending-application refusal), by-name resolution including the 409 case, envelope shape, ETag/`updated_since` rotation flow, write-back, and cross-vault 404 scoping — the shared verification artifact for the OpenConnector-side implementation in its own repository.

#### Scenario: Contract collection verifies the full consumer flow
- **WHEN** the machine-secret-api Newman collection runs against a deployed instance with a seeded application
- **THEN** the discovery → token → by-name → decrypt-input flow, rotation polling, write-back, and every negative case (replay, pending app, ambiguity, cross-vault 404) MUST pass

#### Scenario: Reference resolves end to end
- **WHEN** a consumer resolves `doriath://{appId}/infra/zgw/zgw-api-token` per the documented algorithm
- **THEN** the by-name request is folder-scoped to `infra/zgw` and returns the envelope for that secret
