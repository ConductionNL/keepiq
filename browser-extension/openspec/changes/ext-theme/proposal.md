---
kind: code
depends_on: [ext-vault-browse, ext-i18n]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-i18n
  - ext-theme
---

## Why

The popup follows the system's light or dark preference, and nothing else. Most Keepiq users work in light mode, but a user whose OS is dark gets a dark popup with no way to change it. The tokens for both themes and the `data-theme` hook in `popup.css` already exist; what's missing is the setting, its storage and a place to change it.

The Settings tab is still a placeholder, and ext-settings is too large to wait for. This change delivers the theme switch and the start of the Settings tab, and ext-settings builds on both.

## What Changes

- A Theme setting with System default (the default), Light and Dark, mirroring Bitwarden's Settings, Appearance, Theme.
- The Settings tab gets its section list in Bitwarden's order. Appearance opens; the other five sections show as "Available in a later update" until ext-settings delivers them.
- An Appearance view holding the Theme control only. Compact mode, animations, quick copy and the Language line stay with ext-settings.
- The theme is global, not per account, so it also applies to the unlock, add-account and account-switcher screens.
- `settings.get` and `settings.set` messages, the first part of the ext-settings contract, carrying the global settings record.
- The popup applies the cached theme before its first paint, so a user who picked a theme never sees the other one flash.

## Capabilities

### New Capabilities
- `theme`: the Theme setting, its storage, how the popup applies it, and the Settings section list.

### Modified Capabilities

None. `openspec/specs/` is empty today. ext-settings is trimmed to build on this change: its Settings tab, `settings.get`/`settings.set` and the Theme entry come from here.

## Deviations from Bitwarden

- **Unbuilt sections are listed but disabled**, so the section order stays the same as ext-settings fills them in.

## Keepiq API used

None. The theme is a local preference; the web app's theme is Nextcloud's and is not mirrored.

## Impact

- New: `src/settings/global.ts`, `entrypoints/popup/theme.ts`, `entrypoints/popup/hooks/useTheme.ts`, `entrypoints/popup/hooks/useSettings.ts`, `entrypoints/popup/views/settings/{Settings,SettingsIndex,Appearance}.tsx`, `entrypoints/popup/components/Select.tsx`, with their tests.
- Edited: `src/messages.ts`, `src/background/requests.ts`, `entrypoints/popup/{main.tsx,App.tsx,popup.css}`, `views/Shell.tsx`, `components/Icon.tsx`, `testing.ts`, `locales/en.yml`, `locales/nl.yml`, `ARCHITECTURE.md`, `openspec/README.md`, ext-settings' proposal, spec, design and tasks, and `../docs/browser-extension/using.md`.
- No new permissions.
