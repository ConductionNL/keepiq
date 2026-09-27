# Tasks: clone an item, preview an attachment, print a login or show it as a QR code

## 1. Clone

- [ ] 1.1 Add a `prefill` prop to `SecretCreateDialog.vue` that seeds type, name with " (copy)", address, username, value, additional fields and folder, with a note that attachments, shares and history are not copied. Verify: vitest that the dialog starts with the prefilled values and saves through the normal create action.
- [ ] 1.2 Add Clone to the More menu in `SecretDetailSidebar.vue`, hidden for passkey items. Verify: Playwright flow clone the fixture login, save, a second item exists with a new id and no shares.

## 2. Attachment preview

- [ ] 2.1 Extract the fetch, unwrap and decrypt steps of `attachment.js` `download()` into one helper and add `preview()` that returns an object URL for the allowed types. Verify: vitest for each allowed type and a refusal for `image/svg+xml`.
- [ ] 2.2 Add `src/modals/AttachmentPreviewModal.vue` (image, sandboxed PDF frame, text) and a Preview button in `AttachmentPanel.vue`; revoke the URL on close. Verify: Playwright flow preview the fixture PNG, close, no download happened; hydra modal-isolation gate.

## 3. Print and QR code

- [ ] 3.1 Add `SecretPrintSheet.vue` with print-only styles and a Print action behind `verifyMasterPassword()`. Verify: vitest that print is refused with a wrong master password, and a Playwright check that the sheet holds name, address, username and password and no seed.
- [ ] 3.2 Add the QR encoder dependency and `src/modals/SecretQrModal.vue` behind the same check. Verify: vitest that the encoded QR decodes back to the password, and the npm licence and audit checks.

## 4. Docs

- [ ] 4.1 Document clone, preview, print and QR in `docs/`, including what a clone does not copy. Verify: docs build.

## Acceptance criteria

- Clone opens a prefilled create dialog and saves a new, unshared item.
- Images, PDFs and text attachments preview in the app without a file being downloaded.
- Print and Show as QR code work only after the master password is entered again.
- No action sends plaintext to the server.
