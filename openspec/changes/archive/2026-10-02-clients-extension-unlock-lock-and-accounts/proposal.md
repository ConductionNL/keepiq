---
kind: code
---

# Extension unlock with a fingerprint or face, a chosen lock delay, and several accounts

## Why

The browser extension unlocks only with the master password, locks after a fixed 15 minutes, and knows one Nextcloud account at a time. The web app already unlocks with a platform passkey, users ask to pick their own lock delay, and people with a work and a personal server have to unpair to switch.

| Row | Capability | What Keepiq does today |
|---|---|---|
| clients-06 | The extension locks itself automatically. | The extension locks after 15 idle minutes, on OS or browser lock, on worker termination and on demand. The idle period cannot be changed because nothing ever writes config.idleMinutes, and the extension is not distributed. |
| clients-21 | Switch between several accounts or servers in the same app or extension. | The extension pairs with one Nextcloud account at a time; switching means unpairing. |
| crypto-09 | Unlock with a fingerprint or face scan on your device. | Fingerprint or face unlock works in the web app only by enrolling a platform passkey (Touch ID, Windows Hello) whose browser supports PRF. The browser extension and CLI have no biometric unlock. |

Matrix: keepiq `openspec/parity/capabilities.json`

- clients-06 is partial. Built: the fixed 15 minute idle lock (`browser-extension/src/background/service-worker.js:26` and `:37`, `browser-extension/src/lib/vault.js:114`), the lock on OS lock (`service-worker.js:254`) and the Lock button (`browser-extension/src/popup/popup.js:197`). Missing, from the decision: a user-chosen idle period. The worker reads `config.idleMinutes` (`service-worker.js:33`) but nothing writes it. Distribution is covered by the change `clients-extension-store-release`.
- clients-21 is not built: `browser-extension/src/lib/api.js:13` to `:31` stores one config under one key.
- crypto-09 is partial. Built: the web app's PRF passkey unlock (`src/components/PasskeyManager.vue:25`, `src/store/modules/passkey.js:193`). Missing, from the decision: fingerprint or face unlock in the browser extension. The popup unlocks with the master password only (`popup.js:182`).

### Demand

- clients-21, featureRequest: https://community.bitwarden.com/t/account-switching/716

No demand row for clients-06 or crypto-09.

### Competitors rated yes

clients-06:

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/key-management/vault-timeout/vault-timeout.service.ts; libs/common/src/key-management/vault-timeout/services/vault-timeout.service.ts:59 checkVaultTimeout ..."
- 1Password: "https://support.1password.com/unlock-auto-lock/ : idle auto-lock, and 'when you quit your browser, 1Password will always lock'"
- Passbolt: "passbolt/passbolt_browser_extension@v5.16.0 src/all/background_page/service/auth/startLoopAuthSessionCheckService.js:19 checks the server session every 60 s and logs the extension out when it expired ..."
- Keeper: "https://docs.keeper.io/user-guides/browser-extensions#stay-logged-in : 'Inactivity Logout Timer which automatically logs you out of Keeper after a period of inactivity'; admin Logout Timer policy"

clients-21:

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/src/auth/popup/account-switching/account-switcher.component.ts:40 switcher page; apps/browser/src/auth/popup/account-switching/services/account-switcher.service.ts:72 ACCOUNT_LIMIT, :93 add account entry ..."
- 1Password: "https://support.1password.com/multiple-accounts/ : add multiple accounts to the apps and browser extension, see all items at once or 'Switch to a specific account'."
- Keeper: "https://docs.keeper.io/user-guides/tips-and-tricks/personal-and-business-vaults : switch between business and personal accounts in the web vault, browser extension and mobile apps ('Switch Account', 'Add Account')."

crypto-09:

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/desktop/src/key-management/biometrics/main-biometrics.service.ts, native-v2/os-biometrics-linux.service.ts; apps/browser/src/key-management/biometrics/background-browser-biometrics.service.ts (extension unlock via desktop) ..."
- 1Password: "https://support.1password.com/windows-hello/ and https://support.1password.com/face-id/ : unlock with face, fingerprint, Touch ID"
- Keeper: "https://docs.keeper.io/enterprise-guide/roles/enforcement-policies#device-biometrics : 'Keeper natively supports Windows Hello, Touch ID, Face ID and Android biometrics'"

## What Changes

- The popup gets a settings view where the user picks the idle lock delay per account: 1, 5, 15 (default), 30, 60 or 240 minutes. An administrator can set a maximum, which the extension enforces.
- The extension holds up to five paired accounts, each with its own server, lock state, idle timer and settings. The popup header switches between them; matching and filling use the active account only.
- The extension can enrol a platform passkey with the WebAuthn PRF extension (Touch ID, Windows Hello, a phone or laptop fingerprint or face sensor) and then unlock with it instead of the master password. The wrapped unlock key is stored server-side next to the web app's passkey envelopes, so the web app lists and revokes it.

## Capabilities

### New Capabilities

- `extension-biometric-unlock`: PRF passkey enrolment and unlock inside the browser extension.
- `extension-account-switching`: several paired accounts in one extension.

### Modified Capabilities

- `browser-extension-autofill`: adds a requirement for the user-chosen idle lock period with an administrator maximum.

## Impact

- **Backend**: `passkey_credentials` gains `client_kind` and `rp_id`; `PasskeyService::enroll()` and `loginOptions()` filter by them; the org policy response carries `extensionMaxIdleMinutes`.
- **Frontend**: extension popup (settings view, account switcher, biometric unlock button), a new extension window for the WebAuthn ceremony, `vault.js` and `api.js` refactored to per-account state. In the web app, `PasskeyManager.vue` labels extension credentials.
- **Database**: two new columns on `keepiq_passkey_credentials`; a migration and a `<version>` bump.
- **Security**: the server stores only a PRF-wrapped unlock key, as for the web app; the master password, the raw unlock key and the PRF output never reach it. Accounts are isolated from each other in the worker.
- **Cross-app**: none.
