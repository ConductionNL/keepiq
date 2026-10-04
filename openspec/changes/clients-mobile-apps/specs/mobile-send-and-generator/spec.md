## ADDED Requirements

### Requirement: Generate passwords and passphrases
The apps MUST generate passwords and passphrases on the device with a cryptographically secure random source, using the same options as the web generator, and MUST meet the organisation's password policy from `GET /api/settings/policy`. The generator MUST be reachable from the item editor and on its own.

#### Scenario: Meet the organisation policy
- **GIVEN** an organisation policy of at least 20 characters with a digit
- **WHEN** the user generates a password
- **THEN** the result has at least 20 characters and a digit, and shorter lengths cannot be picked

#### Scenario: Passphrase
- **GIVEN** the generator on passphrase
- **WHEN** the user picks 6 words
- **THEN** a passphrase of 6 words is generated without a server request

### Requirement: Create and manage a Send
The apps MUST create a Send from text, encrypted on the device with a fresh AES-256-GCM key, with the same options as the web app: expiry, view limit and an optional password wrapped with Argon2id. The link MUST carry the key in the fragment (`#k=`) unless a password protects it. The system share sheet MUST offer the link. The user MUST be able to list and delete their Sends.

#### Scenario: Share a Send link
- **GIVEN** the Send screen
- **WHEN** the user enters text, picks one view and shares
- **THEN** the share sheet offers a link whose fragment holds the key, and the web page opens it once

#### Scenario: Send with a password
- **GIVEN** a Send protected with a password
- **WHEN** the recipient opens the link in a browser and enters the password
- **THEN** the text decrypts in the browser, so the server never sees the key

### Requirement: Open a Send link in the app
When a Keepiq Send link is opened on a phone with the app installed, the app MAY open it, decrypting on the device as the public page does. The public web page MUST keep working for recipients without the app.

#### Scenario: Recipient without the app
- **GIVEN** a Send link and a phone without Keepiq
- **WHEN** the recipient taps the link
- **THEN** the public web page opens and decrypts it as before
