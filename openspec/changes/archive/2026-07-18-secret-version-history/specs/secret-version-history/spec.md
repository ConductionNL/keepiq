---
status: proposed
---

# Secret Version History

## Purpose

Retain the previous encrypted value of a secret whenever its fields change, so a bad rotation, an accidental overwrite, or a clobbering sync can be reviewed and rolled back. A version is the previous ciphertext under the secret's existing RSA key — no new cryptography — and a restore is an ordinary update that the existing sync-on-update fan-out propagates to every shared copy.

## ADDED Requirements

### Requirement: Prior encrypted values are retained on update

Doriath SHALL snapshot a secret's pre-update state as an immutable version whenever an update changes any stored field, before the live secret is overwritten. The snapshot MUST capture the plaintext metadata (`name`, `url`) and the encrypted blobs (`key`, `login`, `additional_fields`) exactly as stored, together with the actor and the `encryption_suite_id` under which the blobs were encrypted. The server MUST NOT decrypt any field to create a snapshot.

#### Scenario: Update snapshots the pre-update state

- **GIVEN** a user owns a secret whose current key blob is `C0`
- **WHEN** they update the secret so its key blob becomes `C1`
- **THEN** a version row MUST be created holding `C0` (and the other fields as they were) with the actor and prior `encryption_suite_id`
- **AND** the live secret MUST hold `C1` as the head

#### Scenario: A no-op resubmit does not create a version

- **GIVEN** a user submits an update whose encrypted blobs and metadata equal the current head
- **WHEN** the update is processed
- **THEN** no new version row MUST be created

### Requirement: List, view, and restore versions

Doriath SHALL let a user list a secret's versions (newest first, with version number, actor, and timestamp), view a version's encrypted blobs for client-side decryption identical to reading the head, and restore a version. Restoring MUST create a new head version (snapshotting the current head first), set the head's values to the selected version's, and propagate the change to all shared copies via the existing sync-on-update mechanism.

#### Scenario: Owner lists versions newest first

- **GIVEN** a secret with three prior versions
- **WHEN** the owner opens its version history
- **THEN** the versions MUST be listed newest first with version number, actor, and timestamp
- **AND** no decrypted value MUST appear in the list response

#### Scenario: Restore becomes a new head and syncs

- **GIVEN** a secret shared with a recipient, with a prior version `V`
- **WHEN** the owner restores `V`
- **THEN** the current head MUST first be snapshotted as a new version
- **AND** the head's values MUST become `V`'s values
- **AND** the change MUST propagate to the recipient's copy via sync-on-update

#### Scenario: Viewing a version on a revoked suite is refused

- **GIVEN** a version whose `encryption_suite_id` points to a suite with status `revoked` or `compromised`
- **WHEN** the user requests that version
- **THEN** the system MUST return a 403 response and MUST NOT return the encrypted blobs, identical to the head-read behavior for a revoked suite

### Requirement: Admin-configurable retention and automated pruning

Doriath SHALL bound version retention by count and/or age, both administrator-configurable (`version_retention_count`, default 20; `version_retention_days`, default 365, where 0 means unlimited), and MUST prune versions beyond the configured window in bounded batches via a background job.

#### Scenario: Versions beyond the count window are pruned

- **GIVEN** `version_retention_count` is 20 and a secret has 25 versions
- **WHEN** the prune job runs
- **THEN** only the 20 most recent versions MUST remain
- **AND** the live head MUST be untouched

#### Scenario: Versions older than the age window are pruned

- **GIVEN** `version_retention_days` is 365
- **WHEN** the prune job runs
- **THEN** every version older than 365 days MUST be deleted and newer versions MUST remain

### Requirement: Compromise-recovery migration re-encrypts a bounded version window

During compromise-recovery suite migration, Doriath SHALL re-encrypt the head plus the N most recent versions (default N = 5) of each secret under the new suite and update their `encryption_suite_id`, and MUST drop versions older than that window. Dropped versions are unreadable under the compromised/revoked old suite; this limitation MUST be stated to the user rather than implied away.

#### Scenario: Recent versions migrate, older versions are dropped

- **GIVEN** a secret with a head and 12 prior versions on a suite undergoing compromise recovery, with a migration window of 5
- **WHEN** the migration completes
- **THEN** the head and the 5 most recent versions MUST be re-encrypted under the new suite with their `encryption_suite_id` updated
- **AND** the 7 older versions MUST be deleted
- **AND** every retained version MUST be decryptable with the new suite

### Requirement: Versions are deleted with their secret and excluded from link shares

Doriath SHALL cascade-delete a secret's versions when the secret is deleted, when a containing folder is cascade-deleted, and when a user's account data is deleted. Versions MUST NOT be included in link-share snapshots, which are already point-in-time.

#### Scenario: Deleting a secret deletes its versions

- **GIVEN** a secret with several versions
- **WHEN** the secret is deleted
- **THEN** all of its versions MUST be deleted

#### Scenario: Link share excludes version history

- **GIVEN** a secret with prior versions
- **WHEN** the owner creates a link share of that secret
- **THEN** the link-share snapshot MUST contain only the current value
- **AND** it MUST NOT include any prior version

### Requirement: Restores are auditable

Doriath SHALL dispatch a typed `SecretVersionRestoredEvent` via `OCP\EventDispatcher` when a version is restored, carrying only the secret id, restored version number, actor, and timestamp — never ciphertext or decrypted values.

#### Scenario: Restore dispatches a content-free event

- **GIVEN** an owner restores a prior version of a secret
- **WHEN** the restore completes
- **THEN** a `SecretVersionRestoredEvent` MUST be dispatched carrying only the secret id, restored version number, actor, and timestamp
- **AND** the event payload MUST NOT contain any ciphertext or decrypted value
