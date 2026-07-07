# Design — add-totp-secrets

## Context

Doriath is an always-E2E vault (ADR-003): the server stores only ciphertext, and the master-password-derived AES key plus the decrypted RSA private key live only in the browser as a non-extractable WebCrypto `CryptoKey` (encryption-suites "Session Mechanism"). The `password-health` capability already proved the pattern of computing over the *decrypted* vault entirely client-side while guaranteeing nothing (plaintext, digests, verdicts) reaches the server or browser storage. TOTP code generation is the same shape: a pure function of a decrypted seed and the current time.

## Decisions

### D1 — The seed is stored in the existing encrypted `key` field, not a new column
A TOTP seed is a secret value with exactly the same confidentiality requirement as a password: it must be ciphertext at rest and decryptable only in the browser. The `Secret` entity's `key` field is already the RSA-encrypted blob for "the secret value". Reusing it means every existing path — create/read/update, user sharing (re-encrypt to recipient public cert), group sharing, link-share snapshot, encrypted backup export, GDPR export, and audit denormalization — carries a TOTP secret with **zero changes**, because to all of them it is an opaque ciphertext string. A new `totp_seed` column would fork all of those paths for no benefit and would leak the *existence* of a TOTP seed to the server (a new non-null column is observable), weakening the zero-knowledge posture. Rejected.

### D2 — `otpauth://totp/...` URI as the stored plaintext-before-encryption format
The `otpauth://` URI (Google Authenticator's Key Uri Format) is the universal interchange format: it is what QR codes encode, what Bitwarden/1Password/KeePass export, and it self-describes issuer, account, `algorithm`, `digits`, `period`, and the base32 `secret`. Storing the whole URI (encrypted) means the parameters travel with the seed and a shared/exported/imported TOTP secret reproduces identical codes anywhere. A bare base32 secret (no URI) is also accepted and treated as `algorithm=SHA1, digits=6, period=30` (the RFC 6238 defaults every provider uses when it only shows a "manual entry key").

### D3 — `totp` is a seventh *system* secret type (UI hint), not a new data model
Per the secrets spec, `SecretType` is explicitly "a UI hint only — it drives how the UI labels and presents fields but does not affect server-side validation or the underlying data model." Adding `totp` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the fixed namespace, like the other six) is the whole backend change. The type tells the UI "render the TOTP code component for this secret"; the server treats it like any other secret. Users could technically store an `otpauth://` seed under any type, but the `totp` type is what drives the generator UI and the import mapping target.

### D4 — Code generation is client-side, in-session, never persisted — reusing the password-health no-leak contract
The generator runs only while the vault is unlocked (`CryptoKey` in session). It parses the decrypted URI, base32-decodes the secret, computes `HMAC-<alg>(secret, floor(unixtime/period))` via `crypto.subtle.sign`, truncates per RFC 4226 dynamic truncation to `digits`, and renders the code plus a countdown to the next window. The seed, the imported HMAC `CryptoKey`, and the generated code MUST never be sent to the server, never written to `localStorage`/`sessionStorage`/IndexedDB, and MUST be dropped when the vault locks (session timeout, manual lock, all-tabs-closed) — identical to the `password-health` engine's discard-on-lock rule. This keeps the seam of "server sees only ciphertext" intact; a code is as sensitive as a live password and gets the same in-memory-only treatment.

### D5 — Invalid seeds fail visibly, never fabricate a code
If the decrypted `key` is not a parseable `otpauth://totp` URI or bare base32 secret, the UI shows an explicit "not a valid authenticator secret" state. A password manager that silently shows a plausible-but-wrong 6-digit code is worse than one that shows an error — the user would lock themselves out trusting a fabricated code. No guessing.

### D6 — Import maps existing TOTP fields into a `totp` secret's `key`
Bitwarden's JSON/CSV `login.totp` and KeePass 2.x XML `otp`/`TimeOtp-Secret` fields are standard export carriers. The `secret-import` client-side mapper (which already parses these formats, per the secret-import spec) routes a present TOTP/otp field into the `key` of a `totp`-typed secret, encrypting it in the browser like every other imported field. This preserves the import capability's E2E guarantee (plaintext never sent to the server) and lets migrating users keep working 2FA. This is a field-mapping addition to an existing capability, not a new import path.

### D7 — TOTP seeds are excluded from password-health analysis
A base32 TOTP seed is high-entropy machine material; scoring it for strength, flagging it as "reused" (identical seeds are impossible in practice but a shared secret would legitimately match its copies), or breach-checking it against HIBP is meaningless and noisy. The health engine MUST skip `totp`-typed secrets' `key` values. This is a guard added to the existing engine, asserted by test — not a new health feature.

## Risks / Trade-offs

- **Clock skew**: TOTP tolerates ±1 window on the verifying side; Doriath only *generates*, so it simply uses the browser clock. If the user's device clock is wrong the code will be wrong — this is inherent to every authenticator app and is documented, not solved here.
- **Seed exposure surface**: showing a live code is, by design, exposing 2FA material on screen; this is the same exposure any authenticator app accepts and is bounded by the vault lock. No new server exposure is introduced.
- **No QR scan in v1**: paste-only onboarding is a minor UX gap vs. camera capture; deferred as an additive follow-up because it needs camera-permission UX and a QR-decode dependency, and paste covers 100% of providers (every QR is accompanied by a manual key).

## Migration / Rollout

- One new seeded system type row via the existing `SeedSecretTypes` repair step (idempotent; deterministic UUID). No data migration — pre-existing secrets are unaffected; users opt in by creating `totp` secrets or importing seeds.
