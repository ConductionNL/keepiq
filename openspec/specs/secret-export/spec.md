# secret-export Specification

## Purpose
TBD - created by archiving change secret-export-gdpr. Update Purpose after archive.
## Requirements
### Requirement: Encrypted Backup Export
The system MUST allow a user to export their vault as an encrypted backup file, generated entirely in the browser. The vault MUST be unlocked (CryptoKey in session per the encryption-suites Session Mechanism requirement). The browser decrypts the selected secrets, serializes secrets and folder structure to a versioned JSON payload, derives an AES-256 key from a user-chosen backup passphrase via Argon2id (reusing the link-sharing WASM KDF module), encrypts the payload with AES-256-GCM, and triggers a local file download. The plaintext payload, the passphrase, and the derived key MUST NEVER be transmitted to the server.

The backup passphrase MUST meet a zxcvbn ≥ 3 strength floor with live feedback. The backup MUST be decryptable from the passphrase alone — independent of the user's EncryptionSuite — so that it survives master-password loss, suite revocation, or instance loss.

#### Scenario: Backup created client-side
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** an unlocked user exports an encrypted backup with a valid passphrase
- **THEN** the browser MUST produce a `.doriath-backup` file containing only the versioned envelope (KDF parameters, salt, cipher identifier, encrypted payload)
- **AND** no HTTP request issued by the flow contains plaintext secret values, the passphrase, or the derived key

#### Scenario: Weak backup passphrase rejected
@e2e exclude Strength-floor gating is unit-tested at the component level (ExportDialog.spec.js asserts canSubmit is false below the zxcvbn floor) and the meter logic in the dialog; not driven as a separate DOM flow.
- **WHEN** the user enters a backup passphrase below the strength floor
- **THEN** the export MUST be blocked with live feedback explaining why

#### Scenario: Backup is suite-independent
@e2e exclude Cryptographic property — decryption uses only the envelope KDF params + salt with no suite key material; covered by vitest (export-backup.spec.js round-trip + parameter-bump tests), not a DOM flow.
- **WHEN** a backup is decrypted with the correct passphrase
- **THEN** decryption MUST succeed using only the envelope's KDF parameters and salt — without any EncryptionSuite key material

### Requirement: Backup Format Versioning and Round-Trip
The backup envelope MUST be versioned and self-describing: it MUST record the format version, KDF algorithm and parameters, salt, and cipher identifier, so future parameter changes do not break old backups. The system MUST be able to restore a backup through the import pipeline: a backup restore parser registered in the import wizard prompts for the passphrase, decrypts client-side, and feeds the rows through the standard mapping/duplicate/commit steps.

#### Scenario: Export-restore round-trip
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** a user exports an encrypted backup and restores it via the import wizard with the correct passphrase
- **THEN** all exported secrets and the folder hierarchy MUST be reproduced
- **AND** the standard duplicate-detection step MUST apply

#### Scenario: Wrong restore passphrase
@e2e exclude Client-side decrypt-failure contract — a wrong passphrase throws an AES-GCM tag mismatch and yields no rows; covered by vitest (import-backupParser.spec.js wrong-passphrase test), not a DOM flow.
- **WHEN** a user restores a backup with an incorrect passphrase
- **THEN** decryption MUST fail client-side (AES-GCM tag mismatch) with an error message
- **AND** no rows enter the import pipeline

### Requirement: Plaintext CSV Export with Re-Authentication
The system MUST allow a user to export secrets as a plaintext CSV, generated entirely in the browser and never transmitted to the server. The flow MUST be gated by BOTH: (1) an explicit warning dialog stating the file is unencrypted and should be deleted after use, and (2) fresh master-password re-entry even when the vault is already unlocked. Because the server never sees the master password (encryption-suites spec), re-authentication is a client-side proof of knowledge: the entered password MUST successfully decrypt the stored private-key blob before the export proceeds; the freshly derived key MUST be discarded immediately afterwards.

The CSV column layout MUST be round-trippable through the secret-import generic CSV auto-detection.

#### Scenario: Re-auth required despite unlocked session
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** a user with an unlocked vault starts a plaintext CSV export
- **THEN** the system MUST require master-password re-entry
- **AND** an incorrect password MUST block the export with an error

#### Scenario: Warning precedes plaintext export
@e2e tests/e2e/workflows/export-gdpr.spec.ts
- **WHEN** a user starts a plaintext CSV export
- **THEN** an explicit warning about the unencrypted nature of the file MUST be acknowledged before the password prompt

#### Scenario: CSV generated locally
@e2e exclude Client-only generation contract — the CSV is built in the browser and no plaintext appears in any request; covered by vitest (export-csv.spec.js RFC-4180 + round-trip and useExportStore "no secret material in request body" tests).
- **WHEN** the plaintext CSV export completes
- **THEN** the file MUST be generated in the browser (local download)
- **AND** no plaintext secret value appears in any HTTP request

### Requirement: Export Scope Selection
Both export modes MUST support exporting either the entire vault or a selection of folders (including their subfolders). The export MUST include only secrets the user owns or holds as shared copies in their own vault; scope selection MUST NOT change the security gating of the chosen mode.

#### Scenario: Folder-scoped export
@e2e exclude Serialization scope logic — the relative-path mapping and subtree filter are pure transforms; covered by vitest (export-serializer.spec.js folder-scoped subtree test).
- **WHEN** a user exports only the folder "Work"
- **THEN** the output MUST contain exactly the secrets in "Work" and its subfolders, with their relative folder paths

### Requirement: Export Event Emission
The system MUST emit a typed `SecretExportedEvent` (via `OCP\EventDispatcher`) for every completed export, carrying the user ID, export mode (encrypted-backup or plaintext-csv), scope, secret count, and timestamp — and NEVER secret names, values, or ciphertext. Because export runs client-side, the export flow MUST report the export to a server endpoint before offering the file download; the endpoint emits the event for the session user only.

This requirement is scoped to event emission: audit-trail storage, retention, and UI belong to the future audit-trail change (FEATURES.md V1 "Audit trail on all secret operations"), which will consume these events. The events cover the supported UI flows; a tampered client that reads secrets via the normal API is out of scope for client-reported events and is stated as such.

#### Scenario: Event emitted on export
@e2e exclude Server-side dispatch contract — the SecretExportedEvent payload (counts/modes only, no secret material) is a payload assertion; covered by PHPUnit (ExportControllerTest + ExportGdprEventTest no-secret-material tests).
- **WHEN** a user completes an encrypted backup export of 120 secrets
- **THEN** a `SecretExportedEvent` MUST be dispatched with mode encrypted-backup and count 120
- **AND** the event payload MUST contain no secret names, values, or ciphertext

#### Scenario: Event precedes download
@e2e exclude Ordering + failure-surfacing contract — the store calls the event endpoint before the download and surfaces a failure; covered by vitest (export.spec.js order + failure-blocks-download tests).
- **WHEN** the export-event endpoint call fails
- **THEN** the export flow MUST surface the failure and MUST NOT silently skip event emission

