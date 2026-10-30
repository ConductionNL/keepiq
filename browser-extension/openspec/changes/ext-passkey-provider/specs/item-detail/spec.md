## MODIFIED Requirements

### Requirement: Passkey section
For type passkey the detail SHALL parse the decrypted `key` and show the relying party (`rpName` with `rpId`, or `rpId` alone), the user name (`userName`, falling back to `userDisplayName`) and the credential's `createdAt`. The private key MUST NOT be rendered or copyable. An unparseable payload shows "Could not read this passkey".

#### Scenario: Passkey detail
- **GIVEN** a passkey item for `example.com`
- **WHEN** the detail opens
- **THEN** relying party, user name and creation date are shown, without a note that signing in is not supported
- **AND** no private key field exists in the DOM
