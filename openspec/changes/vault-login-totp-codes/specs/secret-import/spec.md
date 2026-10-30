## MODIFIED Requirements

### Requirement: Supported Import Formats
The system MUST support importing from: generic CSV, Bitwarden JSON export, Bitwarden CSV export, and KeePass 2.x XML export. Format selection MUST be explicit (user picks the source tool/format), with file-content validation against the chosen format.

KDBX (`.kdbx` binary KeePass container) import is OUT OF SCOPE for v1: KDBX is an encrypted container, not an interchange format — supporting it would require shipping a second full crypto stack in the browser and prompting users for a foreign master password inside Keepiq. When a KDBX file is detected (magic bytes), the system MUST reject it with guidance to use KeePass's `File → Export → KeePass XML (2.x)` function and import the resulting XML.

#### Scenario: Bitwarden JSON import
@e2e exclude Format parsing (login items, TOTP, passkeys, collections) covered exhaustively by the import-parsers and passkey vitest with fixtures; the e2e drives the generic-CSV path end-to-end.
- **WHEN** a user selects Bitwarden JSON and provides a valid Bitwarden export file
- **THEN** the system MUST parse login items into rows with name, url, login, key, and additional fields (including notes)
- **AND** a login's TOTP seed and passkeys MUST go on that login's own `totp` and `passkeys` fields, never into separate items; a seed that is not an authenticator key (such as a Steam code) MUST stay an additional field
- **AND** Bitwarden folder/collection names MUST be captured as folder paths

#### Scenario: KeePass XML import
@e2e exclude Nested-group + History-ignored parsing covered by the import-parsers vitest with a KeePass XML fixture.
- **WHEN** a user selects KeePass XML and provides a KeePass 2.x XML export
- **THEN** the system MUST parse entries with their group hierarchy as folder paths
- **AND** an entry's `otp` string, or its `TimeOtp-Secret-Base32` folded with `TimeOtp-Period`, `TimeOtp-Length` and `TimeOtp-Algorithm`, MUST go on that entry's own `totp` field
- **AND** entry `History` elements MUST be ignored (only current values import)

#### Scenario: KDBX file rejected with guidance
@e2e exclude KDBX magic-byte detection + guidance covered by the ImportWizardDialog vitest (kdbxDetected set, no parse) + the isKdbx model vitest.
- **WHEN** a user provides a `.kdbx` file
- **THEN** the system MUST refuse to parse it
- **AND** MUST display instructions for producing a KeePass XML export instead

#### Scenario: File does not match selected format
@e2e exclude Parse-step failure on a wrong-format file covered by the per-parser vitest (each parser throws on a non-matching structure) + the store surfaces the error.
- **WHEN** the file content cannot be parsed as the selected format (e.g. invalid JSON, wrong structure)
- **THEN** the system MUST fail at the parse step with a format-specific error message
- **AND** MUST NOT create any secrets or folders
