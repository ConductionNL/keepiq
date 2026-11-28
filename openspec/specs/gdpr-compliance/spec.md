# gdpr-compliance Specification

## Purpose
TBD - created by archiving change secret-export-gdpr. Update Purpose after archive.

## Requirements

### Requirement: GDPR Personal Data Export
The system MUST provide a full personal data export (GDPR Art. 15, right of access) as one machine-readable, versioned JSON package, assembled in the browser from two halves:

- **Server metadata** (`GET /api/v1/gdpr/metadata`, session user only): EncryptionSuite records (certificate, status, revocation/reinstatement audit fields — excluding the encrypted private-key blob, with the exclusion documented inside the package), shares given and received, each flagged `quarantined`, including quarantined share rows that name the user as their source's owner or their recipient, delegations, link-share metadata (no encrypted snapshots) of the links the user holds and, flagged `handedOver`, of the links they made that a transfer handed to someone else, secret requests, and user settings
- **Client vault**: the decrypted secrets and folder structure, produced in the browser when the vault is unlocked

If the user cannot unlock the vault, the package MUST still be produced with the server half only, and the vault section MUST explicitly state that the vault is end-to-end encrypted and was not unlocked by the data subject. The package MUST be downloaded locally and MUST NOT be stored server-side.

#### Scenario: Full package with unlocked vault
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** an unlocked user requests their GDPR data export
- **THEN** the downloaded package MUST contain both the server metadata and the decrypted vault sections
- **AND** the decrypted vault content MUST NOT appear in any HTTP request

#### Scenario: Metadata-only package without unlock
@e2e exclude Locked-vault package variant — the metadata-only package with the explicit "vault not unlocked" section is assembled client-side; covered by vitest (export-gdprPackage.spec.js metadata-only variant test).
- **WHEN** a user requests their GDPR data export while the vault is locked and declines to unlock
- **THEN** the package MUST contain the server metadata
- **AND** the vault section MUST state that the end-to-end encrypted vault was not unlocked

#### Scenario: Metadata endpoint is self-scoped
@e2e exclude Server-side self-scoping contract — the endpoint has no user selector and collects only the session user's data; covered by PHPUnit (GdprServiceTest self-scoped + GdprControllerTest metadata tests).
- **WHEN** an authenticated user calls the GDPR metadata endpoint
- **THEN** the response MUST contain only data belonging to the session user — no parameter may select another user

### Requirement: Account Data Deletion
The system MUST support deletion of all of a user's Keepiq data (GDPR Art. 17, right to erasure) via two triggers running the same idempotent cascade:

- **In-app**: gated by a verified key proof (see the `vault-key-proof` capability) AND a typed confirmation phrase; deletes Keepiq data while the Nextcloud account remains
- **Automatic**: a `UserDeletedEvent` listener runs the cascade when the Nextcloud account is deleted, so Keepiq data never outlives its account

The in-app trigger wipes every secret, suite and migration in one request, so a Nextcloud session alone MUST NOT be sufficient for it. The master-password re-entry is therefore proven to the server as a signature made with the private key it unlocks, bound to the confirmation phrase, rather than checked only in the browser. The phrase stays as a guard against a slip. A user without an active EncryptionSuite cannot make a proof; their Keepiq data is removed through the automatic trigger when their Nextcloud account is deleted.

The cascade MUST remove: the user's secrets and folders, their EncryptionSuites (including encrypted private keys) and SuiteMigration records, the link shares the user holds (also on secrets that pass to a delegate), secret requests, share records per the shared-secret semantics requirement, and user settings. It MUST also replace the user as the recorded maker of link shares another user holds with a marker that is not a valid Nextcloud user ID, so the holder's compromise revoke does not revoke them. Every cascade step MUST be idempotent so an interrupted run can be safely re-executed.

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

### Requirement: Shared Secret Semantics on Account Deletion
The cascade MUST handle shared and delegated secrets with the following defined semantics:

- **Ownership transfer via delegation**: a secret with an active SecretDelegation transfers to the delegate — the delegation becomes permanent (per the user-sharing spec's `is_permanent` semantics for deleted/revoked owners), the delegate becomes owner, and the delegate's access continues uninterrupted. The delegate's own copy of the secret and its SecretShare link MUST stay, so the delegate's next save of the copy reaches the secret, its other remaining copies and its partner shares. When the link came from a GroupShare or a team folder, it stays as a direct share, so removing the group share, or a later change to the deleted user's team folder, never removes the delegate's copy. When the kept link names the deleted user as its maker, that is replaced with a marker that is not a valid Nextcloud user ID. When the runtime row judge does not trust the delegate's share row, or anything limits the delegate's access (the copy is use-only, has an end date or is read-only, the link carries a limit of its own, or any direct, group or team-folder grant reaching the copy is limited), that copy is detached like any other, so no limit is ever lifted or added
- **Detach with tombstone**: for shares the deleted user granted, each recipient's copy (already a full Secret row encrypted under the recipient's own suite) is kept by the recipient as an independent secret; the SecretShare link is deleted (sync severed) and the copy is marked with `tombstoned_at` and a non-personal `tombstone_reason` — the deleted user's ID, display name, or any other personal data MUST NOT be retained on the recipient's copy. GroupShares created by the user are detached the same way per member. This holds for the other recipients of a secret that passes to a delegate too; only the delegate's own copy is kept
- **Received shares removed**: copies in the deleted user's own vault and their SecretShare links are hard-deleted; the original owners' secrets are untouched
- **Partner shares ended**: the partner shares of a secret that transfers to a delegate go with it to the delegate; the partner shares of every other secret the user owns are revoked as the federated-sharing spec requires when a secret is deleted for good: each partner is sent a `SHARE_UNSHARED`, except for a share the recipient declined or whose partner was removed, which is removed without a notification

