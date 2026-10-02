# Tasks: extension biometric unlock, chosen lock delay, and several accounts

## 1. Idle lock delay

- [x] 1.1 Add `GET /api/v1/extension/policy` returning `maxIdleMinutes` from app config `extension_max_idle_minutes` (default 240), with `#[NoAdminRequired]`, and a field for it in `SessionTimeoutSection.vue`. Verify: PHPUnit `ExtensionControllerTest` for the default and a set value; vitest for the admin field. Done: `ExtensionController::policy()`, tests in `tests/Unit/Controller/ExtensionControllerTest.php`. `SessionTimeoutSection.vue` does not exist, so the admin field is a new `src/components/settings/ExtensionSection.vue` (test `tests/components/ExtensionSection.spec.js`).
- [x] 1.2 Add the popup settings view with the six delays, store `idleMinutes` per account, and clamp to `maxIdleMinutes` on every unlock in the worker. Verify: vitest asserts the stored value, the clamp, and that the timer uses the clamped value. Done: popup settings view, `set-idle` in `browser-extension/src/background/router.js`; tests in `tests/extension/accounts.spec.js` and `tests/extension/popupAccounts.spec.js`.

## 2. Several accounts

- [x] 2.1 Move `api.js` storage to `keepiq.accounts` and `keepiq.activeAccountId`, with a one-time migration from `keepiq.config` and a limit of five accounts. Verify: vitest upgrades a stored old config into one account and refuses a sixth pairing. Done: `tests/extension/accounts.spec.js` (upgrade and sixth pairing).
- [x] 2.2 Refactor `vault.js` to per-account key state and timers; OS lock and worker restart clear all accounts. Verify: vitest unlocks two accounts, lets one timer expire, and asserts only that account is locked. Done: `tests/extension/accounts.spec.js` (per-account timers, OS lock).
- [x] 2.3 Key the worker's blob cache by account, carry the account id in match, fill and save messages, and refuse a fill for a secret id from another account's match. Verify: vitest asserts the refusal and that a capture is saved to the active account. Done: `tests/extension/accounts.spec.js` (cross-account refusal, capture to the active account).
- [x] 2.4 Add the account switcher to the popup header (active account, lock state per account, add and unpair). Verify: vitest renders the switcher for three accounts and switches the active one. Done: `tests/extension/popupAccounts.spec.js`.

## 3. Biometric unlock

- [x] 3.1 Add `client_kind` and `rp_id` to `keepiq_passkey_credentials` with a migration and a `<version>` bump; accept them in `PasskeyService::enroll()` and filter `loginOptions()` by `client` and `rpId`. Verify: PHPUnit `PasskeyServiceTest` asserts the web app never receives an extension credential and the reverse. Done: `Version001007Date20261002180000`, app version 0.3.4-unstable.20261002180000, `tests/Unit/Service/PasskeyServiceTest.php`.
- [x] 3.2 Re-export `deriveUnlockKeyRaw`, `decryptPrivateKeyWithRawKey` and the PRF helpers through `browser-extension/src/crypto/index.js`, and add a worker `unlock-raw` message that imports the key from a raw unlock key. Verify: vitest round trip: wrap a raw key, unwrap it, unlock the worker, decrypt a test secret. Done: `tests/extension/biometricUnlock.spec.js` (round trip).
- [x] 3.3 Add the extension unlock window for enrolment and unlock (D3), opened with `chrome.windows.create`, with feature detection (D5). Verify: Playwright with the unpacked Chrome build and a virtual authenticator with PRF: a vault owner enrols, locks, and unlocks with the authenticator. Built: `browser-extension/src/unlock/`; enrolment and unlock run in vitest against a fake PRF authenticator (`tests/extension/biometricUnlock.spec.js`). Owed: the Playwright run with a virtual authenticator.
- [x] 3.4 Label extension credentials in `src/components/PasskeyManager.vue` and let the owner revoke them there. Verify: vitest renders an extension credential with its label and calls the delete route. Done: `tests/components/PasskeyManager.spec.js`.
- [ ] 3.5 (owed, manual) Check which browsers pass the feature detection and record the result in `browser-extension/README.md`. Verify: manual check on current Chrome, Edge and Firefox.

## Acceptance criteria

- A user can pick an idle lock delay in the popup, and the extension never uses a delay above the administrator's maximum.
- A user can pair up to five accounts, switch between them from the popup header, and each account locks on its own timer.
- A fill request can never use a secret from an account other than the one that produced the match.
- Where the browser supports it, a user can unlock the extension with a fingerprint or face through a PRF passkey, and the master password always remains available.
- The server stores only the PRF-wrapped unlock key for an extension credential, and the web app lists and revokes it.
