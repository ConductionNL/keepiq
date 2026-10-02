## ADDED Requirements

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
The add form MUST offer a type and the fields of that type: username and password for logins, the secret for authenticators, the card or identity fields, notes, additional fields (add, show, remove; a blank, duplicate or reserved name such as `key`, `login`, `url` or `notes` refused) and a folder. A note's text is stored in the key; other items keep notes in the additional field `notes`; card and identity fields are stored as JSON in the key. Edit MUST start from the item fetched fresh from the server and MUST send only the parts that changed. Names, addresses and values MUST respect the import limits (4096 characters; 65536 bytes for the key and the additional fields). A failed save MUST keep the form as typed and say what went wrong (a key migration in progress, a blocked suite, the server's reason, or no connection). Leaving a form with changes MUST ask "You have unsaved changes. Discard them?".

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
