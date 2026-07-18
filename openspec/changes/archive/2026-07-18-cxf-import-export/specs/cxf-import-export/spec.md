---
status: proposed
---

# CXF Import and Export

## Purpose

Add bidirectional, client-side, file-based FIDO Credential Exchange Format (CXF) import and export as a new format on Doriath's existing import and export pipelines — mapping CXF credential entities to and from Doriath secret types (including passkeys via `passkey-item-type`), reusing folder/collection mapping and duplicate detection, reporting every unmappable item, and gating CXF export like the existing plaintext CSV export because a raw CXF file is plaintext.

## ADDED Requirements

### Requirement: Client-side CXF import with E2E encryption

Doriath SHALL parse a CXF JSON document entirely in the browser and MUST encrypt every sensitive field (key, login, additional_fields) with the owner's active EncryptionSuite public certificate before any data is persisted — the plaintext content of a CXF file MUST NEVER be transmitted to the server. CXF import MUST only be possible while the vault is unlocked, and MUST register as a format in the existing import parser registry so it reuses the existing mapping preview, folder mapping, duplicate detection, chunked encrypted batch commit, rejected rows, and summary steps.

#### Scenario: CXF plaintext never sent to the server

@e2e exclude Wire-shape contract — asserting only ciphertext blobs leave the browser during a CXF import is covered by the import-store vitest (plaintext-never-in-request-body) + the batch-commit envelope-validation PHPUnit, not a DOM assertion.
- **WHEN** a user imports a CXF file containing plaintext credentials
- **THEN** every HTTP request issued by the import flow MUST contain only encrypted blobs for key, login, and additional_fields
- **AND** the plaintext-permitted fields MUST be limited to name, url, type, and folder path

#### Scenario: CXF import requires an unlocked vault

@e2e exclude Lock-guard reuse of the existing import wizard; covered by the ImportWizardDialog vitest (locked vault blocks the wizard + reads no file).
- **WHEN** a user opens the CXF import while the vault is locked (no CryptoKey in session)
- **THEN** the system MUST redirect to the lock screen and MUST NOT read the file

#### Scenario: Malformed CXF file fails at parse, creates nothing

@e2e exclude Parse-step failure on a non-CXF file covered by the CXF parser vitest (throws on a non-matching structure) + the store surfaces the error.
- **WHEN** the file cannot be parsed as a CXF document (invalid JSON or wrong structure)
- **THEN** the system MUST fail at the parse step with a format-specific error
- **AND** MUST NOT create any secrets or folders

### Requirement: CXF entity to Doriath secret-type mapping

Doriath SHALL map CXF credential entities to Doriath secret types bidirectionally: passwords/logins → `login`, passkeys → `passkey` (using the `passkey-item-type` canonical schema), TOTP → `totp`, notes → `note`, API keys → `api_key`, SSH keys → `ssh_key`, and Wi-Fi → `note` with custom fields. Export MUST apply the reverse mapping to assemble a standards-conformant CXF document.

#### Scenario: CXF passkey imports as a passkey secret

@e2e exclude CXF passkey-entity field mapping into the `passkey-item-type` canonical schema is covered by the CXF-parser vitest with fixtures; the e2e drives the generic import path end-to-end.
- **GIVEN** a CXF document containing a passkey credential entity
- **WHEN** the user imports the file
- **THEN** a `passkey`-typed Doriath secret MUST be created with the credential encrypted client-side in `key` and its RP id mirrored into `url`, per the `passkey-item-type` canonical schema

#### Scenario: Mixed CXF entities map to their Doriath types

@e2e exclude Per-entity type mapping is covered by the CXF-parser vitest fixtures (login, totp, note, api_key, ssh_key rows land on their types).
- **GIVEN** a CXF document containing a password, a TOTP, a note, an API key, and an SSH key entity
- **WHEN** the user imports the file
- **THEN** each entity MUST become a secret of its mapped Doriath type (`login`, `totp`, `note`, `api_key`, `ssh_key` respectively) with sensitive fields encrypted client-side

### Requirement: Unmapped-item report

Doriath MUST surface every item it cannot represent rather than silently dropping it. On import, a CXF entity Doriath cannot represent (or an entry missing fields its target type requires) MUST land in the import rejected-rows list with a reason and source index and be counted in the summary. On export, a Doriath value with no CXF home (e.g. a passkey `counter`/`transports` extension, or a custom secret type) MUST be recorded in an export unmapped-item report shown before the file download.

