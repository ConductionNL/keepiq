---
status: proposed
---

# Passkey Item Type

## Purpose

Add a `passkey` system secret type so Doriath can store, organise, present, share, and export WebAuthn/FIDO2 credentials with the same always-E2E guarantees as every other secret — the credential material rides in the existing encrypted `key` field, and the canonical field schema aligns 1:1 with the FIDO Credential Exchange Format (CXF) passkey entity.

## ADDED Requirements

### Requirement: Passkey system secret type

Doriath SHALL seed a `passkey` system secret type ("Passkey") as an eighth built-in system type alongside `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, and `totp`, with a stable deterministic UUID, and it MUST be a system type that cannot be modified or deleted. Introducing the `passkey` type MUST NOT add a database column, table, or migration: the credential lives in the existing encrypted `key` blob, so a `passkey` secret is indistinguishable from any other secret to the server.

#### Scenario: Passkey is a seeded system type

- **GIVEN** the app's secret-type seeding has run
- **WHEN** the system secret types are listed
- **THEN** `passkey` ("Passkey") MUST be present as a system type alongside the other seven
- **AND** it MUST be a system type that cannot be modified or deleted

#### Scenario: No new column or migration is introduced

@e2e exclude Server-side data-model contract — asserting no new column/migration exists for the passkey type is covered by PHPUnit (SeedSecretTypes idempotency + schema), not a DOM flow.
- **GIVEN** the `passkey` type has been seeded
- **WHEN** a `passkey` secret is stored
- **THEN** its credential MUST be persisted in the existing encrypted `key` field
- **AND** no new database column MUST be introduced for passkey material
- **AND** the server MUST NOT be able to distinguish the `passkey` secret's ciphertext from any other secret's `key`

### Requirement: Canonical CXF-aligned passkey credential schema

Doriath SHALL store a passkey as a single canonical JSON object in the encrypted `key` field, containing `credentialId`, `rpId`, `rpName`, `userName`, `userDisplayName`, `userHandle`, `privateKey` (PKCS#8), `algorithm` (COSE identifier), `counter`, `transports`, and `createdAt`. The `credentialId`, `rpId`, `userName`, `userDisplayName`, `userHandle`, and `privateKey` fields MUST map 1:1 to the FIDO CXF passkey entity's core fields; `counter`, `transports`, and `createdAt` are Doriath extensions. All credential material MUST be ciphertext in `key`; only the `rpId` MAY additionally be mirrored into the plaintext `url` field.

#### Scenario: Passkey credential is stored as ciphertext in the key field

@e2e exclude Encryption round-trip / wire-shape contract over WebCrypto — asserting the credential JSON is ciphertext in `key` and no credential material appears in any request is covered by PHPUnit + vitest, not a DOM flow.
- **GIVEN** the vault is unlocked and a user creates a `passkey` secret with a canonical credential JSON
- **WHEN** the secret is stored
- **THEN** the entire credential JSON (including `credentialId`, `userHandle`, and `privateKey`) MUST be encrypted in the `key` field
- **AND** no HTTP request issued by the flow MUST contain the plaintext `privateKey`, `userHandle`, or `credentialId`

#### Scenario: RP id is mirrored into the plaintext url field

- **GIVEN** a `passkey` secret whose credential has `rpId` `example.com`
- **WHEN** the secret is stored
- **THEN** `example.com` MUST be stored in the plaintext `url` field so the passkey is searchable and matchable to its site
- **AND** no other credential field (credential id, user handle, private key, counter) MUST appear in any plaintext field

### Requirement: Passkey listing, filtering, and site-associated presentation

Doriath SHALL let a user list and filter secrets by the `passkey` type, and SHALL present a `passkey` secret with its associated site (RP id / RP name), user name / display name, truncated credential id, transports, and creation date — while masking the private key material and never rendering it in full. A `passkey` secret whose decrypted `key` is not parseable canonical JSON MUST display an explicit invalid-credential state and MUST NOT fabricate or best-guess any field.

#### Scenario: Filter the vault to passkeys

- **GIVEN** a vault containing secrets of several types including two `passkey` secrets
- **WHEN** the user filters the list by the `passkey` type
- **THEN** the list MUST show exactly the two `passkey` secrets

#### Scenario: Passkey view shows the associated site and masks the private key

- **GIVEN** the vault is unlocked and a `passkey` secret for `example.com` is opened
- **WHEN** the passkey view renders
- **THEN** it MUST show the associated site (`example.com` / RP name), the user name, the truncated credential id, transports, and creation date
- **AND** the private key material MUST be masked (reveal/copy gated) and MUST NOT be rendered in full

#### Scenario: Invalid credential shows an error, never fabricated fields

@e2e exclude Parser contract over a decrypted in-memory value — the invalid-credential branch is covered by vitest (passkey parser + component test with malformed JSON), not a DOM flow.
- **GIVEN** a `passkey` secret whose decrypted `key` is not valid canonical passkey JSON
- **WHEN** the secret is viewed with the vault unlocked
- **THEN** the UI MUST show an explicit "not a valid passkey credential" state
- **AND** it MUST NOT display any fabricated or best-guess credential fields

### Requirement: Passkey creation via API and Bitwarden import

Doriath SHALL allow a `passkey` secret to be created through the existing secret CRUD API by supplying the canonical credential JSON, and SHALL route Bitwarden `login.fido2Credentials[]` entries into `passkey`-typed rows during import, encrypting every credential field in the browser like any imported field. A Bitwarden entry that cannot yield at least `credentialId`, `rpId`, and `privateKey` MUST be routed to the import rejected-rows list rather than creating a partial passkey.

#### Scenario: Bitwarden passkey imports as a passkey secret

@e2e exclude Bitwarden `fido2Credentials` field mapping is covered exhaustively by the import-parsers vitest with fixtures; the e2e drives the generic-CSV import path end-to-end.
- **GIVEN** a Bitwarden JSON export containing a login item with a `fido2Credentials` entry
- **WHEN** the user imports the file
- **THEN** the passkey MUST become a `passkey`-typed Doriath secret with its credential encrypted client-side in `key` and its `rpId` in `url`
- **AND** the plaintext credential MUST NOT be transmitted to the server

#### Scenario: Partial Bitwarden passkey is rejected, not partially created

@e2e exclude Per-row rejection is covered by the import-parsers/import-store vitest (missing-field entry lands in the rejected list).
- **GIVEN** a Bitwarden `fido2Credentials` entry missing its private key
- **WHEN** the file is imported
- **THEN** the entry MUST appear in the import rejected-rows list with a reason
- **AND** no partial `passkey` secret MUST be created

### Requirement: Passkeys carry through sharing, export, and audit unchanged and are excluded from password-health

Doriath SHALL carry a `passkey` secret through user sharing, link sharing, encrypted/GDPR export, and audit using the existing paths unchanged (the credential stays ciphertext in `key`), and SHALL exclude `passkey`-typed secrets from password-health strength, reuse, and breach analysis.

#### Scenario: Shared passkey re-encrypts under the recipient key with no plaintext exposure

@e2e exclude Server-never-sees-plaintext sharing contract — re-encryption under the recipient's public certificate is covered by PHPUnit/vitest, not browser-observable.
- **GIVEN** a user shares a `passkey` secret with another user
- **WHEN** the share is created
- **THEN** the credential MUST be re-encrypted under the recipient's EncryptionSuite public certificate through the existing secret-sharing path
- **AND** the server MUST NOT see the plaintext credential at any step

#### Scenario: Passkey is excluded from password-health analysis

@e2e exclude Engine-guard contract — asserting `passkey`-typed secrets are skipped by strength/reuse/breach analysis is covered by vitest (health engine excludes passkey type).
- **GIVEN** the vault contains a `passkey` secret and the password-health analysis runs
- **WHEN** the health engine processes the vault
- **THEN** the `passkey` secret's private key MUST NOT be scored for strength, counted for reuse, or breach-checked
