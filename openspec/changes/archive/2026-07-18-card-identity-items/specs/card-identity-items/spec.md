## ADDED Requirements

### Requirement: Payment Card and Identity System Types
The system MUST seed two additional built-in system secret types: `card` (labelled "Payment Card") and `identity` (labelled "Identity"), each with a stable deterministic UUID, alongside the existing seven system types. Both are system-scoped and MUST NOT be modifiable or deletable, and — like every secret type — they are a UI presentation hint only, changing neither server-side validation nor the underlying data model. Introducing them MUST NOT add any database column or migration.

#### Scenario: Card and identity are seeded system types
- **GIVEN** the app's secret-type seeding has run
- **WHEN** the system secret types are listed
- **THEN** `card` ("Payment Card") and `identity` ("Identity") MUST both be present as system types
- **AND** each MUST be a system type that cannot be modified or deleted

#### Scenario: No schema change for the new types
- **GIVEN** the card and identity types have been introduced
- **WHEN** the database schema is inspected
- **THEN** no new column MUST have been added to store card or identity fields
- **AND** a `card` or `identity` secret's payload MUST reside in the existing encrypted `key` field

### Requirement: Card Payload Stored as Ciphertext in the Key Field
A `card` secret MUST store its composite payload as a JSON object in the existing encrypted `key` field, with keys `number`, `expiry`, `cvv`, `pin`, and `cardholder`. The `number`, `cvv`, and `pin` values MUST be ciphertext at rest (never plaintext, never a separate column). The card **brand** and **last-4** MUST NOT be persisted — they MUST be derived in the browser from the decrypted `number` when the vault is unlocked. Payloads that exceed one RSA chunk (~500 bytes) MUST be encrypted through the existing chunked-encryption path used for `additional_fields`. The server MUST NOT be able to distinguish a `card` secret's ciphertext from any other secret's `key`.

#### Scenario: Card number, CVV, and PIN are stored encrypted
- **GIVEN** a user creates a `card` secret with number `4111 1111 1111 1111`, a CVV, and a PIN, with the vault unlocked
- **WHEN** the secret is stored
- **THEN** the number, CVV, and PIN MUST be persisted only as ciphertext inside the `key` field
- **AND** no plaintext card number, CVV, or PIN MUST be written to any column or sent to the server in the clear

#### Scenario: Brand and last-4 are derived, not stored
- **GIVEN** a stored `card` secret whose decrypted number is `4111 1111 1111 1111`
- **WHEN** the secret is opened with the vault unlocked
- **THEN** the UI MUST derive and display the brand and the last four digits (e.g. `Visa •••• 1111`) from the decrypted number
- **AND** neither the brand nor the last-4 MUST be stored in the database or sent to the server

### Requirement: Identity Payload Stored as Ciphertext with BSN Protection
An `identity` secret MUST store its composite payload as a JSON object in the existing encrypted `key` field, with keys `firstName`, `lastName`, `address`, `phone`, `email`, and `bsn`. Every field MUST be ciphertext at rest. The `bsn` (Dutch citizen service number) is sensitive personal data under the AVG/GDPR and MUST be stored as ciphertext and MUST NOT appear in plaintext at rest, in any API response without the master password in session, or in any audit entry.

#### Scenario: Identity fields including BSN are stored encrypted
- **GIVEN** a user creates an `identity` secret with a name, address, and BSN `999990019`, with the vault unlocked
- **WHEN** the secret is stored
- **THEN** all identity fields including the BSN MUST be persisted only as ciphertext inside the `key` field
- **AND** the BSN `999990019` MUST NOT appear in plaintext in any column, API list response, or audit entry

### Requirement: Type-Specific Presentation and Masked Reveal
When a `card` or `identity` secret is created, edited, or viewed with the vault unlocked, the system MUST render the type-specific field set with correct labels. The sensitive sub-fields MUST be masked by default with an explicit per-field reveal control and a copy control: for `card` these are `number`, `cvv`, and `pin`; for `identity` this is `bsn`. The remaining fields (`card` expiry, cardholder, derived brand/last-4; `identity` name, address, phone, email) MAY be shown directly when the vault is unlocked. No card or identity field MUST be shown while the vault is locked.

#### Scenario: Sensitive card fields are masked until revealed
- **GIVEN** a `card` secret is open with the vault unlocked
- **WHEN** the detail view first renders
- **THEN** the number, CVV, and PIN MUST be masked
- **AND** each MUST become visible only after the user activates its reveal control, and MUST offer a copy action

#### Scenario: BSN is masked until revealed
- **GIVEN** an `identity` secret is open with the vault unlocked
- **WHEN** the detail view first renders
- **THEN** the BSN MUST be masked and MUST become visible only after the user activates its reveal control

### Requirement: Card and Identity Carry Through Share, Export, and Audit Unchanged
Because a `card`/`identity` payload is ciphertext in the existing `key` field, the system MUST carry these secrets through user sharing, link sharing, export, GDPR export, and the audit trail using the existing paths, with the payload remaining ciphertext end-to-end. No card or identity field values MUST enter audit event metadata (the audit whitelist forbids `key`).

#### Scenario: Shared card secret keeps its payload ciphertext
- **GIVEN** a user shares a `card` secret with another user
- **WHEN** the share is created
- **THEN** the payload MUST be re-encrypted to the recipient's public certificate client-side
- **AND** the server MUST never see the plaintext card payload at any step

#### Scenario: Audit entry for a card secret contains no card material
- **GIVEN** a `card` or `identity` secret triggers an audited action
- **WHEN** the audit entry is written
- **THEN** the entry MUST NOT contain the card number, CVV, PIN, BSN, or any other `key` field value

### Requirement: Import Maps Card and Identity Fields into the Encrypted Value
The `secret-import` client-side mapper MUST route source card fields (number, expiry, verification number, PIN, cardholder) into a `card`-typed row's encrypted value, and source identity fields (name, address, phone, email, national ID/BSN) into an `identity`-typed row's encrypted value, encrypting the payload in the browser before commit so the plaintext is never sent to the server. This extends the existing import field mapping; it MUST NOT introduce a new import pipeline.

#### Scenario: Importing a card entry produces an encrypted card secret
- **GIVEN** an import source contains a card entry with number `4111 1111 1111 1111`
- **WHEN** the import mapper processes the entry with the vault unlocked
- **THEN** it MUST produce a `card`-typed row whose payload is encrypted in the browser and committed as ciphertext
- **AND** the plaintext card number MUST NOT be transmitted to the server

### Requirement: CXF Credit-Card and Identity-Document Alignment
The `card` and `identity` types MUST align field-by-field with the FIDO Credential Exchange Format (CXF) credit-card and identity-document credential entities, so the `cxf-import-export` capability can map them 1:1 in both directions without loss of core credential material. Card brand and last-4, being derived from the number, need no CXF carrier and MUST be recomputed on read.

#### Scenario: CXF credit-card entity maps to a card secret
- **GIVEN** a CXF document containing a credit-card credential entity
- **WHEN** it is imported through the CXF mapping
- **THEN** its number, expiry, verification number, PIN, and cardholder MUST map into a `card` secret's encrypted `key` payload
- **AND** exporting that `card` secret back to CXF MUST reproduce the same core card fields

#### Scenario: CXF identity-document entity maps to an identity secret
- **GIVEN** a CXF document containing an identity-document credential entity
- **WHEN** it is imported through the CXF mapping
- **THEN** its name, address, phone, email, and national ID/BSN MUST map into an `identity` secret's encrypted `key` payload
