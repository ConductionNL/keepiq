---
kind: code
---

# Proposal: Version history on secrets

## Why

Doriath overwrites a secret's encrypted fields **in place** — the previous value is gone the instant an update lands. Verified in `lib/Service/SecretService.php:766-791`: `update()` sets the new ciphertext (`$secret->setKey($key)` at line 777) and calls `$this->mapper->update($secret)` at line 791, replacing the row. The application-side write path is identical (`updateByApplication`, `lib/Service/SecretService.php:482-489`). There is no version table (`lib/Db/` has no version entity) and `docs/FEATURES.md` never mentions version history. So a fat-fingered edit, a bad rotation, or a sync that clobbers the wrong value is **unrecoverable** today.

Version history is now a shipped, expected capability among the tools Doriath is measured against:

- **Passbolt** shipped secret version history in **v5.7.0 (2026)** — the direct team-vault comparator (`docs/FEATURES.md:26`, "Team password manager… audit logs").
- **Bitwarden** has kept password history per item for years; it is a standard "Reports/Watchtower"-adjacent expectation for the `docs/FEATURES.md:24` tier Doriath targets.
- Rollback after a bad rotation or an accidental overwrite is a recurring team-vault need and a concrete **audit/compliance** driver: NIS2 credential-hygiene expectations assume you can show, and revert to, prior credential state.

The feature fits Doriath's model with no new crypto: a prior version is simply the previous ciphertext blob under the owner's (and each recipient's) existing RSA key — retaining it is a copy, and restoring it is a normal update that the existing sync-on-update fan-out already knows how to propagate (`openspec/specs/user-sharing/spec.md:76-89`). No existing OpenSpec change covers this (checked all active changes' `proposal.md`; none mention versions, history, or rollback).

## What Changes

- **Retain prior encrypted values.** When `SecretService::update` (and `updateByApplication`) changes any stored field, the system MUST snapshot the **pre-update** state as a version row before overwriting the live secret. The live `Secret` row remains the head; versions are immutable historical copies. Snapshots capture the plaintext metadata (`name`, `url`) and the encrypted blobs (`key`, `login`, `additional_fields`) exactly as stored — the server never decrypts them — plus the actor and the `encryption_suite_id` under which the blobs were encrypted.
- **List, view, restore.** A user can list a secret's versions (timestamp + actor + version number, newest first), view a version (fetched as ciphertext and decrypted client-side, identical to reading the head), and **restore** a version. Restoring MUST create a **new head version** (snapshotting the current head first) and set the head's values to the selected version's, then propagate to all shared copies via the existing sync-on-update mechanism.
- **Admin-configurable retention + pruning.** Retention is bounded by count and/or age (`version_retention_count`, default 20; `version_retention_days`, default 365, 0 = unlimited), configurable by an administrator with a sane floor. A background job (mirroring `PurgeAuditLogJob`, `lib/BackgroundJob/PurgeAuditLogJob.php`) prunes versions beyond the configured window in bounded batches.
- **Compromise-recovery migration interaction.** Version blobs are ciphertext under the suite being rotated during compromise recovery (`openspec/specs/encryption-suites/spec.md:155-171`). Because re-encrypting an unbounded history in the browser is costly, migration MUST re-encrypt the **head plus the N most recent versions** (N = a small migration window, default 5) under the new suite and **drop older versions** — they are unreadable once the old suite is `compromised`/`revoked` anyway. This is documented as an honest limitation, not silently lost data.
- **GDPR deletion cascade.** Deleting a secret MUST cascade-delete its versions; account deletion and folder-cascade deletion inherit this (`openspec/specs/gdpr-compliance/spec.md:37`). Versions carry no personal data beyond the actor id, which the account-deletion anonymization already scrubs.
- **Exclude versions from link-share snapshots.** Link shares are already point-in-time AES snapshots under an Argon2id-derived key (`openspec/specs/link-sharing`); they are not live secret links and MUST NOT include version history.
- Dispatch a **typed audit event** (`SecretVersionRestoredEvent`) for restores via `OCP\EventDispatcher`, carrying only the secret id, restored version number, actor, and timestamp — never ciphertext or values. Ordinary version creation on update is already covered by the existing `secret.updated` audit entry.
- **Out of scope for v1**: per-field (rather than whole-secret) version diffing/merge, and named/tagged versions — v1 is a linear, timestamped history with whole-secret restore.

## Impact

- **New DB table**: `doriath_secret_versions`. New migration.
- **New service**: `SecretVersionService` (snapshot-on-update, list, get, restore, prune, migration re-wrap window). New `SecretVersionController` + routes.
- **Modified**: `SecretService::update` / `updateByApplication` gain a pre-write snapshot step; `MigrationService` compromise-recovery gains the head+N re-encrypt / drop-older step; `SecretService` delete + folder-cascade + `AccountDeletionService` cascade gain a version-delete step; `SettingsService` gains the two retention keys; new prune `TimedJob`.
- **Crypto note**: a version's blobs are ciphertext under the owner's (and each recipient's copy's) RSA key. A **recipient's** version history is scoped to their own copy — it only records versions of the copy they hold, updated by them or by sync. The **canonical** history lives with the owner's copy. This is documented so no one expects to see the owner's full edit history from a recipient copy.
- **Frontend**: a version-history tab/panel on the secret detail view (list, view-as-read-only, restore); admin settings inputs for the two retention values.
