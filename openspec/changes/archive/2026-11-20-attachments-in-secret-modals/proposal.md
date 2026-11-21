---
kind: code
---

# Add attachments from the create and edit secret dialogs

## Why

Attachments can only be added from the attachments box in the secret detail sidebar (`src/components/AttachmentPanel.vue:77-95`), one file at a time through a file picker. Someone creating a login that comes with a recovery-codes sheet or a certificate has to save the secret first, open it in the sidebar and then add the file. The create and edit dialogs (`src/dialogs/SecretCreateDialog.vue`, `src/dialogs/SecretEditDialog.vue`) have no way to add a file.

## What Changes

- The create and edit secret dialogs get a drop zone with an Add file button at the bottom of the form. Files can be dropped or picked, several at once, and are listed as waiting with a Remove button.
- Waiting files upload after Create or Save succeeds, through the same browser-side encryption and the same server-side size limit and quota as the sidebar. Nothing uploads before the secret is saved, so Cancel leaves no files behind.
- When a file is refused (too large, over quota, network), the secret stays saved, the dialog stays open and that file shows the server's reason with Retry and Remove. The dialog closes once every waiting file has uploaded or been removed.
- The drop zone shows only where the sidebar allows uploads: the user owns the secret and the vault is not offline read-only. It is not shown for a shared copy or for a secret contributed to someone else's team folder.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `encrypted-attachments`: attachments can also be added from the create and edit secret dialogs.

## Impact

- **Backend**: none. Uploads use `POST /api/v1/secrets/{id}/attachments` and its existing limit and quota checks.
- **Frontend**: a new `src/components/AttachmentDropZone.vue`; `SecretCreateDialog.vue` and `SecretEditDialog.vue` host it; the attachment store gets an upload action that does not reload the sidebar's list.
- **Database**: none.
- **Security**: unchanged. Files are encrypted in the browser before upload exactly as from the sidebar.
- **Cross-app**: none.
