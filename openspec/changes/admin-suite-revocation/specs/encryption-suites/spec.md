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

### Requirement: A Suite In An In-Progress Migration Cannot Be Revoked
The system MUST refuse to revoke a suite, by an administrator's force-revoke that is not marked as a compromise or by its owner, while that suite is the old or the new end of a key migration that is still `in_progress` (keepiq#803). Revoking the old end blocks the reads the owner's browser needs to re-encrypt; revoking the new end makes records that were already re-encrypted, or are being written, unreadable. Either way the migration and the vault write lock would stay `in_progress` with no way to finish. The refusal MUST happen before anything is changed, MUST answer `409` with `error: migration_in_progress`, and MUST say that the migration has to be completed or aborted first.

A force-revoke marked as a compromise (`markCompromised: true`) is the exception. The owner's abort is refused once any record has moved, and every migration route is owner-only, so whoever holds the session and the leaked password could otherwise keep the administrator's containment blocked for good by leaving a migration open. A compromise force-revoke therefore MUST NOT be refused for an in-progress migration. Instead it MUST revoke the migration's other end as compromised too, because during a compromise either end may be the one the attacker controls, and only then end the migration (status `terminated`, which releases the write lock and unlocks the SecretRequests the migration locked, leaving them on the old suite). Ending it last means that if revoking the other end fails, a retry of the force-revoke still finds the open migration and completes the containment.

#### Scenario: Force-revoke of a suite mid-migration is refused
@e2e exclude Server-side refusal on an admin API route; covered by PHPUnit on EncryptionSuiteController and MigrationService.
- **GIVEN** user A's suite is the old or the new end of a migration in state `in_progress`
- **WHEN** an administrator force-revokes that suite
- **THEN** the system MUST refuse with `409` and `error: migration_in_progress`
- **AND** the suite, its emergency contacts and the migration MUST be unchanged

#### Scenario: A compromise force-revoke ends an open migration instead of being blocked
@e2e exclude Server-side admin API behaviour; covered by PHPUnit on EncryptionSuiteController and MigrationService.
- **GIVEN** someone holding user A's session and leaked password started a compromise recovery, committed one record so that abort is refused, and left the migration `in_progress`
- **WHEN** an administrator force-revokes either suite of that migration with `markCompromised: true`
- **THEN** the system MUST revoke the suite without a `409`
- **AND** it MUST revoke the migration's other suite as compromised as well
- **AND** only then it MUST set the migration to `terminated`, releasing the write lock and unlocking the SecretRequests the migration locked

#### Scenario: A failed containment step can be retried
@e2e exclude Server-side failure handling; covered by PHPUnit on EncryptionSuiteController.
- **GIVEN** a compromise force-revoke revoked one end of an in-progress migration, and revoking the other end failed
- **WHEN** the administrator runs the same force-revoke again
- **THEN** the system MUST still find the in-progress migration, revoke the other end, and terminate the migration

#### Scenario: The owner's revoke of a suite mid-migration is refused
@e2e exclude Server-side refusal; covered by PHPUnit on EncryptionSuiteController.
- **GIVEN** user A's suite is part of a migration in state `in_progress`
- **WHEN** A revokes that suite
- **THEN** the system MUST refuse with `409` and `error: migration_in_progress`

#### Scenario: A finished migration does not block revocation
@e2e exclude Server-side check; covered by PHPUnit on MigrationService.
- **GIVEN** every migration the suite was part of is `completed`, `completed_with_errors` or `aborted`
- **WHEN** the suite is revoked
- **THEN** the migration check MUST NOT refuse it
