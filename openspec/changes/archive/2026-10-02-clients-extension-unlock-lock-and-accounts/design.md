# Design: extension biometric unlock, chosen lock delay, and several accounts

## Context

Code at development `4c214a9d`:

- `browser-extension/src/lib/api.js:13` stores one pairing (`{ url, user, appPassword }`) under the key `keepiq.config`; `loadConfig()` (`:16`) returns it or null. Every API call takes that one config.
- `browser-extension/src/lib/vault.js:27` to `:30` keeps one `cryptoKey`, `publicKey`, `suiteId` and `idleTimer` in module scope. `unlock()` (`:49`) fetches the active suite and calls `decryptPrivateKey(suite.privateKey, masterPassword)`; `armIdleLock()` (`:114`) sets the timer.
- `browser-extension/src/background/service-worker.js:26` fixes `DEFAULT_IDLE_MINUTES = 15`; `idleMs()` (`:31`) reads `config.idleMinutes`, which nothing writes. `:254` locks on the OS `locked` idle state. `getState()` (`:42`) reports one `user` and one `url`.
- `browser-extension/src/popup/popup.js:17` switches three views (pair, locked, unlocked); `:182` sends the master password to the worker with `send('unlock', { masterPassword })`.
- `browser-extension/src/crypto/index.js` re-exports `decryptPrivateKey` but not `decryptPrivateKeyWithRawKey` or `deriveUnlockKeyRaw` (both in `src/crypto/aes.js:104` and `:132`), nor the PRF helpers in `src/crypto/passkey.js` (`deriveKekFromPrf` `:72`, `wrapUnlockKey` `:100`, `unwrapUnlockKey` `:119`).
- The web app's passkey unlock: `src/store/modules/passkey.js:94` `enroll(masterPassword, label)` and `:193` `unlockWithPasskey()`, with `RP_ID = window.location.hostname` (`:26`) and `userVerification: 'preferred'`. The server side is `lib/Controller/PasskeyController.php` (`create` `:137`, `loginOptions` `:180`) and `lib/Service/PasskeyService.php` (`enroll` `:95`, `loginOptions` `:134` filtering on `unlock_key_epoch`, `markStaleOnPasswordChange` `:205`, `deleteAllOnRotation` `:217`). The table is `passkey_credentials` in `lib/Migration/Version001000Date20260908000000.php:487`. Routes are `appinfo/routes.php:241` to `:246`.
- The passkey provider already opens a small extension window for a consent step with `chrome.windows.create` (`browser-extension/src/passkey/orchestrator.js:45`).
- Admin session settings live in `src/components/settings/SessionTimeoutSection.vue`; `lib/Service/AdminSettingsService.php:55` allows web session timeouts `session`, `10min`, `30min`.

## Goals / Non-Goals

**Goals:**

- A user chooses the extension's idle lock delay, within an administrator's maximum.
- A user pairs up to five accounts on one or more servers and switches between them in one click.
- A user unlocks the extension with a fingerprint or face through a platform passkey with PRF, where the browser allows it.

**Non-Goals:**

- Biometric unlock in the Go CLI. The row names the extension; the CLI stays on the master password.
- Showing candidates from several accounts at once. Matching and filling use the active account; a merged view is a later change.
- Biometric unlock through a native helper app (the Bitwarden desktop route). Keepiq has no native app (`openspec/specs/mobile-pwa/spec.md:11`).
- Remembering an unlocked state across a browser restart.

## Decisions

### D1: The idle delay is a per-account setting with an administrator maximum

The popup's new settings view offers 1, 5, 15, 30, 60 and 240 minutes and stores the choice as `idleMinutes` on the account in `storage.local` (not sensitive). On every unlock the worker calls a new `GET /api/v1/extension/policy`, which returns `maxIdleMinutes` from the app config key `extension_max_idle_minutes` (default 240, set in `SessionTimeoutSection.vue`), and clamps the choice to it. The lock on OS lock, worker termination and manual lock stays unconditional.

Alternative considered: reusing the web app's `session_timeout` preference. Rejected: its values (`session`, `10min`, `30min`) describe a browser tab, and a user may want a shorter delay in the extension, which fills forms on any site.

### D2: An account list replaces the single pairing

Storage moves to `keepiq.accounts` (a list of `{ id, url, user, appPassword, label, idleMinutes }`) and `keepiq.activeAccountId`. On worker start, an existing `keepiq.config` becomes the first account and the old key is removed. The limit is five accounts.

