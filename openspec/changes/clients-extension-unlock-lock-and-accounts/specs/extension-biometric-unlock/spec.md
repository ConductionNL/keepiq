## ADDED Requirements

### Requirement: Enrol a platform passkey for extension unlock

The extension MUST let a user with a known master password enrol a platform authenticator (fingerprint or face) that supports the WebAuthn `prf` extension, with the extension's own origin as relying party and `userVerification` `required`. The extension MUST wrap the raw vault unlock key with an AES-256-GCM key derived from the PRF output and MUST send the server only the credential metadata, the PRF salt and the wrapped key, stored with `client_kind` `extension`. The master password, the raw unlock key and the PRF output MUST NOT reach the server.

#### Scenario: Enrolment stores only a wrapped key

- **GIVEN** a vault owner with a paired extension on a laptop with Windows Hello
- **WHEN** they choose "Unlock with fingerprint or face" in the popup, confirm their master password and pass the Windows Hello prompt
- **THEN** `POST /api/v1/passkeys` MUST receive a credential with `client_kind` `extension`, a PRF salt and a wrapped unlock key
- **AND** the request MUST NOT contain the master password, the raw unlock key or the PRF output

### Requirement: Unlock the extension with the enrolled passkey

The extension MUST let the user unlock with the enrolled credential: it MUST request the login options for `client` `extension` and its own relying party, run the WebAuthn ceremony with the stored PRF salt, unwrap the raw unlock key in an extension page, hand it to the worker over internal extension messaging, and import the private key as a non-extractable `CryptoKey`. The raw unlock key MUST NOT be written to extension storage. The master password MUST remain available as an unlock method.

#### Scenario: Fingerprint unlock

- **GIVEN** a vault owner who enrolled a fingerprint and whose extension is locked
- **WHEN** they choose the fingerprint unlock in the popup and touch the sensor
- **THEN** the extension MUST unlock and list the matching logins for the current site
- **AND** no extension storage area MUST contain the raw unlock key afterwards

#### Scenario: A changed master password retires the credential

- **GIVEN** a vault owner with an enrolled extension credential
- **WHEN** they change their master password in the web app
- **THEN** the login options MUST no longer offer the extension credential
- **AND** the popup MUST fall back to the master password form

### Requirement: Extension credentials are visible and revocable in the web app

The web app's passkey list MUST show extension credentials labelled as browser extension credentials, and the owner MUST be able to revoke one through `DELETE /api/v1/passkeys/{id}`. The web app MUST NOT offer an extension credential on its lock screen, and the extension MUST NOT be offered a web credential.

#### Scenario: Revoking a lost laptop's extension credential

- **GIVEN** a vault owner whose laptop with an enrolled extension credential is lost
- **WHEN** they revoke that credential in the passkey list of the web app
- **THEN** the extension on that laptop MUST no longer be able to unlock with it

### Requirement: Biometric unlock is offered only where it works

The extension MUST offer biometric unlock only when its page exposes WebAuthn, a user-verifying platform authenticator is available, and enrolment reports PRF support. Otherwise it MUST show the master password form only, without an error.

#### Scenario: Browser without WebAuthn in extension pages

- **GIVEN** a browser that refuses a WebAuthn ceremony from an extension page
- **WHEN** the vault owner opens the locked popup
- **THEN** the popup MUST show the master password form and no biometric option