Tombstone fields are display metadata only (the UI shows the copy as shared by a deleted account and no longer synced); they MUST NOT restrict the recipient's access.

#### Scenario: Delegated secret transfers ownership
@e2e exclude Server-side cascade semantics — ownership transfer + permanent-delegation flip; covered by PHPUnit (AccountDeletionServiceTest delegation-transfer test).
- **WHEN** a user with an active delegation on a secret has their account data deleted
- **THEN** the delegate MUST become the owner of that secret
- **AND** the delegation MUST be marked permanent

#### Scenario: The delegate keeps their linked copy
@e2e exclude Server-side cascade semantics — the delegate's share row and copy survive the detach step; covered by PHPUnit (AccountDeletionServiceTest for the kept row, ShareSaveServiceTest testTheOwnerSavingThroughTheirCopyWritesThePartnerShares for the save).
- **GIVEN** Alice's secret is shared with Dave and Carol, and Dave holds an active delegation on it
- **WHEN** Alice's account data is deleted
- **THEN** Dave's copy MUST stay linked to the secret and MUST NOT be tombstoned
- **AND** Carol's copy MUST be detached with a tombstone
- **AND** Dave's next share-save through his copy MUST write the secret and its partner shares
- **AND** Dave's kept share row MUST NOT name Alice as its maker

#### Scenario: A delegate's restricted copy is detached
@e2e exclude Server-side cascade semantics; covered by PHPUnit (AccountDeletionServiceTest).
- **GIVEN** Alice's secret reaches Dave through a use-only group share that ends at T, and Dave holds an active delegation on it
- **WHEN** Alice's account data is deleted
- **THEN** Dave MUST become the owner of the secret
- **AND** Dave's copy MUST be detached with a tombstone and stay use-only until T

#### Scenario: Recipient copy detached with tombstone
@e2e exclude Server-side cascade semantics — link severed + recipient copy tombstoned with a non-personal reason; covered by PHPUnit (AccountDeletionServiceTest granted-share detach test asserting no deleted-user identity on the copy).
- **WHEN** a user who shared a secret with a recipient has their account data deleted
- **THEN** the recipient MUST keep their copy as an independent, fully accessible secret
- **AND** the copy MUST be marked tombstoned with a reason that contains no personal data of the deleted user
- **AND** subsequent updates by the recipient MUST NOT attempt to sync anywhere

#### Scenario: Original owner unaffected by recipient deletion
@e2e exclude Server-side cascade semantics — received copies + links hard-deleted, original owner untouched; covered by PHPUnit (AccountDeletionServiceTest received-shares-removed test).
- **WHEN** a user who received a shared secret has their account data deleted
- **THEN** the received copy and its share link MUST be deleted
- **AND** the original owner's secret MUST remain unchanged

### Requirement: Deletion and GDPR Audit Events
The system MUST emit typed events via `OCP\EventDispatcher` for data-subject operations: `GdprExportPerformedEvent` (user ID, whether the vault half was included, timestamp) when the GDPR metadata package is produced, and `AccountDataDeletedEvent` (user ID, trigger in-app or user-deleted, per-entity deletion/transfer/detach counts, timestamp) when a deletion cascade completes. Event payloads MUST carry counts and modes only — never secret names, values, or ciphertext.

This requirement is scoped to event emission only: persistence, retention, and admin UI for these events belong to the future audit-trail change (FEATURES.md V1 accountability row), which will register listeners for them. `AccountDataDeletedEvent` MUST be emitted only on completed cascade runs.

#### Scenario: Deletion event carries counts only
@e2e exclude Server-side event-payload contract — AccountDataDeletedEvent carries counts + trigger only; covered by PHPUnit (AccountDeletionServiceTest event-on-completion + ExportGdprEventTest no-secret-material tests).
- **WHEN** an account deletion cascade completes having deleted 200 secrets, transferred 3 delegated secrets, and detached 12 recipient copies
- **THEN** an `AccountDataDeletedEvent` MUST be dispatched carrying those counts and the trigger
- **AND** the payload MUST contain no secret names, values, or ciphertext

#### Scenario: GDPR export event emitted
@e2e exclude Server-side event-payload contract — GdprExportPerformedEvent records whether the vault was included; covered by PHPUnit (GdprControllerTest emits-event + ExportGdprEventTest payload tests).
- **WHEN** a user produces a GDPR data export package
- **THEN** a `GdprExportPerformedEvent` MUST be dispatched recording whether the vault half was included
