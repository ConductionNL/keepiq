# Design: clone an item, preview an attachment, print a login or show it as a QR code

## Context

At development `4c214a9d`:

- `src/components/SecretDetailSidebar.vue:56-113` has the action row (Edit `:62`, Share `:74`, a More menu with Move `:87` and Delete `:96`, Close `:107`). Dialogs open through `cnOpenModal()` (`:1499`, `:1516`, `:1534`, `:1550`) with keys from `src/registry.js` (`'secret-create'` at `:76`).
- `src/dialogs/SecretCreateDialog.vue:212-224` props are `folderId` and `onSaved`; `data()` (`:226-240`) starts every field empty. It encrypts with the user's active suite before `POST /api/v1/secrets` (`src/store/modules/secret.js:348,385`).
- `src/components/AttachmentPanel.vue:22-60` lists attachments with Download and Delete; `src/store/modules/attachment.js:251-281` `download()` fetches `GET /api/v1/attachments/{id}/blob`, unwraps the file key, decrypts with AES and triggers a download from an object URL. The attachment's decrypted `contentType` and `filename` are known.
- `src/crypto/reauth.js:117` `verifyMasterPassword()` is the client-side proof of knowledge used before a plaintext export (`src/dialogs/ExportDialog.vue:130`).
- The only print today is `src/dialogs/ComplianceSnapshotDialog.vue:201`.
- The admin limit `attachment_max_bytes` (`lib/Service/AdminSettingsService.php:166`) caps attachment size.

## Goals / Non-Goals

**Goals**
- Clone in two clicks with nothing sensitive leaking to the new item that the user did not see.
- Look at an attachment without writing it to disk.
- Hand a password to a device without Keepiq, deliberately.

**Non-Goals**
- Copying attachments or passkeys into a clone. A passkey credential is bound to one item and must not be duplicated; attachments would need a re-encryption of every file.
- Previews of office documents or archives.
- A server-side PDF.

## Decisions

**D1. Clone is a prefilled create, not a server copy.** `SecretCreateDialog.vue` gets a `prefill` prop; the sidebar passes the decrypted fields it already holds. Saving is an ordinary create, so the new item gets a fresh id, its own ciphertext, no shares and no history. Alternative: a server-side `POST /secrets/{id}/clone`. Rejected: the server cannot re-encrypt, and copying ciphertext would tie two items to one blob.

**D2. Preview only safe types, only from memory.** Images (`image/png`, `image/jpeg`, `image/gif`, `image/webp`), `application/pdf` and `text/plain`. The decrypted bytes become a `Blob` and an object URL, shown in an `<img>`, a sandboxed `<iframe sandbox>` for PDF, or a `<pre>` for text. SVG is excluded because it can carry script. The URL is revoked when the modal closes. `download()` and `preview()` share one decrypt helper so the code path is tested once.

**D3. Print and QR sit behind a master password check.** Both put the password where anyone can see it. They call `verifyMasterPassword()` first, like the plaintext export, and are hidden for use-only shared copies once that restriction exists. The print sheet is a dedicated component rendered into a print-only container with `@media print` styles, then `window.print()`. The QR code is generated in the browser by a small QR encoder added as an npm dependency; nothing is fetched.

**D4. The one-time code seed is never printed or turned into a QR code by this change.** A printed seed is a second factor on paper next to the password.

## Security and zero-knowledge

Every action works on plaintext the browser already decrypted for the detail view; nothing new reaches the server. Clone writes only fresh ciphertext through the existing create path. Preview never uses a server URL for the plaintext and revokes its object URL. Print and QR are gated by the same client-side proof of knowledge as the plaintext export, which `src/crypto/reauth.js` states is advisory against a tampered client, as every client-side control under end-to-end encryption is.

## Risks / Trade-offs

- A printed sheet is outside Keepiq's control once printed. The confirmation text says so.
- A large PDF preview holds the plaintext in memory; the existing `attachment_max_bytes` cap bounds it.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture login for `example.com` gets one small PNG attachment so the preview flow can be exercised.

## Migration

None.
