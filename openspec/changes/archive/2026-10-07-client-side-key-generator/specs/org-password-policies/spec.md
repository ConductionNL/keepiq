## MODIFIED Requirements

### Requirement: Generator Locked to Policy
The system MUST clamp the key generator to the policy so it can never emit a value below the length floor or missing a required character class, rejecting a non-compliant `regex` override. The generator runs in the browser, so the clamp is applied there, under the same honest-client model as the save checks: the server never sees a generated value. The policy MUST also carry `generator_allow_passphrase` (default true), with which an administrator switches passphrase generation off.

#### Scenario: Generated value is always compliant
@e2e exclude Needs an org-policy write that would leak into every other spec on the shared e2e instance. Covered by tests/vitest/generator.spec.js ("raises the length to the floor and forces the required classes") and tests/dialogs/KeyGeneratorModal.spec.js ("the org policy floor clamps the length").
- GIVEN a policy floor of length 20 with a required symbol
- WHEN a user generates a password
- THEN the value MUST be at least 20 characters and contain a symbol
