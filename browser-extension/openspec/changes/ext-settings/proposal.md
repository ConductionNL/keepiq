---
kind: code
depends_on: [ext-vault-browse, ext-vault-edit, ext-generator, ext-theme, ext-i18n]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
---

## Why

The Settings tab still shows four of its six sections as "Available in a later update". The vault timeout, its action and the clipboard clear delay are stored per account but have no control, and the organisation's idle lock maximum, which Keepiq admins set for the extension, is ignored. ext-autofill also needs a place to read its knobs from.

It mirrors the Bitwarden browser extension's Settings tab (ADR-001), on top of what ext-theme built: the section list, the Appearance view with Theme, `settings.global` and the `settings.get`/`settings.set` pair.

## What Changes

- The sections Account security, Autofill, Notifications and About open their views; Vault and Appearance gain their remaining entries.
- The per-account settings record (`src/accounts/settings.ts`) grows from the timeout and action to every account-scoped entry, with a validator and default per key, and writes become patches.
- The organisation's idle lock maximum is read from `GET /api/v1/extension/policy` alongside the generator policy and clamps the timeout as ADR-002 describes. The Export vault link follows the organisation's `vault_export_disabled`.
- Account security: vault timeout (now with On system lock) and action, Lock now, Log out, Unlock with PIN, biometrics shown as unavailable, Change master password as a link to the Keepiq web app.
- Unlock with PIN: a PIN of at least six characters, set after re-entering the master password and offered first on the unlock screen. After a browser restart the master password is needed once, then the PIN works again.
- Autofill, Notifications, Vault and Appearance entries with their storage, defaults and effect. The behaviour behind the autofill and notification entries comes with ext-autofill, so those entries show "Coming later" until it lands.
- A "Keepiq on the web" group says that Keepiq's notifications, import and export and the master password are managed in Keepiq on Nextcloud, with a link.
- Default item type becomes an extension setting per account, replacing the `GET /api/settings/user` read that ext-vault-edit added to the sync.
- About: extension version, server address, the server's Keepiq version, and links to help, bug reports, support and the privacy policy.

## Capabilities

### New Capabilities
- `settings`: the Settings tab's remaining views, the per-account settings record, the organisation idle maximum, PIN unlock and the About panel.

### Modified Capabilities

None. `openspec/specs/` is empty today. ext-vault-edit's New form keeps reading `defaultSecretType` from the snapshot; this change only changes where that value comes from.

## Deviations from Bitwarden

- **Timeout options are capped by the organisation's maximum,** 4 hours unless the Keepiq admin chose less, so Never and On browser restart are normally unavailable. Bitwarden caps only under an opt-in organisation policy.
- **Unlock with PIN** asks for the master password once after a browser restart, as Bitwarden does by default, but offers no choice to turn that off. Setting a PIN asks for the master password. Five wrong PINs forget the PIN instead of logging out, since Keepiq's logout costs a new app password.
- **Unlock with biometrics** is shown but disabled with "Not available yet". A later change can add it through a WebAuthn PRF passkey, which Keepiq already stores for extensions (`client_kind: extension` on `/api/v1/passkeys`).
- **Two-step login** and **Fingerprint phrase** are omitted. Keepiq has no second factor of its own (Nextcloud owns login) and no account fingerprint.
- **Change master password**, **Import items** and **Export vault** open the Keepiq web app (ADR-003). Export is shown disabled when the organisation turned it off.
- **Show identities as suggestions** and **Show cards as suggestions** are not rendered until ext-autofill supports those item types.
- **No "Show website icons" setting.** ADR-002 rules out fetching icons, so there is nothing to toggle.
- **Default item type** and the **Keepiq on the web** group are additions.
- **Vault timeout default** is 15 minutes idle, and Never carries a warning (ADR-002).

## Keepiq API used

- `GET /api/v1/extension/policy`: `maxIdleMinutes`, since Keepiq 0.3.8.
- `GET /api/settings/policy`: adds `vault_export_disabled` to what the generator already reads, since 0.3.8.

Both predate `MIN_KEEPIQ_VERSION` (0.3.12), so nothing is gated in `KEEPIQ_FEATURES`. `GET/PUT /api/settings/user` is no longer called.

Sync now reuses the existing sync and adds no request.

## Impact

- Core: `src/accounts/settings.ts`, `src/accounts/store.ts`, new `src/accounts/policy.ts` and `src/vault/pin.ts`, `src/vault/unlock.ts`, `src/vault/timeout.ts`, `src/vault/key-store.ts`, `src/vault/sync.ts`, `src/vault/types.ts`, `src/clipboard.ts`, `src/failure.ts`, `src/messages.ts`, `src/background/requests.ts`, `src/background/router.ts`.
- Popup: new views `AccountSecurity`, `Autofill`, `Notifications`, `About` and the `PinDialog` component and `useAccountSettings` hook; edits to `Unlock`, `Settings`, `SettingsIndex`, `Vault`, `Appearance`, `Select`, `ItemCard` and `popup.css`; keys in `locales/en.yml` and `locales/nl.yml`.
- Docs: `ARCHITECTURE.md` storage rows, `../docs/browser-extension/privacy.md` and `using.md`, ext-vault-edit's item-editing spec on the default type, ext-accounts-and-unlock's timeout policy text.
- No new manifest permissions. `commands` is declared by ext-autofill.
- Security: the PIN-wrapped private key lives in `storage.session` only. `storage.local` gains a seal of the PIN under a key derived from the private key, which opens nothing without the master password (ADR-002).
- Later, out of scope: an admin policy to remove unlock with PIN, which needs a Keepiq change and a `KEEPIQ_FEATURES` gate.
