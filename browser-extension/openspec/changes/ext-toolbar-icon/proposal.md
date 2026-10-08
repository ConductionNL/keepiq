---
kind: code
depends_on: [ext-accounts-and-unlock, ext-vault-browse, ext-settings, ext-autofill]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
  - ext-toolbar-icon
---

## Why

The toolbar icon is the only part of the extension that is visible without opening it, and today it is static placeholder art. Bitwarden uses it to show at a glance whether you are logged in, whether the vault is locked, and how many logins it has for the page you are on. Keepiq should do the same.

## What Changes

- Mirrors Bitwarden's toolbar icon states and badge counter. The icon follows the active account:
  - **Logged out**: a grey icon, shown when there is no account or the active account is logged out.
  - **Locked**: the coloured icon with a lock, shown when the active account is locked.
  - **Unlocked**: the coloured icon, with a badge in the bottom right that counts the logins matching the active tab.
- The counter uses the URL matching from ext-autofill on the cached plaintext `url`, so it needs no decryption and no request. It counts the same items as the popup's "Autofill suggestions" section, shows "9+" above nine, and shows nothing at zero.
- The setting "Show number of login autofill suggestions on extension icon" is added to the Appearance view, on by default, as in Bitwarden.
- The icon's tooltip names the state, so the state is not conveyed by colour alone.
- New icon art: grey and locked variants of the toolbar sizes, next to the existing coloured set. The manifest's `default_icon` becomes the grey variant.
- This change re-adds badge code to `entrypoints/background.ts`; ext-accounts-and-unlock removed the scaffold's badge.

## Capabilities

### New Capabilities
- `toolbar-icon`: the icon state for the active account, the per-tab badge counter, the tooltip, and the badge counter setting.

### Modified Capabilities

None. `openspec/specs/` is empty; the Appearance entry is specified here and rendered by the ext-settings view.

## Deviations from Bitwarden

- The tooltip names the state ("Keepiq: Locked", "Keepiq: Logged out"). Bitwarden's tooltip stays the extension name. This is not forced by the API; it is added for accessibility.
- The counter setting is global (in the Appearance record), not per account, because ext-settings keeps every Appearance setting global.

## Keepiq API used

None. The counter reads the cached snapshot from ext-vault-browse (ADR-002), and the account state comes from ext-accounts-and-unlock.

## Impact

- `entrypoints/background.ts`: registers the icon updater's listeners at top level, so an MV3 wake re-registers them.
- New file `src/toolbar-icon.ts`. `src/browser-action.ts` stays the only place `action` and `browserAction` are resolved.
- New art under `public/icon/` for the grey and locked variants.
- `wxt.config.ts`: `action.default_icon` points at the grey set. There are no new permissions: `tabs` comes from ext-autofill.
- ext-settings' Appearance view and schema gain one boolean entry.
