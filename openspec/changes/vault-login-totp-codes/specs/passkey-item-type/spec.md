## ADDED Requirements

### Requirement: New passkeys live on the login

A login MUST be able to hold its passkeys in its own encrypted `passkeys` field, as a JSON array whose entries each follow the canonical credential schema of this spec, exactly as a standalone passkey item holds one credential in `key`. That field is where new passkeys belong. The web app MUST NOT offer the Passkey type in the create dialog or as the default item type, and a saved default of Passkey MUST fall back to Login. A passkey on a login MUST be listed by its metadata only; its private key MUST NOT be shown or copyable in any mode, unlike the reveal and copy that a standalone passkey item gates.

#### Scenario: The create dialog offers no Passkey type

- **GIVEN** a vault user opening the create dialog
- **WHEN** the type list is shown
- **THEN** Passkey MUST NOT be in it

#### Scenario: A login's passkey uses the canonical schema

- **GIVEN** a login whose `passkeys` field holds one credential for `example.com`
- **WHEN** the web app decrypts and lists it
- **THEN** each entry MUST be read with the same canonical schema as a standalone passkey item's `key`
- **AND** its relying party, user name and created date MUST be shown without its private key

### Requirement: Existing passkey items keep working

Passkey items created before logins carried passkeys MUST keep showing, editing, sharing and exporting as before, MUST stay in the vault list's Passkey type filter, and MUST stay excluded from password health. Nothing MUST move or rewrite them automatically.

#### Scenario: An existing passkey item after the change

- **GIVEN** a vault with a standalone passkey item for `example.com`
- **WHEN** the user filters the vault by the Passkey type and opens the item
- **THEN** the item MUST be listed and shown with its site and metadata, as before
