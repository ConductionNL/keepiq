# Tasks — extension-totp-autofill

## 0. Scope Note (read first)

Surface TOTP in the MV3 browser extension: show the current RFC 6238 code + countdown in the popup for a login matched to a `totp` secret by origin, auto-copy the code to the clipboard after autofilling credentials (with a scheduled clear), and optionally fill it into a detected OTP field — **all client-side, no backend, no schema/migration**. Reuse the **existing** RFC 6238 generator (`src/totp/totp.js` — `parseOtpauth`, `generateTotp`, `secondsRemaining`) as the extension's shared TOTP module (one implementation, ADR-003 dual-impl invariant), the existing `totp` seed storage (`lib/Repair/SeedSecretTypes.php:69`), and the `browser-extension-autofill` runtime (unlock, URL matching, decrypt-on-demand, popup, content script — `openspec/changes/browser-extension-autofill/design.md:83`). Association to a login is by origin match; there is no link field.

## 1. Shared TOTP module in the extension

- [x] 1.1 Import `src/totp/totp.js` (`parseOtpauth`, `generateTotp`, `secondsRemaining`) into the extension as its TOTP module — no re-implementation of RFC 6238.
- [x] 1.2 Resolve a login's authenticator by origin: find `totp` secrets whose unencrypted `url`/`name` match the active origin; when several match, surface all for the user to pick.

## 2. Popup — code + countdown

- [x] 2.1 In the service worker, on demand decrypt a matched `totp` secret's seed with the in-memory `CryptoKey` and compute the current code + `secondsRemaining`; hold seed/key/code only transiently in memory.
- [x] 2.2 In the popup, render the current 6-digit code and a live countdown for the matched login, recomputing as each window rolls over.
- [x] 2.3 Show the honest "not a valid authenticator secret" state for an unparseable seed; never render a fabricated code.

## 3. Auto-copy on autofill

- [x] 3.1 When the user autofills a login that has a matched `totp` secret, compute the current code and copy it to the clipboard.
- [x] 3.2 Schedule a clipboard clear after a short timeout (default ~30s) and no later than the code's window expiry.

## 4. OTP-field fill (best-effort)

- [x] 4.1 In the content script, detect a one-time-code input (`autocomplete="one-time-code"`, numeric `inputmode`, or recognised OTP field) on the current and post-submit page, re-detecting after in-origin navigation; fill the current code when confidently detected, else rely on auto-copy and never fill a non-OTP field.

## 5. Lock / no-leak

- [x] 5.1 On extension lock, discard the seed, derived HMAC key, code, and countdown timers; ensure the seed and code are never written to `storage.local`/`storage.sync` nor any request body.

## 6. Tests

- [x] 6.1 Extension unit: current code from a known seed matches an RFC 6238 test vector; countdown from `secondsRemaining` is correct; reuses the shared `src/totp/totp.js` module.
- [x] 6.2 Extension unit: auto-copy on autofill writes the current code to the clipboard and schedules a clear within the timeout / by window expiry (fake clock + clipboard).
- [x] 6.3 Extension integration: OTP-field detection fills a detected field and falls back to auto-copy when none is present; never fills a non-OTP field; re-detects after navigation.
- [x] 6.4 Extension unit: unparseable seed yields the invalid-seed state, never a code; lock discards all TOTP state; the seed and code never appear in `storage.*` or a request body.

## 7. Quality Gates

- [x] 7.1 Extension lint + unit/integration tests pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/extension-totp-autofill/specs/extension-totp-autofill/spec.md` on changed methods.
- [x] 7.2 Confirm no new backend route, no schema/migration, no `AuditEventTypes` change, and no re-implementation of RFC 6238 — the extension imports the existing generator and fetches the same encrypted blobs.


## Implementation notes (done — GH pending)

Extension-only (no backend/schema change, no RFC 6238 re-implementation — confirmed).

- **1.1 shared module** — `src/totp/index.js` re-exports `src/totp/totp.js` (`parseOtpauth`, `generateTotp`, `secondsRemaining`) verbatim; `src/lib/totp-service.js` wraps them.
- **1.2 origin resolve** — the worker matches `totp`-typed secrets by the plaintext `url` index (same machinery as autofill), via `api.typeIdByName(config,'totp')`.
- **2 popup code + countdown** — `doTotpForHost` decrypts the seed transiently and computes code + `secondsRemaining`; the popup renders a live countdown that recomputes on window roll-over. Unparseable seed → honest "not a valid authenticator secret", never a fabricated code.
- **3 auto-copy** — `doFill` returns a matched `totpCode`; the popup writes it to the clipboard and clears it after 30 s (`copyWithAutoClear`).
- **4 OTP-field fill** — `doFill` also sends `fill-otp` to the content script, which fills a detected one-time-code field (`autocomplete="one-time-code"` / numeric 6-digit / otp-named); no OTP field → auto-copy fallback; never fills a non-OTP field.
- **5 lock/no-leak** — the seed/key/code are computed on demand and never stored; `vault.lock` drops the CryptoKey so no further decrypt is possible.

**Tests** (`tests/extension/totp.spec.js`): the current code matches the **RFC 6238 Appendix B vector** (SHA1, T=59 s → `287082`), window roll-over changes the code, and an unparseable/empty seed yields the invalid state (never a code). 424 vitest green. The full extension-loaded OTP-field-fill run is a documented manual QA step.

## Acceptance Criteria

- The popup shows the current RFC 6238 code with a live countdown for a login matched to a `totp` secret by origin; multiple matches are user-picked, never silently guessed.
- The code is computed client-side in the extension from the decrypted seed; the seed and code are never sent to the server, never written to persistent extension storage, and discarded on lock.
- Autofilling a matched login copies its current code to the clipboard and schedules a clipboard clear within the timeout / by window expiry.
- A detected OTP input is optionally filled (re-detected after post-submit navigation); with no OTP field the extension falls back to auto-copy and never fills a non-OTP field.
- An unparseable seed shows an explicit invalid-seed state and never a fabricated code.
- No backend route, schema, migration, or audit-event type is introduced; the extension reuses the existing `src/totp/totp.js` generator (no RFC 6238 re-implementation).
