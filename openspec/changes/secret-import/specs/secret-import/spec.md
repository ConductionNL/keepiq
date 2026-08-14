## ADDED Requirements

### Requirement: Client-Side Parsing and E2E Guarantee
The system MUST parse import files entirely in the browser. The plaintext content of an import file MUST NEVER be transmitted to the server. Before any data is persisted, every sensitive field (key, login, additional_fields) MUST be encrypted in the browser with the owner's active EncryptionSuite public certificate, using the same WebCrypto encryption path as Create Secret. This preserves the encryption-suites spec guarantees: the server stores and receives only encrypted blobs, and no plaintext secret value ever exists server-side.

An import MUST only be possible while the vault is unlocked (the owner's CryptoKey is in the browser session per the encryption-suites Session Mechanism requirement). Parsed plaintext rows MUST be held only in JavaScript memory — never written to `localStorage`, `sessionStorage`, or IndexedDB — and MUST be discarded when the import wizard closes.

#### Scenario: Plaintext never sent to server
- **WHEN** a user imports a file containing plaintext credentials
- **THEN** every HTTP request issued by the import flow MUST contain only encrypted blobs for key, login, and additional_fields
- **AND** the plaintext-permitted fields in any request MUST be limited to name, url, type, and folder path — the same fields the secrets spec stores unencrypted

#### Scenario: Import requires unlocked vault
- **WHEN** a user opens the import wizard while the vault is locked (no CryptoKey in session)
- **THEN** the system MUST redirect to the lock screen and MUST NOT read the file

#### Scenario: Wizard closed mid-import
- **WHEN** the user closes the import wizard before committing
- **THEN** no secrets or folders are created
- **AND** the parsed plaintext rows MUST be released from memory

### Requirement: Supported Import Formats
The system MUST support importing from: generic CSV, Bitwarden JSON export, Bitwarden CSV export, and KeePass 2.x XML export. Format selection MUST be explicit (user picks the source tool/format), with file-content validation against the chosen format.

KDBX (`.kdbx` binary KeePass container) import is OUT OF SCOPE for v1: KDBX is an encrypted container, not an interchange format — supporting it would require shipping a second full crypto stack in the browser and prompting users for a foreign master password inside Doriath. When a KDBX file is detected (magic bytes), the system MUST reject it with guidance to use KeePass's `File → Export → KeePass XML (2.x)` function and import the resulting XML.

#### Scenario: Bitwarden JSON import
- **WHEN** a user selects Bitwarden JSON and provides a valid Bitwarden export file
- **THEN** the system MUST parse login items into rows with name, url, login, key, and additional fields (including notes and the TOTP seed as an opaque additional field)
- **AND** Bitwarden folder/collection names MUST be captured as folder paths

#### Scenario: KeePass XML import
- **WHEN** a user selects KeePass XML and provides a KeePass 2.x XML export
- **THEN** the system MUST parse entries with their group hierarchy as folder paths
- **AND** entry `History` elements MUST be ignored (only current values import)

#### Scenario: KDBX file rejected with guidance
- **WHEN** a user provides a `.kdbx` file
- **THEN** the system MUST refuse to parse it
- **AND** MUST display instructions for producing a KeePass XML export instead

#### Scenario: File does not match selected format
- **WHEN** the file content cannot be parsed as the selected format (e.g. invalid JSON, wrong structure)
- **THEN** the system MUST fail at the parse step with a format-specific error message
- **AND** MUST NOT create any secrets or folders

### Requirement: Nextcloud Passwords App Migration
The system MUST provide a dedicated migration path for the Nextcloud Passwords app via its JSON backup export. The parser MUST map the Passwords fields onto Doriath fields (label→name, url→url, username→login, password→key, notes and custom fields→additional_fields) and MUST preserve the Passwords folder hierarchy as folder paths. The import UI MUST document where the export is produced in the Passwords app (Settings → Backup → Export).

Migration is file-based only: the system MUST NOT read the Passwords app's database or API, because a server-side migration would decrypt credentials on the server, violating the always-E2E architecture (ADR-003).

#### Scenario: Passwords app backup imported with folders
- **WHEN** a user imports a Nextcloud Passwords JSON backup containing passwords organised in folders
- **THEN** each password MUST become a Doriath secret with its custom fields and notes preserved as encrypted additional fields
- **AND** the folder hierarchy MUST be recreated per the folder mapping step

### Requirement: Field Mapping Preview
The system MUST show a field-mapping preview after parsing and before anything is persisted. The preview MUST show which source field maps to which Doriath field, with a sample of parsed rows; sensitive values MUST be masked by default with per-cell reveal.

For known formats (Bitwarden, KeePass XML, Nextcloud Passwords) the mapping is fixed and shown read-only. For generic CSV the mapping MUST be auto-detected from common header names and MUST be user-adjustable per column (target field, named additional field, or ignore); exactly one column MUST map to name, and at most one column to each of url, login, and key. The import MUST NOT proceed past this step without explicit user confirmation.

#### Scenario: CSV columns adjusted before commit
- **WHEN** a user imports a CSV whose password column was auto-detected incorrectly
- **THEN** the user MUST be able to remap the column to the key field in the preview
- **AND** the preview MUST update to reflect the corrected mapping before confirmation

#### Scenario: Nothing persisted before confirmation
- **WHEN** a user reaches the mapping preview and abandons the import
- **THEN** no secrets, folders, or server-side records of the import attempt exist

### Requirement: Folder and Collection Mapping
The system MUST map source hierarchies (CSV folder column, Bitwarden folders/collections, KeePass groups, Passwords folders) onto Doriath folders. For each source root folder, the user MUST be able to choose an existing Doriath folder as the target or have it created (default), and MUST be able to place the entire import under a single new folder. Nested hierarchy MUST be preserved beneath the chosen target. Folders MUST be created idempotently at commit time, and folders whose rows were all rejected or skipped MUST NOT be created.

#### Scenario: Bitwarden collections become folders
- **WHEN** a user imports a Bitwarden export with collections "Work" and "Work/CI"
- **THEN** committed secrets MUST be placed in Doriath folders "Work" and "Work/CI" with the same nesting

#### Scenario: Import under a single new folder
- **WHEN** the user enables "import under one folder"
- **THEN** all imported folders and secrets MUST be created beneath a single new folder

### Requirement: Duplicate Detection
The system MUST detect duplicates client-side before commit by comparing each candidate row against the user's existing vault on normalized name (case-insensitive, trimmed) and normalized url. Detection MUST use only plaintext metadata — it MUST NOT require decrypting existing vault secrets. Detected duplicates MUST be presented as a distinct step with per-row resolution: skip (default) or import as copy (with a distinguishing name suffix), plus bulk-apply controls. The system MUST NOT overwrite an existing secret during import.

#### Scenario: Re-import of the same file
- **WHEN** a user imports a file whose rows were all imported previously
- **THEN** every row MUST be flagged as a duplicate
- **AND** with the default resolution, zero secrets are created and the summary reports them as skipped

#### Scenario: Duplicate imported as copy
- **WHEN** the user resolves a duplicate row with "import as copy"
- **THEN** a new secret MUST be created with a distinguishing name suffix
- **AND** the existing secret MUST remain unchanged

### Requirement: Chunked Batch Commit
The system MUST provide an authenticated batch endpoint that accepts arrays of already-encrypted secret payloads plus the folder paths to ensure, and creates them on behalf of the session user. The browser MUST submit accepted rows in bounded chunks with progress indication. The endpoint MUST validate each item independently and return per-index results, so one invalid item does not fail the rest of its chunk. The endpoint MUST derive ownership exclusively from the authenticated session user and MUST reject requests when the user has no active EncryptionSuite.

#### Scenario: Partial chunk failure
- **WHEN** a chunk of 50 items contains one item with a missing name
- **THEN** the other 49 items MUST be created
- **AND** the response MUST identify the failed index and reason
- **AND** the failed row MUST appear in the rejected list of the summary report

#### Scenario: Large vault import shows progress
- **WHEN** a user commits an import of 2,000 accepted rows
- **THEN** the rows MUST be submitted in multiple chunks
- **AND** the wizard MUST show progress as chunks complete

### Requirement: Malformed Row Rejection
A malformed row MUST NOT abort the import. Rows that fail at parse time (unparseable structure, missing required name, oversized field), mapping time, or commit time MUST be collected into a rejected list carrying the source row number and a human-readable reason. The user MUST be able to download the rejected rows as a client-side-generated CSV for correction and re-import; this file MUST be generated in the browser and never uploaded.

#### Scenario: Mixed valid and malformed rows
- **WHEN** a CSV with 100 rows contains 3 rows without a name value
- **THEN** 97 rows MUST proceed through the wizard
- **AND** the 3 rejected rows MUST be listed with their row numbers and reason

#### Scenario: Rejected rows downloadable
- **WHEN** the summary reports rejected rows
- **THEN** the user MUST be able to download them as a CSV generated locally in the browser

### Requirement: Import Summary Report
After commit, the system MUST display a summary report: count of secrets imported, duplicates skipped, rows rejected (with reasons), and folders created. The report is transient UI state: it MUST NOT be stored server-side and is not recoverable after the wizard closes.

#### Scenario: Summary after a mixed import
- **WHEN** an import completes with 95 created, 2 skipped duplicates, and 3 rejected rows
- **THEN** the summary MUST show all four counts (including folders created) and the per-row rejection reasons
