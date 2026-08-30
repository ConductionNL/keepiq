# Org Password Policies Specification

**Status**: done

**OpenSpec changes:** [org-password-policies](../../changes/org-password-policies/)

## Purpose

Give Keepiq administrators an instance-wide, configurable password policy for stored
secret values — a minimum length and character-class floor locked into the server-side
key generator, a minimum zxcvbn strength score for manually entered login passwords, an
optional block-on-HIBP-hit at save time, and per-`SecretType` exemptions. This turns the
existing generator, password-health, and admin-settings building blocks into the
configurable credential-hygiene control that BIO2 / NIS2 procurement expects and that
Bitwarden, Keeper, and Passbolt gate behind paid tiers.

The policy respects Keepiq's zero-knowledge boundary (ADR-003): the server stores the
policy and authoritatively enforces the generator floor and the per-type exemption
metadata rule, but it can never see a manually entered plaintext value — those checks are
hygiene guidance run client-side in the save flow for honest clients, not a security
boundary against a hostile client.

## Requirements

### Requirement: Configurable Org Password Policy
The system MUST let an admin configure an instance-wide policy (`policy_enabled`,
`generator_min_length`, `generator_require_*` character classes, `min_zxcvbn_score`,
`block_on_hibp_hit`, `policy_exempt_types`) stored in `IAppConfig` with no new table, and
MUST validate it server-side before persisting.

#### Scenario: Admin saves and validation applies
- GIVEN an admin opens the Keepiq admin settings
- WHEN they save a policy with a below-floor `generator_min_length`
- THEN the system MUST reject it, and MUST persist a valid policy otherwise

### Requirement: Generator Locked to Policy
The system MUST clamp the server-side key generator to the policy so it can never emit a
value below the length floor or missing a required character class, rejecting a
non-compliant `regex` override. This enforcement is server-authoritative.

#### Scenario: Generated value is always compliant
- GIVEN a policy floor of length 20 with a required symbol
- WHEN a user generates a password
- THEN the value MUST be at least 20 characters and able to contain a symbol

### Requirement: Client-Side Save Enforcement
The system MUST, in the secret create/edit dialog, block a manually entered login value
below `min_zxcvbn_score` or (when `block_on_hibp_hit`) found in HIBP, before encryption —
skipping exempt types — while the server never inspects or gates on the value.

#### Scenario: Weak manual value blocked, exempt type skipped
- GIVEN a policy requiring score ≥ 3, exempting `ssh_key`
- WHEN a user saves a weak `login` value
- THEN submission MUST be blocked; the same weak value on an `ssh_key` secret MUST save

### Requirement: Audited Policy Changes
The system MUST emit a `password_policy.updated` audit event, containing the actor and
before/after values and no secret data, on every policy change.

#### Scenario: Change is audited
- WHEN an admin changes a policy field
- THEN a `password_policy.updated` audit event MUST be dispatched

## User Stories

- As an administrator, I want to require a minimum password strength and length for stored
  secrets so that my organisation meets its credential-hygiene obligations.
- As an administrator, I want generated passwords to always satisfy our policy so that the
  easy path is the compliant path.
- As an administrator, I want to block passwords found in known breaches at save time.
- As an administrator, I want to exempt machine-key secret types so that policy noise does
  not fall on high-entropy material.
- As a user, I want to see why a password is rejected before I save so that I can fix it.

## Acceptance Criteria

- [ ] Policy is admin-configurable and stored in `IAppConfig` (no new table)
- [ ] Invalid policy values are rejected server-side
- [ ] Generator can never emit a value below the policy floor (server-authoritative)
- [ ] Save dialog blocks weak / HIBP-hit manual login values before encryption
- [ ] Exempt secret types skip the value checks
- [ ] Server never inspects, scores, or gates a save on a secret value
- [ ] Every policy change emits a `password_policy.updated` audit event with no secret data

## Notes

- Related specs: key-generator (the generator the policy clamps), password-health
  (client-side zxcvbn + HIBP k-anonymity reused), admin-settings (master-password floor,
  a different control), secrets-write-ui (the save path the checks hook).
- Related ADRs: ADR-001 (own tables / `IAppConfig`, no OpenRegister), ADR-003
  (zero-knowledge — the reason manual-entry checks are client-side and non-authoritative).
- Out of scope for v1: server-side value inspection, retroactive re-scoring, per-user /
  per-group policy scoping.
