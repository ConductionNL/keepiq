# extension-vault Specification

## Purpose
The Vault tab of the browser extension: browse, open, edit, clone, move and delete items, and manage folders, with values decrypted in the worker.

## Requirements

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

### Requirement: Detail sections for every kind of item
The item detail MUST show the name, the type and the folder path ("Work / Clients", or "No folder"), and then, by kind: Login credentials (username, and the password masked with Show and Copy); an authenticator code computed in the popup, grouped in two halves, with a countdown and Copy, or "This is not a valid authenticator secret"; for a card the brand and last four digits, the cardholder and expiry in plain text and number, CVV and PIN masked; for an identity every field, the BSN masked; for a passkey the site, the account and when it was created, never its private key; the website with Open and Copy; additional fields, each masked with Show and Copy, or "Could not read additional fields"; notes; and when the item was created, updated and expires. A blocked item MUST show why, offer to open Keepiq in Nextcloud, and MUST NOT be decrypted or editable. Decrypted values MUST be dropped when the view closes.

#### Scenario: A login with extra fields and notes
@e2e exclude Browser-extension popup. Covered by tests/extension/popupItemDetail.spec.js ("shows a login with its folder path, extra fields masked, notes and dates").
- **GIVEN** a login in Work with an extra field `pin` and notes
- **WHEN** the user opens it
- **THEN** the header reads "login · Work", the pin is masked until Show, and the notes are shown

#### Scenario: A blocked item
@e2e exclude Browser-extension popup. Covered by tests/extension/popupItemDetail.spec.js ("shows why a blocked item cannot open").
- **GIVEN** an item encrypted for a revoked key
- **WHEN** the user opens it
- **THEN** the reason is shown, nothing is decrypted and Edit is disabled

### Requirement: Edit every kind of item
The add form MUST offer a type and the fields of that type: username and password for logins, the secret for authenticators, the card or identity fields, notes, additional fields (add, show, remove; a blank, duplicate or reserved name such as `key`, `login`, `url` or `notes` refused) and a folder. A note's text is stored in the key; other items keep notes in the additional field `notes`; card and identity fields are stored as JSON in the key. Edit MUST start from the item fetched fresh from the server and MUST send only the parts that changed. Names MUST have at most 255 characters, the width of the server's name column. Addresses and values MUST respect the import limits (4096 characters; 65536 bytes for the key and the additional fields). A failed save MUST keep the form as typed and say what went wrong (a key migration in progress, a blocked suite, the server's reason, or no connection). Leaving a form with changes MUST ask "You have unsaved changes. Discard them?".

#### Scenario: Change a password
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js (the update carries only the key) and tests/extension/itemForm.spec.js.
- **GIVEN** an open login
- **WHEN** the user changes only its password and saves
- **THEN** the update carries only the encrypted key

#### Scenario: Leave a changed form
@e2e exclude Browser-extension popup. Covered by tests/extension/popupItemDetail.spec.js ("asks before leaving a form with changes").
- **GIVEN** an edit form with a changed name
- **WHEN** the user switches tab and does not confirm
- **THEN** the form stays with the change

### Requirement: Clone and move
Clone MUST open the add form filled from the item with " - Clone" after the name, and saving MUST create a new item and leave the original untouched. Move MUST offer the folders and change only the item's folder.

#### Scenario: Clone
@e2e exclude Browser-extension popup. Covered by tests/extension/popupItemDetail.spec.js ("clones an item as a new one").
- **GIVEN** an open item named Mail
- **WHEN** the user clones and saves it
- **THEN** a new item "Mail - Clone" is created and no update is sent

#### Scenario: Move
@e2e exclude Browser-extension popup. Covered by tests/extension/popupItemDetail.spec.js ("moves an item by changing only its folder").
- **GIVEN** an open item in Work
- **WHEN** the user moves it to Work / Clients
- **THEN** the update carries only the folder

### Requirement: Manage folders
The Vault tab MUST offer a folder manager that shows the folders as a tree, sorted by name, and lets the user add a folder (inside another or at the top), rename one (only the name is sent) and delete one. A blank name or a name with a slash MUST be refused before sending. Deleting MUST follow the server's protocol: an empty folder after "Delete <name>? This cannot be undone."; a folder with items only after the user chose to move them to the parent or delete them; a folder with subfolders only with a choice for its own items and, for every direct subfolder, keep, move its items or delete. The manager MUST say once that folder names are not encrypted. The item form's folder picker MUST offer "New folder…".

#### Scenario: Add a folder inside another
@e2e exclude Browser-extension popup. Covered by tests/extension/folders.spec.js ("adds a folder inside another, and refuses a slash").
- **GIVEN** the folder manager
- **WHEN** the user adds "Invoices" inside Work
- **THEN** the folder is created with Work as parent and shows in the tree

#### Scenario: Delete a folder with subfolders
@e2e exclude Browser-extension popup. Covered by tests/extension/folders.spec.js ("deletes a folder with subfolders with a plan for every subfolder").
- **GIVEN** Work holds items and the subfolder Clients
- **WHEN** the user chooses to move Clients' items and confirms
- **THEN** the delete request carries a plan for Work's items and for Clients

### Requirement: A passkey's private key stays in the worker
The worker MUST NOT send a passkey's private key to the popup. The popup receives the site and account of a passkey only, and its edit form MUST NOT offer the key. The extension MUST NOT create a passkey item, change a passkey's key, clone a passkey or offer it for Send: a passkey is made and updated by the website that uses it. Name, address, folder and notes stay editable.

#### Scenario: Opening a passkey
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/popupItemDetail.spec.js ("keeps the private key in the worker: the popup gets site and account only") and ("shows a passkey without clone or Send, and its edit form has no key field").
- **GIVEN** a passkey item in the vault
- **WHEN** the user opens it and then edits it
- **THEN** the popup shows its site and account, no clone or Send, no key field, and the private key appears nowhere in the popup

#### Scenario: Creating or re-keying a passkey
@e2e exclude Browser-extension worker. Covered by tests/extension/popupItemDetail.spec.js ("refuses to create a passkey or change its key, and offers no passkey type for a new item").
- **GIVEN** an unlocked vault
- **WHEN** a save would create a passkey or change a passkey's key
- **THEN** the worker refuses it, and the new-item form offers no passkey type
