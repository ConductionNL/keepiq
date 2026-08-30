# Honey Credentials Specification

**Status**: done

**OpenSpec changes:**
- [honey-credentials](../../changes/archive/2026-07-20-honey-credentials/) — decoy secrets as tripwires with owner/admin flag, central fail-soft access listener, high-severity alerts, per-accessor snooze, rate-limited storms

## Purpose

A vault is the highest-value target an intruder touches first, and zero-knowledge (ADR-003) lets the server see every *access* to a secret while never seeing its *content* — making deception one of the few post-compromise detection controls a zero-knowledge store can offer natively. This feature lets an owner or admin flag a purpose-made decoy secret as a honey credential; any server-observable access to it (UI reveal, machine API, link share, user share) immediately raises a high-severity alert — Nextcloud notification to the owner and admins, a distinguished `honey.accessed` audit event, and an optional SIEM event — riding Keepiq's existing typed audit-event stream (`SecretService::dispatchAudit`, `lib/Service/SecretService.php:166`). The flag is invisible to recipients/attackers, alerts are rate-limited and per-accessor snoozable, and the tripwire is fail-soft. No self-hosted password manager ships this; Infisical offers it only as a paid feature.

## Requirements

### Requirement: Honey flag is owner/admin-only and invisible to others
The system MUST let a secret's owner or a vault admin flag it honey, MUST never serialize that flag into the secret's normal response, and MUST keep the flag and its alerts visible only to the owner and admins.

#### Scenario: Recipient cannot distinguish a honey secret
- GIVEN an owner flags a secret honey and shares it
- WHEN the recipient fetches the shared secret
- THEN the response MUST be identical in shape to an ordinary secret, with no honey flag present

### Requirement: Any access to a honey secret raises a high-severity alert
The system MUST raise an alert on any server-observable access to a honey secret across all channels, including accessor identity, channel, and IP/user-agent where available, notifying the owner and all admins and recording a distinguished `honey.accessed` audit event with no secret material.

#### Scenario: Machine-API fetch of a honey secret alerts
- GIVEN a honey secret and an application authorized to fetch it
- WHEN the application retrieves it via the machine API
- THEN an alert MUST name the application and channel `machine_api`, notify owner + admins, and record a `honey.accessed` event with no key/value/ciphertext

#### Scenario: Alert delivery ignores the security-notification opt-out
- GIVEN a honey secret whose owner disabled security notifications
- WHEN the secret is accessed
- THEN the owner MUST still receive the alert

#### Scenario: Tripwire never blocks the access it observes
- GIVEN raising a honey alert fails
- WHEN a honey secret is accessed
- THEN the access MUST still succeed and the failure MUST be logged

### Requirement: Alert storms are rate-limited and per-accessor snoozable
The system MUST collapse repeated accesses by the same accessor within a configurable window (default 1h) into one alert, and MUST let an owner/admin acknowledge and snooze per accessor — a snoozed accessor stops paging but is still audited.

#### Scenario: Snoozed accessor stops paging but is still audited
- GIVEN an owner has snoozed alerts for a specific accessor
- WHEN that accessor accesses the honey secret again
- THEN no notification MUST be sent, and a `honey.accessed` audit event MUST still be recorded

### Requirement: Honey access never records secret material
The system MUST never record a secret value, login, additional field, plaintext, or ciphertext in a honey alert or the `honey.accessed` event — only non-secret accessor, channel, and request metadata.

#### Scenario: Alert and audit event contain no secret material
- WHEN a honey alert and its `honey.accessed` event are recorded
- THEN neither MUST contain any secret value, login, additional field, or ciphertext

## User Stories

- As an owner, I want a decoy secret that pages me the instant anyone opens it
- As an administrator, I want instance-wide honey alerts so a compromise surfaces immediately
- As an owner, I want the decoy indistinguishable from a real secret
- As an owner, I want to snooze a benign accessor without disabling the tripwire
- As an administrator, I want honey alerts to fire even when a user muted their notifications

## Acceptance Criteria

- [ ] Owner/admin can flag/unflag; the flag never appears in the secret's normal response; recipients cannot distinguish it
- [ ] Non-owner non-admin flagging is rejected; flags and alerts are owner/admin-only
- [ ] Access via UI, machine API, link share, or user share raises an alert with accessor identity, channel, and IP/user-agent where available
- [ ] Each alert notifies owner + all admins and records a distinguished `honey.accessed` event
- [ ] Alert delivery ignores the security-notification opt-out; the tripwire is fail-soft
- [ ] Repeated access by the same accessor within a configurable window collapses to one alert
- [ ] Owner/admin can acknowledge and snooze per accessor; a snoozed accessor stops paging but is still audited
- [ ] No honey alert or `honey.accessed` event ever contains secret value, login, additional field, or ciphertext

## Notes

- Honest boundary: rides the typed audit-event stream, covering exactly the channels that emit a per-object read event.
- Reuses the typed `AuditEvent` bus + string whitelist from `secret-audit-trail` and the `NotificationService`/`KeepiqNotifier` path (new ungated `honey_access` subject).
- SIEM event is optional and class-existence-guarded on the sibling `siem-audit-export` change.
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge — access visible, content not).
