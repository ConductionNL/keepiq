---
kind: code
---

# Ask for the master password again before a sensitive item is shown or filled

## Why

Once the vault is unlocked, every secret in it can be shown, copied and filled until the session times out or is locked. For most logins that is right. For a few (the domain admin account, the bank's payment login, the break-glass root key) a user wants a second look at who is at the keyboard, the way the plaintext export already asks: it verifies the master password again before writing a plain file (`src/dialogs/ExportDialog.vue:130`, `src/crypto/reauth.js:117` `verifyMasterPassword`). Nothing else uses that check. The reveal field (`src/components/SecretDetailSidebar.vue:205-208` `resolveKey`), the copy buttons (`:187`, `:311`, `:367`, `:409`, `:529`, `src/components/SecretListItem.vue:93`) and the extension's fill (`browser-extension/src/background/service-worker.js:113-125`) act at once.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `crypto-20` | Ask for the master password again before a sensitive item is shown or filled. | `no`: master password re-entry guards the plaintext export only |

### Demand

- Feature request, https://community.bitwarden.com/t/require-master-password-re-prompt-for-some-items/41

### Competitors rated yes

- Bitwarden: "src/Core/Vault/Entities/Cipher.cs:27 Reprompt; ... additional-options-section.component.ts:50 reprompt toggle; libs/vault/src/services/password-reprompt.service.ts:42 passwordRepromptCheck(); apps/browser/src/autofill/services/autofill.service.ts:53 openVaultItemPasswordRepromptPopout before fill Note: Per-item master password re-prompt, set in the item form and enforced before view, copy and autofill."
- Passbolt: "getPassphraseService.js:35 passphrase requested for every decrypt unless the user ticked remember; ... InputPassphrase.js:210 remember-me durations Note: By default the passphrase is asked before any secret is shown, copied or filled; users can opt to remember it for a set time. It is global, not a per-item flag."

## What Changes

- **A per-item switch.** The create and edit dialogs get "Ask for my master password before showing or filling this item". It is stored as a plain flag on the holder's row. A share copy starts with the owner's setting.
- **Enforced in the web app.** For a flagged item, revealing the value, copying the value or username, opening the edit dialog, cloning, printing and showing a QR code first ask for the master password and check it with `verifyMasterPassword()`. The list shows a lock marker on flagged items.
- **Enforced in the extension.** Filling a flagged item from the popup first asks for the master password inside the popup and checks it against the vault key.
- **No grace period.** Each action asks again. The check is client-side and says so, like the export check.

## Capabilities

### New Capabilities

- `item-master-password-reprompt`: a per-item flag that makes the web app and the browser extension verify the master password before the item's value is shown, copied or filled.

### Modified Capabilities

- None in delta form.

## Impact

- **Backend**: a boolean column `reprompt` on `keepiq_secrets`, accepted on create and update and copied onto new share copies.
- **Frontend**: a checkbox in `SecretCreateDialog.vue` and `SecretEditDialog.vue`, a shared `useReprompt()` guard wrapping `resolveKey`, `CopyButton` and the edit, clone, print and QR actions, a lock marker in `SecretListItem.vue`, a `RepromptDialog.vue` under `src/dialogs/`.
- **Browser extension**: a master password prompt in the popup before `doFill()` for flagged items.
- **Database**: one migration; `<version>` bump.
- **Security**: the master password never leaves the browser or extension; the flag is not sensitive. The control stops a person at an unlocked, unattended screen; it does not protect against a tampered client, which the dialog's help text says.
- **Cross-app**: none.
