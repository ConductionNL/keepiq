# Design: save a shared secret and every copy of it in one request

## Context

At development `94ad83fa`:

- `PUT /api/v1/secrets/{id}` (`SecretUpdateController`) saves the caller's own row and checks an optional `baseUpdatedAt`.
- `PUT /api/v1/secrets/{sourceSecretId}/sync` (`ShareSyncService::syncUpdate`) writes recipient copies in its own transaction; the caller is the owner, a delegate or a write-grade member (`resolveSyncWriter`).
- `PUT /api/v1/federated-shares/{id}` (`FederatedShareService::update`) writes one partner share's ciphertext without a version check and notifies the partner through `FederatedNotificationDelivery`.
- `POST /api/v1/shares/recipient-certificates` returns certificates for at most 100 users per call; `POST /api/v1/federation/recipient-certificate` returns a partner certificate that the browser verifies against the pinned root.

## Goals / Non-Goals

**Goals**
- One request either saves the editor's row, every local copy and every active partner share, or saves nothing.
- The server can tell a copy left out on purpose from a forgotten one.

**Non-Goals**
- Letting a write-grade copy editor reach partner shares; partner shares stay owner-only.
- Moving the browser extension; it keeps the old routes for now.
- Serialising share creation with share-save (see Risks).

## Decisions

**D1. `{id}` is always the caller's own row.** On the owner path it is the source; on the copy-editor path it is the caller's recipient copy, and the caller must be the owner, a delegate or hold a write or manage grade on the source. A copy holder without one keeps using `PUT /api/v1/secrets/{id}` for their own copy only.

**D2. Compare-and-set inside the transaction.** The server advances `updated_at` with `UPDATE … WHERE id = :id AND updated_at = :base` and treats 0 affected rows as stale. On the copy-editor path the source is advanced first, so an owner save and a copy save of one secret queue up instead of deadlocking. The completeness check runs again under that lock. The new version is at least the base plus one second, because `updated_at` is stored in whole seconds.

**D3. Local copies take a sparse patch, partner shares the whole value.** A copy carries exactly the value fields the patch carries, with the same null-ness (`copy_fields_mismatch` otherwise), so an untouched field is never cleared. A partner share takes the whole value, as the partner pulls a complete copy.

**D4. Skips and suspends are explicit.** `skipped` names a copy whose holder has no vault key; the server refuses the claim when that holder does have an active suite. `suspend` names a partner share the browser can't encrypt for: its certificate failed verification, the partner no longer knows the recipient or is no longer a partner, or it can't be reached and the user chose to save without it. The server suspends it in the same transaction.

**D5. Staging mirrors the certificate lookup's batching.** At most 100 entries in copies plus skipped, and at most 100 in partner shares plus suspends, per call. A larger save sends its entries in chunks into `keepiq_share_save_stages` (ciphertext only), and the final call carries the patch and commits the stage with its own entries in the one transaction. A stage is kept after `incomplete`, so the browser sends only what is missing, and dropped after a stale answer; it expires after 30 minutes and is removed when its user is deleted. The table isn't part of backups.

**D6. Notify after commit, queued in the row.** Writing a partner share stores `SHARE_UPDATED` as its pending notification inside the transaction; after the commit the server delivers it, and `RetryFederatedNotificationsJob` picks up whatever a crash left behind. The stored notification is leased for 60 seconds, so the retry job doesn't send it again while the first send is still running. A share revoked meanwhile is never overwritten.

**D7. A restore is a share-save.** The browser decrypts the version and sends it as a full-value patch with `restoredFrom`, so the restored value reaches every copy in the same transaction, and the server records the restore audit event there. A copy holder without a write grade can only change their own copy, so they restore through `POST /api/v1/versions/{id}/restore`, which also records the restore and now takes an optional `baseUpdatedAt`.

## Security and zero-knowledge

The server receives ciphertext only, encrypted in the browser for each holder's certificate; partner certificates are still verified in the browser against the pinned root. The `incomplete` answer lists only what the caller may already see: copy ids and user ids that `GET /api/v1/secrets/{id}/shares` gives the same caller, and partner cloud ids only to the owner. A copy editor learns only whether partner shares exist that their save couldn't reach, not how many.

## Risks / Trade-offs

- A share created while a share-save runs encrypts the value its creator decrypted, which can be the older one. The next save's completeness check names that copy; closing the gap needs share creation to send the source version it encrypted from (follow-up).
- An unreachable partner blocks the save until the user chooses to save without them, which suspends that partner share. Nothing resumes a suspended share: it stays suspended until the owner revokes it or shares again, and the partner keeps its last value without being told. Resuming it is a follow-up.
- The partner certificate lookup allows 60 calls per minute per user and a save needs one per active partner share. The browser waits out the limit for at most five minutes, so one save handles about 360 partner shares. Only the edit dialog shows the wait; an offline replay, a version restore and the write-back of request-filled fields wait without showing it. A batch lookup for the owner's own partner shares would remove the wait (follow-up).
- The browser extension saves through `PUT /api/v1/secrets/{id}` only and doesn't update copies; `/sync` stays for older web-app tabs and API clients. Both take the version lock on the version they read, so a save that lands meanwhile is never overwritten without a snapshot: a request with a base, and a sync without one, is refused with 409, and a `PUT` without one is tried once more on the current version. `PUT /api/v1/federated-shares/{id}` still writes a partner share without a version check.
