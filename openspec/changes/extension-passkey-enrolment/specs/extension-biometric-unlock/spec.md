## MODIFIED Requirements

### Requirement: Enrol a platform passkey for extension unlock

The extension MUST let a user with a known master password enrol a platform authenticator (fingerprint or face) that supports the WebAuthn `prf` extension, with the host of its paired Nextcloud server, without port, as relying party id and `userVerification` `required`. The server MUST decide that relying party id itself from the request host and MUST ignore a relying party id named in the request. The extension MUST run the ceremony in an extension tab or window, not the popup. The credential MUST use the random user handle the server issued, not the uid, and MUST be created with `residentKey` `discouraged`. The server MUST verify the `create()` attestation and the proof `get()` assertion with the WebAuthn library core ships, with every check the web app's enrolment uses except that the origin MUST be an exact entry of the trusted extension origins (`extension-passkey-trust`). The extension MUST wrap the raw vault unlock key with an AES-256-GCM key derived from the PRF output and MUST send the server only the attestation, the assertion, the PRF salt and the wrapped key, stored with `client_kind` `extension`, the relying party id, the user handle, the signature counter and the id of the app password the request was authenticated with. The master password, the raw unlock key and the PRF output MUST NOT reach the server.

#### Scenario: Enrolment stores only a wrapped key

@e2e exclude Browser-extension window and server ceremony; covered by the unit tests named in tasks.md 1.3 and 2.2.

- **GIVEN** a vault owner with a paired extension from the Chrome Web Store on a laptop with Windows Hello
- **WHEN** they choose "Unlock with fingerprint or face", confirm their master password and pass the Windows Hello prompt
- **THEN** `POST /api/v1/passkeys` MUST receive an attestation and assertion with relying party id equal to the server host and a client data origin equal to the store build's origin
- **AND** the server MUST store the credential with `client_kind` `extension`, the app password's token id and the issued user handle
- **AND** the request MUST NOT contain the master password, the raw unlock key or the PRF output

#### Scenario: A relying party named by the client is ignored

@e2e exclude Server ceremony; covered by PasskeyServiceTest in tasks.md 1.3.

- **GIVEN** an enrolment request from a trusted extension that names another relying party id in its body
- **WHEN** the server verifies it
- **THEN** the server MUST verify against its own host only, and a ceremony made for another relying party MUST be refused with the generic message

#### Scenario: An untrusted extension cannot enrol

@e2e exclude Server ceremony; covered by WebAuthnVerifierTest in tasks.md 1.2.

- **GIVEN** a valid app password and a ceremony whose client data origin is an extension origin that is not on the trusted list
- **WHEN** it is posted to `POST /api/v1/passkeys`
- **THEN** the server MUST refuse it with the generic message, store nothing and register a throttled attempt

### Requirement: Unlock the extension with the enrolled passkey

The extension MUST let the user unlock with the enrolled credential. It MUST request the unlock options for `client` `extension`, which MUST contain a server-issued challenge, the relying party id and the credential ids and PRF salts of the active extension credentials bound to the requesting app password, and MUST NOT contain a wrapped unlock key. It MUST run `get()` in an extension tab or window with that relying party id, `allowCredentials` set, `userVerification` `required` and the stored PRF salt, and post the assertion to the server. The server MUST release the wrapped unlock key only after it took the challenge once and verified the assertion with the extension ceremony step list, user verification, the signature counter, the token binding and the current epoch. The extension MUST then derive the key-encryption key, unwrap the raw unlock key in the extension page, hand it to the worker over internal extension messaging, and import the private key as a non-extractable `CryptoKey`. The raw unlock key MUST NOT be written to extension storage. The master password MUST remain available as an unlock method.

#### Scenario: Fingerprint unlock

@e2e exclude Browser-extension window and worker; covered by the unit tests named in tasks.md 1.4 and 2.3.

- **GIVEN** a vault owner who enrolled a fingerprint and whose extension is locked
- **WHEN** they choose the fingerprint unlock and touch the sensor
- **THEN** the unlock options MUST carry no wrapped unlock key
- **AND** the server MUST return the wrapped unlock key only in answer to the verified assertion
- **AND** the extension MUST unlock and list the matching logins for the current site
- **AND** no extension storage area MUST contain the raw unlock key afterwards

#### Scenario: An assertion is presented twice

@e2e exclude Server ceremony; covered by ExtensionChallengeStoreTest in tasks.md 1.1.

- **GIVEN** an unlock assertion the server already accepted
- **WHEN** the same assertion is posted again
- **THEN** the server MUST refuse it because its challenge is spent, and MUST NOT return the wrapped unlock key

#### Scenario: The signature counter goes back

@e2e exclude Server ceremony; covered by PasskeyServiceTest in tasks.md 1.4.

- **GIVEN** an extension credential whose stored signature counter is 12
- **WHEN** an otherwise valid assertion reports counter 12 or lower
- **THEN** the server MUST refuse the unlock, set the credential `suspended` and notify the owner
- **AND** an authenticator that reports 0 on both sides MUST still unlock

#### Scenario: A changed master password retires the credential

@e2e exclude Server; covered by PasskeyServiceTest (epoch) in tasks.md 1.4.

- **GIVEN** a vault owner with an enrolled extension credential
- **WHEN** they change their master password in the web app
- **THEN** the unlock options MUST no longer offer the extension credential
- **AND** the extension MUST fall back to the master password form
