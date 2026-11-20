# Tasks: add attachments from the create and edit secret dialogs

## 1. Store

- [x] 1.1 Split `upload()` in `src/store/modules/attachment.js` into `uploadFile(secretId, file)` (seal and POST only) and `upload()` built on it. Verify: vitest that `uploadFile` leaves `attachments` untouched and `upload` still refetches.

## 2. Drop zone

- [x] 2.1 Add `src/components/AttachmentDropZone.vue`: drop target, Add file button, waiting list with Remove, failed files with reason and Retry, `uploadPending(secretId)`. Verify: vitest for drop, pick several, remove, a refused upload staying listed with its reason, and retry.

## 3. Dialogs

- [x] 3.1 Host the drop zone in `SecretCreateDialog.vue`, hidden when contributing to a team folder; upload after create; stay open on a failed upload and never create twice. Verify: vitest for success, a refused file, and retry against the created id.
- [x] 3.2 Host the drop zone in `SecretEditDialog.vue`, shown only for the owner and not offline read-only; upload after save; refresh the sidebar list. Verify: vitest for success, Cancel uploading nothing, and no drop zone on a shared copy.

## 4. Docs

- [x] 4.1 Mention adding files from the dialogs in `docs/item-actions.md`, next to the attachment preview.

## Acceptance criteria

- Files can be dropped or picked in the create and edit dialogs and upload after the save.
- A refused file leaves the secret saved, shows the reason, and can be retried or removed.
- Cancel uploads nothing; no drop zone where the sidebar would not allow uploads.
