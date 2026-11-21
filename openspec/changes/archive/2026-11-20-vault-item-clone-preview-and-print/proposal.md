---
kind: code
---

# Clone an item, preview an attachment, print a login or show it as a QR code

## Why

Three everyday actions are missing from the secret detail sidebar, and all three are client-side work on a secret that is already decrypted in the browser:

- There is no clone or duplicate action (`src/components/SecretDetailSidebar.vue:60-113` holds Edit, Share, Move, Delete and Close). Creating the fifth login for a family of internal systems means retyping the address pattern, the username and the additional fields each time. `SecretCreateDialog.vue` takes only a `folderId` and an `onSaved` callback (`:212-224`), so nothing can prefill it.
- Attachments decrypt only to a download (`src/components/AttachmentPanel.vue:42-44`, `src/store/modules/attachment.js:251-281`): to look at a scanned certificate or a recovery-codes image, the user writes the plaintext file to disk. The encrypted-attachments spec rules out only a server-side preview (`openspec/specs/encrypted-attachments/spec.md:147`, "impossible under ADR-003"); a preview decrypted in the browser keeps ADR-003.
- A login cannot be printed or shown as a QR code to type on a device that has no Keepiq, such as a television or a guest laptop. `docs/FEATURES.md:84` lists "Export to PDF (single secret), print-friendly credential sheet" as an Enterprise feature.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-22` | Clone an existing item as the starting point for a new one. | `no`: no clone or duplicate action on a secret |
| `vault-26` | Preview an attached file without downloading it. | `no`: attachments decrypt to a download; no in-app preview |
| `vault-30` | Print a login, or show its password as a QR code to type it on another device. | `no`: only the compliance report can be printed |

### Demand

- `vault-26`: feature request, https://community.bitwarden.com/t/view-attachments-without-downloading/52
- `vault-30`: feature request, https://community.bitwarden.com/t/share-wifi-passwords-via-qr-codes-and-nfc/6472
- `vault-22`: no demand row. Four competitors rate it yes. All three rows are in the core area (vault).

### Competitors rated yes

- `vault-22`, Bitwarden: "apps/web/src/app/vault/individual-vault/vault.component.ts:1375 cloneCipher; ... item-more-options.component.ts:291 clone() Note: Clone action opens a prefilled form for a new item."
- `vault-22`, 1Password: "right-click the item, select Duplicate" (https://support.1password.com/move-copy-items/).
- `vault-22`, Keeper: "clone creates a new record from an existing one; web vault 'Create Duplicate' in the right-click menu" (https://docs.keeper.io/keeperpam/secrets-manager/secrets-manager-command-line-interface/secret-command).
- `vault-22`, Nextcloud Passwords: "src/js/Manager/PasswordManager.js:150 clonePassword() opens the dialog prefilled ... Clone opens the create dialog prefilled from the source item."
- `vault-26`: no competitor rated yes.
- `vault-30`, Nextcloud Passwords: "src/js/Actions/Password/PrintPasswordAction.js:27 print(); src/js/Actions/Password/PasswordActions.js:82 qrcode(); src/vue/Components/ContentList/Item/PasswordItem/PasswordItemActionMenu.vue:105,111."

## What Changes

- **Clone.** A Clone action in the detail sidebar's menu opens the create dialog prefilled with the source's type, name (with " (copy)" added), address, username, value and additional fields, in the same folder. Saving creates a new, unshared secret encrypted fresh with the user's own certificate. Attachments, shares, versions and passkey material are not copied, and the dialog says so.
- **Attachment preview.** Images, PDFs and plain-text attachments get a Preview action next to Download. The file is decrypted in the browser and shown in a modal from an in-memory object URL that is revoked when the modal closes. Other types keep Download only.
- **Print and QR.** A Print action renders a print sheet of the login (name, address, username, password, one-time code seed excluded) and opens the browser's print dialog. A Show as QR code action shows the password as a QR code in a modal. Both first ask for the master password, the same client-side check the plaintext export uses.

## Capabilities

### New Capabilities

- `secret-item-actions`: clone, attachment preview, print and QR code actions on a single secret, all performed in the browser.

### Modified Capabilities

- None in delta form. `encrypted-attachments` keeps its no-server-preview rule, which this change honours.

## Impact

- **Backend**: none. Clone uses `POST /api/v1/secrets`; preview uses `GET /api/v1/attachments/{id}/blob`.
- **Frontend**: `SecretDetailSidebar.vue` (Clone, Print, Show as QR code), `SecretCreateDialog.vue` (a `prefill` prop), `AttachmentPanel.vue` and the attachment store (a `preview()` action), a new `AttachmentPreviewModal.vue` under `src/modals/`, a new `SecretPrintSheet.vue`, a new `SecretQrModal.vue`, and a QR encoder as a new npm dependency.
- **Database**: none.
- **Security**: all plaintext stays in browser memory; print and QR need a fresh master password check; the preview renders in a sandboxed frame for PDFs and never from a server URL.
- **Cross-app**: none.
