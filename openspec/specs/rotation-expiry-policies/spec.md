# Credential Rotation Policies and Expiry Reminders Specification

**Status**: in-progress

**OpenSpec changes:**
- [rotation-expiry-policies](../../changes/rotation-expiry-policies/)

## Purpose

Doriath's own roadmap lists "Password expiry reminders for secrets" (`docs/FEATURES.md:108`) as an unbuilt Enterprise feature, and it already carries the server-side fields a rotation loop needs — `key_updated_at` (ciphertext-age, `lib/Service/SecretService.php:411`) and `possibly_compromised_at` (`lib/Db/Secret.php:153`) — but nothing turns "stale" or "compromised" into a reminder or tracks whether the credential was actually rotated. This feature adds credential-hygiene the way NIS2 Art. 21(2)(j) (Dutch Cyberbeveiligingswet, ~Aug 2026) and BIO2 v1.3 push public-sector orgs to have it: per-secret and per-folder/type expiry, an admin-default and user-override max-age policy, approaching/overdue reminders (dashboard + Nextcloud notifications), a proven mark-rotated flow, and a rotate-after-breach/compromise flagging loop — all computed on server-visible metadata with **no decryption**, preserving the zero-knowledge model (ADR-003) and password-health's "No Server-Side Health Knowledge" invariant.

## Requirements

### Requirement: Per-secret expiry without ciphertext change
The system MUST let a secret owner set or clear an `expires_at` on a secret without altering the secret's `key` ciphertext or resetting its `key_updated_at`, and MUST reject setting expiry on a secret the caller does not own.

#### Scenario: Owner sets expiry
- GIVEN an owner viewing their own secret
- WHEN they set an `expires_at`
- THEN the system MUST store it and MUST leave `key_updated_at` unchanged

#### Scenario: Cross-owner expiry rejected
- GIVEN user A and a secret owned by user B
- WHEN A attempts to set expiry on B's secret
- THEN the system MUST return an authorization error and store nothing

### Requirement: Expiry policies with admin default and user override
The system MUST support max-age expiry policies scoped to a secret type or folder subtree, an instance-wide admin default (shipped disabled) and reminder cadence, and a per-user override. The effective rotate-by instant MUST be the earliest of the per-secret `expires_at` and each applicable policy's `key_updated_at + max_age_days`, computed from server-visible fields only.

#### Scenario: Most-specific policy wins
- GIVEN an admin default and a stricter folder policy both applying to a secret
- WHEN the effective rotate-by instant is resolved
- THEN the earliest applicable instant MUST be used, and a per-secret `expires_at` MUST take precedence over every policy

#### Scenario: No policy means no expiry
- GIVEN a secret with no `expires_at` and no applicable policy
- WHEN expiry is evaluated
- THEN the system MUST NOT treat the secret as expiring or overdue

### Requirement: Approaching-expiry and overdue reminders
The system MUST run a background job that dispatches a Nextcloud notification to the owner for each secret crossing an approaching-expiry threshold or overdue, gated by the owner's security-notification preference, without duplicating a notification for the same secret and threshold, and MUST raise an idempotent `policy_expiry` rotation flag for overdue secrets.

#### Scenario: Approaching expiry notifies owner
- GIVEN a secret within a reminder threshold and the owner's security notifications enabled
- WHEN the reminder job runs
- THEN the owner MUST receive a Nextcloud notification identifying the secret and due date

#### Scenario: Overdue raises a rotation flag once
- GIVEN a secret past its effective rotate-by instant with no open flag
- WHEN the reminder job runs
- THEN exactly one `policy_expiry` rotation flag MUST be raised for that secret

### Requirement: Proven mark-rotated flow
The system MUST resolve an open rotation flag to `rotated` only when the secret's `key` ciphertext actually changed since the flag was raised (its `key_updated_at` advanced past the snapshot); a mark-rotated request against an unchanged secret MUST leave the flag open and offer the secret-requests re-request path.

#### Scenario: Rotation confirmed by key change
- GIVEN an open rotation flag snapshotting the secret's `key_updated_at`
- WHEN the owner rewrites the value and marks it rotated
- THEN the flag MUST transition to `rotated`

