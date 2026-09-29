## MODIFIED Requirements

### Requirement: Revoke Emergency Contact
The grantor MUST be able to revoke an emergency contact at any time. Revocation MUST delete the recovery envelope and cancel any pending request, and a revoked contact MUST NOT be able to break glass until re-designated (which rebuilds a fresh envelope).

Revocation MUST require a verified key proof (see the `vault-key-proof` capability). The recovery envelope is the only copy of the grantor's private key that survives a replacement of the stored envelope, which makes it the last recovery path out of an account lockout. An attacker holding the grantor's session would otherwise be able to delete the safety net first and destroy the vault second, using the same authority for both.

The requirement is on the grantor-initiated revocation of a designated contact. Envelope clearing that follows from suite revocation or rotation is a consequence of those operations, is governed by *Envelope Invalidation on Key Change*, and is not separately gated here.

#### Scenario: Revoked contact cannot break glass
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** A has designated B as an emergency contact
- **WHEN** A revokes B
- **THEN** the recovery envelope MUST be deleted and any pending request cancelled
- **AND** B MUST be unable to initiate or complete a break-glass request until re-designated

#### Scenario: Revocation without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** A has designated B as an emergency contact
- **AND** an authenticated session for A
- **WHEN** revocation is requested without a verified key proof
- **THEN** the system MUST refuse with `403` and `error: key_proof_required`
- **AND** the recovery envelope MUST be unchanged and still usable

### Requirement: Designate Emergency Contact
The system MUST allow a vault owner (grantor), while their vault is unlocked, to designate one or more Nextcloud users as emergency contacts. Each designation MUST record the grantee, an access level, and a wait period. The **v1 access level MUST be `view`** (the contact may read the grantor's vault); account takeover is out of scope for v1. The wait period MUST be grantor-configurable (at minimum the options 1, 3, 7, and 30 days; default 7).

A grantee MUST have an active EncryptionSuite; designating a user with no active suite MUST fail with a clear error (the recovery envelope is encrypted to the grantee's public certificate and cannot be built otherwise).

Designation MUST require a verified key proof (see the `vault-key-proof` capability), bound to the grantee, the wait period and the recovery envelope. A designation names who a later rotation escrows the grantor's private key to, and re-designating an existing contact replaces its envelope. With a session alone, an attacker could plant their own account as a grantee and receive the grantor's next key (keepiq#800), or overwrite an envelope and destroy break-glass, which the proof on revocation exists to prevent.

#### Scenario: Designate a contact with a wait period
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest.
- **GIVEN** grantor A is unlocked and user B has an active EncryptionSuite
- **WHEN** A designates B as an emergency contact with access level `view` and a 7-day wait period, carrying a verified key proof
- **THEN** the system MUST record the emergency-contact relationship in state `granted`
- **AND** it MUST record the access level and wait period

#### Scenario: Grantee without an EncryptionSuite is rejected
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest.
- **GIVEN** user B has never opened Keepiq and has no EncryptionSuite
- **WHEN** grantor A attempts to designate B as an emergency contact
- **THEN** the system MUST return an error indicating the grantee has no encryption suite
- **AND** no emergency-contact relationship MUST be created

#### Scenario: Designation without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** an authenticated session for A, and no key proof
- **WHEN** a designation of any grantee, or a re-designation of an existing contact, is requested
- **THEN** the system MUST refuse with `403` and `error: key_proof_required`
- **AND** no contact MUST be created, and an existing contact's envelope MUST be unchanged
