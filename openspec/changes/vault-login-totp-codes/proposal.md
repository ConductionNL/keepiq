---
kind: code
---

# One-time codes and passkeys on a login

## Why

Keepiq shows a live one-time code only on a separate Authenticator item (a secret of type `totp` whose encrypted `key` is the seed) and keeps a passkey only as a separate Passkey item. A user who wants both on their bank login keeps three items side by side and has to keep them in step by hand, and every client has to guess which belong together. Bitwarden, 1Password, Keeper and Passbolt all keep the code and the passkeys on the login.

keepiq#1324 decided that a one-time code and passkeys belong to the login, as two new encrypted fields on the secret, `totp` and `passkeys`, next to `key`, `login` and `additionalFields`. keepiq#1325 added those fields to the backend. This change is keepiq#1326, the web app half: entering, showing, sharing and versioning them.

An earlier version of this change kept the seed under a reserved `totp` key inside the encrypted additional fields. That design is superseded: a field of its own lets share-save, federation, version history and the vault list handle the seed without guessing at a key inside a blob, while the server still sees only ciphertext.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq before this change |
|---|---|---|
| `vault-16` | Keep a one-time password seed as an authenticator item and see the current code. | `partial`: a live code works only on a separate Authenticator item |

### Demand

No demand row. Four competitors rate it yes and it is in the core area (vault).

### Competitors rated yes

- Bitwarden: "libs/common/src/vault/services/totp.service.ts:13 totpCode/totpCodeFormatted; libs/vault/src/cipher-view/login-credentials/login-credentials-view.component.ts:41 totpCodeFormatted shown ... TOTP seed on a login shows the live code with countdown."
- 1Password: "Select Add More, then select One-Time Password" on a Login item (https://support.1password.com/one-time-passwords/).
- Passbolt: "plugins/PassboltCe/ResourceTypes/src/Model/Entity/ResourceType.php:54 v5-totp-standalone, :55 v5-default-with-totp ... DisplayResourceDetailsTotp.js:29 TotpCodeGeneratorService, :120 copy code."
- Keeper: "click Add Two-Factor Code to store ... TOTP ... in your Keeper records" (https://docs.keeper.io/user-guides/web-vault#two-factor-codes-for-totp).

## What Changes

- **An Authenticator key on a login.** The create and edit dialogs of a login get an optional Authenticator key field: an `otpauth://totp/` URI or a base32 secret, or a QR image decoded in the browser. It is stored in the login's encrypted `totp` field. Once saved the seed is never shown again; the edit dialog offers Replace and Remove.
- **A live code on the login.** The detail sidebar shows the current code with its countdown and a copy button through the existing `TotpDisplay`, for any item whose `totp` field holds a value.
- **Passkeys on the login.** The detail sidebar lists the passkeys in the `passkeys` field (relying party, user name, created date) and, where the user may edit the login, offers Delete behind a confirmation. Passkeys are created by a provider or an import, never typed in; no private key is ever shown.
- **Use-only copies** show and copy the code and list the passkeys, never the seed or a private key.
- **Every path carries both fields**: share-save, direct, group and team-folder shares, ownership transfer, version history and restore, the offline cache and queue, and encryption suite migration. Federated partners receive them only when they advertise that they store them; link shares never carry them.
- **No new Authenticator or Passkey items.** The create dialog and the default item type setting no longer offer those types; existing items keep working.
- **A vault list marker** on rows with a code or passkeys.

## Capabilities

### New Capabilities

- `login-one-time-codes`: a TOTP seed and passkeys stored in a login's own encrypted fields, shown, shared and versioned by the web app.

### Modified Capabilities

- `use-only-shares`: a use-only copy may show a current code and list passkey metadata, and must not reveal the seed or any passkey private key.
- `passkey-item-type`: new passkeys live on the login; the standalone type is no longer creatable from the web app, and existing items keep working.

## Impact

- **Backend**: none in this change. The `totp` and `passkeys` columns, routes, federation advertisement and the 409 `partner_upgrade_required` refusal come from keepiq#1325.
- **Frontend**: new `AuthenticatorKeyField.vue`, `PasskeyList.vue`, `LoginExtrasMarkers.vue`, `PasskeyDeleteDialog.vue`, `src/totp/authenticatorKey.js`, `src/totp/qrImage.js`, `src/passkey/loginPasskeys.js`, `src/utils/valueFields.js`, `src/services/recipientCopy.js`; changes to the create, edit and version details dialogs, the detail sidebar, the vault list, the secret, version, share, team-folder, federation, offline and preferences stores, share-save and the suite migration pipeline.
- **Dependency**: `jsqr` 1.4.0, loaded in its own chunk on first use.
- **Security**: the seed and passkeys are encrypted and decrypted only in the browser. The seed is write-only in the UI after saving and no login passkey private key is ever rendered. Link shares leave both fields out because a link recipient decrypts the whole shared value.
- **Browser extension and Android**: not in this change. The upcoming extension changes `ext-totp-autofill` and `ext-passkey-provider` and keepiq#1329 adopt the fields.
- **Cross-app**: none. OpenConnector reads `key` only.
