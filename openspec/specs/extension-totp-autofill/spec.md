# Extension TOTP Surfacing + Auto-Copy Specification

**Status**: in-progress

**OpenSpec changes:**
- [extension-totp-autofill](../../changes/extension-totp-autofill/)

## Purpose

Doriath already stores TOTP seeds and generates the current 6-digit code, but only in the web UI — a user who autofills a login through the browser extension must still open the web app to read the code. This feature surfaces TOTP where the login is filled: the extension popup shows the current RFC 6238 code with a live countdown for a login matched to a `totp` secret by origin, auto-copies the code to the clipboard after autofilling credentials (with a scheduled clipboard clear), and optionally fills it into a detected one-time-code input. All code generation happens client-side in the extension from the decrypted seed, which never leaves the extension — the same zero-knowledge guarantee the web UI already provides. It reuses the existing `totp` type and seed storage and the existing RFC 6238 generator (`src/totp/totp.js`), and the extension runtime from `browser-extension-autofill`; it adds no backend and no schema. This closes the oldest, most-discussed one-app credential+TOTP wish on the platform (NC Passwords #69, 43 comments) and ships free the auto-copy-on-autofill Bitwarden charges premium for.

## Requirements

### Requirement: Current code in the popup for a matched login

The extension MUST show the current RFC 6238 code with a live countdown in the popup for a login it lists, matching a `totp` secret to that login by origin (unencrypted `url`/`name`), computing the code client-side from the decrypted seed. Multiple matches MUST be listed for the user to pick, never silently guessed.

#### Scenario: Popup shows the code and countdown
- GIVEN an unlocked extension listing a login for `example.com` and a `totp` secret matching `example.com`
- WHEN the popup renders the login
- THEN it MUST show the current 6-digit code and a countdown, with neither seed nor code sent to the server

### Requirement: Auto-copy the code on autofill

The extension MUST copy the current code to the clipboard when the user autofills a login that has a matched `totp` secret, and MUST schedule a clipboard clear after a short timeout and no later than the code's window expiry.

#### Scenario: Autofill copies and later clears the code
- GIVEN an unlocked extension and a login with a matched `totp` secret
- WHEN the user autofills the login
- THEN the extension MUST copy the current code to the clipboard and schedule a clear within the timeout / by window expiry

### Requirement: Optional OTP-field fill with fallback

The extension MAY fill the current code into a detected one-time-code input on the current or post-submit page (re-detecting after in-origin navigation), and MUST fall back to the clipboard auto-copy when no OTP field is detected, never filling a non-OTP field.

#### Scenario: Fill a detected OTP field, else fall back
- GIVEN a page exposing a one-time-code input and a matched `totp` secret
- WHEN the extension processes the login
- THEN it MUST fill the detected field with the current code, or rely on auto-copy when no OTP field is present

### Requirement: Honest invalid seed, discard on lock, seed never persisted

The extension MUST show a "not a valid authenticator secret" state and never a fabricated code for an unparseable seed, MUST discard the seed, derived HMAC key, code, and timers on lock, and MUST NOT write the seed or code to persistent extension storage.

#### Scenario: Locking discards all TOTP state
- GIVEN an unlocked extension displaying a code with a running countdown
- WHEN the extension locks
- THEN it MUST discard the seed, derived key, code, and timers, and MUST NOT have written the seed or code to `storage.*`

## User Stories

- As a user, I want the current one-time code shown next to my login in the extension so I don't have to open the Doriath web app to read it
- As a user, I want the code copied to my clipboard when I autofill so it is one paste away on the 2FA prompt
- As a user, I want the code filled into the OTP box automatically when possible
- As a security-conscious user, I want the code generated in the extension and the clipboard cleared shortly after, so the seed never leaves the extension and the code doesn't linger
- As a user, I want an invalid seed to say so rather than show a wrong code

## Acceptance Criteria

- [ ] The popup shows the current RFC 6238 code and a countdown for a login matched to a `totp` secret by origin; multiple matches are user-picked
- [ ] The code is computed client-side from the decrypted seed; the seed and code never reach the server, are never persisted to extension storage, and are discarded on lock
- [ ] Autofilling a matched login copies the current code to the clipboard and schedules a clipboard clear within the timeout / by window expiry
- [ ] A detected OTP input is optionally filled (re-detected after post-submit navigation); with no OTP field the extension falls back to auto-copy and never fills a non-OTP field
- [ ] An unparseable seed shows an explicit invalid-seed state and never a fabricated code
- [ ] No backend route, schema, migration, or audit-event type is introduced; the extension reuses the existing `src/totp/totp.js` generator (no RFC 6238 re-implementation)

## Notes

- Depends on `browser-extension-autofill` (MV3 runtime: unlock, URL matching, decrypt-on-demand, popup, content script) and the archived `add-totp-secrets` (the `totp` type, seed storage in encrypted `key`, and the RFC 6238 generator `src/totp/totp.js`).
- Out of scope: in-browser QR scanning, TOTP editing/creation from the extension (managed in the web UI), HOTP, any server-side code computation.
- Related ADRs: ADR-001 (own tables, imperative — no OpenRegister), ADR-003 (always E2E, browser-user client-side WebCrypto, unencrypted `url`/`name` for matching).
