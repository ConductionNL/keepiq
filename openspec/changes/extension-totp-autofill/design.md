# Design — extension-totp-autofill

## Context

The archived `add-totp-secrets` change already established everything server-side and web-side for TOTP: a `totp` system secret type whose seed rides as ciphertext in the existing `key` field (`lib/Repair/SeedSecretTypes.php:69`), a pure client-side RFC 6238 parser + generator (`src/totp/totp.js` — `parseOtpauth`, `generateTotp`, `secondsRemaining`), and a display component with a countdown and copy, gated on the vault being unlocked and discarding all state on lock (`src/components/TotpDisplay.vue`). All of that is only reachable in the **web UI**.

`browser-extension-autofill` ships the MV3 extension runtime — popup, in-extension unlock holding the `CryptoKey` in the service worker, URL matching over the unencrypted `url`/`name` fields, decrypt-on-demand, and autofill (`openspec/changes/browser-extension-autofill/design.md:83`–`:88`) — and explicitly listed TOTP autofill as a non-goal to be a follow-up (`openspec/changes/browser-extension-autofill/design.md:24`). This change is that follow-up: a thin extension-side surface that reuses both the existing generator and the existing extension runtime. No new crypto, no new backend.

The trust model is unchanged (ADR-003): the seed is decrypted only in the extension's service-worker memory, the code is computed client-side, and neither the seed nor the code is ever sent to the server — identical to the web UI's guarantee.

## Goals / Non-Goals

**Goals:**
- Show the current RFC 6238 code with a live countdown in the extension popup for a login matched to a `totp` secret by origin.
- Auto-copy the code to the clipboard after autofilling credentials, with a scheduled clipboard clear.
- Optionally fill the code into a detected OTP input field, re-detecting after navigation.
- Compute the code entirely client-side from the decrypted seed, which never leaves the extension; discard all TOTP state on lock.

**Non-Goals:**
- In-browser QR scanning to add a seed; editing/creating TOTP secrets from the extension (managed in the web UI).
- HOTP (counter-based) codes — TOTP only, mirroring `add-totp-secrets`.
- Any server-side code computation or seed exposure.
- A schema link field between a login and its TOTP secret (association is by origin match).

## Decisions

### D1 — Reuse the existing `src/totp/totp.js` generator as the extension's shared TOTP module
The extension imports the same `parseOtpauth`/`generateTotp`/`secondsRemaining` functions the web UI uses, honouring ADR-003's dual-implementation invariant by having a *single* implementation rather than a second one. This eliminates drift and re-uses the already-tested RFC 6238 code path. *Rejected alternative:* a fresh RFC 6238 implementation in the extension — needless duplication and a drift risk against the published test vectors the web module already passes.

### D2 — Associate a TOTP secret to a login by origin match, not a link field
There is no field linking a `login` secret to its `totp` secret. The extension treats a `totp` secret as the active login's authenticator when its unencrypted `url`/`name` matches the active tab's origin — the exact matching machinery autofill already uses (`browser-extension-autofill` URL-matched listing, ADR-003 `:57`). If multiple `totp` secrets match one origin, the popup lists them; the user picks. *Rejected alternative:* inventing a `totpSecretId` link field on the login secret — a schema change (ADR-001) for a v1 convenience the origin match already delivers.

