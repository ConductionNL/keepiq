## ADDED Requirements

### Requirement: A Request Sealed To An Inactive Suite Cannot Be Filled
A SecretRequest is sealed to the EncryptionSuite it was created for, and its fill-in link hands that suite's certificate to whoever opens it. When that suite is no longer `active` (revoked, flagged compromised, or gone), a value submitted through the link would be encrypted to a key that may be in someone else's hands. The system MUST therefore refuse to show or fill a `pending` request whose suite is not `active`: it MUST answer `410` with reason `unavailable`, MUST NOT return the suite's certificate, and MUST leave the request unchanged.

This is in addition to the existing fill-in refusals (see Requirement: Fill In via Link): `not-found`, `expired`, `fulfilled`, `declined` and `locked`. It applies however the suite stopped being active, including a compromise force-revoke that terminated a migration and unlocked the request on its old suite (see encryption-suites: Administrator Force-Revocation). A suite that cannot be found counts as not active.

#### Scenario: A pending request on a revoked suite
@e2e exclude Server-side policy refusal; covered by PHPUnit on SecretRequestPolicy, SecretRequestService and SecretRequestFillController, and vitest on the fill page.
- **GIVEN** a SecretRequest in state `pending` whose suite is `revoked` or `compromised`
- **WHEN** someone opens or submits its fill-in link
- **THEN** the system MUST refuse with `410` and reason `unavailable`
- **AND** MUST NOT return the suite's certificate
- **AND** the request MUST remain `pending`

#### Scenario: A request unlocked by a compromise termination stays closed
@e2e exclude Server-side listener and policy chain; covered by PHPUnit running the real unlock and the real policy.
- **GIVEN** a compromise force-revoke terminated a migration, and the termination unlocked the request back to `pending` on the old suite
- **WHEN** someone opens or submits its fill-in link
- **THEN** the system MUST refuse with `410` and reason `unavailable`
