## 0. Scope Note (read first)

Add a `totp` system secret type and a client-side RFC 6238 code generator. The seed lives in the existing encrypted `key` field — **no new column, no migration, no server-side TOTP logic**. Code generation is browser-only and never touches the server, mirroring the `password-health` no-leak contract. Verify against HEAD: `SeedSecretTypes::SYSTEM_TYPES`, the encryption-suites Session Mechanism (`CryptoKey`), the password-health engine's lock-discard + no-leak patterns, and the `secret-import` field mappers before coding.

## 1. Backend — system type seed

- [ ] 1.1 Add `'totp' => 'Authenticator (TOTP)'` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the existing `TYPE_NAMESPACE`, like the other six). Confirm the repair step remains idempotent.
- [ ] 1.2 Confirm no schema/migration change is needed — the seed rides in the existing `key` ciphertext blob; the `totp` type is a UI hint per the secrets spec.

## 2. Frontend — TOTP parser + generator (client-side only)

- [ ] 2.1 Add an `otpauth://totp` / bare-base32 parser: extract base32 `secret`, `algorithm` (SHA1 default | SHA256 | SHA512), `digits` (6 default | 8), `period` (30 default); a bare base32 string uses the RFC 6238 defaults.
- [ ] 2.2 Add an RFC 6238 code computer using WebCrypto (`crypto.subtle.importKey` HMAC + `sign`, RFC 4226 dynamic truncation to `digits`). Never derive/hold the HMAC key or code anywhere but in-memory.
- [ ] 2.3 Add a TOTP display component (secret detail, and optionally list row) that renders the code + a live countdown ring to the next window + copy-to-clipboard, gated on the vault being unlocked.
- [ ] 2.4 Invalid/unparseable seed → explicit "not a valid authenticator secret" state; never render a code (design D5).
- [ ] 2.5 Wire discard-on-lock: on vault lock / session timeout / all-tabs-closed, drop all seeds, derived keys, codes, and countdown timers (reuse the password-health lock hook pattern).
- [ ] 2.6 Ensure the seed/derived-key/code never enter any HTTP request body or `localStorage`/`sessionStorage`/IndexedDB.

## 3. Import mapping (extends secret-import)

- [ ] 3.1 In the `secret-import` client-side mapper, route a present TOTP/otp field (Bitwarden `login.totp`, KeePass 2.x `otp`/`TimeOtp-Secret`) into the `key` of a `totp`-typed secret, encrypted in the browser like every other imported field (plaintext never sent to the server).

## 4. Password-health guard

- [ ] 4.1 Exclude `totp`-typed secrets' `key` from the health engine's strength, reuse, and breach analysis (design D7).

## 5. Tests

- [ ] 5.1 vitest: RFC 6238 published SHA1 test vectors produce the expected codes at their fixed timestamps; SHA256/SHA512 vectors if used.
- [ ] 5.2 vitest: `otpauth://` parsing handles issuer/account/algorithm/digits/period variants and a bare base32 secret; invalid seeds yield the error branch, never a code.
- [ ] 5.3 vitest: no-leak guard — no HTTP request body and no browser-storage write from the generator contains the seed, derived key, or code.
- [ ] 5.4 vitest: lock discards all TOTP state (seeds, keys, codes, timers).
- [ ] 5.5 vitest/PHPUnit: a `totp` secret round-trips create → read → share → export with the seed staying ciphertext server-side; the health engine skips `totp` secrets.
- [ ] 5.6 PHPUnit: `SeedSecretTypes` seeds `totp` with a stable deterministic UUID and is idempotent.

## 6. Quality Gates

- [ ] 6.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix any pre-existing issues in touched files in the same batch.
- [ ] 6.2 Frontend lint + vitest pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/add-totp-secrets/specs/secrets/spec.md` on changed methods.
- [ ] 6.3 Confirm no route and no `AuditEventTypes` change; a `totp` secret uses the existing secret CRUD + audit events unchanged.

## Acceptance Criteria

- `totp` ("Authenticator (TOTP)") is a seeded seventh system type with a stable deterministic UUID; no schema/migration change was introduced.
- A `totp` secret's seed is stored as ciphertext in the existing `key` field; the server cannot distinguish it from any other secret.
- The current one-time code is generated in the browser per RFC 6238 (matching published test vectors) and displayed with a live countdown and copy action, only while the vault is unlocked.
- The seed, derived HMAC key, and generated code are never transmitted to the server or written to browser storage, and are discarded on vault lock.
- An unparseable seed shows an explicit error state and never a fabricated code.
- Bitwarden/KeePass TOTP fields import into `totp` secrets with the seed encrypted client-side.
- TOTP seeds are excluded from password-health analysis.
- `totp` secrets carry through user sharing, link sharing, export, and audit unchanged (seed remains ciphertext).
