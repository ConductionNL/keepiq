# Clients passkey origin Specification

**Status**: done

**OpenSpec changes:**
- [clients-extension-save-prompt-and-passkey-origin](../../changes/archive/2026-09-30-clients-extension-save-prompt-and-passkey-origin/) _(archived 2026-09-30)_

## Purpose
A passkey request in the browser extension is bound to the origin the browser reports for the requesting frame, and its relying party id must belong to that origin. Parity row clients-05.

## Requirements

### Requirement: Passkey origin binding

The extension MUST take the origin of a passkey request from the browser's record of the requesting frame (the runtime message sender), never from the page's own message, and MUST refuse a request whose relying party id is not equal to, or a registrable suffix of, that origin's host. The `clientDataJSON` origin MUST be that derived origin.

#### Scenario: A page asks for another site's rpId

@e2e exclude Covered by vitest tests/extension/passkeyOrigin.spec.js 'refuses get for another site' (no vault read) and 'refuses a foreign rpId'.

- **GIVEN** a page on evil.example calling navigator.credentials.get with rpId bank.example
- **WHEN** the request reaches the extension
- **THEN** the extension refuses and no assertion is created

#### Scenario: A page uses its own rpId

@e2e exclude Covered by vitest tests/extension/passkeyOrigin.spec.js 'allows a parent domain' and 'searches the vault for its own parent rpId'; the client data origin is the origin handed to getAssertion, pinned by tests/extension/webauthn.spec.js.

- **GIVEN** a page on login.bank.example with rpId bank.example
- **WHEN** the user consents
- **THEN** the assertion is created and its client data origin is https://login.bank.example
