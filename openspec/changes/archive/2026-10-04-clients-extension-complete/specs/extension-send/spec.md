## ADDED Requirements

### Requirement: Password-protected sends
The Send form MUST offer an optional password. With one, the content key MUST be wrapped under an Argon2id key derived from the password in the extension, as the web app does; the server receives the wrapped key and the salt, never the password, and the link carries no key. The list MUST offer Copy link only for sends made while the popup is open.

#### Scenario: Send with a password
@e2e exclude Browser-extension popup. Covered by tests/extension/vaultSendGenerator.spec.js ("wraps the key under a password") and live in Chromium, where the web app opened the send with its password.
- **GIVEN** a text and a password in the Send form
- **WHEN** the user creates the link
- **THEN** the link has no key, the request carries a wrapped key and a salt but no password
- **AND** the recipient opens the send by entering the password
