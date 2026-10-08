---
kind: code
depends_on: []
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

The extension is still the WXT starter scaffold: it has no notion of a Keepiq account, cannot talk to a server and cannot decrypt anything.
Every later change in the chain (vault browsing, editing, generator, send, settings, autofill) needs an authenticated account, an unlocked private key and an API client, so those have to exist first and be owned by exactly one module.

This is change 1 of 7 in the chain; it mirrors Bitwarden's "Log in", "Unlock", "Account switcher" and "Vault timeout" surfaces (ADR-001) on top of Keepiq's app-password plus master-password model (ADR-002, ADR-003).

## What Changes

- Add account: a first-run screen that takes a Nextcloud server URL, username and app password, verifies them against Nextcloud and Keepiq, requests the server origin as an optional host permission and stores the account.
- Account switcher: a Nextcloud avatar in the popup header that opens a panel listing up to 5 accounts with Unlocked / Locked / Logged out status, per-account Lock and Log out, Lock all, Log out all and Add account.
- Unlock and lock: an unlock screen that derives the unlock key from the master password and decrypts the suite's private-key envelope client-side, a lock state machine with Bitwarden's timeout options and actions, and a private key that lives in `storage.session` only (ADR-002).
- Keepiq API client: one background-only module that applies Basic auth and the OCS header, treats 401 as revocation, 423 as a retryable write lock and network failure as offline, with typed response shapes from ADR-003.
- Crypto module: envelope decode, PBKDF2, PKCS#8 import, X.509 SPKI extraction and chunked RSA-OAEP, byte-compatible with the Keepiq web app (ADR-003).
- Manifest: add `alarms` and `idle` permissions and optional host permissions for `https://*/*` and `http://*/*` (Chrome `optional_host_permissions`, Firefox MV2 `optional_permissions`).
- **BREAKING** for the scaffold only: the popup's on/off toggle, its badge counter and the `get_state`, `set_enabled` and `enabled_changed` messages are removed. The content script's `page_ready` handshake stays for ext-autofill.

## Capabilities

### New Capabilities
- `account-management`: adding, verifying, switching, locking out and removing Nextcloud accounts, with the avatar-driven account switcher.
- `vault-unlock`: unlocking a suite with the master password, private key lifetime, manual and timed lock, timeout actions.
- `api-client`: the single HTTP client for Keepiq and Nextcloud identity routes, its auth headers, error mapping and typed responses.

### Modified Capabilities

None. `openspec/specs/` is empty today.

## Deviations from Bitwarden

- Login asks for Server URL, Username and App password instead of email and master password. Forced: Keepiq has no login endpoint, and a Nextcloud app password is the only credential an extension can hold (ADR-003).
- An account has two credentials, an app password for the server and a master password for the vault, so "Logged out" and "Locked" are distinct states with distinct screens. Forced by the same API model (ADR-002).
- The account switcher and the unlock screen identify an account by Nextcloud display name and server host, and the header shows the Nextcloud profile avatar instead of Bitwarden's initials disc. Not forced: Nextcloud supplies both and email may be empty on a Nextcloud account. Initials are the fallback when the avatar cannot be fetched.
- Verification on add contacts Nextcloud and Keepiq before anything is stored and distinguishes "Keepiq not installed" and "no active suite" errors. Not forced, but Bitwarden has one server and Keepiq is an optional app on an arbitrary Nextcloud.
- "Never" as a vault timeout shows a warning and is the only case that writes key material to `storage.local`. Same as current Bitwarden; recorded because ADR-002 calls it out.
- No "Log in with device", SSO, biometric unlock or "Remember email" options. Out of scope, not deviations (ADR-001).

## Keepiq API used

- `GET /ocs/v2.php/cloud/user` (Nextcloud identity, ADR-003)
- `GET /index.php/avatar/{uid}/{size}` (Nextcloud avatar, ADR-003)
- `GET /api/v1/suites`

All other typed shapes in the API client (`/api/v1/secrets`, `/api/v1/folders`, `/api/v1/secret-types`, `/api/v1/offline/manifest`, `/api/settings/user`) are declared here for downstream changes but not called by this change.

## Impact

- New: `src/api/`, `src/crypto/`, `src/accounts/`, `src/vault/`, `entrypoints/popup/App.tsx`, `entrypoints/popup/views/`, `entrypoints/popup/components/`, `entrypoints/popup/hooks/`.
- Edited: `wxt.config.ts` (permissions, React module), `eslint.config.mjs` (react-hooks rules), `src/messages.ts` (message unions), `entrypoints/background.ts` (message router, timeout engine), `entrypoints/popup/index.html`, `popup.css`. `entrypoints/popup/main.ts` becomes `main.tsx`.
- Unchanged: `entrypoints/content.ts`, `src/browser-action.ts`; the background stays plain TypeScript.
- New dependencies (ADR-004, added once here for the whole chain): `react`, `react-dom`, `@types/react`, `@types/react-dom`, `@wxt-dev/module-react`, `eslint-plugin-react-hooks`. New dev dependency `vitest` for the crypto module only.
- Store review: optional host permissions for all origins are requested at runtime per server, which both stores accept more readily than a static `<all_urls>` host permission. The content script `matches` question stays open (CLAUDE.md).
- Downstream: ext-vault-browse owns the `vaultCache.<accountId>` key named here and replaces the unlocked placeholder view. ext-settings owns the timeout option picker and PIN unlock; this change ships the engine and defaults they configure.
