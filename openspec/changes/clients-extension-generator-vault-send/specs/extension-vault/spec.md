## ADDED Requirements

### Requirement: Browse and search the vault
The popup MUST offer a Vault tab that lists the account's vault items alphabetically, without trashed or archived items, searchable by name and address and filterable by folder and type. The list MUST be built from index fields only: no encrypted or decrypted value reaches the popup until the user opens an item.

#### Scenario: Find an item
@e2e exclude Browser-extension popup. Covered by tests/extension/vaultSendGenerator.spec.js (index, search, filters, no blobs) and tests/extension/popupVaultSendGenerator.spec.js on the real popup.
- **GIVEN** an unlocked extension with items in several folders
- **WHEN** the user types part of a name or address, or picks a folder or type
- **THEN** the list shows only the matching items

### Requirement: Item detail with copy and reveal
Opening an item MUST show its name, address and username, with the password hidden until the user chooses Show, and MUST let the user copy the username and the password. An item the extension cannot decrypt MUST NOT open; the user is pointed to the web app. The popup MUST drop the decrypted values when the item closes.

#### Scenario: Open an item and show its password
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("browses, reveals, edits and creates items").
- **GIVEN** the Vault tab lists an item
- **WHEN** the user opens it and chooses Show
- **THEN** the username and then the password are shown

### Requirement: Add, edit and delete items
The user MUST be able to add an item and edit an item (name, address, username, password, folder) with a generated password on request, and to move an item to the trash after confirming. Values MUST be encrypted in the extension before they are sent; the org password policy MUST be applied before saving; a refused save MUST keep the user's input.

#### Scenario: Change a password
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js, which records every request body and fails on any plaintext value.
- **GIVEN** an open item
- **WHEN** the user edits its password and saves
- **THEN** the server receives only ciphertext for the username and password

#### Scenario: Delete an item
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("moves an item to the trash after confirmation").
- **GIVEN** an open item
- **WHEN** the user chooses Delete and confirms
- **THEN** the item moves to the trash and can be restored in the web app
