## ADDED Requirements

### Requirement: In-Process Application Vault Secret Deletion

The system MUST provide an in-process service operation `SecretService::deleteByApplication(string $secretId, string $applicationId): void` allowing a same-instance trusted caller (another Nextcloud app resolving `SecretService` via dependency injection, e.g. OpenRegister's credential-custody integration) to delete a single secret from an application's own vault.

The operation MUST be scoped strictly to the application's own vault: only a secret with `owner_type = 'application'` AND `owner_id = $applicationId` may be deleted. A nonexistent secret id and an id owned by any other vault (another application or a user) MUST both be silent no-ops that are indistinguishable to the caller — void return, no exception, no audit entry — making the operation idempotent and free of an existence oracle.

An actual deletion MUST cascade sharing-graph cleanup so no orphan rows referencing the secret survive, and MUST dispatch exactly one `secret.deleted` audit event with the application as actor. A no-op MUST NOT dispatch any audit event.

The operation MUST NOT be exposed on any HTTP route: the machine secret-store surface keeps its no-DELETE stance (secret-store-api spec — deletion via bearer token remains impossible; human/administrative deletion remains with application-mgmt).

#### Scenario: Own-vault delete removes the secret and audits once

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-delete tests), not Playwright.
- **GIVEN** a secret exists in the vault of application `00000000-0000-0000-0000-000000000000`
- **WHEN** a trusted in-process caller invokes `deleteByApplication` with that secret's id and that application id
- **THEN** the secret row MUST be removed
- **AND** exactly one audit entry MUST be recorded with event type `secret.deleted` and the application as actor

#### Scenario: Cross-vault id is a silent no-op without an existence oracle

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-delete tests), not Playwright.
- **GIVEN** a secret id that exists but belongs to a different vault (another application or a user)
- **WHEN** `deleteByApplication` is invoked with that id and a non-owning application id
- **THEN** nothing MUST be deleted, no exception raised, and no audit event dispatched
- **AND** the caller MUST NOT be able to distinguish the outcome from a nonexistent id

#### Scenario: Double delete is idempotent

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-delete tests), not Playwright.
- **GIVEN** a secret was already deleted from its application vault
- **WHEN** `deleteByApplication` is invoked again with the same id and application id
- **THEN** the call MUST return silently with no exception and no audit event

### Requirement: In-Process Application Vault Secret Read

The system MUST provide an in-process service operation `SecretService::getByNameForApplication(string $name, string $applicationId): ?Secret` allowing a same-instance trusted caller (another Nextcloud app resolving `SecretService` via dependency injection, e.g. OpenRegister's credential-custody integration) to resolve a single secret by exact plaintext name from an application's own vault.

Resolution MUST use the same owner-keyed exact-match semantics as the machine HTTP by-name path (`SecretMapper::findByName` with `owner_type = 'application'` AND `owner_id = $applicationId`, vault-wide): the query MUST be structurally incapable of matching another vault. Zero matches MUST return `null`; a name that exists only in another vault MUST be indistinguishable from a nonexistent name (no existence oracle). More than one match MUST return `null` and log a warning — the system MUST NOT silently pick one of several same-named secrets, and the caller MUST NOT be able to distinguish ambiguity from absence.

A single match MUST return the `Secret` entity with its encrypted fields (`key`, `login`, `additional_fields`) as stored ciphertext — the operation MUST NOT decrypt anything (the caller decrypts with its own private key; the server-side zero-knowledge stance is unchanged).

A successful read MUST dispatch exactly one `application.secret_retrieved` audit event with the application as actor — the same event type and actor form the machine HTTP read path dispatches on a full read. A `null` outcome MUST NOT dispatch any audit event.

The operation MUST NOT be exposed on any HTTP route; the machine secret-store surface (bearer token, envelope responses) remains the only remote read path.

#### Scenario: Own-vault hit returns the entity with audit parity

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-read tests), not Playwright.
- **GIVEN** exactly one secret named `00000000-0000-0000-0000-000000000000` exists in the calling application's vault
- **WHEN** a trusted in-process caller invokes `getByNameForApplication` with that name and application id
- **THEN** the `Secret` entity MUST be returned with its ciphertext fields intact and nothing decrypted
- **AND** exactly one audit entry MUST be recorded with event type `application.secret_retrieved` and the application as actor — identical in form to a machine HTTP full read

#### Scenario: Nonexistent and cross-vault names return null indistinguishably

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-read tests), not Playwright.
- **GIVEN** a name that matches no secret in the calling application's vault (whether it matches nothing anywhere or exists only in another application's or a user's vault)
- **WHEN** `getByNameForApplication` is invoked with that name
- **THEN** the call MUST return `null` with no exception and no audit event
- **AND** the caller MUST NOT be able to distinguish the two cases

#### Scenario: Ambiguous name is never guessed

@e2e exclude In-process service API with no UI or HTTP surface; covered by PHPUnit unit tests (SecretService application-read tests), not Playwright.
- **GIVEN** two secrets with the same name exist in the calling application's vault
- **WHEN** `getByNameForApplication` is invoked with that name
- **THEN** the call MUST return `null`, MUST log a warning, and MUST NOT return either candidate
- **AND** no audit event MUST be dispatched
