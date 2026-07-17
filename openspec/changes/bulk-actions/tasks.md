# Tasks: Bulk actions

## 1. Vault-list selection (frontend)

- [ ] 1.1 Add per-row checkboxes, header select-all (current filtered/paginated view), and shift-click range selection to the vault list; hold selection as a `Set<secretId>` in the list store.
- [ ] 1.2 Show a selection count with a clear control; discard selection on vault lock and never persist it to the server or browser storage.

## 2. Shared chunked runner (frontend)

- [ ] 2.1 Build a `useBulkRunner` composable that processes a selection in fixed chunks (default 25), emits progress `(done, total, label)`, and collects a per-item result `{ secretId, status: ok|failed|skipped, reason? }`; guarantee every selected item appears in the report exactly once.
- [ ] 2.2 Make the runner cancellable (stop after the current chunk) and add "retry failed" that re-runs only the failed subset (safe via idempotent server writes).

## 3. Bulk action bar + dialogs (frontend)

- [ ] 3.1 Add a bulk action bar (Move / Delete / Share / Add to team folder) shown only while a selection is active; each dialog is its own `.vue` under `src/dialogs/` (ADR-004), pickers use `NcSelect` with `inputLabel`.
- [ ] 3.2 Progress + report UI: shared progress bar with cancel, and a final per-item report table (ok / failed+reason / skipped) with a "retry failed" button.

## 4. Bulk move

- [ ] 4.1 Wire the move dialog's per-item function to `secret.updateSecret({ folderId })` (metadata only, no re-encryption); received shares move only within the recipient's own folder tree.

## 5. Bulk delete (irreversible hard delete)

- [ ] 5.1 Wire the delete dialog's per-item function to the secret hard-delete controller path (`SecretService::delete`, `lib/Service/SecretService.php:826`), reusing its cascade to shares/requests/group-shares/delegations; report an already-gone secret as skipped, not failed.
- [ ] 5.2 Require a confirmation that names the exact count (typed confirmation for large sets) and state it cannot be undone (no trash exists today).

## 6. Bulk share + add-to-team-folder (reuse team-folder-sharing fan-out)

- [ ] 6.1 Wire the share dialog to the per-recipient RSA fan-out: for each (secret × recipient) decrypt with the owner's `CryptoKey`, re-encrypt under the recipient's certificate (WebCrypto, ADR-003), POST via `createBatchShares` (`lib/Service/ShareService.php:227`); skip recipients without an active suite.
- [ ] 6.2 Wire the add-to-team-folder dialog to the team-folder-sharing membership fan-out for the selected secrets; both actions rely on the idempotent upsert so resume/retry never double-shares.

## 7. Optional batch endpoints (backend, additive)

- [ ] 7.1 If profiling warrants it, add idempotent, owner-scoped `POST /api/v1/secrets/batch-move` and `POST /api/v1/secrets/batch-delete` returning a per-item `[{ secretId, status, reason? }]` array; keep each item's existing per-object authorization guard (no new IDOR surface).

## 8. Tests

- [ ] 8.1 vitest: select-all-current-view / shift-range selection; selection clears on lock and is never persisted; runner reports every item exactly once and "retry failed" re-runs only failures.
- [ ] 8.2 vitest: bulk share fans out per (secret × recipient) with no plaintext leaving the browser; a suite-less recipient is skipped; a resumed run does not double-share.
- [ ] 8.3 PHPUnit: bulk/hard delete cascades to shares/requests/group-shares/delegations; an already-deleted item is a not-found handled as skipped; any batch endpoint is idempotent and keeps its authorization guard.
- [ ] 8.4 e2e (Playwright): select multiple secrets, bulk-move them, then bulk-delete with the count-named confirmation, verifying the per-item report.

## Acceptance criteria

- Vault list supports per-row, select-all (current view), and shift-range selection; selection is client-only and cleared on lock.
- A bulk action bar offers move, delete, share, and add-to-team-folder while a selection is active.
- Every action runs chunked with progress, produces a per-item report, and drops nothing silently; failures can be retried.
- Bulk move is metadata-only; bulk delete is an explicit, count-confirmed irreversible hard delete with cascade.
- Bulk share and add-to-team-folder reuse the per-recipient RSA fan-out and idempotent upsert; no plaintext leaves the browser and resume/retry never double-shares.
- Not-permitted items in a mixed selection are skipped with a reason, never failing the whole run, and no new unguarded batch surface is introduced.
