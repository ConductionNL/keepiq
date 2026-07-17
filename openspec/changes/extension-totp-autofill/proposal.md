---
kind: code
depends_on: [browser-extension-autofill]
---

# Proposal: Extension TOTP surfacing + auto-copy on autofill

## Why

Doriath already **stores** TOTP seeds and **generates** the current 6-digit code — the archived `add-totp-secrets` change added the `totp` system type (seed as an `otpauth://totp` URI or bare base32 in the encrypted `key`, `lib/Repair/SeedSecretTypes.php:69`) and a full client-side RFC 6238 generator (`src/totp/totp.js:199` `generateTotp`, `src/components/TotpDisplay.vue`). But that code is only visible in the **web UI**: a user who autofills a login through the browser extension still has to open the Doriath web app, find the matching authenticator secret, read the code, and type it — a second-surface gap exactly like the one autofill itself closed for passwords.

Surfacing the code where the login is being filled is the pattern users cite:

- **Bitwarden makes auto-copy-TOTP-on-autofill a premium feature; Doriath can ship it free.** Bitwarden's premium extension copies the current TOTP code to the clipboard the moment it autofills credentials, so the code is one paste away on the 2FA prompt. Doriath's seed and generator already exist client-side, so the same convenience is a pure extension-side addition with no new storage.
- **One-app credential + TOTP flow is the oldest, most-discussed platform wish.** NC Passwords issue **#69** is the oldest and among the most-discussed feature requests (**43 comments**) for exactly this: keeping the login and its one-time code in one app and one flow. Doriath's own analysis already counts TOTP among tier-1 platform expectations and notes it is "built in Doriath" (`docs/FEATURES.md:499`), yet the extension — the daily-driver surface — cannot show a code.
- **The extension already has the runtime.** `browser-extension-autofill` ships the MV3 popup, the in-extension unlock, URL matching on the unencrypted `url`/`name` fields, and autofill; it explicitly left TOTP autofill out of scope (`openspec/changes/browser-extension-autofill/design.md:24`) as a follow-up. This change fills that follow-up seam.

Zero-knowledge is preserved with no server involvement: the TOTP seed is decrypted only in the extension, the code is computed client-side per RFC 6238 (the same algorithm `src/totp/totp.js` already implements, re-used as the extension's shared crypto module per ADR-003's dual-implementation invariant), and the seed never leaves the extension decrypted — the server never sees the seed or the code, exactly as the web UI already guarantees.

## What Changes

- **Surface the current TOTP code in the extension popup for a matched login.** When the popup lists a credential for the active tab's origin, and a `totp`-typed secret matches the same origin (by the unencrypted `url`/`name` fields, ADR-003 `:57`), the popup MUST show the current 6-digit code with a live countdown to the next window — decrypting the seed and computing the code entirely in the extension (RFC 6238, reusing the `src/totp/totp.js` parser + generator as the extension's shared module).
- **Auto-copy the code to the clipboard after autofilling credentials.** When the user autofills a login that has a matched TOTP secret, the extension MUST copy the current code to the clipboard so it is one paste away on the site's 2FA prompt (the Bitwarden-premium behavior, free here), and MUST schedule a clipboard clear after a short timeout to avoid leaving the code resident.
- **Optionally fill the code into a detected OTP input field.** When the current page (or the post-submit page) exposes a one-time-code input (`autocomplete="one-time-code"`, numeric `inputmode`, or a recognised OTP field), the extension MAY fill the current code directly, re-detecting after navigation; where no OTP field is present, auto-copy is the fallback.
- **Match TOTP to the login by origin.** A `totp` secret is treated as the login's authenticator when its unencrypted `url`/`name` matches the active origin — the same matching machinery autofill already uses; there is no schema link field between a login and its TOTP secret, so origin match is the v1 association.
- **Reject unparseable seeds honestly, discard on lock.** An unparseable `totp` seed MUST show a "not a valid authenticator secret" state and never a fabricated code (mirroring the web UI); the seed, derived HMAC key, code, and timers MUST be discarded when the extension locks — reusing the extension's lock semantics and the web UI's no-leak contract.
- **Explicitly out of scope:** in-browser QR scanning to add a seed, HOTP (counter-based) codes, editing/creating TOTP secrets from the extension (they are managed in the web UI), and any server-side code computation.

## Capabilities

### New Capabilities

- `extension-totp-autofill`: Surfaces TOTP in the browser extension — showing the current RFC 6238 code with a countdown in the popup for a login matched to a `totp` secret by origin, auto-copying the code to the clipboard after autofilling credentials (with a scheduled clipboard clear), and optionally filling it into a detected OTP input — all computed client-side in the extension from the decrypted seed, which never leaves the extension. The canonical home for the extension's TOTP surface that `browser-extension-autofill` deferred.

### Modified Capabilities

<!-- None. The seed storage, the `totp` type, and the RFC 6238 generator already exist (add-totp-secrets, archived); this change adds a new extension surface consuming the existing blob endpoints and the existing generator. No existing requirement's behavior changes. -->

## Impact

- **Extension workspace (`browser-extension/`)**: the popup gains a TOTP row (code + countdown) for a matched login; the autofill action gains an auto-copy-code + scheduled-clipboard-clear step; the content script gains best-effort OTP-field detection and fill. Reuses the existing unlock, URL matching, and decrypt-on-demand machinery (`openspec/changes/browser-extension-autofill/design.md:83`).
- **Shared crypto**: the extension imports the existing `src/totp/totp.js` parser + RFC 6238 generator (`generateTotp`, `secondsRemaining`) as its TOTP module, honouring ADR-003's dual-implementation invariant — no re-implementation, no drift.
- **Backend**: none — no new route, no schema/migration. TOTP secrets are fetched as the same encrypted blobs the extension already fetches; codes are computed in the extension.
- **Data model**: none (ADR-001, imperative, own tables). No link field is added between a login and its TOTP secret; association is by origin match.
- **Security**: zero-knowledge preserved — the seed is decrypted only in the extension, the code is computed client-side, and the seed/derived key/code are never sent to the server nor written to persistent extension storage, and are discarded on lock. The clipboard auto-copy is a deliberate, user-triggered exposure mitigated by a scheduled clear; this tradeoff is documented.
- **OpenConnector**: unaffected — TOTP surfacing is a browser-user convenience, separate from the machine secret-store path.
- **Docs**: extension guide note that TOTP codes are generated in the extension and auto-copied on autofill, and that the clipboard is cleared after a timeout.
