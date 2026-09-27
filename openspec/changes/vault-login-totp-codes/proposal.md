---
kind: code
---

# One-time codes from a seed kept on a login

## Why

Keepiq shows a live one-time code only for a separate Authenticator item: a secret of type `totp` whose encrypted value is the seed (`lib/Repair/SeedSecretTypes.php:67`, `src/components/SecretDetailSidebar.vue:214-228`, `:1243` `isTotp`). Most people keep the seed with the login it belongs to, and every other manager lets them. Keepiq's own Bitwarden import already does: it puts a login's seed into the login's additional fields as `totp` (`src/import/parsers/bitwarden.js:70-71`) and its header says so, "so a future TOTP feature needs no re-import" (`:9-10`). Today that seed is shown as raw text in the Additional fields box (`SecretDetailSidebar.vue:570-580`) and never becomes a code, and the browser extension looks only for a separate `totp` item on the same host (`browser-extension/src/background/service-worker.js:155-163`).

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-16` | Keep a one-time password seed as an authenticator item and see the current code. | `partial`: a live code works only on a separate Authenticator item; a seed kept on a login is shown as raw text |

The missing half is a code from a seed kept on a login. The separate Authenticator item is built.

### Demand

No demand row. Four competitors rate it yes and it is in the core area (vault).

### Competitors rated yes

- Bitwarden: "libs/common/src/vault/services/totp.service.ts:13 totpCode/totpCodeFormatted; libs/vault/src/cipher-view/login-credentials/login-credentials-view.component.ts:41 totpCodeFormatted shown ... TOTP seed on a login shows the live code with countdown."
- 1Password: "Select Add More, then select One-Time Password" on a Login item (https://support.1password.com/one-time-passwords/).
- Passbolt: "plugins/PassboltCe/ResourceTypes/src/Model/Entity/ResourceType.php:54 v5-totp-standalone, :55 v5-default-with-totp ... DisplayResourceDetailsTotp.js:29 TotpCodeGeneratorService, :120 copy code."
- Keeper: "click Add Two-Factor Code to store ... TOTP ... in your Keeper records" (https://docs.keeper.io/user-guides/web-vault#two-factor-codes-for-totp).

## What Changes

- **A one-time code field on logins.** The create and edit dialogs of a login get an optional Authenticator key field (an `otpauth://totp/` URI or a base32 secret, or a scanned QR image read in the browser). It is stored inside the login's encrypted additional fields under the reserved key `totp`, which is where the Bitwarden import already puts it.
- **A live code on the login.** When a login's decrypted additional fields hold a `totp` seed, the detail sidebar shows the live code with its countdown and a copy button, through the existing `TotpDisplay` component and `src/totp/totp.js`. The raw seed is no longer listed among the additional fields.
- **The extension uses it.** When the extension fills a login that carries a seed, it computes the code from that seed first and falls back to a separate `totp` item on the same host, as today.
- Existing Bitwarden imports start showing codes without a re-import.

## Capabilities

### New Capabilities

- `login-one-time-codes`: a TOTP seed stored on a login and turned into a live code in the web app and the browser extension.

### Modified Capabilities

- None in delta form. The Authenticator item (`secrets`, client-side TOTP code generation) and `extension-totp-autofill` keep their requirements; this change adds a second source for the seed.

## Impact

- **Backend**: none. The seed lives in the already encrypted `additional_fields` blob; no column, route or migration.
- **Frontend**: `SecretCreateDialog.vue`, `SecretEditDialog.vue` and `AdditionalFieldsEditor.vue` (reserved key handling), `SecretDetailSidebar.vue` (code row for logins), a small helper that reads the seed from decrypted additional fields.
- **Browser extension**: `service-worker.js` `doFill()` and `doTotpForHost()` read the seed from the filled login first.
- **Import**: the Bitwarden parser keeps writing `totp`; the CXF importer attaches a TOTP credential to its login when both sit in one CXF item, instead of creating a separate item with no address.
- **Security**: the seed never leaves the encrypted blob and is only decrypted in the browser or the extension worker, as for the Authenticator item.
- **Cross-app**: none.
