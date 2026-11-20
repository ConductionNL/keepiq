# Design: add attachments from the create and edit secret dialogs

## Context

At development `5d2ef52cb`:

- `src/components/AttachmentPanel.vue` lists a secret's attachments and offers one file picker (`:77-95`), shown when `canManage` is true. The sidebar passes `isOwner && !offlineReadOnly` (`src/components/SecretDetailSidebar.vue:977`).
- `src/store/modules/attachment.js` `upload(secretId, file)` (`:361`) seals the file in the browser (`sealedUpload`, `:164`), POSTs it, then calls `fetchAttachments(secretId)`, which replaces the store's shared `attachments` list that the sidebar renders.
- `lib/Service/AttachmentService.php` `upload()` (`:210`) accepts uploads only from the secret's owner and enforces `attachment_max_bytes` and `attachment_user_quota_bytes` (`:261`). The browser does not know either limit.
- `SecretCreateDialog.vue` `submit()` (`:687`) creates the secret, or contributes it to a team folder the user does not own (`contributeSecret`). `SecretEditDialog.vue` `submit()` (`:644`) knows `viewOnly` and `isCopy` for shared copies.

## Approaches

1. **A shared `AttachmentDropZone` component (chosen).** One component holds the drop area, the Add file button and the waiting files, and exposes `uploadPending(secretId)`. Both dialogs host it. Queue, retry and errors live in one place.
2. **A waiting mode in `AttachmentPanel`.** Reuses markup, but that panel lists, previews, downloads and deletes stored attachments; a queue would make it two components in one file.
3. **Inline in each dialog.** Least setup, but two copies of the queue and error handling that would drift apart.

Files upload after the save, not on drop: a new secret has no id until it is created, and uploading on drop in the edit dialog would make Cancel unable to undo it.

## Decisions

**D1. `AttachmentDropZone.vue`.** Props: `disabled`. State: the waiting files, each `{ file, status: 'waiting'|'uploading'|'failed', error }`. A drop target (dragover/drop, highlighted while a file is over it) with an Add file button and a hidden multi-file input, as in `AttachmentPanel`. Each listed file shows name, size, its error when failed, and Remove; failed files also show Retry. It emits `update:count` (or exposes `hasPending`) so the dialog knows whether files are waiting. Method `uploadPending(secretId)` uploads the waiting and failed files one at a time, drops each one that succeeds from the list, and resolves to whether all succeeded.

**D2. A store action that does not touch the listed attachments.** Split `upload()` into `uploadFile(secretId, file)`, which seals and POSTs only and throws with the server's message, and `upload()`, which keeps its current behaviour on top of it for the sidebar. The drop zone uses `uploadFile`, so creating a secret while the sidebar shows another one cannot swap the sidebar's list. The sidebar opens the edit dialog with `onSaved: () => this.reloadWithList()` (`SecretDetailSidebar.vue:2505`), which reloads the secret but not its attachments (`AttachmentPanel` refetches only when `secretId` changes). That callback also refetches the attachment list. The edit dialog calls `onSaved` once, when it closes after a save: every upload that will run has run by then, so the sidebar reloads once and shows the files that were added.

**D3. Save flow.** On Create or Save, the dialog saves the secret as today, then calls `uploadPending(id)`. If all files upload, it emits `saved` and closes as today. If any fails, it emits `saved` (the secret is saved) but stays open, with the failed files showing their reason. The primary button then reads Retry uploads or the user removes the failed files; the dialog closes when none are left. Pressing Create again must not create a second secret: once created, the create dialog only retries uploads against the created id.

**D4. Where it shows.** Create dialog: shown unless the secret is being contributed to a team folder the user does not own (`contributeTo`), since the server only accepts the owner's uploads. Edit dialog: shown when the user owns the secret (not `viewOnly`, not `isCopy`) and the offline store is not read-only, the same rule as the sidebar. When that rule stops holding while files are listed (a team folder picked, the app going offline), the zone stays with the files and the reason (`unavailable` prop), adding is off, Remove stays on, and the save waits until the files are removed, so none is dropped without the user seeing it.

## Out of scope

- Listing, previewing or deleting stored attachments in the dialogs; the sidebar keeps that.
- Checking the size limit or quota in the browser before saving.
- Pushing new attachments to existing share recipients; the sidebar upload does not do this either.

## Left to the builder

- Exact styling of the drop area, within the Nextcloud dialog look.
