## ADDED Requirements

### Requirement: Designate Emergency Contact
The system MUST allow a vault owner (grantor), while their vault is unlocked, to designate one or more Nextcloud users as emergency contacts. Each designation MUST record the grantee, an access level, and a wait period. The **v1 access level MUST be `view`** (the contact may read the grantor's vault); account takeover is out of scope for v1. The wait period MUST be grantor-configurable (at minimum the options 1, 3, 7, and 30 days; default 7).

A grantee MUST have an active EncryptionSuite; designating a user with no active suite MUST fail with a clear error (the recovery envelope is encrypted to the grantee's public certificate and cannot be built otherwise).

#### Scenario: Designate a contact with a wait period
- **GIVEN** grantor A is unlocked and user B has an active EncryptionSuite
- **WHEN** A designates B as an emergency contact with access level `view` and a 7-day wait period
- **THEN** the system MUST record the emergency-contact relationship in state `granted`
- **AND** it MUST record the access level and wait period

#### Scenario: Grantee without an EncryptionSuite is rejected
- **GIVEN** user B has never opened Doriath and has no EncryptionSuite
- **WHEN** grantor A attempts to designate B as an emergency contact
- **THEN** the system MUST return an error indicating the grantee has no encryption suite
- **AND** no emergency-contact relationship MUST be created

### Requirement: Client-Side Recovery Envelope Escrow
On designation, the grantor's browser MUST build the recovery envelope entirely client-side: the grantor's EncryptionSuite private key MUST be hybrid-encrypted (a fresh random AES-256-GCM key encrypts the private key; that AES key is RSA-encrypted to the grantee's public certificate). The raw private-key material MUST exist only transiently in the grantor's browser and MUST be discarded after the envelope is built. The server MUST store only the grantee-encrypted envelope ciphertext and MUST NEVER receive the grantor's private key or a usable key. The grantor's private key MUST NOT be transmitted to the server in any form other than the grantee-encrypted envelope.

#### Scenario: Envelope is built in the browser and only ciphertext is stored
@e2e exclude Client-side WebCrypto escrow — asserting the private key is re-encrypted to the grantee's public cert in-browser and that only the grantee-encrypted envelope (never the raw key) reaches the server is a crypto/wire-shape assertion, not a DOM flow; covered by vitest (envelope builder + no-raw-key-in-request guard).
- **GIVEN** grantor A designates emergency contact B while unlocked
- **WHEN** the recovery envelope is created
- **THEN** the grantor's private key MUST be hybrid-encrypted to B's public certificate in A's browser
- **AND** only the grantee-encrypted envelope ciphertext MUST be sent to and stored by the server
- **AND** the raw private-key material MUST be discarded from the browser after the envelope is built

### Requirement: Break-Glass Request and Wait Timer
The system MUST allow a designated emergency contact to initiate a break-glass request against a grantor who granted them access. Initiating a request MUST move the relationship to state `requested`, record the request time, start the grantor's configured wait period, and notify the grantor. No key material MUST be released at request time.

#### Scenario: Contact requests emergency access
- **GIVEN** grantor A has designated B as an emergency contact with a 7-day wait period
- **WHEN** B initiates a break-glass request
- **THEN** the relationship MUST move to state `requested` with the request time recorded and the 7-day timer started
- **AND** the grantor MUST be notified of the request
- **AND** no recovery envelope MUST be released to B

### Requirement: Grantor Decline (Veto)
At any time before the wait period elapses, the grantor MUST be able to decline a pending break-glass request. Declining MUST move the relationship out of `requested` (to `declined` or back to `granted`), MUST NOT release the recovery envelope, and MUST be recordable together with an optional revocation of the contact.

#### Scenario: Grantor declines within the wait window
- **GIVEN** B has a break-glass request pending against A and the wait period has not elapsed
- **WHEN** A declines the request
- **THEN** the request MUST be rejected and no recovery envelope MUST be released to B
- **AND** the relationship MUST leave the `requested` state

### Requirement: Approval by Timeout and Grantee View Access
If the wait period elapses on a `requested` relationship without the grantor declining, the system MUST transition it to `approved`. The server MUST release the recovery envelope to the grantee **only** when the relationship is `approved` and the caller is the named grantee; it MUST refuse the envelope in any other state or to any other caller. Once released, the grantee decrypts the envelope with their **own** in-session private key to recover the grantor's private key in their browser, and MAY then read (view) the grantor's secrets. The grantor MUST be notified when the grantee actually accesses the vault.

#### Scenario: Timer elapses and the grantee gains view access
- **GIVEN** B has a `requested` relationship against A and the 7-day wait period has elapsed with no decline
- **WHEN** the request is evaluated
- **THEN** the relationship MUST transition to `approved`
- **AND** B MUST be able to fetch the recovery envelope and decrypt it with B's own private key to read A's secrets
- **AND** A MUST be notified when B accesses the vault

#### Scenario: Envelope is refused before approval
- **GIVEN** a break-glass request that is still `requested` (wait period not elapsed) or has been `declined`
- **WHEN** the grantee attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

#### Scenario: Envelope is refused to a non-grantee
- **GIVEN** an `approved` emergency-access relationship between grantor A and grantee B
- **WHEN** a user other than B attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

### Requirement: Revoke Emergency Contact
The grantor MUST be able to revoke an emergency contact at any time. Revocation MUST delete the recovery envelope and cancel any pending request, and a revoked contact MUST NOT be able to break glass until re-designated (which rebuilds a fresh envelope).

#### Scenario: Revoked contact cannot break glass
- **GIVEN** A has designated B as an emergency contact
- **WHEN** A revokes B
- **THEN** the recovery envelope MUST be deleted and any pending request cancelled
- **AND** B MUST be unable to initiate or complete a break-glass request until re-designated

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST invalidate existing envelopes. When the grantor's EncryptionSuite is rotated (compromise recovery), existing recovery envelopes MUST be invalidated and the grantor MUST be prompted to re-establish emergency access against the new key. When the grantor's EncryptionSuite is revoked, existing recovery envelopes MUST be cleared. Likewise, if a grantee's EncryptionSuite is revoked, envelopes encrypted to that grantee MUST be invalidated.

#### Scenario: Suite rotation invalidates envelopes
- **GIVEN** A has an emergency contact B with a recovery envelope holding A's current private key
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** the existing recovery envelope MUST be invalidated
- **AND** A MUST be prompted to re-establish emergency access against the new key

#### Scenario: Suite revocation clears envelopes
- **GIVEN** A has one or more emergency contacts with recovery envelopes
- **WHEN** A's EncryptionSuite is revoked
- **THEN** the recovery envelopes MUST be cleared
