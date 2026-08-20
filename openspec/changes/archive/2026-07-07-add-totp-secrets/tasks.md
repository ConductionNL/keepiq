## 0. Scope Note (read first)

Add a `totp` system secret type and a client-side RFC 6238 code generator. The seed lives in the existing encrypted `key` field — **no new column, no migration, no server-side TOTP logic**. Code generation is browser-only and never touches the server, mirroring the `password-health` no-leak contract. Verify against HEAD: `SeedSecretTypes::SYSTEM_TYPES`, the encryption-suites Session Mechanism (`CryptoKey`), the password-health engine's lock-discard + no-leak patterns, and the `secret-import` field mappers before coding.

**Implementation note (applied 2026-07-07):** Core module `src/totp/totp.js` (base32 decode + `otpauth://` parser + RFC 6238/4226 WebCrypto generator); `src/components/TotpDisplay.vue` (code + countdown ring + copy, gated on unlocked vault, discard-on-lock via `onVaultLock` + `beforeDestroy`); wired into `src/views/SecretDetail.vue`. Backend: `lib/Repair/SeedSecretTypes.php` adds `totp => 'Authenticator (TOTP)'` (deterministic UUIDv5, idempotent). Import: `lib/Service/ImportService.php` passes `typeId` through to `SecretService::create`; `src/store/modules/import.js` `expandTotpRows()` splits a seed carried in `additionalFields.totp` into a `totp`-typed row (seed → encrypted `key`), stamping the resolved totp type id. Health guard: `src/store/modules/health.js` excludes `totp`-typed secrets. **e2e status:** every TOTP spec Scenario carries `@e2e exclude` by design (they are cryptographic-vector / in-memory / wire-shape contracts, not DOM flows) — gate-19 is satisfied by those annotations and the assertions are covered by vitest (RFC 6238 published SHA1 vectors, parser variants, invalid-seed branch, no-leak request/storage guard, lock-discard, health exclusion) + the TotpDisplay component render test. A live Playwright run against a deployed instance is **deferred**: the worktree is not deployed and deploying to the shared dev instance is prohibited; the browser-observable behaviour (code renders, rotates, copy) is exercised by the jsdom component test.

## 1. Backend — system type seed

- [x] 1.1 Add `'totp' => 'Authenticator (TOTP)'` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the existing `TYPE_NAMESPACE`, like the other six). Confirm the repair step remains idempotent.
- [x] 1.2 Confirm no schema/migration change is needed — the seed rides in the existing `key` ciphertext blob; the `totp` type is a UI hint per the secrets spec.

## 2. Frontend — TOTP parser + generator (client-side only)

- [x] 2.1 Add an `otpauth://totp` / bare-base32 parser: extract base32 `secret`, `algorithm` (SHA1 default | SHA256 | SHA512), `digits` (6 default | 8), `period` (30 default); a bare base32 string uses the RFC 6238 defaults.
- [x] 2.2 Add an RFC 6238 code computer using WebCrypto (`crypto.subtle.importKey` HMAC + `sign`, RFC 4226 dynamic truncation to `digits`). Never derive/hold the HMAC key or code anywhere but in-memory.
- [x] 2.3 Add a TOTP display component (secret detail, and optionally list row) that renders the code + a live countdown ring to the next window + copy-to-clipboard, gated on the vault being unlocked.
- [x] 2.4 Invalid/unparseable seed → explicit "not a valid authenticator secret" state; never render a code (design D5).
- [x] 2.5 Wire discard-on-lock: on vault lock / session timeout / all-tabs-closed, drop all seeds, derived keys, codes, and countdown timers (reuse the password-health lock hook pattern).
- [x] 2.6 Ensure the seed/derived-key/code never enter any HTTP request body or `localStorage`/`sessionStorage`/IndexedDB.

## 3. Import mapping (extends secret-import)

- [x] 3.1 In the `secret-import` client-side mapper, route a present TOTP/otp field (Bitwarden `login.totp`, KeePass 2.x `otp`/`TimeOtp-Secret`) into the `key` of a `totp`-typed secret, encrypted in the browser like every other imported field (plaintext never sent to the server).

## 4. Password-health guard

- [x] 4.1 Exclude `totp`-typed secrets' `key` from the health engine's strength, reuse, and breach analysis (design D7).

## 5. Tests

- [x] 5.1 vitest: RFC 6238 published SHA1 test vectors produce the expected codes at their fixed timestamps; SHA256/SHA512 vectors if used.
- [x] 5.2 vitest: `otpauth://` parsing handles issuer/account/algorithm/digits/period variants and a bare base32 secret; invalid seeds yield the error branch, never a code.
- [x] 5.3 vitest: no-leak guard — no HTTP request body and no browser-storage write from the generator contains the seed, derived key, or code.
- [x] 5.4 vitest: lock discards all TOTP state (seeds, keys, codes, timers).
- [x] 5.5 vitest/PHPUnit: a `totp` secret round-trips create → read → share → export with the seed staying ciphertext server-side; the health engine skips `totp` secrets.
- [x] 5.6 PHPUnit: `SeedSecretTypes` seeds `totp` with a stable deterministic UUID and is idempotent.

## 6. Quality Gates

- [x] 6.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix any pre-existing issues in touched files in the same batch.
- [x] 6.2 Frontend lint + vitest pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/add-totp-secrets/specs/secrets/spec.md` on changed methods.
- [x] 6.3 Confirm no route and no `AuditEventTypes` change; a `totp` secret uses the existing secret CRUD + audit events unchanged.

## Acceptance Criteria

- `totp` ("Authenticator (TOTP)") is a seeded seventh system type with a stable deterministic UUID; no schema/migration change was introduced.
- A `totp` secret's seed is stored as ciphertext in the existing `key` field; the server cannot distinguish it from any other secret.
- The current one-time code is generated in the browser per RFC 6238 (matching published test vectors) and displayed with a live countdown and copy action, only while the vault is unlocked.
- The seed, derived HMAC key, and generated code are never transmitted to the server or written to browser storage, and are discarded on vault lock.
- An unparseable seed shows an explicit error state and never a fabricated code.
- Bitwarden/KeePass TOTP fields import into `totp` secrets with the seed encrypted client-side.
- TOTP seeds are excluded from password-health analysis.
- `totp` secrets carry through user sharing, link sharing, export, and audit unchanged (seed remains ciphertext).
