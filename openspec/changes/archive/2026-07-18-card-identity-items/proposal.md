---
kind: code
---

# Proposal: Payment-card and identity secret types

## Why

Doriath's secret-type catalogue covers developer credentials well but ships **zero personal or financial types**. Verified: `lib/Repair/SeedSecretTypes.php:62-69` seeds exactly seven system types — `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, `totp` — and the canonical secrets spec (`openspec/specs/secrets/spec.md:152-158`) states "There are **seven** built-in system types". None of them models a payment card or a personal identity. A user who wants to keep a credit card or their name/address/BSN in the vault today must abuse `note` or `login`, so the fields are unlabelled, un-masked, and — critically — cannot round-trip through a portable-format import.

Demand is table-stakes across the competitor set: **Bitwarden, 1Password, and Proton Pass all ship "Card" and "Identity" item types as core free features** (`docs/FEATURES.md:36,40` names all three as the consumer benchmark Doriath is measured against), and the NC Passwords project has carried an open request for card/identity item types (issue #120, open since 2019). Migrating users bring this data with them: the **FIDO Credential Exchange Format (CXF)** — which the sibling `cxf-import-export` change adopts as a portable import/export format — defines dedicated credit-card and identity-document credential entities. Verified: the CXF↔Doriath mapping table (`openspec/changes/cxf-import-export/design.md:35-45`) has rows for login, passkey, totp, note, api_key, ssh_key, and Wi-Fi, but **no card and no identity row** — because the target types do not exist yet. Import fidelity for a real migrating user therefore requires these types to exist first. This is canonical feature `card-identity-items` (demand 52).

Like `totp` before it (archived `add-totp-secrets`), this fits Doriath's always-E2E architecture with **zero change to the trust model**: a card or identity payload is just another secret value, encrypted with the owner's EncryptionSuite public certificate and stored as ciphertext in the existing `key` field (ADR-003, `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md`). The server never sees the plaintext and cannot distinguish a card's ciphertext from a password's. **BSN (the Dutch citizen service number) is sensitive personal data under the AVG/GDPR and MUST be ciphertext** — which the E2E model already guarantees for anything in `key`.

## What Changes

- **Add two system secret types** to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the existing type namespace, like the other seven): `card` ("Payment Card") and `identity` ("Identity"). Per the secrets spec, a secret type is a **UI hint only** — it changes presentation, not the storage model or server validation.
- **Store the composite payload as a JSON object in the existing encrypted `key` field.** No new column, no schema migration — exactly the `totp` precedent (`SeedSecretTypes.php:56-69`, secrets spec "TOTP secret stores its seed as ciphertext" scenario). For `card`, `key` decrypts to `{number, expiry, cvv, pin, cardholder}`; for `identity`, `key` decrypts to `{firstName, lastName, address, phone, email, bsn}`. To the server it is one opaque ciphertext blob, so every existing path — create/read/update, user sharing (re-encrypt to recipient), link-share snapshot, backup/export, GDPR export, and audit denormalization — carries it **unchanged**.
- **Ciphertext-vs-metadata decision (documented in design):** *everything* card/identity carries is ciphertext in `key`; nothing new is stored in plaintext. Card **brand** and **last-4** are NOT persisted — they are **derived in the browser** from the decrypted number (brand via IIN/BIN prefix, last-4 via substring) and shown as non-sensitive display metadata only while the vault is unlocked. This keeps the zero-knowledge posture intact (the server never sees even the last-4) and avoids a migration.
- **Type-specific create/edit/display presentation** in the Vue frontend, mirroring the `keyLabel`/`isTotp` type-branching already in `src/views/SecretDetail.vue:239-261` and the dedicated `src/components/TotpDisplay.vue` component. The create/edit dialogs render the per-type field set; the detail view renders labelled fields with the correct masking.
- **Masked-reveal interactions** for the sensitive sub-fields, reusing the eye-toggle + copy pattern of `src/components/PasswordField.vue:5-12` and `src/components/CopyButton.vue`: card **number, CVV, and PIN** are masked by default with individual reveal + copy; **BSN** is masked by default with reveal + copy. Card brand, last-4, expiry, cardholder and identity name/address/phone/email are shown directly when the vault is unlocked (no additional mask).
- **Inclusion in shares, export, audit, and import mappings.** Sharing/export/audit carry the two types unchanged because the payload is ciphertext in `key` (the audit whitelist already forbids `key`, `lib/Service/AuditService.php:9-10`). The `secret-import` client-side mapper routes the composite fields into a `card`/`identity`-typed row's encrypted value (the normalized row model, `src/import/model.js:34-49`, already carries `password`→`key` and `additionalFields`).
- **Documented 1:1 CXF alignment** (in this change's design; the `cxf-import-export` change's files are NOT edited): CXF credit-card ↔ Doriath `card` and CXF identity-document ↔ Doriath `identity`, field-by-field, so `cxf-import-export` can extend its mapping table to map these entities without loss.

### Non-Goals

- **No server-side card/identity logic.** No PAN validation, no Luhn check, no CVV verification on the server — the payload is opaque ciphertext. Any Luhn/format hinting is a client-side, best-effort convenience only.
- **No new column or migration.** Both types ride the existing `key` ciphertext blob.
- **No plaintext card fingerprint.** Brand and last-4 are derived in-browser, never stored, never sent to the server.
- **No autofill / browser-extension card fill** in this change — that is the separate `browser-extension-autofill` capability's surface.
- **No editing of `cxf-import-export`.** The CXF mapping is stated here for that change to adopt; its files are not touched.

## Capabilities

### New Capabilities

- `card-identity-items`: the two new system secret types (`card`, `identity`), their encrypted-`key` JSON payload shape, the ciphertext-vs-derived-metadata rules, type-specific presentation and masked-reveal, and their behaviour across share/export/audit/import plus the CXF entity alignment.

## Impact

- **Database**: none — no new column, no migration. Two seeded rows in the existing `doriath_secret_types` table via the existing `SeedSecretTypes` repair step (idempotent, deterministic UUIDv5).
- **Backend**: `lib/Repair/SeedSecretTypes.php` (two entries). No controller, no service logic, no encryption change — the payload is an opaque ciphertext blob to the server.
- **Frontend**: per-type field sets in `SecretCreateDialog.vue`/`SecretEditDialog.vue`, labelled + masked presentation in `SecretDetail.vue`, and (optionally) a `CardDisplay.vue`/`IdentityDisplay.vue` component mirroring `TotpDisplay.vue`; brand/last-4 derivation helper; `secret-import` field mapping extended to route card/identity fields into the encrypted value.
- **API**: none — card/identity secrets are created/read/updated through the existing secret CRUD endpoints as ordinary encrypted secrets.
- **Cross-capability**: `secret-import` gains card/identity field mappings (additive); `link-sharing`, `secret-export`, `user-sharing`, and `secret-audit-trail` carry the new types unchanged (payload is ciphertext in `key`); `cxf-import-export` can now map CXF credit-card / identity-document entities 1:1 (adopts the alignment documented here — not edited by this change).
- **OpenConnector**: no impact — connector API credentials remain `api_key`/`login`; the new types are personal-vault types.
- **Security**: the payload enjoys the same E2E guarantees as any secret value — ciphertext at rest, decrypted only in the browser. BSN is ciphertext, satisfying its AVG/GDPR sensitivity. The server-side zero-knowledge stance (ADR-003) is unchanged; the server cannot distinguish a `card`/`identity` secret's ciphertext from a password's. No card/identity material enters audit entries (the existing whitelist already forbids `key`).
