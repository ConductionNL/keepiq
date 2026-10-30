## ADDED Requirements

### Requirement: A login's passkeys
The detail SHALL list the passkeys in a login's `passkeys` field under "Passkeys", each with its relying party (`rpName` with `rpId`, or `rpId` alone), user name (`userName`, falling back to `userDisplayName`) and `createdAt`, for a use-only copy too. The popup asks the background for them with `item.passkeys`, which decrypts the field and answers only those three values per entry; no private key or other credential field reaches the popup. An entry that does not parse shows "Could not read this passkey"; a field that does not decrypt or parse as an array shows it once. A login without passkeys shows no section. Deleting a passkey stays in the web app.

#### Scenario: Login with two passkeys
- **GIVEN** a login whose `passkeys` holds credentials for `example.com` and `example.org`
- **WHEN** the detail opens
- **THEN** both are listed with relying party, user name and creation date
- **AND** no private key is in the DOM or in any message to the popup

## MODIFIED Requirements

### Requirement: Passkey section
For an existing standalone item of type passkey the detail SHALL parse the decrypted `key` and show the relying party (`rpName` with `rpId`, or `rpId` alone), the user name (`userName`, falling back to `userDisplayName`) and the credential's `createdAt`, with the note "Keepiq signs in with passkeys saved on a login, not with this item". The private key MUST NOT be rendered or copyable. An unparseable payload shows "Could not read this passkey".

#### Scenario: Passkey detail
- **GIVEN** a standalone passkey item for `example.com`
- **WHEN** the detail opens
- **THEN** relying party, user name, creation date and the note are shown
- **AND** no private key field exists in the DOM
