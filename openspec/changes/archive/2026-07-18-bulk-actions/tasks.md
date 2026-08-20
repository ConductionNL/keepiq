# Tasks: Bulk actions

## 1. Vault-list selection (frontend)

- [x] 1.1 Add per-row checkboxes, header select-all (current filtered/paginated view), and shift-click range selection to the vault list; hold selection as a `Set<secretId>` in the list store.
  > Note: selection lives in a dedicated `useBulkStore` (deduped id array) rather than the list store; the custom `#list-item` slot renders its own checkbox (the lib slot bypasses CnObjectList's built-in selection UI) with shift-range implemented against the current view order.
- [x] 1.2 Show a selection count with a clear control; discard selection on vault lock and never persist it to the server or browser storage.

## 2. Shared chunked runner (frontend)

- [x] 2.1 Build a `useBulkRunner` composable that processes a selection in fixed chunks (default 25), emits progress `(done, total, label)`, and collects a per-item result `{ secretId, status: ok|failed|skipped, reason? }`; guarantee every selected item appears in the report exactly once.
  > Note: shipped as the `run()`/`retryFailed()` actions of `useBulkStore` (Pinia idiom used app-wide) instead of a standalone composable — identical contract, vitest-locked.
- [x] 2.2 Make the runner cancellable (stop after the current chunk) and add "retry failed" that re-runs only the failed subset (safe via idempotent server writes).

## 3. Bulk action bar + dialogs (frontend)

- [x] 3.1 Add a bulk action bar (Move / Delete / Share / Add to team folder) shown only while a selection is active; each dialog is its own `.vue` under `src/dialogs/` (ADR-004), pickers use `NcSelect` with `inputLabel`.
- [x] 3.2 Progress + report UI: shared progress bar with cancel, and a final per-item report table (ok / failed+reason / skipped) with a "retry failed" button.

## 4. Bulk move

- [x] 4.1 Wire the move dialog's per-item function to `secret.updateSecret({ folderId })` (metadata only, no re-encryption); received shares move only within the recipient's own folder tree.

## 5. Bulk delete (irreversible hard delete)

- [x] 5.1 Wire the delete dialog's per-item function to the secret hard-delete controller path (`SecretService::delete`, `lib/Service/SecretService.php:826`), reusing its cascade to shares/requests/group-shares/delegations; report an already-gone secret as skipped, not failed.
- [x] 5.2 Require a confirmation that names the exact count (typed confirmation for large sets) and state it cannot be undone (no trash exists today).

## 6. Bulk share + add-to-team-folder (reuse team-folder-sharing fan-out)

- [x] 6.1 Wire the share dialog to the per-recipient RSA fan-out: for each (secret × recipient) decrypt with the owner's `CryptoKey`, re-encrypt under the recipient's certificate (WebCrypto, ADR-003), POST via `createBatchShares` (`lib/Service/ShareService.php:227`); skip recipients without an active suite.
  > Note: `createBatchShares` presumes the recipient copies already exist, which no direct-share surface could provide — added the additive, idempotent `ShareService::registerDirectShares` + `POST /api/v1/shares/register-batch` (mirrors the team-folder registration: server creates the copy from client-encrypted blobs, per-item owner guard, `exists` on resume) plus `GET /api/v1/shares/recipient-certificate`. The dialog fans out per (secret × recipient) through it.
- [x] 6.2 Wire the add-to-team-folder dialog to the team-folder-sharing membership fan-out for the selected secrets; both actions rely on the idempotent upsert so resume/retry never double-shares.

## 7. Optional batch endpoints (backend, additive)

- [x] 7.1 If profiling warrants it, add idempotent, owner-scoped `POST /api/v1/secrets/batch-move` and `POST /api/v1/secrets/batch-delete` returning a per-item `[{ secretId, status, reason? }]` array; keep each item's existing per-object authorization guard (no new IDOR surface).
  > Note: not warranted — move/delete run through the existing per-object endpoints via the chunked runner (25/chunk keeps request volume modest and every item behind its existing guard). The only batch endpoint added is the share registration above, which the E2E model requires.

## 8. Tests

- [x] 8.1 vitest: select-all-current-view / shift-range selection; selection clears on lock and is never persisted; runner reports every item exactly once and "retry failed" re-runs only failures.
- [x] 8.2 vitest: bulk share fans out per (secret × recipient) with no plaintext leaving the browser; a suite-less recipient is skipped; a resumed run does not double-share.
- [x] 8.3 PHPUnit: bulk/hard delete cascades to shares/requests/group-shares/delegations; an already-deleted item is a not-found handled as skipped; any batch endpoint is idempotent and keeps its authorization guard.
- [x] 8.4 e2e (Playwright): select multiple secrets, bulk-move them, then bulk-delete with the count-named confirmation, verifying the per-item report.
  > Note: executed as a live UI verification on the deployed dev instance (Playwright MCP browser session) rather than a committed script, matching sibling changes.

## Acceptance criteria

- Vault list supports per-row, select-all (current view), and shift-range selection; selection is client-only and cleared on lock.
- A bulk action bar offers move, delete, share, and add-to-team-folder while a selection is active.
- Every action runs chunked with progress, produces a per-item report, and drops nothing silently; failures can be retried.
- Bulk move is metadata-only; bulk delete is an explicit, count-confirmed irreversible hard delete with cascade.
- Bulk share and add-to-team-folder reuse the per-recipient RSA fan-out and idempotent upsert; no plaintext leaves the browser and resume/retry never double-shares.
- Not-permitted items in a mixed selection are skipped with a reason, never failing the whole run, and no new unguarded batch surface is introduced.
