---
kind: code
---

## Why

Storing a TOTP (time-based one-time password, RFC 6238) secret alongside the login it protects, and generating the current 6-digit code in the vault UI, is a **table-stakes feature of every mainstream password manager** — KeePassXC, Bitwarden/Vaultwarden, 1Password, and Proton Pass all do it. Doriath does not: its six seeded system secret types are `login`, `api_key`, `ssh_key`, `certificate`, `note`, and `database`, and there is no TOTP type, no seed-storage guidance, and no code generation anywhere in `src/`. The team's own competitive analysis (`docs/FEATURES.md`) lists KeePassXC's TOTP and Bitwarden's authenticator among competitor strengths but never carries TOTP into Doriath's own feature matrix — it is an unlisted gap, not a deliberate exclusion.

Demand is real and specifically relevant to Doriath's market: the intelligence corpus shows two-factor / MFA / TOTP requirements across ~21 tenders and single-sign-on across ~220, and Dutch public-sector security baselines (BIO, and the "Information security" / "NIST security framework" requirement clusters, each 175–210 tenders) push MFA everywhere. A user who is told to enable 2FA on a service needs somewhere to keep the seed; today they either use a separate authenticator app (fragmenting their secrets) or paste the seed into a plain note field where no code is ever generated.

Critically, **TOTP fits Doriath's always-E2E architecture with zero server changes to the trust model.** A TOTP seed is just another secret value — it is encrypted with the owner's EncryptionSuite public certificate exactly like a password (the server only ever sees ciphertext, ADR-003), and the 6-digit code is a pure function of the decrypted seed and the current time, computed **entirely in the browser** from the in-memory `CryptoKey`-decrypted value. The server never sees the seed, never computes a code, and never learns whether a secret carries a TOTP seed. This is the same zero-knowledge posture the `password-health` capability already established for client-side-only computation over the unlocked vault.

## What Changes

- **Add a seventh system secret type `totp`** ("Authenticator (TOTP)") to the seeded system types (`SeedSecretTypes::SYSTEM_TYPES`, deterministic UUIDv5 like the other six). Per the secrets spec, secret type is a UI hint only — it changes presentation, not the storage model or server validation.
- **Store the TOTP seed in the existing encrypted `key` field**, as an `otpauth://totp/...` URI (the de-facto interchange format emitted by QR codes and every competitor's export). No new column, no schema migration: the seed is ciphertext in the `key` blob exactly like a password, so all existing encryption, sharing (re-encrypt-to-recipient), link-sharing snapshot, backup/export, and audit paths carry it unchanged. A secret of type `totp` MAY additionally use the `login`/`url` plaintext-metadata fields to name the account it belongs to.
- **Generate the current code client-side.** When a secret of type `totp` is viewed with the vault unlocked, the browser parses the decrypted `otpauth://` URI (issuer, account, algorithm SHA1/SHA256/SHA512, digits 6/8, period, base32 secret), computes the current one-time code per RFC 6238 using WebCrypto HMAC, displays it with a live countdown ring to the next window, and offers copy-to-clipboard. The plaintext seed, the derived HMAC key, and the generated code MUST NEVER be sent to the server or written to `localStorage`/`sessionStorage`/IndexedDB, and MUST be discarded when the vault locks — mirroring the `password-health` no-leak contract.
- **Reject unparseable seeds honestly.** A `totp` secret whose decrypted `key` is not a valid `otpauth://totp` URI (or a bare base32 secret) MUST show an inline "not a valid authenticator secret" state rather than a wrong or fabricated code.
- **Accept TOTP seeds on import.** The `secret-import` mappers (Bitwarden JSON/CSV, KeePass 2.x XML) already carry a TOTP/`otp` field per entry; the import path MUST map that field into the `key` of a `totp`-typed secret so migrating users keep their authenticator seeds. This extends the existing import capability's field mapping — no new import surface.
- **Unit/vitest tests**: RFC 6238 test vectors (the published SHA1 vectors) produce the expected codes; `otpauth://` parsing handles issuer/algorithm/digits/period variants and a bare base32 secret; an invalid seed yields the error state, never a code; the code/seed/HMAC-key never appear in any HTTP request body or browser storage; locking the vault discards TOTP state; a `totp`-typed secret round-trips through create/read/share/export with the seed staying ciphertext server-side.

### Non-Goals

- **No server-side TOTP.** The server never computes, validates, or stores a plaintext seed or code. There is no verification endpoint — Doriath stores *other services'* TOTP seeds; it does not become a 2FA provider for Nextcloud login.
- **No new column or migration.** The seed lives in the existing `key` ciphertext field.
- **No QR-code camera capture in this change.** Users paste the `otpauth://` URI or base32 secret (the string every provider offers next to the QR image). In-browser QR scanning can be a later additive change.
- **No HOTP (counter-based) support.** TOTP only; HOTP is rare and would need server-persisted counter state that conflicts with the snapshot/share model.
- **No change to `password-health`.** A TOTP seed is not a password and MUST be excluded from strength/reuse/breach analysis (it is high-entropy machine material) — verified as a guard, not a new feature.

## Capabilities

### Modified Capabilities

- `secrets`: MODIFIES the "Secret Types" requirement to add `totp` as the seventh system type and pin the seed's storage in the encrypted `key` field; ADDS a "Client-Side TOTP Code Generation" requirement. The `secrets` capability is the canonical home because it owns the secret type catalogue and the client-side crypto contract for secret fields; the code-generation requirement reuses the encryption-suites Session Mechanism (unlocked-vault `CryptoKey`) exactly as `password-health` does.

## Impact

- **Database**: none — no new column, no migration. `totp` is a seeded row in the existing `doriath_secret_types` table (via the existing `SeedSecretTypes` repair step).
- **Backend**: `lib/Repair/SeedSecretTypes.php` (one entry). No controller, no service-logic, no encryption change — the seed is an opaque ciphertext blob to the server.
- **Frontend**: a TOTP presentation component in the secret detail/list view (parse `otpauth://`, RFC 6238 code, countdown ring, copy), gated on the vault being unlocked; `secret-import` field mapping extended to route the seed into a `totp` secret's `key`.
- **API**: none — no new route; TOTP secrets are created/read/updated through the existing secret CRUD endpoints as ordinary encrypted secrets.
- **Cross-capability**: `secret-import` gains a seed→`key` mapping (additive); `link-sharing`, `secret-export`, `user-sharing`, and `secret-audit-trail` carry `totp` secrets unchanged because the seed is just ciphertext in `key`.
- **Security**: the seed enjoys the same E2E guarantees as any secret value — ciphertext at rest, decrypted only in the browser, code computed only in-session and never persisted or transmitted. The server-side zero-knowledge stance (ADR-003) is unchanged; the server cannot even distinguish a `totp` secret's ciphertext from a password's. No secret material enters audit entries (the existing whitelist already forbids `key`).
