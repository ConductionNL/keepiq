## ADDED Requirements

### Requirement: Extension ceremonies pass only for trusted extension origins

The server MUST accept a WebAuthn enrolment or unlock ceremony with `client_kind` `extension` only when the client data origin equals, as an exact string, an entry of the trusted extension origins. The trusted extension origins MUST be the origins of Keepiq's official store builds pinned in the app's code, plus the origins an admin added. An admin entry MUST match `chrome-extension://` followed by 32 letters `a` to `p`, or `moz-extension://` followed by 64 letters `a` to `p`, with nothing before or after; the system MUST refuse to save any other entry, including any wildcard. Every other check of the ceremony MUST be the one core's WebAuthn library performs.

#### Scenario: The official store build

@e2e exclude Server ceremony; covered by WebAuthnVerifierTest in tasks.md 1.2.

- **GIVEN** an instance with default settings
- **WHEN** the Firefox build with add-on id `keepiq@conduction.nl` enrols a passkey
- **THEN** its origin MUST be accepted without any admin setting

#### Scenario: An admin adds a self-built extension

@e2e exclude Admin settings API; covered by AdminSettingsServiceTest in tasks.md 1.5.

- **GIVEN** an admin on the Keepiq admin settings
- **WHEN** they add `chrome-extension://abcdefghijklmnopabcdefghijklmnop`
- **THEN** the entry MUST be saved and ceremonies from that origin MUST be accepted

#### Scenario: A wildcard is refused

@e2e exclude Admin settings API; covered by AdminSettingsServiceTest in tasks.md 1.5.

- **GIVEN** an admin on the Keepiq admin settings
- **WHEN** they add `chrome-extension://*` or an origin with a path
- **THEN** the setting MUST NOT be saved and the admin MUST be told which entry is invalid

### Requirement: Extension passkeys fail closed

The server MUST refuse every extension enrolment and unlock ceremony when the admin turned extension passkeys off, when the admin origin setting cannot be parsed, or when the origin is not trusted. A refusal MUST be logged with the failed check, answered with one generic message, and MUST NOT affect master-password unlock. Removing an origin from the trusted list MUST stop unlock for its credentials at their next attempt.

#### Scenario: A broken setting

@e2e exclude Server ceremony; covered by TrustedExtensionOriginsTest in tasks.md 1.2.

- **GIVEN** an `extension_passkey_origins` value that is not a JSON list
- **WHEN** any extension posts an enrolment or unlock
- **THEN** the server MUST refuse it, even for a pinned store origin
- **AND** the extension MUST still unlock with the master password

#### Scenario: An origin is removed

@e2e exclude Server ceremony; covered by PasskeyServiceTest in tasks.md 1.4.

- **GIVEN** an extension credential enrolled from an admin-added origin
- **WHEN** the admin removes that origin and the user tries to unlock with the credential
- **THEN** the server MUST refuse the unlock and return no wrapped unlock key

### Requirement: Extension challenges are server-side, bound and single-use

For requests authenticated with an app password, the server MUST store WebAuthn challenges server-side, bound to the uid, the app password's token id and the purpose (`enrol` or `unlock`). A challenge MUST be 32 bytes from `ISecureRandom`, MUST expire 300 seconds after it was issued, and MUST be taken by an atomic delete so that at most one request can verify against it. Passkey enrolment and unlock with `client_kind` `extension` MUST be refused for a request not authenticated with an app password.

#### Scenario: Two requests race for one challenge

@e2e exclude Server store; covered by ExtensionChallengeStoreTest in tasks.md 1.1.

- **GIVEN** one pending unlock challenge
- **WHEN** two unlock requests presenting it arrive at the same time
- **THEN** at most one of them MUST verify, and the other MUST be refused

#### Scenario: A challenge from another app password

@e2e exclude Server store; covered by ExtensionChallengeStoreTest in tasks.md 1.1.

- **GIVEN** a challenge issued to the user's extension app password
- **WHEN** a request authenticated with another app password of the same user presents it
- **THEN** the server MUST refuse it

#### Scenario: An expired challenge

@e2e exclude Server store; covered by ExtensionChallengeStoreTest in tasks.md 1.1.

- **GIVEN** a challenge issued 301 seconds ago
- **WHEN** the extension presents it
- **THEN** the server MUST refuse it

### Requirement: Extension credentials follow their app password

Each extension credential MUST store the token id of the app password it was enrolled under. The server MUST offer its unlock options, challenge and wrapped unlock key only to requests authenticated with that app password. When Nextcloud invalidates that app password (`TokenInvalidatedEvent`), the system MUST delete the extension credentials and pending challenges bound to it and MUST write an audit event.

#### Scenario: Revoked in Devices & sessions

@e2e exclude Core event listener; covered by TokenInvalidatedListenerTest in tasks.md 1.6.

- **GIVEN** a user whose laptop extension has an enrolled credential
- **WHEN** they revoke that extension's app password in Nextcloud's Devices & sessions
- **THEN** the credential and its wrapped unlock key MUST be deleted
- **AND** the extension MUST be signed out at its next request

#### Scenario: Another app password of the same user

@e2e exclude Server; covered by PasskeyServiceTest in tasks.md 1.4.

- **GIVEN** an extension credential bound to app password one
- **WHEN** a request authenticated with app password two asks for extension unlock options
- **THEN** that credential MUST NOT be listed

### Requirement: Passkey ceremonies are throttled with Nextcloud's throttler

Every refused enrolment or unlock MUST register an attempt with `IThrottler` under `keepiq_passkey_enrol` or `keepiq_passkey_unlock`, and both routes MUST apply the throttler's delay before verifying. A verified unlock MUST reset the delay for its action.

#### Scenario: Repeated failures

@e2e exclude Server; covered by PasskeyControllerTest in tasks.md 1.4.

- **GIVEN** an IP that sent several refused unlock assertions
- **WHEN** it sends another
- **THEN** the server MUST apply the throttler's delay before verifying, and refuse once the throttler's maximum is reached
