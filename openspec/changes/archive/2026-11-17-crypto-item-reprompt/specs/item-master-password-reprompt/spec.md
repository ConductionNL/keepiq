## ADDED Requirements

### Requirement: A per-item master password re-prompt

The system MUST let the holder of a secret switch on "Ask for my master password before showing or filling this item" in the create and edit dialogs, stored as the `reprompt` flag on the holder's row. A new share copy MUST start with the owner's value. The vault list MUST mark flagged items.

#### Scenario: A vault user flags a sensitive login

- **GIVEN** a vault user editing the login "Domain admin" in the edit dialog
- **WHEN** the user switches on the re-prompt option and saves
- **THEN** the login shows a lock marker in the vault list at /secrets

### Requirement: The web app verifies the master password before revealing a flagged item

For a flagged secret, the web app MUST verify the master password in the browser, against the user's encrypted private-key envelope, before it reveals the value, copies the value or username, opens the edit dialog, clones, prints or shows a QR code. Each such action MUST ask again. A wrong password MUST reveal nothing. The master password MUST NOT be sent to the server.

#### Scenario: A colleague at an unlocked screen

- **GIVEN** a vault user who left their unlocked vault open with the flagged login "Domain admin"
- **WHEN** someone clicks the reveal button on that login in the secret detail sidebar and enters a wrong master password
- **THEN** the value stays hidden
- **AND** no request carrying the entered password is made

#### Scenario: The owner reveals the value

- **GIVEN** the same flagged login
- **WHEN** the user clicks copy on the password and enters the right master password
- **THEN** the password is copied
- **AND** clicking copy again asks for the master password again

### Requirement: The extension verifies the master password before filling a flagged item

The browser extension MUST ask for the master password in its popup and verify it against the vault key envelope before filling a flagged item. A wrong or missing password MUST fill nothing.

#### Scenario: Filling a flagged login from the extension

- **GIVEN** an unlocked extension on the sign-in page of the flagged login's site
- **WHEN** the user picks the login in the popup
- **THEN** the popup asks for the master password
- **AND** the login is filled only after the right password is entered
