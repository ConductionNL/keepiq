---
status: proposed
---

# Honey Credentials

## Purpose

Turn a purpose-made decoy secret into a tripwire: any server-observable access to a honey-flagged secret raises a high-severity alert to the owner and administrators, using the one detection signal a zero-knowledge store can offer natively — access, not content (ADR-003).

## ADDED Requirements

### Requirement: Honey flag is owner/admin-only and invisible to others

The system MUST let a secret's owner or a vault administrator flag a secret as a honey credential, and MUST store that flag such that it NEVER appears in the secret's normal serialized response. Recipients, link visitors, and applications MUST NOT be able to tell a honey secret from an ordinary one. The flag and its alerts MUST be visible only to the owner and administrators.

#### Scenario: Recipient cannot distinguish a honey secret
@e2e exclude Absence of the flag from the shared secret's serialized shape is an API-shape assertion; covered by PHPUnit (serializer + share-response tests).
- GIVEN an owner flags a secret honey and shares it with another user
- WHEN the recipient fetches the shared secret
- THEN the response MUST be identical in shape to an ordinary shared secret, with no honey flag present

#### Scenario: Non-owner non-admin cannot flag
@e2e exclude Owner/admin authorization returning an error is an attribute+guard contract; covered by PHPUnit (no-admin-idor guard test).
- GIVEN user A and a secret owned by user B
- WHEN A attempts to flag B's secret honey
- THEN the system MUST return an authorization error and create no flag

### Requirement: Any access to a honey secret raises a high-severity alert

The system MUST raise a high-severity alert on any server-observable access to a honey-flagged secret — UI reveal/decrypt fetch, machine-API fetch, link-share access, and share-recipient access. The alert MUST include the accessor identity (or anonymous for a link visitor), the channel, and the IP/user-agent where the request exposes them. The alert MUST notify the owner and all vault administrators, and MUST record a distinguished `honey.accessed` audit event carrying no secret value or ciphertext.

#### Scenario: Machine-API fetch of a honey secret alerts
@e2e exclude Tripwire-on-audit-event dispatch is a server-side contract over the machine API; covered by PHPUnit (HoneyTripwireListener tests asserting alert row + notification + `honey.accessed`).
- GIVEN a honey-flagged secret and an application authorized to fetch it
- WHEN the application retrieves it via the machine API
- THEN an alert MUST be raised naming the application as accessor with channel `machine_api`
- AND the owner and all vault administrators MUST be notified
- AND a `honey.accessed` audit event MUST be recorded with no key, value, or ciphertext

#### Scenario: Alert delivery ignores the security-notification opt-out
@e2e exclude Ungated delivery is a notification-map contract; covered by PHPUnit (NotificationService honey-subject test asserting delivery despite opt-out).
- GIVEN a honey secret whose owner has disabled security notifications
- WHEN the secret is accessed
- THEN the owner MUST still receive the honey alert

#### Scenario: Tripwire never blocks the access it observes
@e2e exclude Fail-soft contract — simulating a listener failure and asserting the read still succeeds is not DOM-observable; covered by PHPUnit (HoneyTripwireListener fail-soft test).
- GIVEN raising a honey alert fails (notification or alert-write error)
- WHEN a honey secret is accessed
- THEN the access MUST still succeed and the failure MUST be logged

### Requirement: Alert storms are rate-limited and per-accessor snoozable

The system MUST collapse repeated accesses by the same accessor to a honey secret within a configurable window (default one hour) into a single alert, and MUST let an owner or administrator acknowledge an alert and snooze future alerts per accessor. A snoozed accessor MUST stop paging until the snooze elapses, while the distinguished audit event MUST still be recorded for every access.

#### Scenario: Repeated access within the window does not re-page
@e2e exclude Dedup-window behaviour is a server-side timing assertion; covered by PHPUnit (dedup-key + window tests).
- GIVEN an accessor already triggered an alert on a honey secret within the window
- WHEN the same accessor accesses it again inside the window
- THEN no new page MUST be sent and the existing alert MUST reflect the repeated access

#### Scenario: Snoozed accessor stops paging but is still audited
@e2e exclude Snooze suppression + continued audit recording is a server-side contract; covered by PHPUnit (snooze tests asserting no notification but a `honey.accessed` event).
- GIVEN an owner has snoozed alerts for a specific accessor
- WHEN that accessor accesses the honey secret again
- THEN no notification MUST be sent
- AND a `honey.accessed` audit event MUST still be recorded

### Requirement: Honey access never records secret material

The system MUST NEVER record a secret value, login, additional field, plaintext, or ciphertext in a honey alert row or in the `honey.accessed` audit event; only non-secret accessor, channel, and request metadata are permitted.

#### Scenario: Alert and audit event contain no secret material
@e2e exclude Structural whitelist guarantee over persisted rows; covered by PHPUnit (AuditService forbidden-key tests + HoneyCredentialService alert-shape test).
- WHEN a honey alert and its `honey.accessed` audit event are recorded
- THEN neither MUST contain any secret value, login, additional field, or ciphertext

## User Stories

- As an owner, I want to plant a decoy secret that pages me the instant anyone opens it, so I detect an intruder in my vault
- As an administrator, I want honey access alerts across the whole instance so a compromise surfaces immediately
- As an owner, I want the decoy to look exactly like a real secret so an attacker cannot avoid it
- As an owner, I want to snooze a known-benign accessor without disabling the tripwire, so real intrusions still page me
- As an administrator, I want honey alerts to survive a user muting their notifications, since a tripwire must always fire

## Acceptance Criteria

- [ ] Owner or admin can flag/unflag a secret honey; the flag never appears in the secret's normal response; recipients cannot distinguish it
- [ ] Non-owner non-admin flagging is rejected; flags and alerts are visible only to owner + admins
- [ ] Access via UI reveal, machine API, link share, or user share raises an alert with accessor identity, channel, and IP/user-agent where available
- [ ] Each alert notifies the owner and all vault admins and records a distinguished `honey.accessed` audit event
- [ ] Alert delivery ignores the security-notification opt-out
- [ ] The tripwire is fail-soft — it never blocks, slows, or fails the observed access
- [ ] Repeated access by the same accessor within a configurable window (default 1h) collapses to one alert
- [ ] Owner/admin can acknowledge and snooze per accessor; a snoozed accessor stops paging but is still audited
- [ ] No honey alert or `honey.accessed` event ever contains secret value, login, additional field, or ciphertext

## Notes

- Honest boundary: the tripwire rides the existing typed audit-event stream, so it covers exactly the channels that emit a per-object read event; a channel emitting no such event cannot trip the wire.
- Reuses: the typed `AuditEvent` bus + string-typed whitelist from `secret-audit-trail`; the `NotificationService`/`DoriathNotifier` path (new ungated `honey_access` subject).
- The SIEM event is optional and class-existence-guarded on the sibling `siem-audit-export` change (not yet built).
- Placement guidance (e.g. a decoy `prod-database` secret in a shared team folder) ships in docs.
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge — access is visible, content is not).