`vault.js` keeps a map from account id to `{ cryptoKey, publicKey, suiteId, idleTimer }`. Each account locks on its own timer; OS lock and worker termination lock them all. The blob cache from `doMatch()` is keyed by account. Every worker message that reads or fills carries the account id, and the worker refuses a fill for a secret id that came from another account's match. A captured login is saved to the active account, and the save prompt names that account.

The popup header shows the active account (`user@host`) with a menu listing the others, their lock state, and "Add account". Unpair removes one account.

Alternative considered: one account unlocked at a time, switching locks the previous one. Rejected: a user switching back and forth would re-enter a password every time, which invites weak master passwords.

### D3: Biometric unlock is a PRF passkey owned by the extension

The extension enrols its own platform credential; it cannot use the web app's, because that one is bound to the Nextcloud host as relying party. The ceremony runs in a small extension window opened with `chrome.windows.create`, like the passkey consent window, because the OS prompt takes focus and would close the popup. The relying party is the extension's own origin. The request asks for `authenticatorAttachment: 'platform'`, `userVerification: 'required'` and the `prf` extension.

Enrolment: the user enters the master password once in that window. The window derives the raw unlock key with `deriveUnlockKeyRaw` from the suite envelope's salt, checks it against the envelope, runs `create()` and a `get()` with a fresh PRF salt, derives the key-encryption key with `deriveKekFromPrf`, wraps the raw unlock key with `wrapUnlockKey`, and asks the worker to post the credential. This is the web app's recipe (`src/store/modules/passkey.js:94`), reused, not re-implemented.

Unlock: the window fetches the login options through the worker, runs `get()` with the stored PRF salt, derives the key-encryption key, unwraps the raw unlock key, and sends it to the worker over the extension's internal messaging, the same channel the popup already uses for the master password. The worker calls `decryptPrivateKeyWithRawKey`, imports the non-extractable key, and the window closes.

Alternative considered: keeping the wrapped key only in extension storage. Rejected: the user could not see or revoke it from the web app, and it would escape the server's `unlock_key_epoch` invalidation on a password change or rotation.

### D4: Server-side, extension credentials sit next to web credentials

`passkey_credentials` gains `client_kind` (`web` default, or `extension`) and `rp_id`. `POST /api/v1/passkeys` accepts both; `GET /api/v1/passkeys/login-options` takes `client` and `rpId` query parameters and returns only matching credentials, so the web app never offers an extension credential and the reverse. `PasskeyManager.vue` labels extension credentials "Browser extension" and revokes them the same way. The existing epoch check and `deleteAllOnRotation()` apply unchanged.

### D5: Feature detection, not browser lists

The unlock option appears only when the extension page exposes `PublicKeyCredential`, `isUserVerifyingPlatformAuthenticatorAvailable()` returns true, and enrolment reports `prf.enabled`. A browser that refuses WebAuthn from an extension origin, or an authenticator without PRF, shows the master password form only. Which browsers pass is recorded in the extension README after the implementation checks them.

## Security and zero-knowledge

The server stores, per extension credential: the credential id, the PRF salt, the AES-256-GCM wrapped unlock key, the epoch, the label, `client_kind` and `rp_id`. It never receives the master password, the raw unlock key, the PRF output or the key-encryption key (ADR-003, and the `passkey-vault-login` rules). A stolen database row is useless without the user's authenticator and a successful user verification.

The raw unlock key exists briefly in the unlock window and in the worker, never in `storage.*`. The window closes after the handoff. The worker keeps only the non-extractable `CryptoKey` it already keeps today.

Extension storage holds, per account: the server URL, the user, the Nextcloud app password, a label and the idle delay. None of it is key material; revoking the app password in Nextcloud cuts the account off, as today.

Accounts cannot read each other's data: key material, blob caches and timers are keyed by account, and a fill request is checked against the account that produced the match.

## Risks / Trade-offs

- **Browser support for WebAuthn in extension pages varies.** D5 hides the option where it fails; the master password always works.
- **A shorter maximum set later by an administrator** takes effect at the next unlock, not immediately. Acceptable: the lock on OS lock is unconditional.
- **Five unlocked accounts hold five keys in memory.** Each has its own timer, and one OS lock clears them all.
- **Storage migration from `keepiq.config`** runs once; a test covers an upgrade from the old shape.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests use a virtual WebAuthn authenticator (Chrome DevTools protocol in Playwright) and the existing `tests/extension` fixtures.

## Migration

A migration step adds `client_kind` (`STRING(16)`, not null, default `web`) and `rp_id` (`STRING(255)`, nullable) to `keepiq_passkey_credentials`. Existing rows become `web`. The `<version>` in `appinfo/info.xml` must bump. The extension migrates its own `keepiq.config` to `keepiq.accounts` on first start after the update.