#### Scenario: Mark-rotated without a change does not close
- GIVEN an open flag and an unchanged secret
- WHEN the owner marks it rotated
- THEN the flag MUST remain open and the response MUST offer the re-request rotation path

### Requirement: Rotate-after-breach and rotate-after-compromise flagging
The system MUST let a client batch-flag secret IDs for rotation with a neutral `user_flagged` reason, MUST auto-flag every `possibly_compromised_at` secret after suite-compromise recovery with reason `suite_compromise`, and MUST NOT persist any breach verdict, strength score, or value digest server-side.

#### Scenario: Client batch-flags without a verdict
- GIVEN a client that locally found secrets needing rotation
- WHEN it submits the batch-flag request
- THEN the body MUST contain only secret IDs and the stored flags MUST carry reason `user_flagged` with no verdict persisted

#### Scenario: Compromise auto-flags
- GIVEN a suite-compromise recovery marks secrets `possibly_compromised_at`
- WHEN handling completes
- THEN each affected secret MUST carry an open `suite_compromise` flag

### Requirement: Rotation surfaced on dashboard and health report
The system MUST surface pending rotation flags and overdue-expiry counts on the Doriath dashboard and within the existing password-health report categories, each finding deep-linking to the secret.

#### Scenario: Dashboard shows rotation-due count
- GIVEN an owner with open rotation flags
- WHEN they view the dashboard while unlocked
- THEN a rotation-due count MUST be shown and link to the affected secrets

### Requirement: Expiry and rotation actions are audited
The system MUST emit audit events for setting expiry, flagging, rotating, dismissing, and policy changes using the existing string-typed audit whitelist, carrying no secret value, login, or ciphertext.

#### Scenario: Rotation completion audited without secret material
- WHEN a flag transitions to `rotated`
- THEN a `secret.rotated` audit event MUST be recorded with no key, login, value, or ciphertext field

## User Stories

- As a user, I want to set an expiry on a secret so I am reminded to rotate it before it goes stale
- As an admin, I want to set a default rotation cadence per secret type or folder so the whole vault stays compliant with our policy
- As a user, I want a reminder in Nextcloud when a credential is approaching or past its rotation date
- As a security-conscious user, I want breached or compromised secrets flagged for rotation and tracked until I actually replace them
- As an admin, I want an audit trail of expiry and rotation actions for a NIS2/BIO2 compliance review
- As a user, I want to re-request a secret from a vendor to rotate it without ever seeing the new value

## Acceptance Criteria

- [ ] Per-secret `expires_at` is set/cleared without changing `key_updated_at`; cross-owner set is rejected
- [ ] Effective expiry resolves to the earliest applicable instant from server-visible fields only; admin default ships disabled; no-policy secrets never expire
- [ ] Approaching and overdue reminders fire on the daily job, gated by `notify_security`, with no duplicate per secret+threshold
- [ ] Overdue and `possibly_compromised_at` secrets raise idempotent rotation flags
- [ ] Client batch-flag stores secret IDs only with reason `user_flagged`; no breach verdict/score/digest is persisted
- [ ] Mark-rotated closes a flag only on a proven `key_updated_at` advance; otherwise it offers re-request
- [ ] Rotation-due/overdue counts surface on the dashboard and in the password-health report with deep links
- [ ] Expiry/rotation/policy actions emit audit events carrying no secret material

## Notes

- Honest boundary: Doriath stores static, zero-knowledge secrets and cannot mint dynamic credentials; this feature owns the policy/reminder/rotation-tracking loop on server-visible metadata (`key_updated_at`, `expires_at`, `possibly_compromised_at`) only — it never decrypts.
- Reuses: `key_updated_at` and the staleness threshold from `password-health`; the re-request rotation mechanism from `secret-requests`; the `TimedJob`/notification patterns from `CheckRootCertificateExpiry`/`DoriathNotifier`; the string-typed audit whitelist from `secret-audit-trail`.
- Related: `machine-secret-leases` (lease expiry emits a rotation trigger consumed here when both are present).
- Related ADRs: ADR-001 (own tables — imperative, no OpenRegister), ADR-003 (zero-knowledge, no server-side decryption).