### D3 — Auto-copy on autofill, with a scheduled clipboard clear
When the user autofills a login that has a matched `totp` secret, the extension computes the current code in the service worker and copies it to the clipboard (Bitwarden-premium behavior, free here), then schedules a clipboard clear after a short timeout (default ~30s, and cleared no later than the code's window expiry) to avoid leaving the code resident. The clipboard write is a deliberate, user-triggered exposure — the one place the code leaves the extension's memory — and is documented as such. *Rejected alternative:* never touching the clipboard (forcing manual copy from the popup) — loses the cited convenience; or copying without a clear — leaves the code resident indefinitely.

### D4 — OTP-field fill is best-effort and re-detected after navigation
Where the current page exposes a recognisable one-time-code input (`autocomplete="one-time-code"`, numeric `inputmode`, or heuristic OTP field names), the content script MAY fill the current code directly. Because 2FA prompts commonly appear on a **post-submit** page, the content script re-runs detection after navigation within the matched origin. Where no OTP field is found, auto-copy (D3) is the fallback so the user always has the code one paste away. *Rejected alternative:* filling only on the initial page — misses the common two-step login where the OTP field appears after the password submit.

### D5 — Honest invalid-seed state and discard-on-lock, reusing the web contract
An unparseable `totp` seed shows a "not a valid authenticator secret" state and never a fabricated code, and the seed, derived HMAC key, code, and countdown timers are discarded when the extension locks — reusing the web UI's no-leak / honest-error contract (`add-totp-secrets`) and the extension's lock semantics (`browser-extension-autofill`). *Rejected alternative:* a best-guess code on a malformed seed — the same dishonesty the web UI already forbids.

## Declarative-vs-imperative decision

Imperative, per ADR-001: Doriath owns its own tables and does **not** use OpenRegister. There is no declarative schema/register layer. The surface is standalone MV3 JS/TS in the `browser-extension/` workspace consuming the existing blob endpoints and the existing `src/totp/totp.js` module; no OR schema, no seed-data register, no new persistence.

## Data model deltas

**None.** No new table, column, or migration (ADR-001). The `totp` type is already seeded (`lib/Repair/SeedSecretTypes.php:69`); TOTP secrets are fetched as the same encrypted blobs the extension already fetches. No link field is added between a login and its TOTP secret — the association is computed by origin match at display time. Nothing is persisted by this change.

## Extension architecture (MV3)

- **Service worker**: on an autofill for a matched login, resolves the matching `totp` secret(s) by origin, decrypts the seed on demand with the in-memory `CryptoKey`, computes the current code with the shared `generateTotp`, and (a) returns it to the popup for display and (b) writes it to the clipboard and schedules the clear (D3). Holds the seed/derived key/code only transiently in memory; drops them on lock.
- **Popup UI**: renders a TOTP row for a matched login — the current 6-digit code and a live countdown (`secondsRemaining`) — and a copy action; shows the honest invalid-seed state where applicable. Recomputes the code as each window rolls over while open.
- **Content script** (reusing the autofill `all_frames` registration): best-effort OTP-field detection and fill (D4), re-detecting after in-origin navigation; requests the current code from the service worker via runtime messaging when an OTP field is present.
- **Message passing**: popup ⇄ service worker and content script ⇄ service worker via `chrome.runtime` messaging; the seed and code never transit the page except as a filled value the user chose. The countdown timer lives in the popup/worker, never in page context.
- **Shared crypto**: imports `src/totp/totp.js` (`parseOtpauth`, `generateTotp`, `secondsRemaining`) — one implementation across web + extension.

## Surface flows

**Popup display** — popup lists a credential for the active origin → service worker finds a `totp` secret whose `url`/`name` matches → decrypts the seed, computes the code + `secondsRemaining` → popup shows the 6-digit code and countdown, recomputing per window.

**Auto-copy on autofill** — user autofills the matched login → service worker computes the current code → writes it to the clipboard → schedules a clipboard clear (~30s / by window expiry).

**OTP-field fill** — content script detects a one-time-code input on the current or post-submit page → requests the code from the service worker → fills it → falls back to auto-copy when no field is present.

## Risks / Trade-offs

- **Clipboard residency of the code** → deliberate, user-triggered exposure; mitigated by a scheduled clear and by clearing no later than window expiry; documented so the user understands the tradeoff.
- **Wrong TOTP matched to a login** (multiple authenticators for one origin) → the popup lists all origin matches and the user picks; auto-copy uses the selected/only match, never a silent wrong guess.
- **OTP-field mis-detection** → best-effort only; on no confident match the extension does not fill and relies on auto-copy, never filling a non-OTP field.
- **Seed leakage** → the seed and derived HMAC key live only in service-worker memory, never in `storage.*`, never in a request body; discarded on lock (reuses the web no-leak contract).
- **Generator drift** → avoided by importing the single `src/totp/totp.js` module rather than re-implementing RFC 6238.

## Decisions made under uncertainty

1. **Reuse the existing `src/totp/totp.js` RFC 6238 module** as the extension's TOTP crypto rather than re-implementing — chosen to satisfy ADR-003's dual-implementation invariant with one tested implementation and zero drift.
2. **Associate TOTP to a login by origin match** (unencrypted `url`/`name`) rather than adding a link field — chosen to avoid a schema change (ADR-001) for a v1 convenience the existing matching already provides; multiple matches are user-picked.
3. **Auto-copy the code on autofill with a scheduled clipboard clear** (default ~30s / by window expiry) — chosen to deliver the cited Bitwarden-premium convenience while bounding clipboard residency; the exposure is documented.
4. **Best-effort OTP-field fill with post-navigation re-detection**, falling back to auto-copy — chosen because 2FA fields commonly appear on a post-submit page and cannot be reliably targeted up front.
5. **Honest invalid-seed state and discard-on-lock**, reusing the web UI's contract — chosen to keep the extension's TOTP behavior identical to the already-shipped web behavior.
6. **QR scanning, TOTP editing/creation, and HOTP excluded** — TOTP secrets are managed in the web UI; the extension only surfaces existing seeds.
