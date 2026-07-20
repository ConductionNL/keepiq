---
status: proposed
---

# Extension WebAuthn Passkey Provider

## Purpose

Make the browser extension act as a WebAuthn credential provider so stored passkeys become usable, not inert data: intercept `navigator.credentials.create()`/`get()` on relying-party pages, save newly-created passkeys into the vault and assert with stored ones, sign the ceremony client-side in the extension with the decrypted passkey private key, update the stored signature counter, and gate everything on per-site user opt-in with fall-through to the platform authenticator on decline — preserving zero-knowledge (the private key is only ever decrypted in the extension) with zero server changes.

## ADDED Requirements

### Requirement: Intercept passkey registration and save into the vault

The extension MUST intercept `navigator.credentials.create()` on a relying-party page and, on per-site user opt-in and with the extension unlocked, generate a new WebAuthn key pair client-side (WebCrypto, honouring the page's `pubKeyCredParams` with ES256/`-7` as default), build the attestation response, and save the credential by writing a `passkey`-typed secret through the existing secret-create path defined by `passkey-item-type` (canonical JSON in the encrypted `key`, RP id mirrored into the plaintext `url`). The new private key MUST NOT be transmitted to the server, and MUST NOT be written to any persistent extension storage.

#### Scenario: Create saves a passkey through the passkey-item-type seam

@e2e exclude Browser-extension WebAuthn `create` ceremony + in-extension WebCrypto key generation — not drivable by the app's Playwright harness against the deployed instance; covered by extension unit/integration tests (key-pair generation, attestation build, and the secret-create write-through).
- **GIVEN** an unlocked extension and a page calling `navigator.credentials.create()` for RP `example.com`
- **WHEN** the user opts in at the consent prompt
- **THEN** the extension MUST generate the key pair client-side and return a valid attestation `PublicKeyCredential` to the page
- **AND** it MUST save a `passkey`-typed secret whose encrypted `key` holds the canonical credential JSON and whose plaintext `url` is `example.com`
- **AND** no HTTP request MUST contain the plaintext private key

### Requirement: Intercept passkey authentication and assert with a stored passkey

The extension MUST intercept `navigator.credentials.get()` on a relying-party page, match stored vault passkeys by RP id (the unencrypted `url`) and the request's `allowCredentials`, prompt the user to select and confirm, decrypt the chosen passkey's private key in the extension, build the `authenticatorData`, sign `SHA-256(authenticatorData ‖ clientDataHash)` with the credential's COSE algorithm, and return the assertion to the page. The private key MUST be decrypted only in the extension's service-worker memory and MUST NOT be transmitted to the server.

#### Scenario: Get asserts with the decrypted vault passkey

@e2e exclude Browser-extension WebAuthn `get` ceremony + in-extension assertion signing — not drivable by the app's Playwright harness; covered by extension unit/integration tests (candidate match, WebCrypto signing, assertion shape) using placeholder credential ids/keys.
- **GIVEN** an unlocked extension holding a `passkey` secret for `example.com` (credential id `BASE64URL_CREDENTIAL_ID_HERE`) and a page calling `navigator.credentials.get()` for `example.com`
- **WHEN** the user selects that passkey and confirms
- **THEN** the extension MUST decrypt the private key in the extension only, sign the assertion client-side, and return it to the page
- **AND** no HTTP request MUST contain the plaintext private key, the challenge, or the assertion signature

### Requirement: Update the stored signature counter after an assertion

The extension MUST report a signature counter in the assertion's `authenticatorData`. For a stored credential whose counter is `0` (the synced/multi-device convention) the extension MUST report `0` and MUST NOT write back. For a stored credential whose counter is non-zero, the extension MUST increment it and persist the new value by re-encrypting the credential JSON through the existing secret-update path — with no new endpoint, column, or migration.

#### Scenario: Non-zero counter is incremented and persisted

@e2e exclude Counter write-back over an encrypted secret update — covered by extension/backend unit tests, not a DOM flow.
- **GIVEN** a stored `passkey` secret whose counter is `41`
- **WHEN** the extension completes a `get` assertion with it
- **THEN** the assertion's counter MUST be `42`
- **AND** the extension MUST persist `42` by updating the existing `passkey` secret's encrypted `key` via the existing secret-update path

#### Scenario: Synced (zero) counter is not written back

@e2e exclude Multi-device-credential counter convention — covered by extension unit tests.
- **GIVEN** a stored `passkey` secret whose counter is `0`
- **WHEN** the extension completes a `get` assertion with it
- **THEN** the assertion's counter MUST be `0`
- **AND** the extension MUST NOT issue any secret-update request

### Requirement: Per-site opt-in with fall-through to the platform authenticator

The extension MUST show a consent prompt naming the relying-party origin before any ceremony and MUST NOT register or assert silently. If the user declines, or the relying party requires an authenticator the vault cannot be, the extension MUST fall through so the browser's platform authenticator handles the ceremony (returning "not handled" on the native proxy path, or calling the original `navigator.credentials` method on the shim path) rather than aborting the page's flow.

#### Scenario: Declining falls through to the platform authenticator

@e2e exclude Browser-extension consent + WebAuthn fall-through behaviour — not drivable by the app's Playwright harness; covered by extension integration tests.
- **GIVEN** a page invoking `navigator.credentials.get()` and an extension consent prompt for the RP origin
- **WHEN** the user declines
- **THEN** the extension MUST NOT sign anything
- **AND** the ceremony MUST proceed with the browser's platform authenticator

### Requirement: User verification is gated on the unlocked extension and zero-knowledge is preserved

The extension MUST set the assertion's user-verification flag only when it is unlocked (master password entered per the in-extension unlock), MUST prompt for unlock before signing when locked, and MUST NEVER sign with a key it cannot decrypt. The passkey private key MUST be held only in service-worker memory as a non-extractable key, MUST NOT be written to any `storage.*`, and MUST NOT be sent to the server at any step.

#### Scenario: A locked extension prompts for unlock before signing

@e2e exclude Extension lock-state gating of the WebAuthn ceremony — covered by extension integration tests.
- **GIVEN** a locked extension and a page calling `navigator.credentials.get()`
- **WHEN** the ceremony reaches the signing step
- **THEN** the extension MUST require the master password before signing
- **AND** it MUST NOT sign or set the user-verification flag until unlocked

#### Scenario: Passkey key material is never persisted or transmitted

@e2e exclude In-memory-key / no-leak contract — asserting the key is `extractable: false`, absent from `storage.*`, and absent from every request body is covered by extension unit tests with a storage/request guard, not a DOM flow.
- **GIVEN** an unlocked extension that has decrypted a passkey private key for signing
- **WHEN** the ceremony completes and the extension stores configuration
- **THEN** the private key MUST be a non-extractable WebCrypto key held only in service-worker memory
- **AND** it MUST NOT appear in any `storage.local`/`storage.sync` write or any HTTP request body
