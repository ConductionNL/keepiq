# Short-lived Credential Leases Specification

**Status**: done

**OpenSpec changes:**
- [machine-secret-leases](../../changes/machine-secret-leases/)

## Purpose

Doriath is one of the very few secret stores that spans both the human vault and a machine secret-store API (RFC 7523 JWT-bearer, `lib/Service/JwtAuthService.php`; the `doriath://` OpenConnector integration, `docs/integration-openconnector.md`) — a segment growing ~22% CAGR (Mordor NHI report) that machine-only tools (Vault, OpenBao, Infisical) own on one side and consumer vaults own on the other, bridged only by Bitwarden's paid bolt-on. What the machine side lacks is any bounded, revocable, audited **access grant**. This feature layers TTL-bounded, renewable, revocable, audited **leases** onto the existing machine API so Doriath moves toward the just-in-time posture OWASP Secrets Management recommends. Honest scope: Doriath stores static, zero-knowledge secrets (ADR-003) and **cannot** mint dynamic credentials like Vault; a lease bounds the **access-grant lifetime** and drives rotation/reminder triggers — it is not credential generation, and it cannot claw back a value the consumer already decrypted. Leases are additive: the `doriath-machine-secret-v1` envelope and the `doriath://` resolution algorithm are unchanged.

## Requirements

### Requirement: Lease granted on machine secret fetch
The system MUST, on an authenticated machine fetch, create or reuse a policy-bounded lease for that `(application, secret)` and return the lease id and expiry as response headers WITHOUT modifying the `doriath-machine-secret-v1` envelope body. A repeat poll MUST reuse a live lease without silently extending it, and a requested TTL over the maximum MUST be capped at the policy maximum.

#### Scenario: Fetch returns a lease in headers
- GIVEN an authenticated application fetching a secret by name or id
- WHEN the response is produced
- THEN it MUST carry a lease id and expiry as headers and the envelope body MUST be byte-compatible with `doriath-machine-secret-v1`

#### Scenario: Poll reuses without extending
- GIVEN a live lease for a secret
- WHEN the application re-fetches that secret
- THEN the existing lease MUST be reused and its expiry MUST NOT be extended without an explicit renewal

### Requirement: Lease renewal within policy
The system MUST let an application renew its own active lease within the maximum-TTL policy, incrementing the renewal count, and MUST refuse renewal past the maximum lifetime or when the policy marks the application non-renewable. Renewing another application's lease MUST return the same 404 as a nonexistent lease.

#### Scenario: Renewal extends up to the cap
- GIVEN an active, renewable lease before its maximum lifetime
- WHEN the application renews it
- THEN the expiry MUST advance up to the policy cap and the renewal count MUST increment

#### Scenario: Renewal past maximum refused
- GIVEN a lease that has reached `granted_at + max TTL`
- WHEN the application renews it
- THEN the renewal MUST be refused

### Requirement: Lease revocation by admin, owner, or application
The system MUST let an administrator or the secret owner revoke an active lease, and an application revoke its own, recording the withdrawal and emitting an audit event plus a rotation trigger. Revocation MUST NOT be represented as recovering an already-served value. When the block-on-revoke policy is enabled, a subsequent fetch of the scoped secret by that application MUST be refused until re-granted; by default (policy off) re-fetch MUST succeed with a new lease.

#### Scenario: Admin revokes an active lease
- GIVEN an active lease
- WHEN an administrator revokes it
- THEN the lease status MUST become `revoked` with the revoker recorded, and a `lease.revoked` audit event and rotation trigger MUST be emitted

#### Scenario: Block-on-revoke refuses re-fetch when enabled
- GIVEN block-on-revoke enabled and a revoked lease for a secret
- WHEN the application fetches that secret again
- THEN the fetch MUST be refused until an administrator re-grants access

### Requirement: Admin lease-TTL policy
The system MUST provide an instance-wide default TTL, maximum TTL, and renewable flag, optionally overridden per application, and MUST advertise the effective policy additively in the machine discovery document without an envelope or addressing change.

