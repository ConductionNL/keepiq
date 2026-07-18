# Payment Card & Identity Items Specification

**Status**: done

**Feature tier**: V1

**OpenSpec changes:** [card-identity-items](../../changes/card-identity-items/)

## Purpose

Doriath's system secret types cover developer credentials (`login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, `totp`) but ship no personal or financial types. Bitwarden, 1Password, and Proton Pass all offer "Card" and "Identity" items as core free features, and migrating users bring this data with them — the FIDO Credential Exchange Format (CXF) defines dedicated credit-card and identity-document entities, so import fidelity requires the target types to exist. This feature adds two system types, `card` ("Payment Card") and `identity` ("Identity"), that store their composite payloads as ciphertext in the existing encrypted `key` field with no new column or migration, following the `totp` precedent. Card brand and last-4 are derived in the browser, never stored. BSN, sensitive personal data under the AVG/GDPR, is always ciphertext.

## Requirements

### Requirement: Payment Card and Identity System Types
The system MUST seed `card` ("Payment Card") and `identity` ("Identity") as built-in system secret types with stable deterministic UUIDs, alongside the existing seven. Both are UI hints only and MUST NOT add a database column or migration.

#### Scenario: Card and identity are seeded system types
- GIVEN the secret-type seeding has run
- WHEN the system secret types are listed
- THEN `card` and `identity` MUST both be present as non-modifiable, non-deletable system types

### Requirement: Composite Payload Stored as Ciphertext in the Key Field
A `card` secret MUST store `{number, expiry, cvv, pin, cardholder}` and an `identity` secret MUST store `{firstName, lastName, address, phone, email, bsn}` as a JSON object encrypted into the existing `key` field. Card number/CVV/PIN and all identity fields including BSN MUST be ciphertext at rest. Card brand and last-4 MUST be derived in the browser from the decrypted number and MUST NOT be persisted. Payloads exceeding one RSA chunk (~500 bytes) MUST use the existing chunked-encryption path.

#### Scenario: Sensitive fields are ciphertext, brand and last-4 are derived
- GIVEN a `card` secret with number `4111 1111 1111 1111` and an `identity` secret with BSN `999990019`
- WHEN they are stored and later opened with the vault unlocked
- THEN the number, CVV, PIN, and BSN MUST have been persisted only as ciphertext in `key`
- AND the card brand and last-4 MUST be derived from the decrypted number, not read from storage

### Requirement: Type-Specific Presentation and Masked Reveal
The system MUST render type-specific labelled fields for `card` and `identity` secrets, masking `number`/`cvv`/`pin` (card) and `bsn` (identity) by default with per-field reveal and copy controls, and MUST render nothing while the vault is locked.

#### Scenario: Sensitive sub-fields are masked until revealed
- GIVEN a `card` or `identity` secret is open with the vault unlocked
- WHEN the detail view renders
- THEN the number/CVV/PIN and BSN MUST be masked and revealed only on explicit control activation

### Requirement: Carry Through Share, Export, Audit, and Import
The system MUST carry `card` and `identity` secrets through user sharing, link sharing, export, GDPR export, and the audit trail unchanged (payload stays ciphertext, no `key` value in audit metadata), and the `secret-import` mapper MUST route card/identity fields into the correct type with the payload encrypted client-side.

#### Scenario: Shared card keeps its payload ciphertext and out of audit
- GIVEN a user shares a `card` secret
- WHEN the share and its audit entry are written
- THEN the payload MUST be re-encrypted to the recipient client-side and the audit entry MUST contain no card material

### Requirement: CXF Credit-Card and Identity-Document Alignment
The `card` and `identity` types MUST align field-by-field with the CXF credit-card and identity-document credential entities so `cxf-import-export` can map them 1:1 without loss of core credential material.

#### Scenario: CXF entities map to card and identity secrets
- GIVEN a CXF document with credit-card and identity-document entities
- WHEN imported through the CXF mapping
- THEN their fields MUST map into `card` and `identity` encrypted `key` payloads, and exporting back MUST reproduce the same core fields

## User Stories

- As a user, I want to store a payment card with its number, expiry, CVV, and PIN so that I keep my card details in my encrypted vault
- As a user, I want the card list to show the brand and last-4 so that I can recognise a card without revealing the full number
- As a user, I want to store my identity details (name, address, phone, email, BSN) so that I can fill forms without retyping them
- As a user migrating from Bitwarden/1Password/Proton or a CXF export, I want my card and identity items to import intact so that I do not lose them
- As a privacy-conscious user, I want my BSN to be encrypted at rest so that it is never readable by the server

## Acceptance Criteria

- [ ] `card` and `identity` are seeded system types with stable deterministic UUIDs; no schema/migration change
- [ ] Card number/CVV/PIN and all identity fields including BSN are ciphertext in the existing `key` field
- [ ] Card brand and last-4 are derived in-browser and never stored or sent to the server
- [ ] Sensitive sub-fields are masked by default with per-field reveal + copy; nothing renders while locked
- [ ] Card/identity secrets carry through share, export, GDPR export, and audit unchanged with the payload ciphertext
- [ ] No card/identity `key` value appears in audit metadata
- [ ] Card/identity fields import into the correct type with the payload encrypted client-side
- [ ] The types align 1:1 with the CXF credit-card and identity-document entities

## Notes

- Follows the `totp` precedent (archived `add-totp-secrets`): a new system type is one entry in `SeedSecretTypes::SYSTEM_TYPES` plus a type-specific presentation component; the payload rides the existing encrypted `key` field.
- The CXF mapping is defined in this change's design (D6) for the `cxf-import-export` change to adopt; that change's files are not edited here.
- Any Luhn/expiry hinting is client-side and best-effort — the server never validates a PAN.
- Related ADRs: ADR-001 (own DB tables), ADR-003 (encryption architecture). Related specs: secrets, secret-import, cxf-import-export, secret-audit-trail.
