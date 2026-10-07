## ADDED Requirements

### Requirement: Create a send from the popup
The popup MUST offer a Send tab to create a send from text, or from a username and password whose body is exactly `Username: <username>` newline `Password: <password>`, also prefilled from an open item. The user MUST choose how often it can be opened (1 to 100) and when it expires (1 hour, 1 day, 2, 3, 7 or 30 days, or Custom: 1 to 720 hours). The payload MUST be encrypted in the extension with a fresh key that rides only the link's fragment, and the link MUST be shown once.

#### Scenario: Send a login from an item
@e2e exclude Browser-extension popup. Covered by tests/extension/popupVaultSendGenerator.spec.js ("sends an item's login as a credential") and tests/extension/vaultSendGenerator.spec.js (the link alone decrypts the body; no plaintext in the request).
- **GIVEN** an open item
- **WHEN** the user chooses Send, picks 3 days and creates the link
- **THEN** the request carries `payloadType` `credential` and `ttlSeconds` 259200 and no plaintext
- **AND** the link's fragment holds the key

#### Scenario: Custom expiry above the cap
@e2e exclude Browser-extension popup. Covered by tests/extension/vaultSendGenerator.spec.js ("maps the expiry presets and bounds Custom to 720 hours").
- **GIVEN** the user picks Custom and enters 721 hours
- **WHEN** they create the link
- **THEN** the send is refused with "At most 720 hours (30 days)"

### Requirement: List and end my sends
The Send tab MUST list the account's sends as "Text send" or "Credential send" with their creation date and time and how often they were opened, and MUST let the user end a send.

#### Scenario: End a send
@e2e exclude Browser-extension popup. Covered by tests/extension/vaultSendGenerator.spec.js ("lists and ends sends").
- **GIVEN** the Send tab lists a send
- **WHEN** the user chooses End
- **THEN** the send ends and the link no longer opens
