# emergency-access Specification

## Purpose
TBD - created by archiving change add-emergency-access. Update Purpose after archive.
## Requirements
### Requirement: Designate Emergency Contact
The system MUST allow a vault owner (grantor), while their vault is unlocked, to designate one or more Nextcloud users as emergency contacts. Each designation MUST record the grantee, an access level, and a wait period. The **v1 access level MUST be `view`** (the contact may read the grantor's vault); account takeover is out of scope for v1. The wait period MUST be grantor-configurable (at minimum the options 1, 3, 7, and 30 days; default 7).

A grantee MUST have an active EncryptionSuite; designating a user with no active suite MUST fail with a clear error (the recovery envelope is encrypted to the grantee's public certificate and cannot be built otherwise).

#### Scenario: Designate a contact with a wait period
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** grantor A is unlocked and user B has an active EncryptionSuite
- **WHEN** A designates B as an emergency contact with access level `view` and a 7-day wait period
- **THEN** the system MUST record the emergency-contact relationship in state `granted`
- **AND** it MUST record the access level and wait period

#### Scenario: Grantee without an EncryptionSuite is rejected
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
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
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** grantor A has designated B as an emergency contact with a 7-day wait period
- **WHEN** B initiates a break-glass request
- **THEN** the relationship MUST move to state `requested` with the request time recorded and the 7-day timer started
- **AND** the grantor MUST be notified of the request
- **AND** no recovery envelope MUST be released to B

### Requirement: Grantor Decline (Veto)
At any time before the wait period elapses, the grantor MUST be able to decline a pending break-glass request. Declining MUST move the relationship out of `requested` (to `declined` or back to `granted`), MUST NOT release the recovery envelope, and MUST be recordable together with an optional revocation of the contact.

#### Scenario: Grantor declines within the wait window
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** B has a break-glass request pending against A and the wait period has not elapsed
- **WHEN** A declines the request
- **THEN** the request MUST be rejected and no recovery envelope MUST be released to B
- **AND** the relationship MUST leave the `requested` state

### Requirement: Approval by Timeout and Grantee View Access
If the wait period elapses on a `requested` relationship without the grantor declining, the system MUST transition it to `approved`. The server MUST release the recovery envelope to the grantee **only** when the relationship is `approved` and the caller is the named grantee; it MUST refuse the envelope in any other state or to any other caller. Once released, the grantee decrypts the envelope with their **own** in-session private key to recover the grantor's private key in their browser, and MAY then read (view) the grantor's secrets. The grantor MUST be notified when the grantee actually accesses the vault.

#### Scenario: Timer elapses and the grantee gains view access
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** B has a `requested` relationship against A and the 7-day wait period has elapsed with no decline
- **WHEN** the request is evaluated
- **THEN** the relationship MUST transition to `approved`
- **AND** B MUST be able to fetch the recovery envelope and decrypt it with B's own private key to read A's secrets
- **AND** A MUST be notified when B accesses the vault

#### Scenario: Envelope is refused before approval
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** a break-glass request that is still `requested` (wait period not elapsed) or has been `declined`
- **WHEN** the grantee attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

#### Scenario: Envelope is refused to a non-grantee
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** an `approved` emergency-access relationship between grantor A and grantee B
- **WHEN** a user other than B attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

### Requirement: Revoke Emergency Contact
The grantor MUST be able to revoke an emergency contact at any time. Revocation MUST delete the recovery envelope and cancel any pending request, and a revoked contact MUST NOT be able to break glass until re-designated (which rebuilds a fresh envelope).

#### Scenario: Revoked contact cannot break glass
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). A live Playwright run of the DOM flow is deferred: the worktree is not deployed and deploying to the shared dev instance is prohibited.
- **GIVEN** A has designated B as an emergency contact
- **WHEN** A revokes B
- **THEN** the recovery envelope MUST be deleted and any pending request cancelled
- **AND** B MUST be unable to initiate or complete a break-glass request until re-designated

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST invalidate existing envelopes. When the grantor's EncryptionSuite is rotated (compromise recovery), existing recovery envelopes MUST be invalidated and the grantor MUST be prompted to re-establish emergency access against the new key. When the grantor's EncryptionSuite is revoked, existing recovery envelopes MUST be cleared. Likewise, if a grantee's EncryptionSuite is revoked, envelopes encrypted to that grantee MUST be invalidated.

#### Scenario: Suite rotation invalidates envelopes
@e2e exclude Server-side suite rotation/revocation listener contract — covered by PHPUnit (invalidateForGrantorRotation/clearForGrantorRevocation/invalidateForGranteeRevocation + invalidated audit). Live UI run deferred (worktree not deployed).
- **GIVEN** A has an emergency contact B with a recovery envelope holding A's current private key
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** the existing recovery envelope MUST be invalidated
- **AND** A MUST be prompted to re-establish emergency access against the new key

#### Scenario: Suite revocation clears envelopes
@e2e exclude Server-side suite rotation/revocation listener contract — covered by PHPUnit (invalidateForGrantorRotation/clearForGrantorRevocation/invalidateForGranteeRevocation + invalidated audit). Live UI run deferred (worktree not deployed).
- **GIVEN** A has one or more emergency contacts with recovery envelopes
- **WHEN** A's EncryptionSuite is revoked
- **THEN** the recovery envelopes MUST be cleared

### Requirement: Emergency contact designation requires two-phase confirmation

Doriath SHALL let a vault owner register an emergency contact with a configured wait period and access level, and SHALL require a separate client-side confirmation step (performed against the contact's then-current EncryptionSuite public key) before any key material is transmitted.

#### Scenario: Registration alone grants no access

- **GIVEN** an owner registers a new emergency contact with a 48-hour wait period
- **WHEN** the registration completes
- **THEN** the contact record status MUST be `pending-confirmation` and the contact MUST NOT be able to request or receive access until the owner separately confirms

#### Scenario: Confirmation wraps key material for the contact's current key only

- **GIVEN** a `pending-confirmation` emergency contact record
- **WHEN** the owner confirms it
- **THEN** the owner's browser MUST fetch the contact's currently active EncryptionSuite public key, re-encrypt the relevant key material against it client-side, and only the resulting ciphertext MUST be sent to the server; the record status becomes `active`

#### Scenario: Self-designation is rejected

- **GIVEN** an authenticated user
- **WHEN** they attempt to register themselves as their own emergency contact
- **THEN** the request MUST be rejected with an error, and no record MUST be created

### Requirement: Access requests start an owner-cancellable wait period

Doriath SHALL let a designated, active emergency contact request access to an owner's vault, notify the owner immediately, and start a wait-period timer that the owner can cancel at any time before it elapses.

#### Scenario: Owner is notified immediately on request

- **GIVEN** an active emergency contact relationship
- **WHEN** the contact submits an access request
- **THEN** the owner MUST receive a notification (in-app + configured channel) within the same request cycle, including a direct reject action

#### Scenario: Owner rejection immediately blocks the request

- **GIVEN** a `requested` emergency access request
- **WHEN** the owner rejects it before the wait period elapses
- **THEN** the request status MUST become `rejected` and the contact MUST NOT gain any access, regardless of elapsed time

#### Scenario: Non-contact cannot request access

- **GIVEN** a user with no active emergency-contact relationship to a given owner
- **WHEN** they attempt to submit an access request for that owner
- **THEN** the request MUST be rejected with a 403/forbidden response

### Requirement: Unrejected requests auto-grant on wait-period expiry

Doriath SHALL run a background job that transitions an emergency access request from `requested` to `granted` once its configured wait period has elapsed without an owner rejection, and grant the configured access level (`view` or `takeover`).

#### Scenario: Expiry job grants access after the wait period

- **GIVEN** a `requested` emergency access request with an 24-hour wait period, requested at time T
- **WHEN** the background job runs at T+25h and the request has not been rejected or cancelled
- **THEN** the request status MUST become `granted` and the contact MUST subsequently be able to read the owner's secrets (for `view` level) via the existing secret-listing/read endpoints

#### Scenario: Expiry job does not touch requests still within their wait period

- **GIVEN** a `requested` emergency access request with a 48-hour wait period, requested 10 hours ago
- **WHEN** the background job runs
- **THEN** the request status MUST remain `requested`

### Requirement: Granted view access is scoped per-object, alongside owner access

Doriath's secret-read authorization SHALL treat a `granted` emergency access request as an additional valid identity for the named owner's secrets, without introducing a parallel access-control mechanism.

#### Scenario: Contact with granted view access can read the owner's secrets

- **GIVEN** a `granted` emergency access request naming a contact for a specific owner, level `view`
- **WHEN** the contact calls the secret list/read endpoints
- **THEN** the response MUST include the owner's secrets, decrypted client-side using the key material wrapped for the contact at confirmation time

#### Scenario: Contact without a granted request cannot read another owner's secrets

- **GIVEN** no `granted` emergency access request exists between a given contact and owner
- **WHEN** the contact calls the secret read endpoint for one of that owner's secret ids
- **THEN** the response MUST be 403/forbidden, identical to the existing non-owner behavior

### Requirement: Every emergency-access state transition is auditable

Doriath SHALL dispatch typed events for request, rejection, and grant so the (separately specced) audit-trail change can persist them.

#### Scenario: Grant dispatches a typed event with no key material

- **GIVEN** an emergency access request transitions to `granted`
- **WHEN** the transition occurs
- **THEN** an `EmergencyAccessGrantedEvent` MUST be dispatched carrying only owner id, contact id, request id, and timestamps — never key material or secret content