#### Scenario: Discovery advertises lease policy
- WHEN a consumer fetches the discovery document
- THEN it MUST include the lease default TTL, maximum TTL, and renewable flag, and the envelope format and addressing MUST be unchanged from the current API version

#### Scenario: Per-application override wins
- GIVEN an application with a stricter per-application lease policy
- WHEN it is granted a lease
- THEN the per-application maximum TTL MUST bound the lease

### Requirement: Lease-aware audit trail
The system MUST record `lease.granted`, `lease.renewed`, `lease.revoked`, and `lease.expired` audit events capturing who fetched what, when, and the grant lifetime, using the existing string-typed audit whitelist and carrying no secret value, login, or ciphertext.

#### Scenario: Grant and expiry audited without secret material
- WHEN a lease is granted and later expires
- THEN `lease.granted` and `lease.expired` events MUST be recorded with the lease id, application, secret id, and lifetime, and neither MUST contain a key, login, value, or ciphertext field

### Requirement: Lease expiry background job
The system MUST run a background job that transitions active leases past their expiry to `expired`, emits `lease.expired`, and fires the rotation/reminder trigger, leaving unexpired leases untouched.

#### Scenario: Expired leases aged out
- GIVEN a lease past its expiry and another still within its window
- WHEN the lease-expiry job runs
- THEN the past-expiry lease MUST become `expired` and emit `lease.expired`, and the unexpired lease MUST be left untouched

## User Stories

- As a connector operator, I want each application's access to a credential to be a bounded, renewable grant so access is just-in-time rather than perpetual
- As an admin, I want to revoke an application's active lease and have that drive a rotation of the underlying secret
- As an admin, I want a per-application maximum lease TTL so a misconfigured connector cannot hold a grant indefinitely
- As an auditor, I want a trail of who leased what, when, and for how long, distinct from the raw retrieval event
- As a consumer author, I want to discover the lease policy from the discovery document without any breaking change to the envelope I already parse

## Acceptance Criteria

- [ ] A machine fetch returns a policy-bounded lease id + expiry in headers with an unchanged `doriath-machine-secret-v1` envelope body
- [ ] Requested TTL is capped at the maximum; a repeat poll reuses the lease without extending it
- [ ] Renewal advances expiry up to `granted_at + max TTL`, increments the count, and is refused past max or when non-renewable
- [ ] Cross-application renew/revoke is indistinguishable from a nonexistent lease (404)
- [ ] Admin/owner/self revocation marks the lease revoked, emits `lease.revoked` + rotation trigger; block-on-revoke refuses re-fetch only when enabled
- [ ] Discovery advertises the lease policy additively without an envelope or addressing change
- [ ] `lease.granted|renewed|revoked|expired` events record grant lifetime and carry no secret material
- [ ] The lease-expiry job ages out only past-expiry leases; the shared Newman collection covers grant/renew/revoke/expiry/discovery
- [ ] Scope is access-grant lifetime governance for static secrets — no dynamic credential minting, no K8s operator

## Notes

- Honest boundary vs. Vault/Keeper: static zero-knowledge secrets cannot be dynamically minted per lease; revocation is a governance/rotation signal (optionally blocking future re-fetch), not a clawback of an already-decrypted value.
- Additive design: lease data rides in `Doriath-Lease-*` response headers + a new discovery field, so a lease-unaware consumer keeps working and no `apiVersion` bump is needed.
- Reuses: the RFC 7523 token exchange and `#[PublicPage]`+`AnonRateLimit` pattern from `secret-store-api`; the `TimedJob` pattern from `ApproveElapsedEmergencyRequests`; the string-typed audit whitelist from `secret-audit-trail`.
- Related: `rotation-expiry-policies` consumes the lease-expiry rotation trigger when present; the two are independently deployable.
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge, ciphertext-only machine endpoints).
