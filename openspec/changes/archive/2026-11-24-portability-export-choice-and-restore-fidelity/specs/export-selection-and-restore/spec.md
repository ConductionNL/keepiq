## ADDED Requirements

### Requirement: Choose what an export contains

The export dialog MUST let the user limit an encrypted backup, plaintext CSV or CXF export to chosen folders with their subfolders, to chosen secret types, or to the secrets currently selected in the vault list, and MUST offer Export selected in the vault list's selection strip. For the encrypted backup and the plaintext CSV the dialog MUST let the user leave out usernames, additional fields or one-time code seeds, and MUST always include each secret's name and value. A backup with fields left out MUST be marked partial inside its encrypted payload.

#### Scenario: A vault user exports the API keys of two folders

- **GIVEN** a vault user with secrets of several types in five folders
- **WHEN** the user opens the export dialog on /secrets, chooses two folders and the type API key, and exports an encrypted backup
- **THEN** the backup holds exactly the API keys in those two folders and their subfolders

#### Scenario: A vault user exports a selection

- **GIVEN** a vault user who selected three secrets in the vault list
- **WHEN** the user chooses Export selected in the selection strip and exports a plaintext CSV after entering the master password
- **THEN** the file holds exactly those three secrets

### Requirement: Nothing is left out of an export in silence

When secrets cannot be decrypted while an export is prepared, the export dialog MUST show how many secrets are not included and MUST let the user cancel before any file is written.

#### Scenario: A secret under a revoked suite

- **GIVEN** a vault user with one secret whose encryption suite was revoked
- **WHEN** the user opens the export dialog
- **THEN** the dialog says that 1 secret could not be decrypted and is not in this export

### Requirement: A restored backup keeps types and row positions

The encrypted backup MUST carry each secret's type name, and for a custom type its label. Restoring a backup MUST give each secret its original type: a system type by name, a custom type by an existing type of the same name, or a new user-scoped custom type created for it. A secret MUST fall back to the default type only when that fails, with a warning on its row. For a backup written before type names were exported, a type id that exists on the instance MUST be used. Every restored or rejected row MUST carry its 1-based position in the backup. A partial backup MUST be announced before the restore commits.

#### Scenario: A vault user restores a backup on a new instance

- **GIVEN** a backup holding an authenticator, a passkey, an API key and a secret of the custom type "VPN profile"
- **WHEN** the user restores it through the import wizard on an instance with no "VPN profile" type
- **THEN** each secret is restored with its own type
- **AND** a user-scoped custom type "VPN profile" exists and the restore summary lists it

#### Scenario: A rejected row names its position

- **GIVEN** a backup whose seventh secret has an empty name
- **WHEN** the user restores it
- **THEN** the rejected rows list shows row 7 with its reason
