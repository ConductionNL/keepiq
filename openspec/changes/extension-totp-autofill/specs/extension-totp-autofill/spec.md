---
status: proposed
---

# Extension TOTP Surfacing + Auto-Copy on Autofill

## Purpose

Surface TOTP in the browser extension so a login and its one-time code live in one flow: show the current RFC 6238 code with a countdown in the popup for a login matched to a `totp` secret by origin, auto-copy the code to the clipboard after autofilling credentials (with a scheduled clipboard clear), and optionally fill it into a detected OTP input — all computed client-side in the extension from the decrypted seed, which never leaves the extension. Reuses the existing `totp` type, seed storage, and RFC 6238 generator (`add-totp-secrets`, archived) and the existing extension runtime (`browser-extension-autofill`); adds no backend and no schema.

## ADDED Requirements

### Requirement: Show the current TOTP code for a matched login

The extension MUST, for a login it lists for the active tab's origin, find any `totp`-typed secret whose unencrypted `url`/`name` matches the same origin, decrypt the seed in the extension, compute the current RFC 6238 code client-side, and display it with a live countdown to the next window. The seed and code MUST NOT be sent to the server. Where multiple `totp` secrets match one origin, the extension MUST list them for the user to pick rather than silently choosing.

#### Scenario: Popup shows the current code with a countdown

@e2e exclude Extension-popup TOTP display over an in-extension WebCrypto computation — not drivable by the app's Playwright harness against the deployed instance; covered by extension unit tests (RFC 6238 code from a known seed + countdown) reusing the shared `src/totp/totp.js` module.
- **GIVEN** an unlocked extension listing a login for `example.com` and a `totp` secret whose `url` matches `example.com`
- **WHEN** the popup renders the login
- **THEN** it MUST show the current 6-digit code (e.g. `123456` for a known test vector) and a live countdown to the next window
- **AND** neither the seed nor the code MUST be transmitted to the server

### Requirement: Auto-copy the code to the clipboard on autofill

The extension MUST, when the user autofills a login that has a matched `totp` secret, compute the current code client-side and copy it to the clipboard, and MUST schedule a clipboard clear after a short timeout (and no later than the code's window expiry) so the code is not left resident.

#### Scenario: Autofilling a login copies its current code and later clears it

@e2e exclude Clipboard write + scheduled-clear behaviour from the extension service worker — not drivable by the app's Playwright harness; covered by extension unit/integration tests with a fake clock and clipboard.
- **GIVEN** an unlocked extension and a login for `example.com` with a matched `totp` secret
- **WHEN** the user autofills the login
- **THEN** the extension MUST copy the current code to the clipboard
- **AND** it MUST schedule a clipboard clear within the configured timeout / by window expiry

### Requirement: Optionally fill the code into a detected OTP field

The extension MAY fill the current code into a detected one-time-code input (`autocomplete="one-time-code"`, numeric `inputmode`, or a recognised OTP field) on the current or post-submit page, re-detecting after in-origin navigation. Where no OTP field is detected, the extension MUST fall back to the clipboard auto-copy and MUST NOT fill a non-OTP field.

#### Scenario: Fill a detected OTP field, else fall back to copy

@e2e exclude Content-script OTP-field detection + post-navigation re-detection inside the extension — not drivable by the app's Playwright harness; covered by extension integration tests with fixture pages.
- **GIVEN** a page exposing a one-time-code input on the origin's 2FA step and a matched `totp` secret
- **WHEN** the extension processes the login
- **THEN** it MUST fill the detected OTP field with the current code
- **AND** where no OTP field is present it MUST rely on the clipboard auto-copy instead, never filling a non-OTP field

### Requirement: Honest invalid seed and discard on lock, seed never persisted

The extension MUST show a "not a valid authenticator secret" state and MUST NOT display a fabricated code when a matched `totp` secret's decrypted seed is unparseable, and MUST discard the seed, any derived HMAC key, the code, and countdown timers when it locks. The seed and code MUST NOT be written to any persistent extension storage.

#### Scenario: Unparseable seed shows an error, never a code

@e2e exclude Parser contract over a decrypted in-memory value — covered by extension unit tests (reusing the shared parser with a malformed seed).
- **GIVEN** a matched `totp` secret whose decrypted seed is not a valid `otpauth://totp` URI or base32 secret
- **WHEN** the extension surfaces it
- **THEN** it MUST show an explicit invalid-seed state and MUST NOT display any code

#### Scenario: Locking discards all TOTP state and nothing is persisted

@e2e exclude Memory/timer-lifecycle + no-leak contract — asserting the seed/derived-key/code/timers are dropped and absent from `storage.*` is covered by extension unit tests, not a DOM flow.
- **GIVEN** an unlocked extension displaying a TOTP code with a running countdown
- **WHEN** the extension locks
- **THEN** it MUST discard the seed, derived HMAC key, code, and timers
- **AND** the seed and code MUST NOT appear in any `storage.local`/`storage.sync` write
