## MODIFIED Requirements

### Requirement: Vault section entries
The extension SHALL show Sync now with "Last sync: <time>" (or "Never" before the first sync), Folders (the folder manager from ext-vault-edit), Import items and Export vault (each opens the Keepiq web app in a new tab) and Default item type. Export vault is disabled with "Turned off by your organisation" while the stored policy reports `vault_export_disabled` for the account. Default item type is labelled "Default item type in this extension", is stored per account, lists the account's cached secret types by label except `passkey` and `totp`, defaults to Login, and is the type New starts with. A stored type the account's cached types lack, or a stored `totp`, is read as Login. There is no website-icons setting (ADR-002).

#### Scenario: Sync now
- **GIVEN** an unlocked account online
- **WHEN** the user taps Sync now
- **THEN** a sync runs and "Last sync" updates on completion

#### Scenario: Sync now offline
- **WHEN** the user taps Sync now while the server is unreachable
- **THEN** "Sync failed, you are offline" is shown and "Last sync" keeps the previous time

#### Scenario: Sync now server error
- **WHEN** the user taps Sync now and the server answers with an error or is busy
- **THEN** "Sync failed, try again later" is shown and "Last sync" keeps the previous time

#### Scenario: Export turned off
- **GIVEN** the last policy read reports `vault_export_disabled: true`
- **WHEN** the Vault view renders
- **THEN** Export vault is disabled with "Turned off by your organisation" and Import items stays a link

#### Scenario: Default item type
- **GIVEN** the user picks Note as the default item type
- **WHEN** they tap New in the Vault tab
- **THEN** the form starts as a note

#### Scenario: Default type missing on this server
- **GIVEN** a stored default item type `<slug>` that the account's cached types do not contain
- **WHEN** the Vault view renders
- **THEN** the control shows Login and New starts as a login

#### Scenario: A stored default of Authenticator
- **GIVEN** a stored default item type `totp`, from before this change
- **WHEN** the Vault view renders
- **THEN** the control shows Login, Authenticator is not listed, and New starts as a login
