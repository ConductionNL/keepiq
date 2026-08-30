---
status: proposed
---

# Short-lived Credential Leases for the Machine Secret-Store API

## Purpose

Layer TTL-bounded, renewable, revocable, audited access-grant **leases** onto Doriath's existing RFC 7523 machine secret-store API so the unified human+machine vault can offer just-in-time credential governance (OWASP Secrets Management). A Doriath lease bounds the **access-grant lifetime** of a static, zero-knowledge secret and drives rotation/reminder triggers — it does **not** mint dynamic credentials, and it cannot claw back a value a consumer already decrypted (ADR-003). Leases are additive: the `doriath-machine-secret-v1` envelope and the `doriath://` resolution algorithm are unchanged.

## ADDED Requirements

### Requirement: Lease granted on machine secret fetch

Doriath SHALL, when an authenticated application fetches a secret via the machine API, create or reuse a lease for that `(application, secret)` bounded by the effective TTL policy, and return the lease id and expiry as response headers WITHOUT modifying the `doriath-machine-secret-v1` envelope body.

#### Scenario: Fetch returns a lease in headers

@e2e exclude Machine-to-machine API contract with no UI surface; covered by MachineLeaseServiceTest (grant on fetch) + ApplicationSecretsControllerTest lease-header assertion and the machine-secret-api Newman lease-grant request.
- **WHEN** an authenticated application fetches a secret by name or id
- **THEN** the response MUST carry a lease id and lease expiry as headers
- **AND** the encrypted envelope body MUST be byte-compatible with the existing `doriath-machine-secret-v1` format

#### Scenario: TTL is bounded by policy

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseServiceTest::testRequestedTtlCappedToMax and the Newman lease-ttl-cap request.
- **WHEN** an application requests a lease TTL longer than the effective maximum TTL policy
- **THEN** the granted lease's expiry MUST be capped at the policy maximum

#### Scenario: A repeat poll does not silently extend the lease

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseServiceTest::testPollReusesLeaseWithoutExtending.
- **WHEN** an application re-fetches the same secret while a live lease exists
- **THEN** the existing lease MUST be reused and its expiry MUST NOT be extended without an explicit renewal

### Requirement: Lease renewal within policy

Doriath SHALL let an authenticated application renew its own active lease within the maximum-TTL policy, incrementing the renewal count, and MUST refuse renewal once the lease has reached its maximum lifetime or when the policy marks the application non-renewable.

#### Scenario: Renewal extends expiry up to the cap

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseControllerTest::testRenewExtendsExpiry and the Newman lease-renew request.
- **WHEN** an application renews an active, renewable lease before its maximum lifetime
- **THEN** the lease expiry MUST advance up to the policy cap and the renewal count MUST increment

#### Scenario: Renewal past the maximum lifetime is refused

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseControllerTest::testRenewPastMaxRefused.
- **WHEN** an application renews a lease that has already reached `granted_at + max TTL`
- **THEN** the renewal MUST be refused

#### Scenario: Renewing another application's lease is invisible

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseControllerTest::testRenewCrossVaultNotFound — same own-vault-scoping guarantee as the secrets endpoints.
- **WHEN** application A renews a lease belonging to application B
- **THEN** the response MUST be 404, indistinguishable from a nonexistent lease

### Requirement: Lease revocation by admin, owner, or application

Doriath SHALL let an administrator or the secret owner revoke an active lease, and SHALL let an application revoke its own lease. Revocation MUST record the withdrawal and emit an audit event and a rotation trigger. Revocation MUST NOT be represented as recovering an already-served value; when the block-on-revoke policy is enabled, a subsequent fetch of the scoped secret by that application MUST be refused until re-granted.

#### Scenario: Admin revokes an active lease

@e2e exclude Machine-to-machine grant governance verified via MachineLeaseServiceTest::testAdminRevokeMarksRevoked + audit assertion; the owner-facing revoke button is covered by the application-detail e2e once built.
- **WHEN** an administrator revokes an active lease
- **THEN** the lease status MUST become `revoked` with the revoker recorded
- **AND** a `lease.revoked` audit event and a rotation trigger MUST be emitted

#### Scenario: Block-on-revoke refuses re-fetch when enabled

@e2e exclude Machine-to-machine API contract; covered by ApplicationSecretsControllerTest::testFetchAfterRevokeBlockedWhenPolicyOn and the Newman revoke-then-fetch request.
- **GIVEN** the block-on-revoke policy is enabled and an application's lease for a secret is revoked
- **WHEN** the application fetches that secret again
- **THEN** the fetch MUST be refused until an administrator re-grants access

#### Scenario: Default policy keeps re-fetch available after revoke

@e2e exclude Machine-to-machine API contract; covered by ApplicationSecretsControllerTest::testFetchAfterRevokeAllowedWhenPolicyOff.
- **GIVEN** the block-on-revoke policy is disabled (default)
- **WHEN** an application fetches a secret whose prior lease was revoked
- **THEN** the fetch MUST succeed and a new lease MUST be granted

### Requirement: Admin lease-TTL policy

Doriath SHALL provide an instance-wide default TTL, maximum TTL, and renewable flag for machine leases, optionally overridden per application, and SHALL advertise the effective policy in the machine discovery document additively (without an envelope or addressing change).

#### Scenario: Discovery advertises lease policy

@e2e exclude Machine-to-machine API contract; covered by DiscoveryControllerTest::testDocumentAdvertisesLeasePolicy and the Newman discovery lease-field assertion.
- **WHEN** a consumer fetches the discovery document
- **THEN** it MUST include the lease default TTL, maximum TTL, and renewable flag
- **AND** the envelope format and addressing MUST be unchanged from the current API version

#### Scenario: Per-application override wins

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseServiceTest::testPerApplicationPolicyOverridesInstanceDefault.
- **GIVEN** an application with a per-application lease policy stricter than the instance default
- **WHEN** it is granted a lease
- **THEN** the per-application maximum TTL MUST bound the lease

### Requirement: Lease-aware audit trail

Doriath SHALL record `lease.granted`, `lease.renewed`, `lease.revoked`, and `lease.expired` audit events capturing who fetched what, when, and the grant lifetime, using the existing string-typed audit whitelist, and these events MUST NOT carry any secret value, login, or ciphertext.

#### Scenario: Grant and expiry are audited without secret material

@e2e exclude Machine-to-machine API contract; covered by MachineLeaseServiceTest audit assertions + AuditEventTypes whitelist test — the forbidden-keys guard rejects any value/ciphertext key.
- **WHEN** a lease is granted and later expires
- **THEN** a `lease.granted` and a `lease.expired` audit event MUST be recorded with the lease id, application, secret id, and lifetime
- **AND** neither event MUST contain a key, login, value, or ciphertext field

### Requirement: Lease expiry background job

Doriath SHALL run a background job that transitions active leases past their expiry to `expired`, emits the `lease.expired` audit event, and fires the rotation/reminder trigger, mirroring the existing timed-job pattern.

#### Scenario: Expired leases are aged out

@e2e exclude Machine-to-machine API contract with no UI surface; covered by ExpireMachineLeasesJobTest::testOnlyPastExpiryLeasesTransition (leaves unexpired leases untouched).
- **WHEN** the lease-expiry job runs and a lease is past its expiry
- **THEN** that lease MUST become `expired` and emit `lease.expired`
- **AND** leases that have not reached their expiry MUST be left untouched
