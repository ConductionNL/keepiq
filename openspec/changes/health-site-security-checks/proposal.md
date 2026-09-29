---
kind: code
---

# Site checks in the password health report: unused two-factor, plain http and passkeys

## Why

The password health report looks at the values only. The engine flags weak, reused, stale, compromised and breached secrets (`src/health/engine.js:114-175`) and the report shows those categories plus rotation (`src/views/HealthReportView.vue:95-153`). It says nothing about the site a login belongs to: whether that site offers two-factor login the user has not set up, whether the saved address is plain `http://` so the password crosses the network in the clear, and whether the site accepts a passkey the user could switch to. All three can be answered in the browser from the login's plain-text address and the user's own vault, without teaching the server anything.

The three rows share one screen, the password health report, and one service, the health engine.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `health-11` | Be told which websites offer two-factor login you have not switched on. | `no`: no inactive two-factor report; the engine has no such category |
| `health-12` | Be warned about logins that use an unencrypted http address. | `no`: no insecure-address finding in the report or the extension |
| `health-15` | See which of your logins are for websites that accept a passkey you have not set up. | `no`: the report has no passkey-available check |

### Demand

- `health-15`: changelog, https://github.com/bitwarden/clients/pull/22766
- `health-11`, `health-12`: no demand row; two and three competitors rate them yes.

### Competitors rated yes

- `health-11`, Bitwarden: "apps/web/src/app/dirt/reports/pages/inactive-two-factor-report.component.ts:98 loads the 2fa directory and flags logins on sites supporting TOTP without a TOTP seed ... Inactive two-step login report for personal and organisation vaults."
- `health-11`, 1Password: shows "logins for websites that support two-factor authentication" (https://support.1password.com/watchtower/).
- `health-12`, Bitwarden: "apps/web/src/app/dirt/reports/pages/unsecured-websites-report.component.ts:136 flags URIs starting with 'http://' Note: Unsecured websites report."
- `health-12`, 1Password: "'Unsecured websites' catches HTTP sites" (https://support.1password.com/watchtower/).
- `health-12`, Keeper: KeeperFill setting "Enforce the HTTP Fill Warning popup" (https://docs.keeper.io/enterprise-guide/roles/enforcement-policies#keeperfill).
- `health-15`, Bitwarden: "apps/web/src/app/dirt/reports/pages/passkey-report.service.ts:31 loadPasskeyDirectory via PasskeyDirectoryApiService, :74 skips logins that already have hasFido2Credentials ... It is behind the PasskeyLoginReport feature flag."
- `health-15`, 1Password: "Passkeys available shows logins for websites that support passkeys, but don't yet have a passkey saved in the item." (https://support.1password.com/watchtower/).

## What Changes

- **Plain http.** A new health category, Unencrypted address, lists logins whose main or extra address starts with `http://`, with a Change to https action that edits the address. The browser extension shows a warning before filling on an `http://` page.
- **Unused two-factor.** A new category lists logins for sites that support one-time codes when the vault holds no seed for that login: neither a seed on the login (`vault-login-totp-codes`) nor an authenticator item for the same host.
- **Passkey available.** A new category lists logins for sites that accept passkeys when the vault holds no passkey item for that site.
- **A site directory, fetched whole.** The two-factor and passkey checks read a public directory of sites. When an administrator switches the site directory on (off by default), a daily job on the server downloads the whole directory, and the browser downloads it from Keepiq and matches locally. No login address leaves the browser.

## Capabilities

### New Capabilities

- `health-site-checks`: three site-based health categories, computed in the browser, and an admin-gated site directory the server fetches whole.

### Modified Capabilities

- None in delta form. `password-health` keeps "No Server-Side Health Knowledge": the server serves a public list and learns nothing about the vault.

## Impact

- **Backend**: `site_directory_enabled` in `AdminSettingsService` (default off), a `RefreshSiteDirectoryJob` that fetches the directory through `IClientService` into app data, and `GET /api/v1/site-directory` that serves it.
- **Frontend**: three categories in `src/health/engine.js` and `HealthReportView.vue`, a site directory switch in the breach checking admin section or next to it.
- **Browser extension**: an http warning before a fill.
- **Database**: none. The directory is a file in app data.
- **Security**: the server fetches a public list and never receives a host from a user; the browser matches locally.
- **Cross-app**: when `adopt-connection-registry` has landed, the directory is declared as a connection next to `hibp`.
