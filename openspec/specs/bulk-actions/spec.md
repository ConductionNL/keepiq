# Bulk Actions Specification

**Status**: done

**OpenSpec changes:** [bulk-actions](../../changes/bulk-actions/)

## Purpose

Let a user act on many secrets at once from the vault list instead of one dialog at a time.
Multi-select (per-row, select-all of the current view, shift-range) drives four bulk
operations — move to folder, delete, share to user/group, and add to team folder — each run
as a chunked, progress-reported client operation with a per-item success/failure report and
no silent partial-failure drops. Vault cleanup and team migrations are one-by-one today; the
team-folder-sharing change will make 100+-secret org vaults normal, so bulk is the
difference between usable and painful. Requested in NC Passwords issue #610 and logged as
the canonical `bulk-actions` feature at demand 55.

Nothing new is invented: move reuses the metadata-only update path, delete reuses the
existing irreversible hard-delete cascade (there is no trash), and share / add-to-team-folder
reuse the per-recipient RSA fan-out and idempotent-upsert pattern from team-folder-sharing.

## Requirements

### Requirement: Multi-Select and Bulk Action Bar
The system MUST support per-row, select-all (current filtered/paginated view), and
shift-range selection in the vault list, with a count and a clear control; selection is
client-only and cleared on lock. A bulk action bar MUST appear while a selection is active,
offering move, delete, share, and add-to-team-folder.

#### Scenario: Select and act
- GIVEN a user has selected several secrets
- WHEN the selection is active
- THEN a bulk action bar MUST offer move, delete, share, and add-to-team-folder

### Requirement: Chunked Execution With a Per-Item Report
Every bulk action MUST run in chunks with live progress, be cancellable, and produce a
per-item report (succeeded / failed-with-reason / skipped-with-reason) in which every
selected secret appears exactly once, with a retry that re-runs only the failures.

#### Scenario: Nothing is dropped silently
- GIVEN a bulk action over N selected secrets where some fail
- WHEN the run completes
- THEN all N secrets MUST appear in the report and "retry failed" MUST re-run only the failures

### Requirement: The Four Bulk Operations
The system MUST provide bulk move (metadata-only), bulk delete (explicit, count-confirmed,
irreversible hard delete with cascade), bulk share (per-recipient client-side RSA
re-encryption, no plaintext to the server), and bulk add-to-team-folder (team-folder
membership fan-out). Share and add-to-team-folder MUST use idempotent server writes so
resume/retry never double-shares.

#### Scenario: Move, delete, and share behave correctly
- GIVEN a selection of owned secrets
- WHEN the user bulk-moves them, or bulk-deletes them after a count-named confirmation, or bulk-shares them
- THEN move MUST only change `folderId`, delete MUST hard-delete with cascade, and share MUST fan out re-encrypted copies with no plaintext leaving the browser

### Requirement: Ownership and Authorization Preserved
The system MUST act only on secrets the user is permitted to act on, reusing each endpoint's
existing per-object guard; non-permitted items in a mixed selection MUST be skipped with a
reason, and no new unguarded batch surface may be introduced.

#### Scenario: Mixed selection skips the non-permitted
- GIVEN a selection mixing permitted and non-permitted secrets
- WHEN a bulk action runs
- THEN non-permitted secrets MUST be skipped with a reason while the permitted ones are processed

## User Stories

- As a user, I want to select many secrets and move them into a folder at once so that
  reorganising my vault is fast.
- As a user, I want to delete a batch of stale secrets in one confirmed action.
- As a user, I want to share several secrets with a teammate at once so that onboarding is
  not one dialog per secret.
- As a user, I want to add a batch of secrets to a team folder in one action.
- As a user, I want a clear report of what succeeded and what failed so that nothing is lost
  silently, and I want to retry just the failures.

## Acceptance Criteria

- [ ] Vault list supports per-row, select-all (current view), and shift-range selection
- [ ] Selection is client-only and cleared on lock
- [ ] A bulk action bar offers move, delete, share, and add-to-team-folder
- [ ] Each action is chunked, progress-reported, and produces a per-item report with retry
- [ ] No selected item is dropped silently — every item appears in the report exactly once
- [ ] Bulk move is metadata-only (no re-encryption)
- [ ] Bulk delete is an explicit, count-confirmed, irreversible hard delete with cascade
- [ ] Bulk share re-encrypts per recipient client-side; no plaintext leaves the browser
- [ ] Bulk share / add-to-team-folder use idempotent writes; resume/retry never double-shares
- [ ] Non-permitted items in a mixed selection are skipped with a reason; no new IDOR surface

## Notes

- **No trash today**: `SecretService::delete` is an irreversible hard delete
  (`lib/Service/SecretService.php:826`, `:852`); a trash/undo is out of scope and would be a
  separate change.
- Depends on `team-folder-sharing` for the fan-out / idempotent-upsert pattern and the
  add-to-team-folder action.
- Related specs: secrets (list/delete/move), secrets-write-ui (single-item move/share),
  team-folder-sharing (fan-out), user-sharing (per-recipient share copies).
- Related ADRs: ADR-001 (own tables, no OpenRegister), ADR-003 (zero-knowledge — server-side
  share fan-out is impossible, so shares are client-driven).
- Out of scope for v1: trash/undo, bulk value-edit, machine-seam bulk actions,
  whole-vault (cross-page) select-all.
