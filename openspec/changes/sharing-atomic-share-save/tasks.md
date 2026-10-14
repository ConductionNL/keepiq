# Tasks: save a shared secret and every copy of it in one request

## 1. Backend

- [x] 1.1 Add `PUT /api/v1/secrets/{id}/share-save` with its request parser, owner and copy-editor paths, completeness check and compare-and-set transaction. Verify: `ShareSaveServiceTest`, `ShareSaveRequestTest`, `ShareSaveControllerTest`, `ShareSaveVersionLockTest`.
- [x] 1.2 Add the staged save: `keepiq_share_save_stages` with migration, chunk limits, a 30-minute expiry job, removal on account deletion, and no backup. Verify: the staging cases in `ShareSaveServiceTest` and `ArchiveTest`.
- [x] 1.3 Write partner shares only while active, queue `SHARE_UPDATED` in the row and deliver it after the commit. Verify: `ShareSaveServiceTest` notify cases, the partner-share cases in `ShareSaveVersionLockTest` and the queued-update cases in `FederatedShareOwnerChangesTest`.
- [x] 1.4 Accept `restoredFrom` and record the restore in the same transaction. Verify: the restore cases in `ShareSaveServiceTest`.
- [x] 1.5 Keep `/sync`, `PUT /api/v1/federated-shares/{id}` and `PUT /api/v1/secrets/{id}` working. Verify: `ShareServiceTest`, `FederatedShareOwnerChangesTest`, `SecretServiceTest`.
- [x] 1.6 Hand a secret's partner shares to its new owner in the same transaction as the secret, repair the ones a transfer left behind, and serve none whose owner isn't the secret's owner. Verify: `SecretOwnerTransferServiceTest`, `PartnerShareOwnerTransferTest`, the transfer cases in `AccountDeletionServiceTest` and `TeamFolderServiceTest`, and `FederatedShareOwnerChangesTest`.

## 2. Web app

- [x] 2.1 Add `src/services/shareSave.js`: optimistic send, certificate lookup in batches of 100, sparse encryption, skips and suspends, staging and at most three `incomplete` rounds. Verify: `tests/store/shareSave.spec.js`.
- [x] 2.2 Save value edits, offline replay and version restore through it, and delete the old fan-out. Verify: `offlineQueue.spec.js`, `federatedShare.sync.spec.js`, `secret-pending-fields.spec.js`.
- [x] 2.3 Show a failed share-save in the edit dialog and failed replays with their reason. Verify: `SecretEditDialog.shareSave.spec.js`.

## 3. Follow-up

- [ ] 3.1 Let share creation send the source version it encrypted from, so a share created during a share-save can't keep an older value (#1255).
- [ ] 3.2 Add a batch partner certificate lookup for the owner's own partner shares, so a save no longer waits out the per-call rate limit (#1256).
- [ ] 3.3 Move the browser extension to share-save, then remove `/sync` and the federated share update route.
- [ ] 3.4 Let the owner resume a suspended partner share, so saving without an unreachable partner no longer ends the share for good (#1262).

## Acceptance criteria

- A value change of a shared secret saves the editor's row, every copy and every partner share together, or nothing.
- A save from an older version, or one that misses a copy, is refused with nothing changed.
- The four sync bugs of #1253 are gone in the web app.
