---
kind: code
---

# An inactivity lock that follows activity, and a timeout the user keeps

## Why

The web vault locks itself after a timeout, but `src/store/modules/session.js:194` compares against `lastActivity`, which is set only at unlock (`:121`, `:159`); `updateActivity()` (`:204`) has no caller. An active user is locked out after the timeout however busy they are. The user's timeout choice in `src/App.vue:873-874` lives in memory only, the component that saves it (`src/components/settings/SessionTimeoutSection.vue:52-66`) is mounted nowhere, and choosing Nextcloud session maps to 0 which `|| 600000` turns into 10 minutes. Four competitors rate yes on each row. Both rows are one service (the session store) and one control (the timeout select), so they are one change.

The rows share one screen or service, so they are one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `crypto-06` | Lock the vault by hand, and have it lock itself after a period of inactivity. | `partial`: `partial`: the web app auto-lock is a timer from unlock, because `updateActivity` has no caller, so an active user is locked out mid-work |
| `crypto-07` | Choose your own session timeout. | `partial`: `partial`: the chosen timeout applies to the page session only, is never saved, and the Nextcloud session choice silently becomes 10 minutes |

### Demand

- `crypto-06`: no demand row.
- `crypto-07`: no demand row.

### Competitors rated yes

- `crypto-06`, bitwarden: "bitwarden/clients@web-v2026.9.0 libs/common/src/key-management/vault-timeout/services/vault-timeout.service.ts:59 checkVaultTimeout (periodic, :36); apps/web/src/locales/en/messages.json:2536 'lockNow'; apps/browser/src/manifest.v"
- `crypto-06`, onepassword: "https://support.1password.com/unlock-auto-lock/ : 'Lock after system is idle for' minutes, also locks on sleep"
- `crypto-06`, keeper: "https://docs.keeper.io/enterprise-guide/roles/enforcement-policies#account-settings : Logout Timer 'to automatically log out a user from Keeper when they are inactive' for Web, Mobile and Desktop"
- `crypto-06`, hashicorp-vault: "hashicorp/vault@v2.1.1 ui/lib/core/addon/components/sidebar/user-menu.hbs:63 Log out; ui/app/services/auth.js:32 IDLE_TIMEOUT 3 min, :382 stops token renewal after idle so the session ends at token expiry; ui/app/components/token-"
- `crypto-07`, bitwarden: "bitwarden/clients@web-v2026.9.0 libs/common/src/key-management/vault-timeout/services/vault-timeout-settings.service.ts timeout value and action (lock or log out); apps/web/src/locales/en/messages.json:7534 'vaultTimeout'; bitward"
- `crypto-07`, onepassword: "https://support.1password.com/unlock-auto-lock/ : adjust Auto-Lock minutes; Business presets in https://support.1password.com/unlock-auto-lock-policy/"
- `crypto-07`, keeper: "https://docs.keeper.io/enterprise-guide/roles/enforcement-policies#account-settings : the admin timer is the maximum; users choose their own timer up to it ('If a Keeper user's current timer is set greater than this value, it will"
- `crypto-07`, nextcloud-passwords: "marius-wieschollek/passwords@2026.9.0 src/vue/Section/Settings.vue:92-106 'End session after' select (1 to 60 minutes) bound to user.session.lifetime; src/lib/Helper/Settings/UserSettingsHelper.php session/lifetime default 600 Not"

## What Changes

- Call `updateActivity()` on pointer, key and scroll events, throttled, so the lock counts inactivity.
- Mount `SessionTimeoutSection` in personal settings, remove the in-memory select from `App.vue`, and load the saved value at unlock.
- Fix the Nextcloud session option so it means no idle timer beyond the Nextcloud session, not 10 minutes.

## Capabilities

### New Capabilities

- `vault-session-lock`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `src/store/modules/session.js`, `src/App.vue`, `SessionTimeoutSection.vue`, the settings page that hosts it.
- **Backend**: none; `session_timeout` is already a user preference (`lib/Service/SettingsService.php:93`).
- **Database**: none.
- **Cross-row**: `admin-24` (an administrator cap on the timeout) is decided no on its own; this change leaves the select ready for a maximum but adds none.
