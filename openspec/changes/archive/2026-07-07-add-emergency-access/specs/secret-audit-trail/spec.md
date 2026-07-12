## MODIFIED Requirements

### Requirement: Server-Observable Operation Capture
The system MUST record an audit entry for every server-observable secret operation: secret created, updated, read (individual encrypted-blob fetch), and deleted; folder cascade deletion; share granted, revoked, delegated, and delegation reclaimed; link share created, accessed, access-attempt failed, revoked, and auto-deleted; secret request created, fulfilled, re-requested, and revoked; suite revoked, reinstated, and compromise recovery started/completed; application registered, approved, rejected, deleted, token issued, and application secret retrieved; **emergency access granted, requested, declined, approved, accessed, revoked, and invalidated**.

Each entry MUST record the timestamp, actor (user, application, system, or anonymous link visitor), event type, object reference, and the object's non-sensitive name (denormalized so the entry survives object deletion). All entries MUST be written through typed events dispatched via `OCP\EventDispatcher` and a single audit listener; an audit-write failure MUST NOT fail or roll back the audited operation.

For emergency-access events the actor is the grantor (for `granted`, `declined`, `revoked`) or the grantee (for `requested`, `accessed`), with the system as actor for a timeout-driven `approved` and for an `invalidated` triggered by a key change; the object reference identifies the emergency-contact relationship (grantor and grantee), and no key material or secret value is ever recorded (the recovery envelope and private key MUST NEVER appear in an entry).

List and search calls are not per-secret reads and MUST NOT produce `secret.read` entries.

#### Scenario: Secret update is logged
@e2e tests/e2e/workflows/audit-trail.spec.ts
- **WHEN** a user updates a secret they own
- **THEN** an audit entry MUST be recorded with event type `secret.updated`, the user as actor, and the secret's id and name

#### Scenario: Emergency-access break-glass is logged end to end
@e2e exclude Server-side dispatch contract across the emergency-access lifecycle — asserting the recorded event types, actors, and the absence of key material is a DB/payload assertion, not a DOM flow; covered by PHPUnit (EmergencyAccessService dispatch + AuditService whitelist tests).
- **WHEN** grantee B requests emergency access to grantor A's vault, the wait period elapses to approval, and B then accesses the vault
- **THEN** audit entries MUST be recorded for `emergency_access.requested` (actor B), `emergency_access.approved` (actor system), and `emergency_access.accessed` (actor B)
- **AND** none of the entries MUST contain the recovery envelope, the grantor's private key, or any secret value

#### Scenario: Failed link-share access attempt is logged
@e2e exclude Server-side dispatch contract — asserting the recorded actor type, missing actor id, and absence of the attempted password is a DB/payload assertion, not a DOM flow; covered by PHPUnit (LinkShareService dispatch + AuditService whitelist tests).
- **WHEN** an anonymous visitor enters a wrong password on a link share
- **THEN** an audit entry MUST be recorded with event type `link_share.access_failed`, actor type `link_visitor`, and no actor id
- **AND** the entry MUST NOT contain the attempted password

#### Scenario: Entry survives object deletion
@e2e exclude Server-side denormalization contract — verifying the entry remains readable with the recorded name after deletion is a persistence assertion; covered by PHPUnit (AuditService record + AuditEntryMapper tests).
- **WHEN** a secret is deleted
- **THEN** the `secret.deleted` entry and all prior entries for that secret MUST remain readable, displaying the secret's recorded name

#### Scenario: Audit failure does not block the operation
@e2e exclude Fail-soft contract — simulating a listener failure and asserting the operation still succeeds + an error is logged is not DOM-observable; covered by PHPUnit (AuditListener fail-soft test).
- **WHEN** the audit listener fails while a secret is being created
- **THEN** the secret creation MUST succeed
- **AND** the failure MUST be logged at error level
