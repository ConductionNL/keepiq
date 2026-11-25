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
The system MUST let a user list versions newest-first (metadata only), view a version via client-side decryption identical to a head read, and restore a version — creating a new head version and reaching every copy in the same share-save. The holder of a view-only copy (user-sharing, "View-Only Copies") MAY list and view its versions but MUST NOT restore one: the web app MUST NOT offer Restore on it, and `POST /api/v1/versions/{id}/restore` MUST answer 428 with `error` `view_only` and change nothing.

#### Scenario: Restore becomes a new head and syncs
- GIVEN a secret shared with a recipient and a prior version `V`
- WHEN the owner restores `V`
- THEN the current head MUST first be snapshotted, the head MUST become `V`'s values, and the recipient's copy MUST hold `V`'s values in the same transaction

#### Scenario: A view-only holder cannot restore
- GIVEN a secret shared directly with Bob, whose copy has a prior version `V`
- WHEN Bob sends `POST /api/v1/versions/{id}/restore` for `V`
- THEN the system MUST answer 428 with `error` `view_only`, and Bob's copy and its version list MUST stay as they were

#### Scenario: A restore of the TOTP seed and passkeys
@e2e exclude Asserted on the persisted head and the share-save body; covered by PHPUnit (SecretVersionServiceTest, ShareSaveRequestTest, ShareSaveServiceTest).
- GIVEN a version records an empty `totp` or `passkeys` as `''` and a version made before the fields existed holds null
- WHEN the version is restored
- THEN a null field MUST keep the head's value, a `''` field MUST clear it, and a value MUST set it, on the head and on every copy
- AND a field whose value is the head's own ciphertext counts as kept, so a client that does not send it is not refused; a different value the patch leaves out MUST be refused with 400 `invalid_body`
- AND the restore MUST clear `totp_updated_at` or `passkeys_updated_at` of each of the two it changes, and on a flagged secret `key_updated_at` of a key it changes, so written-back old ciphertext never counts as a rotation and reads as exposed
- AND on a secret that is not flagged a restore that changes the key MUST set `key_updated_at` to the time of the restore, as an edit does, so the password's age for expiry, health and the compliance report is never taken from `created_at` by a restore

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

### Requirement: Compare two versions side by side

The system MUST let the owner of a secret pick two entries in the version history panel, two earlier versions or one earlier version and the current value, and show them side by side. Both MUST be decrypted in the browser with the key already in session, the same way a single version is viewed. Every field that differs (name, address, username, value, notes and each additional field, including one added or removed) MUST be marked, and a secret value MUST stay masked until the owner reveals it. No decrypted value and no comparison result MUST be sent to the server. A recipient of a shared copy MUST NOT be offered the comparison, as recipients get no version history.

#### Scenario: An owner sees what changed between two versions

- **GIVEN** an owner whose login "Mail server" has a version from last week and a current value with a new password and a new additional field `port`
- **WHEN** the owner opens the version history panel in the secret detail sidebar, picks last week's version and the current value and chooses Compare
- **THEN** the two are shown side by side with the password row and the `port` row marked as changed
- **AND** both passwords stay masked until the owner chooses Reveal

#### Scenario: Two identical versions

- **GIVEN** two versions of a secret that differ only in their timestamps
- **WHEN** the owner compares them
- **THEN** the comparison says that no field changed

### Requirement: A restore is one share-save of the version
The web app MUST restore a version by decrypting it in the browser and sending it as one share-save with `restoredFrom` and the version's whole value, based on the head's `updatedAt`. A refused or failed share-save MUST leave the head, every copy and the version history unchanged and MUST show an error. The version restore route, `POST /api/v1/versions/{id}/restore`, changes one row only and records the restore too; the web app doesn't use it. That route MUST accept an optional `baseUpdatedAt` and, when it differs from the row's `updatedAt`, change nothing and answer 409 as a stale update does. With or without a base, a save that changes the row between the route's read and its write MUST make the route change nothing and answer 409 too.

#### Scenario: A failed restore changes nothing
- GIVEN a shared secret whose head changed in another browser after this one opened it
- WHEN the owner restores a prior version here
- THEN the server MUST answer 409 `stale`, and the head, the copies and the version list MUST stay as they were

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
