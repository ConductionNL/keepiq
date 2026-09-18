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
