## MODIFIED Requirements

### Requirement: Account Data Deletion
The system MUST support deletion of all of a user's Keepiq data (GDPR Art. 17, right to erasure) via two triggers running the same idempotent cascade:

- **In-app**: gated by a verified key proof (see the `vault-key-proof` capability) AND a typed confirmation phrase; deletes Keepiq data while the Nextcloud account remains
- **Automatic**: a `UserDeletedEvent` listener runs the cascade when the Nextcloud account is deleted, so Keepiq data never outlives its account

The in-app trigger wipes every secret, suite and migration in one request, so a Nextcloud session alone MUST NOT be sufficient for it. The master-password re-entry is therefore proven to the server as a signature made with the private key it unlocks, bound to the confirmation phrase, rather than checked only in the browser. The phrase stays as a guard against a slip. A user without an active EncryptionSuite cannot make a proof; their Keepiq data is removed through the automatic trigger when their Nextcloud account is deleted.

The cascade MUST remove: the user's secrets and folders, their EncryptionSuites (including encrypted private keys) and SuiteMigration records, link shares, secret requests, share records per the shared-secret semantics requirement, and user settings. Every cascade step MUST be idempotent so an interrupted run can be safely re-executed.

#### Scenario: In-app deletion double-gated
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** a user initiates in-app account data deletion
- **THEN** the system MUST require master-password re-entry and the typed confirmation phrase
- **AND** failing either gate MUST abort with nothing deleted

#### Scenario: In-app deletion without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** an authenticated session for a user with an active EncryptionSuite
- **WHEN** in-app deletion is requested with the correct confirmation phrase but without a verified key proof
- **THEN** the system MUST refuse with `428` and `error: key_proof_required`
- **AND** nothing MUST be deleted

#### Scenario: Nextcloud account deletion cascades
@e2e exclude Server-side lifecycle contract — the UserDeletedEvent listener runs the cascade with no UI; covered by PHPUnit (UserDeletedListenerTest triggers the cascade with the user-deleted trigger).
- **WHEN** a Nextcloud administrator deletes a user account
- **THEN** all of that user's Keepiq data MUST be removed by the listener-triggered cascade without any manual step

#### Scenario: Interrupted cascade is re-runnable
@e2e exclude Server-side idempotency contract — re-running the cascade completes without error; covered by PHPUnit (AccountDeletionServiceTest idempotent re-run test).
- **WHEN** a deletion cascade is interrupted and triggered again
- **THEN** the re-run MUST complete the remaining steps without error
