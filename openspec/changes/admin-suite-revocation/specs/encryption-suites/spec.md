## ADDED Requirements

### Requirement: Administrator Force-Revocation

An administrator MUST be able to revoke any EncryptionSuite by id — user-owned or application-owned — through `POST /api/v1/suites/{id}/force-revoke`, without producing the vault-key proof the owner path requires. The vault is zero-knowledge (see ADR-003): the server never holds a usable private key, so an administrator cannot sign the revoke challenge. Administrator revocation is therefore the *only* revocation path for a locked-out owner (forgotten master password), a de-authorised departure, or a compromise, and the only revocation path of any kind for an application-owned suite, which has no human owner to produce a proof.

The endpoint MUST be guarded by BOTH:

- `#[AuthorizedAdminSetting(AdminSettings::class)]` — administrator only, mirroring the existing admin-only `reinstate()`; a non-administrator MUST be rejected by Nextcloud middleware before the controller body runs.
- `#[PasswordConfirmationRequired]` — Nextcloud sudo mode. The administrator re-confirms their **own** account password; there is no vault key to prove. A stale or missing sudo confirmation MUST cause Nextcloud to refuse the request before the controller body runs.

The endpoint MUST reuse the owner-agnostic `EncryptionSuiteService::revokeSuite()`, which records the acting administrator (resolved via `OCP\IUserSession`) as `revokedBy` and dispatches `EncryptionSuiteRevokedEvent` so the existing destructive cascade (`EncryptionSuiteRevokedListener`) runs: the owner's inbound `ShareTarget`s are deleted, their temporary delegations promoted, and their emergency envelopes cleared.

The administrator MUST supply a **required, free-form** `reason`. An empty or missing reason MUST be rejected. The reason MUST be stored in the existing `revoked_reason` `STRING(255)` column — no new column and no migration.

Whether the revoked suite's secrets are treated as **compromised** MUST be an explicit, transient request parameter `markCompromised` (default `false`), carried only in the request and **never persisted** as a suite column. The compromise decision is a human, situational judgment, and MUST NOT be derived from the free-form reason text.

The `SUITE_REVOKED` audit event's metadata MUST carry `{ reason, markCompromised, emergencyContactsDestroyed }`; the `AuditEventTypes` metadata whitelist for `SUITE_REVOKED` MUST permit those three keys.

#### Scenario: Administrator force-revokes a locked-out owner's suite (forgotten password)

- **GIVEN** a user is locked out of their vault (forgotten master password) and cannot produce a vault-key proof
- **WHEN** an authenticated administrator, having passed sudo confirmation, calls `POST /api/v1/suites/{id}/force-revoke` with a non-empty `reason` and `markCompromised=false`
- **THEN** the suite MUST be revoked with `revokedBy` set to the administrator and `revoked_reason` set to the supplied reason
- **AND** no compromise cascade MUST run (secrets MUST NOT be flagged `possibly_compromised_at` and no `suite_compromise` rotation flags MUST be raised)
- **AND** the user MUST be able to re-onboard with a fresh suite through the existing onboarding flow

#### Scenario: Administrator de-authorises a departing user without marking compromise

- **GIVEN** a departing user whose secrets are long, generated, non-memorable passwords
- **WHEN** the administrator force-revokes the user's suite with `markCompromised=false`
- **THEN** the suite MUST be revoked and no compromise cascade MUST run
- **AND** the response MUST surface a warning that the revoked user may still know these secrets and that rotation may be warranted, leaving the rotation decision to the administrator

#### Scenario: Administrator marks the revocation as a compromise

- **GIVEN** a suite whose private key or master password is believed to be in an attacker's hands
- **WHEN** the administrator force-revokes the suite with `markCompromised=true`
- **THEN** every secret sealed under the suite (`SecretMapper::findByEncryptionSuiteId`) MUST be flagged `possibly_compromised_at`
- **AND** `suite_compromise` rotation flags MUST be raised for those secrets via `RotationPolicyService`/`RotationFlagService::flagCompromisedSecrets` (idempotent)
- **AND** the affected owners MUST be notified with the existing `secret_compromised` notification subject
- **AND** the cascade MUST run on the revoke path itself (scoped to the revoked suite), not via a `SuiteMigrationCompletedEvent`

#### Scenario: A non-administrator is refused

- **GIVEN** an authenticated non-administrator user
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke` on any suite id
- **THEN** Nextcloud's `AuthorizedAdminSetting` guard MUST reject the request before the controller body runs
- **AND** the suite MUST NOT be revoked

#### Scenario: Sudo confirmation is required

- **GIVEN** an administrator whose password-confirmation (sudo) window has expired
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke`
- **THEN** the `PasswordConfirmationRequired` guard MUST refuse the request until the administrator re-confirms their own account password
- **AND** the suite MUST NOT be revoked until sudo is satisfied

#### Scenario: A missing reason is rejected

- **GIVEN** an administrator who has passed the admin and sudo guards
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke` with an empty or missing `reason`
- **THEN** the request MUST be rejected and the suite MUST NOT be revoked

#### Scenario: An application-owned suite is force-revoked

- **GIVEN** an application-owned EncryptionSuite (id `00000000-0000-0000-0000-000000000000`) that has no human owner able to produce a vault-key proof
- **WHEN** an administrator force-revokes it with a non-empty `reason`
- **THEN** the same endpoint MUST revoke it via `revokeSuite()`, recording the administrator as `revokedBy`
- **AND** no owner-side vault-key proof MUST be required

#### Scenario: Emergency access is cleared unconditionally and its count audited, not gated

- **GIVEN** a suite with one or more usable emergency contacts
- **WHEN** an administrator force-revokes it
- **THEN** the revocation MUST proceed and the emergency access MUST be cleared unconditionally (revocation is authoritative — unlike the owner path, no `acceptEmergencyLoss` gate blocks it)
- **AND** the count of destroyed usable emergency contacts (`EmergencyEnvelopeInvalidationService::countUsableForGrantorSuite`) MUST be recorded in the `SUITE_REVOKED` audit metadata as `emergencyContactsDestroyed` and surfaced to the administrator as an informational warning
- **AND** the emergency contacts' identities MUST NOT cross the wire — only the count
