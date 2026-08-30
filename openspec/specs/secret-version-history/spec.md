# Secret Version History Specification

**Status**: done

**OpenSpec changes:**
- `secret-version-history` (2026-07-16) — Retain prior encrypted values on update as immutable versions; list/view (client-side decrypt)/restore; admin-configurable retention (count + age) with a background prune job; bounded re-encrypt window during compromise-recovery migration (head + N recent, older dropped); GDPR deletion cascade; exclusion from link-share snapshots; typed restore audit event

## Purpose

Keepiq overwrites a secret's encrypted fields in place (`lib/Service/SecretService.php:766-791`), so a bad rotation or accidental overwrite is unrecoverable. Version history retains the previous ciphertext of a secret whenever its fields change and lets a user review and roll back to a prior value. A version is simply the previous ciphertext under the secret's existing RSA key — no new cryptography — and a restore is an ordinary update that the existing sync-on-update fan-out propagates to every shared copy. This matches shipped capability in the comparators Keepiq is measured against (Passbolt v5.7.0, 2026; Bitwarden item history) and answers a concrete NIS2 credential-hygiene / audit driver.

## Requirements

### Requirement: Prior encrypted values are retained on update
The system MUST snapshot a secret's pre-update state as an immutable version whenever an update changes any stored field, capturing plaintext metadata and encrypted blobs exactly as stored, without decrypting anything server-side.

#### Scenario: Update snapshots the pre-update state
- GIVEN a secret whose current key blob is `C0`
- WHEN the owner updates it so the key blob becomes `C1`
- THEN a version row MUST retain `C0` with the actor and prior encryption_suite_id, and the head MUST hold `C1`

### Requirement: List, view, and restore versions
The system MUST let a user list versions newest-first (metadata only), view a version via client-side decryption identical to a head read, and restore a version — creating a new head version and propagating via sync-on-update.

#### Scenario: Restore becomes a new head and syncs
- GIVEN a secret shared with a recipient and a prior version `V`
- WHEN the owner restores `V`
- THEN the current head MUST first be snapshotted, the head MUST become `V`'s values, and the change MUST propagate to the recipient's copy

### Requirement: Admin-configurable retention and pruning
The system MUST bound retention by count and/or age (defaults 20 versions / 365 days, 0 = unlimited), administrator-configurable, pruned in bounded batches by a background job that never touches the head.

#### Scenario: Versions beyond the count window are pruned
- GIVEN a retention count of 20 and a secret with 25 versions
- WHEN the prune job runs
- THEN only the 20 most recent versions MUST remain and the head MUST be untouched

### Requirement: Compromise-recovery migration re-encrypts a bounded window
During compromise-recovery migration the system MUST re-encrypt the head plus the N most recent versions (default 5) under the new suite and drop older versions, stating the limitation to the user.

#### Scenario: Recent versions migrate, older versions drop
- GIVEN a secret with a head and 12 prior versions migrating with a window of 5
- WHEN migration completes
- THEN the head and 5 most recent versions MUST be re-encrypted under the new suite and the 7 older versions MUST be deleted

### Requirement: Versions cascade-delete and are excluded from link shares
The system MUST delete a secret's versions when the secret, a containing folder, or the owning account is deleted, and MUST NOT include versions in link-share snapshots.

#### Scenario: Link share excludes version history
- GIVEN a secret with prior versions
- WHEN the owner creates a link share
- THEN the snapshot MUST contain only the current value and no prior version

### Requirement: Restores are auditable
The system MUST dispatch a typed `SecretVersionRestoredEvent` on restore carrying only the secret id, restored version number, actor, and timestamp — never ciphertext or values.

#### Scenario: Restore dispatches a content-free event
- GIVEN an owner restores a prior version
- WHEN the restore completes
- THEN a `SecretVersionRestoredEvent` MUST be dispatched with no ciphertext or decrypted value in its payload

## User Stories

- As a user, I want to roll back a secret after a bad rotation so that I recover the working credential
- As a user, I want to see who changed a secret and when so that I can audit its history
- As a user, I want to view a prior value before restoring it so that I restore the right one
- As an admin, I want to bound how much history is kept so that storage stays predictable
- As a compliance owner, I want prior credential state recoverable so that I meet credential-hygiene expectations

## Acceptance Criteria

- [ ] Every field-changing update snapshots the pre-update state; no-op resubmits do not
- [ ] Versions are listable newest-first (metadata only), viewable via client-side decrypt, and restorable
- [ ] Restore creates a new head version and propagates to shared copies via sync-on-update
- [ ] Retention is bounded by admin-configurable count and age, pruned by a background job without touching the head
- [ ] Compromise-recovery migration re-encrypts head + N recent versions and drops older ones, surfaced to the user
- [ ] Deleting a secret / folder / account cascades to its versions; link-share snapshots exclude versions
- [ ] A version read is refused (403) when its suite is revoked/compromised, matching head-read
- [ ] Restores dispatch a typed audit event carrying no ciphertext or values

## Notes

- **Crypto scope**: a version's blobs are ciphertext under the owner's (and each recipient copy's) RSA key. A recipient's history is scoped to their own copy; the canonical history lives with the owner's copy. No one can see the owner's full edit history from a recipient copy.
- **Migration window (provisional)**: N = head + 5 most recent versions re-encrypted; older dropped. See the change's design "Decisions made under uncertainty".
- Out of scope for v1: per-field diff/merge and named/tagged versions.
- Related ADRs: ADR-001 (own DB tables), ADR-003 (RSA/AES encryption architecture). Verified overwrite-in-place: `lib/Service/SecretService.php:766-791` and `:482-489`.
