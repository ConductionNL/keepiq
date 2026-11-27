## MODIFIED Requirements

### Requirement: A passkey can be deleted from a login

Where the holder may edit the login, each passkey MUST have a Delete action behind a confirmation; confirming MUST save every other entry exactly as stored, or `''` when none remain. After a delete, keyboard focus MUST move to the passkey list when passkeys remain, and otherwise to the Edit button or the detail itself. When a partner organisation the login is shared with stops the save (federated-sharing, "An unreachable partner stops the save unless the user saves without them"), nothing MUST be deleted, and the error MUST name each such partner with its own reason and ask the user to try again later.

#### Scenario: A vault user deletes one passkey

- **GIVEN** the owner of a login with two passkeys
- **WHEN** the owner chooses Delete on the first and confirms
- **THEN** the login is saved with only the second passkey, byte for byte as before
- **AND** cancelling the confirmation instead leaves both passkeys stored

#### Scenario: A partner stops the passkey delete

- **GIVEN** the owner of a login with two passkeys, shared with Bob on `cloud.partner.example`, to which outbound sharing is turned off
- **WHEN** the owner deletes the first passkey and confirms
- **THEN** both passkeys MUST stay stored
- **AND** the error MUST say that sharing to Bob's organisation is turned off and that the passkey was not deleted