#### Scenario: Unrepresentable CXF entity is reported, not dropped

@e2e exclude Rejected-row reuse for an unrepresentable entity is covered by the CXF-parser/import-store vitest (entity lands in the rejected list with a reason).
- **GIVEN** a CXF document containing an entity type Doriath cannot represent
- **WHEN** the user imports the file
- **THEN** that entity MUST appear in the rejected-rows list with a human-readable reason and MUST NOT be silently dropped
- **AND** the summary MUST count it

#### Scenario: Non-representable export field is reported before download

@e2e exclude Export unmapped-item report is covered by the CXF-writer vitest (extension/custom-type values recorded in the report); the report content is asserted, not a DOM flow.
- **GIVEN** the vault contains a passkey with `transports` extensions and a custom-typed secret
- **WHEN** the user exports to CXF
- **THEN** the export unmapped-item report MUST list what will not survive the round-trip before the file is offered for download

### Requirement: Client-side CXF export gated like plaintext CSV

Doriath SHALL assemble a CXF document entirely in the browser from the client-decrypted vault and MUST NOT assemble it server-side. Because a raw CXF file is plaintext, CXF export MUST be gated by BOTH an explicit unencrypted-file warning AND fresh master-password re-authentication even when the vault is already unlocked, and MUST report the export to the existing export-event endpoint before offering the download, emitting a `SecretExportedEvent` with mode `cxf` carrying no secret names, values, or ciphertext.

#### Scenario: CXF export requires warning and re-auth

@e2e exclude Reuses the existing plaintext-CSV export gating; the warning + re-auth gate is covered by the ExportDialog vitest (canSubmit gating) and the export-store re-auth path, mirroring the CSV scenarios.
- **WHEN** a user with an unlocked vault starts a CXF export
- **THEN** an explicit unencrypted-file warning MUST be acknowledged first
- **AND** fresh master-password re-entry MUST be required, and an incorrect password MUST block the export

#### Scenario: CXF assembled locally, event precedes download

@e2e exclude Client-only assembly + event-before-download ordering is covered by vitest (no plaintext in any request body; export-event called before the local download) and PHPUnit (SecretExportedEvent mode `cxf`, no secret material).
- **WHEN** a CXF export completes
- **THEN** the CXF file MUST be generated in the browser (local download) with no plaintext secret value in any HTTP request
- **AND** a `SecretExportedEvent` with mode `cxf` MUST be dispatched before the download, carrying no secret names, values, or ciphertext

### Requirement: Folder mapping and duplicate detection reuse existing machinery

Doriath SHALL map CXF collections onto Doriath folders through the existing import folder-and-collection-mapping step (choose or create target, nested hierarchy preserved, idempotent creation, empty-folder suppression) and MUST detect duplicates client-side against the existing vault on normalized name and url without decrypting existing secrets, with per-row skip (default) or import-as-copy resolution. CXF import MUST NOT overwrite an existing secret.

#### Scenario: CXF collections become Doriath folders

@e2e exclude Nested-folder idempotent ensuring is covered by the ImportService PHPUnit; the e2e drives single-folder creation on the generic path.
- **GIVEN** a CXF document with nested collections
- **WHEN** the user imports it
- **THEN** committed secrets MUST be placed in Doriath folders reproducing the collection hierarchy, created idempotently

#### Scenario: Re-import of the same CXF file detects duplicates

@e2e exclude Duplicate detection on normalized name/url is covered by the import-store vitest (all rows flagged; default skip creates zero).
- **WHEN** a user re-imports a CXF file whose rows were all imported previously
- **THEN** every row MUST be flagged as a duplicate
- **AND** with the default resolution, zero secrets MUST be created and the summary MUST report them skipped

### Requirement: CXP is out of scope in v1

Doriath's v1 CXF support MUST be file-based only (import and export of a CXF JSON document). The encrypted Credential Exchange Protocol (CXP, HPKE-based device-to-device transfer), device pairing, and live app-to-app handoff MUST NOT be implemented in this change and are recorded as future work.

#### Scenario: Only file-based CXF is offered

@e2e exclude Scope assertion — the absence of a CXP/live-transfer surface is covered by the wizard vitest (only a file-based CXF format is registered), not a DOM flow.
- **WHEN** a user uses CXF import or export
- **THEN** the only supported mechanism MUST be a local CXF file (parsed/assembled in the browser)
- **AND** no encrypted CXP transfer or device-pairing flow MUST be offered
