---
status: proposed
---

# Credential Rotation Policies and Expiry Reminders

## Purpose

Give Doriath a compliance-grade credential-hygiene loop (NIS2 Art. 21(2)(j) / Dutch Cyberbeveiligingswet, BIO2 v1.3) — per-secret and per-folder/type expiry, admin-default and user-override max-age policy, approaching/overdue reminders, a proven mark-rotated flow, and a rotate-after-breach/compromise flagging loop — computed entirely on server-visible metadata (`key_updated_at`, `expires_at`, `possibly_compromised_at`) with no decryption.

## ADDED Requirements

### Requirement: Per-secret expiry without ciphertext change

Doriath SHALL let a secret owner set or clear an `expires_at` on a secret, and this operation MUST NOT alter the secret's `key` ciphertext or reset its `key_updated_at`.

#### Scenario: Owner sets an expiry date

- **WHEN** an owner sets `expires_at` on one of their secrets
- **THEN** the secret's `expires_at` MUST be stored
- **AND** the secret's `key_updated_at` MUST remain unchanged

#### Scenario: Setting expiry on another user's secret is rejected

- **WHEN** a user attempts to set `expires_at` on a secret they do not own
- **THEN** the request MUST be rejected with a forbidden/authorization error and no value MUST be stored

### Requirement: Expiry policies with admin default and user override

Doriath SHALL support max-age expiry policies scoped to a secret type or a folder subtree, an instance-wide admin default max-age and reminder cadence, and a per-user override. The effective rotate-by instant for a secret MUST be the earliest of its per-secret `expires_at` and every applicable policy's `key_updated_at + max_age_days`, resolved from server-visible fields only. The admin default MUST ship disabled (max-age `0`).

#### Scenario: Most-specific policy wins

- **GIVEN** an admin default max-age and a stricter folder policy both apply to a secret
- **WHEN** the effective rotate-by instant is resolved
- **THEN** the earliest applicable instant MUST be used
- **AND** a per-secret `expires_at`, when set, MUST take precedence over every policy

#### Scenario: No policy means no expiry

- **GIVEN** a secret with no `expires_at` and no applicable type, folder, user, or admin policy
- **WHEN** expiry is evaluated
- **THEN** the secret MUST NOT be treated as expiring or overdue

#### Scenario: Resolution never decrypts

- **WHEN** effective expiry is computed for any secret
- **THEN** the computation MUST use only server-visible fields (`expires_at`, `key_updated_at`, `created_at`, policy rows)
- **AND** MUST NOT require or perform any decryption of the secret value

### Requirement: Approaching-expiry and overdue reminders

Doriath SHALL run a background job that finds secrets crossing an approaching-expiry reminder threshold or already overdue and dispatches a Nextcloud notification to the owner, gated by the owner's security-notification preference, and MUST NOT dispatch duplicate notifications for the same secret and threshold.

#### Scenario: Approaching expiry notifies the owner

- **GIVEN** a secret whose effective rotate-by instant falls within a configured reminder threshold and the owner's security notifications are enabled
- **WHEN** the reminder job runs
- **THEN** the owner MUST receive a Nextcloud notification identifying the secret and its due date

#### Scenario: Security notifications off suppresses reminders

- **GIVEN** an owner who has disabled security notifications
- **WHEN** the reminder job runs over their expiring secrets
- **THEN** no expiry notification MUST be dispatched to that owner

#### Scenario: Overdue secret raises a rotation flag

- **GIVEN** a secret past its effective rotate-by instant with no open rotation flag
- **WHEN** the reminder job runs
- **THEN** an open rotation flag with reason `policy_expiry` MUST be raised for that secret exactly once

### Requirement: Proven mark-rotated flow

Doriath SHALL resolve an open rotation flag to `rotated` only when the secret's `key` ciphertext has actually changed since the flag was raised (its `key_updated_at` advanced past the value snapshotted at flag time); a mark-rotated request against an unchanged secret MUST NOT close the flag and MUST offer the re-request rotation path instead.

#### Scenario: Rotation is confirmed by a key change

- **GIVEN** an open rotation flag whose snapshot recorded the secret's `key_updated_at` at flag time
- **WHEN** the owner rewrites the secret value and then marks it rotated
- **THEN** the flag MUST transition to `rotated` because `key_updated_at` advanced

#### Scenario: Mark-rotated without a key change does not close the flag

- **GIVEN** an open rotation flag and a secret whose `key` ciphertext has not changed since flagging
- **WHEN** the owner clicks mark-rotated
- **THEN** the flag MUST remain open
- **AND** the response MUST offer the secret-requests re-request path for write-without-read rotation

### Requirement: Rotate-after-breach and rotate-after-compromise flagging

Doriath SHALL let a client batch-flag a set of secret IDs for rotation with a neutral `user_flagged` reason, MUST auto-flag every secret carrying `possibly_compromised_at` after a suite-compromise recovery with reason `suite_compromise`, and MUST NOT persist any breach verdict, strength score, or value digest server-side.

#### Scenario: Client batch-flags breached secrets without leaking a verdict

- **GIVEN** a client that has locally determined (via the password-health breach check) that certain secrets need rotation
- **WHEN** it submits the batch-flag request
- **THEN** the request body MUST contain only secret IDs
- **AND** the stored flags MUST carry reason `user_flagged` with no breach verdict, score, or digest persisted

#### Scenario: Compromise recovery auto-flags affected secrets

- **GIVEN** a suite-compromise recovery marks secrets with `possibly_compromised_at`
- **WHEN** the compromise handling completes
- **THEN** each affected secret MUST carry an open rotation flag with reason `suite_compromise`

### Requirement: Rotation surfaced on dashboard and health report

Doriath SHALL surface pending rotation flags and overdue-expiry counts on the Doriath dashboard and within the existing password-health report categories, each finding deep-linking to the affected secret.

#### Scenario: Dashboard shows rotation-due count

- **GIVEN** an owner with one or more open rotation flags
- **WHEN** they view the Doriath dashboard while unlocked
- **THEN** a rotation-due count MUST be shown
- **AND** it MUST link to the list of affected secrets

### Requirement: Expiry and rotation actions are audited

Doriath SHALL emit audit events for setting expiry, flagging, rotating, dismissing, and policy changes using the existing string-typed audit whitelist, and these events MUST NOT carry any secret value, login, or ciphertext.

#### Scenario: Rotation completion is audited without secret material

- **WHEN** a rotation flag transitions to `rotated`
- **THEN** a `secret.rotated` audit event MUST be recorded
- **AND** it MUST contain no key, login, value, or ciphertext field
