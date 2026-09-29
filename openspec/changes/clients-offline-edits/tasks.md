# Tasks: offline edit queue

## 1. Server

- [ ] 1.1 Accept an optional `baseUpdatedAt` on `SecretController::update()` and `destroy()`; when it differs from the stored `updatedAt`, change nothing and answer 409 with the current row. Verify: PHPUnit `SecretControllerTest` covers a match, a mismatch, and an absent value behaving as today.
- [ ] 1.2 Add the `offline_edits_enabled` app config (default false) to `AdminSettingsService` and to the offline manifest response. Verify: PHPUnit asserts the default, a set value, and the manifest field.

## 2. Queue at rest

- [ ] 2.1 Add a queue store to the offline IndexedDB database (new schema version) with entries sealed to the owner's certificate through the hybrid envelope (D1). Verify: vitest asserts no stored entry contains a plain value, name or URL, and that a round trip opens with the private key.
- [ ] 2.2 Add coalescing (D4): update chains, create then update, create then delete. Verify: vitest for each chain.

## 3. Offline editing

- [ ] 3.1 When offline and `offline_edits_enabled` is true, enable create, edit, move and delete in `SecretList.vue` and the secret dialogs, write to the queue, and show queued changes on the cached view marked "Not synced yet". Keep share, link share, send, team-folder, folder and attachment actions disabled with an explanation. Verify: vitest for the enabled and disabled action sets.
- [ ] 3.2 Show "N changes waiting to sync" in the stale-data banner and a warning before logout while entries are pending. Verify: vitest renders both states.

## 4. Replay

- [ ] 4.1 Replay the queue oldest first when online and unlocked, through the existing store actions; for a secret with recipients fetch the write context at replay time and run the normal sync fan-out. Verify: vitest with a mocked API asserts the certificates used are the ones returned at replay, not any cached value.
- [ ] 4.2 Handle outcomes (D5): 409 opens the conflict dialog, 403 and 404 move the entry to the failed list, 423 and network errors keep it queued, and a failed recipient sync retries only the sync step. Verify: vitest for each outcome.
- [ ] 4.3 Add the conflict dialog in `src/dialogs/` (keep mine, keep the server's) and the failed-changes list with copy and discard. Verify: vitest for both choices and for copy.
- [ ] 4.4 Block a suite rotation while entries are pending, and after a rotation elsewhere ask once for the previous master password to reopen entries under the cached old envelope. Verify: vitest for the block and the reopen path.

## 5. Administrator and end-to-end

- [ ] 5.1 Add the offline edits switch to `OfflineCacheSection.vue`. Verify: vitest for the switch and its save call.
- [ ] 5.2 Add a Playwright flow: a vault owner unlocks online, goes offline, edits a secret shared with a second user, goes online, and the second user sees the new value. Verify: the Playwright spec passes in the E2E job.
- [ ] 5.3 Add a Playwright flow for a conflict: the same secret changes online from a second browser while the first is offline; the first sees the conflict dialog on reconnect. Verify: the Playwright spec passes in the E2E job.

## Acceptance criteria

- With offline edits enabled, a user can create, edit, move and delete secrets offline and sees the changes at once, marked as not synced.
- The queue at rest holds no plain value, name or URL.
- Replay uses the online code paths, and recipient ciphertext is made only at replay time with certificates fetched at replay time.
- A secret changed on the server since the snapshot is never overwritten without the user's choice.
- Sharing, link shares, sends, team-folder membership, folders and attachments stay unavailable offline.
- With offline edits disabled (the default), the offline view behaves exactly as the read-only cache does today.
