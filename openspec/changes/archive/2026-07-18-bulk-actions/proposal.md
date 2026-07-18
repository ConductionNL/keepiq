---
kind: code
depends_on: [team-folder-sharing]
---

# Proposal: Multi-select bulk actions in the vault list

## Why

Every vault operation in Doriath is one-secret-at-a-time. Moving ten logins into a folder,
deleting a batch of stale credentials, or sharing a project's secrets with a new
teammate each means repeating the same dialog N times. The team-folder-sharing change
(this change's dependency) will make 100+-secret org vaults normal, turning that friction
into a real adoption blocker. Bulk multi-select is table stakes in every mature vault
(Bitwarden, 1Password), NC Passwords issue #610 requests exactly this, and it is logged as
the canonical `bulk-actions` feature at demand 55.

Verified the primitives exist and are single-item today:

- **Delete is a hard delete with no trash.** `SecretService::delete`
  (`lib/Service/SecretService.php:826`) calls `$this->mapper->delete($secret)`
  (`lib/Service/SecretService.php:852`) after cascading to link shares, requests, user
  shares, group shares, and delegations. There is **no** soft-delete / trash column on the
  `Secret` entity (`grep -in "deleted_at\|trash" lib/Db/Secret.php` finds only a share-copy
  deletion-reason marker), so a bulk delete is an irreversible hard delete — this change
  must treat it as such, not assume trash semantics.
- **Move is a metadata-only update.** A folder move is `secret.updateSecret({ folderId })`
  with no re-encryption (`openspec/specs/secrets-write-ui/spec.md:50`).
- **Share is a per-recipient RSA re-encryption per secret.** `ShareService::createShare`
  (`lib/Service/ShareService.php:125`) and the transaction-wrapped
  `ShareService::createBatchShares` (`lib/Service/ShareService.php:227`) already back the
  client fan-out; the owner's browser decrypts then re-encrypts under each recipient's
  certificate (ADR-003).
- **Add-to-team-folder** is `POST /api/v1/team-folders/{id}/members`-style fan-out from the
  team-folder-sharing change — the same chunked, idempotent-upsert fan-out pattern
  (upsert on `(source_secret_id, target_user_id, team_folder_id)`,
  team-folder-sharing `design.md` "Fan-out is membership × secrets").

Doriath already has the chunked, progress-reported, idempotent fan-out pattern from
team-folder-sharing; this change reuses it for the crypto-heavy actions and adds the
select-and-report UX around all four.

## What Changes

- Add **multi-select to the vault list**: per-row checkboxes, select-all (current
  filtered/paginated view), shift-click range selection, and a selection count with a
  clear-selection control. Selection state is client-only (no persistence, cleared on lock).
- Add a **bulk action bar** that appears while a selection is active, offering: **move to
  folder**, **delete**, **share to user/group**, and **add to team folder**.
- Implement each action as a **chunked, progress-reported client operation** with a
  **per-item success/failure report** and **no silent partial-failure drops**:
  - **Bulk move** — chunked loop over `secret.updateSecret({ folderId })` (metadata only,
    no crypto).
  - **Bulk delete** — chunked loop over `SecretService::delete`; because delete is an
    irreversible hard delete (no trash today), the action MUST require an explicit
    typed/confirmed confirmation naming the count, and report exactly which items were
    deleted vs failed.
  - **Bulk share to user/group** — reuse the team-folder-sharing per-recipient RSA
    fan-out + `createBatchShares` (`lib/Service/ShareService.php:227`): for each
    (secret × recipient) the browser decrypts and re-encrypts client-side, POSTing
    ciphertext; server upserts idempotently so a retried/resumed run never double-shares.
  - **Bulk add to team folder** — reuse the team-folder membership fan-out
    (team-folder-sharing) for the selected secrets' folder(s).
- Add a **progress + report UI**: a cancellable/resumable progress indicator during the
  run and a final per-item report (succeeded / failed-with-reason / skipped), with a
  "retry failed" affordance that re-runs only the failed items (safe because server writes
  are idempotent).
- Explicitly **out of scope for v1**: a trash/undo for bulk delete (no soft-delete exists;
  a separate change would introduce trash), bulk edit of secret **values** (each value is a
  distinct ciphertext — no meaningful batch), bulk operations from the machine/`doriath://`
  seam (browser-user feature only), and cross-user bulk actions on secrets you do not own.

## Capabilities

### New Capabilities
- `bulk-actions`: multi-select in the vault list plus chunked, progress-reported,
  per-item-reported bulk move / delete / share / add-to-team-folder operations that reuse
  the existing per-item write paths and the team-folder fan-out pattern.

### Modified Capabilities
_(none — the underlying delete, move, share, and team-folder-member operations are reused
unchanged; the delta is the selection UX and the chunked orchestration, all in the new
capability. If a thin batch move/delete endpoint is added it is additive, not a change to
existing requirements.)_

## Impact

- **Frontend**: vault list gains selection state + a bulk action bar; a shared
  chunked-runner composable drives progress/report/retry; reuses the team-folder fan-out
  and share dialogs' crypto.
- **Backend**: optional thin batch move/delete endpoints (idempotent) to cut round-trips;
  otherwise the existing single-item endpoints and `createBatchShares` are reused. No new
  table (ADR-001), no new crypto (ADR-003).
- **Safety**: bulk delete is an irreversible hard delete — the confirmation UX and the
  per-item report are the guardrails; nothing is dropped silently.
- **Depends on** team-folder-sharing for the fan-out/idempotent-upsert pattern and the
  add-to-team-folder action.
