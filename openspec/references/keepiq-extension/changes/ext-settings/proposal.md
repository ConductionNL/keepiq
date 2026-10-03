---
kind: code
depends_on: [ext-vault-browse]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

The popup shell from ext-accounts-and-unlock has a Settings tab with nothing behind it, and every other change in the chain needs a place to read its knobs from: the vault timeout and action (ADR-002), the clipboard clear delay, the autofill defaults, the theme. Without one schema those values end up as ad hoc `storage.local` keys with different defaults in every module.

This change is spec 6 of 7 in the extension chain. It mirrors the Bitwarden browser extension's Settings tab (ADR-001) and defines the one settings schema every earlier and later change reads through.

## What Changes

- A Settings tab with six sections, in Bitwarden's order and with Bitwarden's labels: Account security, Autofill, Notifications, Vault, Appearance, About.
- One typed settings schema (`src/settings/schema.ts`) with keys, defaults, validation and a storage scope per key, read and written through a `settings.get` and `settings.set` message pair handled by the background.
- Account security: vault timeout and timeout action with the ADR-002 options and defaults, Lock now, Log out, Unlock with PIN, biometrics shown as unavailable, Change master password as a link to the Keepiq web app.
- Unlock with PIN: the RSA private-key bytes are wrapped under a PBKDF2-derived PIN key and stored in `storage.local` or `storage.session` depending on the "Require master password on browser restart" toggle. The unlock screen gains a PIN field. Five wrong PINs revert to the master password.
- Autofill, Notifications, Vault and Appearance settings entries with their storage, defaults and effect. The behaviour behind the autofill and notification entries is delivered by ext-autofill; folder management by ext-vault-edit. Only the entries live here.
- Server-mirrored settings: the four Keepiq notification toggles and the default secret type are read from and written to `GET/PUT /api/settings/user`, cached per account, marked stale when offline, and re-read on every sync.
- A policy hook so an admin-provided clamp object (ADR-002) can restrict the maximum timeout and force the timeout action later without touching the views.
- About: extension version, server origin, help and store links.

## Capabilities

### New Capabilities
- `settings`: the Settings tab, the settings schema and storage, PIN unlock, server-mirrored preferences and the About panel.

### Modified Capabilities

None. `openspec/specs/` is empty today.

## Deviations from Bitwarden

- **Unlock with biometrics** is shown but disabled with "Not available yet". Bitwarden needs a native messaging host installed on the desktop; this project has none.
- **Two-step login** and **Fingerprint phrase** are omitted. Keepiq has no second factor of its own (Nextcloud owns login) and no account fingerprint.
- **Change master password** opens the Keepiq web app instead of a form. ADR-003 places master password changes out of reach for an app-password client.
- **Import items** and **Export vault** open the Keepiq web app. There is no in-extension import or export; Keepiq's web app owns both.
- **Show identities as suggestions** and **Show cards as suggestions** are not rendered until ext-autofill supports those item types. Bitwarden shows them by default.
- **No "Show website icons" setting.** Bitwarden has one, on by default. ADR-002 rules out fetching icons from sites or icon services, so there is nothing to toggle; the setting returns when Keepiq stores a favicon on the secret.
- **Language** is read-only and follows the browser UI language. Bitwarden offers a picker; WXT's i18n module is the intended route once translations exist.
- **Five wrong PINs** return the unlock screen to master-password mode and discard the PIN-wrapped key. Bitwarden logs the account out instead. Keepiq's logout costs a new app password, which is disproportionate for a mistyped PIN.
- **Keepiq server notifications** and **Default item type** are additions. They mirror Keepiq's own per-user preferences so the extension and the web app agree.
- **Vault timeout default** is 15 minutes idle with a lock on every browser restart, and **Never** carries a warning (ADR-002). Bitwarden's extension default differs; ADR-002 explains why 15 minutes was chosen.

## Keepiq API used

- `GET /api/settings/user`: read `notify_shares`, `notify_requests`, `notify_group_shares`, `notify_security`, `default_secret_type` and `session_timeout` (the last is display-only per ADR-002).
- `PUT /api/settings/user`: write a changed notification toggle or default secret type.

Sync now reuses the sync routine from ext-vault-browse and adds no calls of its own. The type list that backs Default item type is the cached `GET /api/v1/secret-types` result from ext-vault-browse.

## Impact

- New: `src/settings/schema.ts`, `src/settings/store.ts`, `src/settings/policy.ts`, `src/settings/pin.ts`, `src/settings/server-mirror.ts`; React views `entrypoints/popup/views/settings/{SettingsIndex,AccountSecurity,Autofill,Notifications,Vault,Appearance,About}.tsx`; components `entrypoints/popup/components/{SettingRow,Toggle,Select,SectionList,PinDialog,WarningDialog}.tsx`; hooks `entrypoints/popup/hooks/{useSettings,useServerSettings,useTheme}.ts` (ADR-004).
- Edited: `src/messages.ts` (settings, PIN and About message kinds), `entrypoints/background.ts` (handlers, timeout config fed from settings, PIN unlock, clearing the PIN blob on lock and logout), ext-accounts-and-unlock's `Unlock` view component (PIN field and "Use master password" link), `entrypoints/popup/popup.css` (theme tokens, compact mode).
- Other changes in the chain read their knobs through `settings.get` instead of their own keys: ext-accounts-and-unlock (timeout, action), ext-vault-browse (clipboard clear, quick copy actions), ext-vault-edit (default item type), ext-autofill (every Autofill and Notifications entry).
- No new manifest permissions. The `commands` entry the shortcut display reads is declared by ext-autofill.
- Security: a PIN-wrapped copy of the private key can live in `storage.local`. Design.md states the trade-off and the mitigations.
