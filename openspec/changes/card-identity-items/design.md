# Design — card-identity-items

## Context

Doriath is an always-E2E vault (ADR-003): the server stores only ciphertext, and the master-password-derived AES key plus the decrypted RSA private key live only in the browser as a non-extractable WebCrypto `CryptoKey` (encryption-suites "Session Mechanism"). A secret's sensitive value lives in the encrypted `key` field; only `name`, `url`, `type_id`, and `folder_id` are plaintext (`openspec/specs/secrets/spec.md:55-73`). `SecretType` is explicitly "a UI hint only — it drives how the UI labels and presents fields but does not affect server-side validation or the underlying data model" (secrets spec, "Secret Types" requirement).

The archived `add-totp-secrets` change established the exact template this change follows: add a system type by adding one entry to `SeedSecretTypes::SYSTEM_TYPES`, store the type-specific payload as ciphertext in the existing `key` field (no new column, no migration), and render a type-specific presentation component gated on the unlocked vault. `totp` stored a structured `otpauth://` URI string in `key`; `card` and `identity` store a structured **JSON object** in `key` — the same "one opaque ciphertext blob to the server" shape.

## Decisions

### D1 — Two new *system* types (`card`, `identity`), UI hints only
Add `'card' => 'Payment Card'` and `'identity' => 'Identity'` to `SeedSecretTypes::SYSTEM_TYPES` (`lib/Repair/SeedSecretTypes.php:62-69`), each with a deterministic UUIDv5 under the existing `TYPE_NAMESPACE`, exactly like the other seven. This is the whole backend change. The type tells the UI which field set and presentation to render; the server treats the secret like any other. Rejected: a dedicated table/columns per type — it would fork every share/export/import/audit path and leak the *existence* of card/identity data to the server (a new non-null column is observable), weakening zero-knowledge. This is the same reasoning that rejected a `totp_seed` column (`add-totp-secrets` design D1).

### D2 — Composite payload is a JSON object in the existing encrypted `key` field
The sensitive multi-field payload is serialized to JSON, then encrypted into `key` exactly like any secret value:

- **`card`** → `key` decrypts to `{ "number": string, "expiry": string, "cvv": string, "pin": string|null, "cardholder": string|null }` (expiry as `"MM/YY"`).
- **`identity`** → `key` decrypts to `{ "firstName": string, "lastName": string, "address": string|null, "phone": string|null, "email": string|null, "bsn": string|null }`.

To all server-side paths this is one opaque ciphertext string, so create/read/update, user sharing (re-encrypt to recipient public cert), link-share snapshot, encrypted backup export, GDPR export, and audit denormalization carry it **unchanged** — the same zero-fork benefit `totp` gained (design D1 there). Rejected: spreading fields across `key` + encrypted `login` + encrypted `additional_fields`. While `login`/`additional_fields` are also encrypted, splitting the payload would scatter the type's contract across three columns and complicate the import/share/export round-trip; a single JSON-in-`key` payload is atomic and matches the `totp`-in-`key` precedent. (`login` MAY still be populated with the cardholder/full-name for cross-type list consistency, but it is a denormalized convenience copy, never the source of truth.)

### D3 — Ciphertext-vs-metadata: everything is ciphertext; brand + last-4 are *derived*, never stored
This is the load-bearing decision. **Every field a card or identity carries is ciphertext inside `key`.** Nothing new is stored in plaintext.

- **Card `number`, `cvv`, `pin`** — ciphertext; the most sensitive fields (PCI/financial). Masked by default in the UI with individual reveal + copy.
- **Card `expiry`, `cardholder`** — ciphertext; shown directly when the vault is unlocked (low reveal risk, high utility), no extra mask.
- **Card brand (Visa/Mastercard/…) and last-4** — **NOT stored at all**. They are computed in the browser from the decrypted `number`: brand via the IIN/BIN prefix, last-4 via the final four digits. They are shown as non-sensitive display metadata (e.g. `Visa •••• 1111`) only while unlocked. Because they are derived, the server never sees even the last-4, and no migration/column is needed.
- **Identity `bsn`** — ciphertext, **MUST** be so: the Dutch citizen service number is sensitive personal data under the AVG/GDPR. Masked by default with reveal + copy.
- **Identity `firstName`, `lastName`, `address`, `phone`, `email`** — ciphertext; shown directly when unlocked (they are PII but low-shock, and the whole payload is already E2E-encrypted at rest).

Rejected alternative: a plaintext `last4`/`brand` column so a *locked* list can show `Visa •••• 1111`. Rejected because it stores a card fingerprint on the server (a re-identification vector) and needs a migration — for a cosmetic locked-state gain the user already covers by naming the secret ("Personal Visa"). Derivation on unlock is strictly more private.

### D4 — Type-specific presentation reuses the existing type-branch + component pattern
`SecretDetail.vue` already branches on the secret's type (`keyLabel()`, `isTotp()`, `src/views/SecretDetail.vue:239-261`) and delegates `totp` rendering to `src/components/TotpDisplay.vue`. This change adds `isCard`/`isIdentity` computeds and (optionally) `CardDisplay.vue` / `IdentityDisplay.vue` components that parse the decrypted `key` JSON and render labelled fields. Create/edit dialogs (`SecretCreateDialog.vue`, `SecretEditDialog.vue`) render the per-type field set driven by the selected type — the same `typeOptions` machinery already present (`src/dialogs/SecretCreateDialog.vue:144-149`). The masked sub-fields reuse `PasswordField.vue`'s eye-toggle (`src/components/PasswordField.vue:5-12`) and `CopyButton.vue`.

