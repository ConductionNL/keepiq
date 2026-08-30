## ADDED Requirements

### Requirement: GDPR Personal Data Export
The system MUST provide a full personal data export (GDPR Art. 15, right of access) as one machine-readable, versioned JSON package, assembled in the browser from two halves:

- **Server metadata** (`GET /api/v1/gdpr/metadata`, session user only): EncryptionSuite records (certificate, status, revocation/reinstatement audit fields — excluding the encrypted private-key blob, with the exclusion documented inside the package), shares given and received, delegations, link-share metadata (no encrypted snapshots), secret requests, and user settings
- **Client vault**: the decrypted secrets and folder structure, produced in the browser when the vault is unlocked

If the user cannot unlock the vault, the package MUST still be produced with the server half only, and the vault section MUST explicitly state that the vault is end-to-end encrypted and was not unlocked by the data subject. The package MUST be downloaded locally and MUST NOT be stored server-side.

#### Scenario: Full package with unlocked vault
- **WHEN** an unlocked user requests their GDPR data export
- **THEN** the downloaded package MUST contain both the server metadata and the decrypted vault sections
- **AND** the decrypted vault content MUST NOT appear in any HTTP request

#### Scenario: Metadata-only package without unlock
- **WHEN** a user requests their GDPR data export while the vault is locked and declines to unlock
- **THEN** the package MUST contain the server metadata
- **AND** the vault section MUST state that the end-to-end encrypted vault was not unlocked

#### Scenario: Metadata endpoint is self-scoped
- **WHEN** an authenticated user calls the GDPR metadata endpoint
- **THEN** the response MUST contain only data belonging to the session user — no parameter may select another user

### Requirement: Account Data Deletion
The system MUST support deletion of all of a user's Keepiq data (GDPR Art. 17, right to erasure) via two triggers running the same idempotent cascade:

- **In-app**: gated by master-password re-entry (client-side proof of knowledge, as in plaintext export) AND a typed confirmation phrase; deletes Keepiq data while the Nextcloud account remains
- **Automatic**: a `UserDeletedEvent` listener runs the cascade when the Nextcloud account is deleted, so Keepiq data never outlives its account

The cascade MUST remove: the user's secrets and folders, their EncryptionSuites (including encrypted private keys) and SuiteMigration records, link shares, secret requests, share records per the shared-secret semantics requirement, and user settings. Every cascade step MUST be idempotent so an interrupted run can be safely re-executed.

#### Scenario: In-app deletion double-gated
- **WHEN** a user initiates in-app account data deletion
- **THEN** the system MUST require master-password re-entry and the typed confirmation phrase
- **AND** failing either gate MUST abort with nothing deleted

#### Scenario: Nextcloud account deletion cascades
- **WHEN** a Nextcloud administrator deletes a user account
- **THEN** all of that user's Keepiq data MUST be removed by the listener-triggered cascade without any manual step

#### Scenario: Interrupted cascade is re-runnable
- **WHEN** a deletion cascade is interrupted and triggered again
- **THEN** the re-run MUST complete the remaining steps without error

### Requirement: Shared Secret Semantics on Account Deletion
The cascade MUST handle shared and delegated secrets with the following defined semantics:

- **Ownership transfer via delegation**: a secret with an active SecretDelegation transfers to the delegate — the delegation becomes permanent (per the user-sharing spec's `is_permanent` semantics for deleted/revoked owners), the delegate becomes owner, and the delegate's access continues uninterrupted
- **Detach with tombstone**: for shares the deleted user granted, each recipient's copy (already a full Secret row encrypted under the recipient's own suite) is kept by the recipient as an independent secret; the SecretShare link is deleted (sync severed) and the copy is marked with `tombstoned_at` and a non-personal `tombstone_reason` — the deleted user's ID, display name, or any other personal data MUST NOT be retained on the recipient's copy. GroupShares created by the user are detached the same way per member
- **Received shares removed**: copies in the deleted user's own vault and their SecretShare links are hard-deleted; the original owners' secrets are untouched

Tombstone fields are display metadata only (the UI shows the copy as shared by a deleted account and no longer synced); they MUST NOT restrict the recipient's access.

#### Scenario: Delegated secret transfers ownership
- **WHEN** a user with an active delegation on a secret has their account data deleted
- **THEN** the delegate MUST become the owner of that secret
- **AND** the delegation MUST be marked permanent

#### Scenario: Recipient copy detached with tombstone
- **WHEN** a user who shared a secret with a recipient has their account data deleted
- **THEN** the recipient MUST keep their copy as an independent, fully accessible secret
- **AND** the copy MUST be marked tombstoned with a reason that contains no personal data of the deleted user
- **AND** subsequent updates by the recipient MUST NOT attempt to sync anywhere

#### Scenario: Original owner unaffected by recipient deletion
- **WHEN** a user who received a shared secret has their account data deleted
- **THEN** the received copy and its share link MUST be deleted
- **AND** the original owner's secret MUST remain unchanged

### Requirement: Deletion and GDPR Audit Events
The system MUST emit typed events via `OCP\EventDispatcher` for data-subject operations: `GdprExportPerformedEvent` (user ID, whether the vault half was included, timestamp) when the GDPR metadata package is produced, and `AccountDataDeletedEvent` (user ID, trigger in-app or user-deleted, per-entity deletion/transfer/detach counts, timestamp) when a deletion cascade completes. Event payloads MUST carry counts and modes only — never secret names, values, or ciphertext.

This requirement is scoped to event emission only: persistence, retention, and admin UI for these events belong to the future audit-trail change (FEATURES.md V1 accountability row), which will register listeners for them. `AccountDataDeletedEvent` MUST be emitted only on completed cascade runs.

#### Scenario: Deletion event carries counts only
- **WHEN** an account deletion cascade completes having deleted 200 secrets, transferred 3 delegated secrets, and detached 12 recipient copies
- **THEN** an `AccountDataDeletedEvent` MUST be dispatched carrying those counts and the trigger
- **AND** the payload MUST contain no secret names, values, or ciphertext

#### Scenario: GDPR export event emitted
- **WHEN** a user produces a GDPR data export package
- **THEN** a `GdprExportPerformedEvent` MUST be dispatched recording whether the vault half was included
