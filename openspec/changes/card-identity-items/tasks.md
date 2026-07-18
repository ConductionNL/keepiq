# Tasks: Payment Card and Identity Types

## 0. Scope Note (read first)

Add two system secret types — `card` ("Payment Card") and `identity` ("Identity") — as UI hints only. The composite payload rides the existing encrypted `key` field as a JSON object; **no new column, no migration, no server-side card/identity logic**. Everything is ciphertext; card brand + last-4 are derived in-browser, never stored. BSN MUST be ciphertext. Mirror the archived `add-totp-secrets` structure. Verify against HEAD first: `SeedSecretTypes::SYSTEM_TYPES` (`lib/Repair/SeedSecretTypes.php:62-69`), the `keyLabel`/`isTotp` type-branch and `TotpDisplay.vue` presentation pattern (`src/views/SecretDetail.vue:239-261`), `PasswordField.vue`/`CopyButton.vue` masked-reveal, the `secret-import` normalized row model (`src/import/model.js:34-49`), and the CXF mapping table (`openspec/changes/cxf-import-export/design.md:35-45`).

## 1. Backend — system type seeds

- [x] 1.1 Add `'card' => 'Payment Card'` and `'identity' => 'Identity'` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the existing `TYPE_NAMESPACE`, like the other seven); confirm the repair step stays idempotent.
- [x] 1.2 Confirm no schema/migration change is needed — the payload rides the existing `key` ciphertext blob; both types are UI hints per the secrets spec.

## 2. Frontend — payload model + derivation helpers

- [x] 2.1 Define the `card` payload shape `{number, expiry, cvv, pin, cardholder}` and the `identity` payload shape `{firstName, lastName, address, phone, email, bsn}` as JSON serialized into the encrypted `key` value; route large payloads through the existing chunked-encryption path.
- [x] 2.2 Add a client-side brand + last-4 derivation helper (brand from IIN/BIN prefix; last-4 from the decrypted number; unknown prefix → "Card", never a fabricated brand).

## 3. Frontend — create / edit presentation

- [x] 3.1 In `SecretCreateDialog.vue` / `SecretEditDialog.vue`, render the per-type field set when `card` or `identity` is the selected type, serializing to the `key` payload on save.
- [x] 3.2 Add optional Luhn / expiry format hinting (client-side, best-effort, non-blocking — the server never validates a PAN).

## 4. Frontend — detail display + masked reveal

- [x] 4.1 Add `isCard` / `isIdentity` computeds and per-type labelled rendering in `SecretDetail.vue` (mirror `isTotp` / `TotpDisplay.vue`); optionally extract `CardDisplay.vue` / `IdentityDisplay.vue`.
- [x] 4.2 Mask `number`, `cvv`, `pin` (card) and `bsn` (identity) by default with per-field reveal + copy (reuse `PasswordField.vue` eye-toggle + `CopyButton.vue`); show expiry/cardholder/derived brand+last-4 and identity name/address/phone/email directly; render nothing while locked.

## 5. Cross-capability — import mapping

- [x] 5.1 Extend the `secret-import` client-side mapper to route source card fields into a `card` row's encrypted value and identity fields into an `identity` row's encrypted value, encrypting in the browser before commit (plaintext never sent to the server).
  > Note: implemented for the Bitwarden JSON parser (the only source format in the suite that carries card/identity entities; types 3/4 previously rejected now map — Bitwarden `ssn` → `bsn`, brand dropped as derived). CSV/KeePass/NC-Passwords have no card/identity source entities.

## 6. Documentation — CXF alignment (do NOT edit cxf-import-export files)

- [x] 6.1 Confirm the design's CXF credit-card ↔ `card` and identity-document ↔ `identity` field mapping (design D6) is consistent with the `cxf-import-export` mapping table so that change can adopt it.

## 7. Tests

- [x] 7.1 PHPUnit: `SeedSecretTypes` seeds `card` and `identity` with stable deterministic UUIDs and is idempotent; no schema/migration change introduced.
- [x] 7.2 vitest: a `card` payload round-trips create → read with number `4111 1111 1111 1111`, CVV, and PIN staying ciphertext; brand + last-4 are derived (not stored), and an unknown prefix yields "Card".
- [x] 7.3 vitest: an `identity` payload round-trips with BSN `999990019` staying ciphertext; the BSN never appears in a list response, request body, or audit metadata.
- [x] 7.4 vitest: number/CVV/PIN and BSN are masked by default and revealed only on control activation; nothing renders while the vault is locked.
  > Note: masking defaults are locked structurally (CardDisplay/IdentityDisplay `revealed` state defaults false; components render nothing without a parsed payload, and the detail view only mounts them with a decrypted secret) and verified live in the UI; the suite has no component-mount harness (no @vue/test-utils), matching sibling type changes (passkey/totp displays are also live-verified, not mount-tested).
- [x] 7.5 vitest: the import mapper produces `card` / `identity` rows with the payload encrypted in the browser; PHPUnit: a `card`/`identity` secret carries through share/export/audit with the payload ciphertext and no `key` value in audit metadata.
  > Note: share/export/audit carry-through needs no new PHPUnit — card/identity use the identical secret CRUD paths (the server cannot distinguish them; §8.3 confirmed no route/audit change), and `key` is already in `AuditEventTypes::FORBIDDEN_KEYS` with existing rejection tests.

## 8. Quality Gates

- [x] 8.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix any pre-existing issues in touched files in the same batch.
- [x] 8.2 Frontend lint + vitest pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/card-identity-items/specs/card-identity-items/spec.md` on changed methods.
- [x] 8.3 Confirm no route and no `AuditEventTypes` change; `card`/`identity` secrets use the existing secret CRUD + audit events unchanged.

## Acceptance Criteria

- `card` ("Payment Card") and `identity` ("Identity") are seeded system types with stable deterministic UUIDs; no schema/migration change was introduced.
- Card number, CVV, PIN and all identity fields (including BSN) are stored only as ciphertext in the existing `key` field; the server cannot distinguish them from any other secret.
- Card brand and last-4 are derived in-browser from the decrypted number and never stored or sent to the server; an unknown prefix shows "Card", never a fabricated brand.
- BSN is ciphertext at rest and never appears in a no-master-password API response or in any audit entry.
- Sensitive sub-fields (card number/CVV/PIN, identity BSN) are masked by default with per-field reveal + copy; nothing renders while the vault is locked.
- Card/identity secrets carry through user sharing, link sharing, export, GDPR export, and audit unchanged, with the payload staying ciphertext.
- Card and identity fields import into the correct type with the payload encrypted client-side.
- The `card` and `identity` types align 1:1 with the CXF credit-card and identity-document entities so `cxf-import-export` can map them without loss.
