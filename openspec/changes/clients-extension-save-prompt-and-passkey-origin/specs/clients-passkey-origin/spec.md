## ADDED Requirements

### Requirement: Passkey origin binding

The extension MUST derive the origin of a passkey request from the page location in the content script and MUST refuse a request whose relying party id is not equal to, or a registrable suffix of, that origin's host. The `clientDataJSON` origin MUST be that derived origin.

#### Scenario: A page asks for another site's rpId

- **GIVEN** a page on evil.example calling navigator.credentials.get with rpId bank.example
- **WHEN** the request reaches the extension
- **THEN** the extension refuses and no assertion is created

#### Scenario: A page uses its own rpId

- **GIVEN** a page on login.bank.example with rpId bank.example
- **WHEN** the user consents
- **THEN** the assertion is created and its client data origin is https://login.bank.example