### D5 — Import maps composite fields into the encrypted value
The `secret-import` client-side mapper normalizes every source format to `{ name, url, login, password, additionalFields, folder, type }` (`src/import/model.js:34-49`), then encrypts in the browser before commit (plaintext never sent to the server). For `card`/`identity` rows the mapper serializes the composite payload as the row's encrypted value (routed into `key`) and stamps the resolved `card`/`identity` type id — a field-mapping addition to the existing capability, not a new import path. This is the same shape `totp` used to route a seed into `key` (`add-totp-secrets` design D6).

### D6 — CXF entity ↔ Doriath type alignment (stated here; `cxf-import-export` adopts it)
The `cxf-import-export` mapping table (`openspec/changes/cxf-import-export/design.md:35-45`) currently has no card/identity row. This change defines the 1:1 mapping so that change can extend its table without editing this one. **This change does NOT edit `cxf-import-export`'s files.**

| CXF credential entity | Doriath secret type | Field mapping (import direction) |
|-----------------------|---------------------|----------------------------------|
| Credit-card / payment-card | `card` | number → `key.number`, expiryDate → `key.expiry`, verificationNumber (CVV) → `key.cvv`, pin → `key.pin`, cardholderName → `key.cardholder`; brand/last-4 derived on read (not carried) |
| Identity-document / person identity | `identity` | firstName/givenName → `key.firstName`, lastName/familyName → `key.lastName`, address → `key.address`, phone → `key.phone`, email → `key.email`, nationalId/BSN → `key.bsn` |

Export reverses each row. CXF fields with no Doriath home, and Doriath fields with no CXF home, go to `cxf-import-export`'s existing unmapped-item report (that change's D4) — never silently dropped. Card brand/last-4 are derived, so they are recomputed on the far side from the number and need no CXF carrier.

### Declarative-vs-imperative decision
Imperative, per ADR-001 (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns its own tables and does **not** use OpenRegister. The two types are seeded as rows in Doriath's own `doriath_secret_types` table via the existing repair step; card/identity secrets are ordinary rows in `doriath_secrets`. There is no OR register, schema, or seed data involved.

## Decisions made under uncertainty

- **JSON-in-`key` vs. spread across encrypted columns.** Chose a single JSON object in `key` (D2) for an atomic, round-trippable payload matching the `totp`-in-`key` precedent. If a future need arises to search/index one sub-field without decryption, that would be a separate, deliberate change (and would trade away zero-knowledge for that field).
- **RSA chunk cap for large payloads.** ADR-003 notes an RSA cap of ~500 bytes per chunk; a fully-populated identity payload (address + all fields) can exceed one chunk. Decision: reuse the existing chunked-encryption path already used for `additional_fields` (secrets spec Notes) — the payload MUST encrypt/decrypt through that path, not a single RSA block. No new chunking logic is invented.
- **Brand detection scope.** IIN/BIN ranges evolve. Decision: detect the common brands (Visa, Mastercard, Amex, Discover, and generically fall back to "Card") from the leading digits; an unrecognized prefix shows "Card" plus the last-4 rather than a wrong brand — never fabricate a brand, mirroring `totp`'s "never show a fabricated code" honesty (`add-totp-secrets` design D5).
- **CXF entity/field names may drift.** CXF reached Proposed Standard in Aug 2025; credit-card / identity-document entity and field naming may still change. Decision: state the mapping semantically (D6) and let `cxf-import-export` isolate the exact field names in its single mapping module (that change's uncertainty note) — a later revision touches only that module.
- **Luhn / expiry validation.** Decision: any format hinting (Luhn check, expiry in the past) is client-side, best-effort, and non-blocking — the server never validates a PAN, and a user may legitimately store a test/placeholder card. No server-side card semantics are introduced.
- **`identity` name granularity.** Chose `firstName` + `lastName` (plus free-text `address`) over a fully structured postal address (street/city/postcode/country). Rationale: covers the common case and CXF's identity-document fields without over-modelling; a structured address can be an additive change if a real import format demands it.

## Risks / Trade-offs

- **On-screen exposure of card/BSN.** Showing a decrypted PAN, CVV, PIN, or BSN is by design exposing sensitive material; bounded by masked-by-default reveal and the vault lock — the same exposure any password manager's card view accepts. No new *server* exposure is introduced.
- **Derived brand/last-4 only when unlocked.** A locked list cannot show `•••• 1111` — it shows the user-chosen `name`. Accepted trade-off for strictly better privacy (D3).
- **Payload size / chunking.** A large identity payload crosses the RSA chunk boundary; mitigated by reusing the existing chunked path (uncertainty note above), asserted by a round-trip test.

## Migration / Rollout

- Two new seeded system-type rows via the existing `SeedSecretTypes` repair step (idempotent; deterministic UUIDv5). No data migration — pre-existing secrets are unaffected; users opt in by creating `card`/`identity` secrets or importing them. `cxf-import-export` can adopt the D6 mapping independently once these types are seeded.
