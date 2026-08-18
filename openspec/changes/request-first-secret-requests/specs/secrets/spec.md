## ADDED Requirements

### Requirement: Unfilled Request Placeholder

A Secret MUST normally carry a value: creating one with an empty `key` MUST be refused. A Secret with neither a value nor a reason to be empty is litter, and the requirement exists so that stays true.

The single exception is the placeholder a secret request writes into. A Secret MAY have an empty `key` only while a pending `SecretRequest` targets it. The secret-requests capability requires the system to create exactly such a placeholder for a fresh request (`a placeholder with no key value`), so without this exception stated here, that requirement and this one contradict each other — which is how the implementation came to refuse the placeholder the other spec mandates.

The exception MUST be an explicit opt-in at creation, asserted by the caller creating the placeholder, and MUST NOT be the default. A caller that does not ask for it MUST still be refused an empty `key`. The system MUST NOT infer the exception by looking for a pending request at creation time: the caller already knows its own intent, and the lookup would couple secret creation to the request store.

Cleanup is the other half of the invariant and is already required by the secret-requests capability: revoking a request MUST delete the unfilled Secret it created.

Known limitation, stated rather than implied: an EXPIRED request remains `pending` — expiry stops submissions but does not revoke — so its placeholder persists as a permanently empty Secret until the request is revoked. Whether expiry should auto-revoke is a change to the Optional Expiry requirement and is out of scope here.

#### Scenario: An ordinary secret still requires a value
- **WHEN** a Secret is created without a `key` and without asserting the placeholder exception
- **THEN** the system MUST refuse it

#### Scenario: A request placeholder may be created without a value
- **GIVEN** a fresh secret request is being created
- **WHEN** the system creates the Secret the request will write into, asserting the placeholder exception
- **THEN** the Secret MUST be created with an empty `key`
- **AND** it MUST be owned by the requester and linked to their active EncryptionSuite

#### Scenario: A name is still required for a placeholder
- **WHEN** a placeholder is created with an empty `key` and no name
- **THEN** the system MUST refuse it, because a nameless empty Secret cannot be identified in a vault

#### Scenario: Revoking the request removes the placeholder
- **GIVEN** a pending request that created its own unfilled Secret
- **WHEN** the request is revoked
- **THEN** the unfilled Secret MUST be deleted, so no keyless Secret outlives the request that justified it
