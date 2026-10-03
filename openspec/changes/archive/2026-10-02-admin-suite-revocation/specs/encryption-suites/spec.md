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

### Requirement: A Compromise Force-Revoke Contains The Account
A force-revoke with `markCompromised: true` MUST contain everything the compromised key could reach, not only flag the secrets sealed under it. The system MUST collect the blast radius of every suite it is about to revoke (the named suite and, during an in-progress migration, the other end) BEFORE it revokes any of them, because the revoke cascade deletes the ShareTargets and invalidates the emergency contacts the lookup reads (keepiq#864). The revoke cascade MUST sweep only the ShareTargets of copies sealed under the suite being revoked.

After the revoke the system MUST:

- stamp and flag every secret in the blast radius and, for a shared copy, its source, and warn each affected owner once with the first secret's name and the number of other affected secrets (keepiq#875);
- warn every user who holds a copy of the revoked user's own secrets, and every grantor whose `approved` emergency grant is sealed to a revoked suite, because that envelope escrows the grantor's private key (keepiq#872);
- revoke the revoked user's link shares and passkeys through the same helper the owner's compromise recovery uses (keepiq#858);
- end every Nextcloud session and app password of the revoked user (keepiq#860).

Each step MUST contain its own failure: a failure on one secret, one notification or one cleanup step MUST NOT stop the others. The number of failed steps MUST be returned in the response as `cascade.failed`, with `cascadeIncomplete: true` when it is above zero, so the administrator is not told containment ran when part of it did not (keepiq#863).

Every force-revoke, compromise or not, that deletes usable emergency contacts MUST notify the suite's owner with the number deleted (both ends of a terminated migration counted together), because revocation leaves the owner nothing to look at afterwards (keepiq#876). The response MUST report the other end's count as `alsoRevokedEmergencyContactsDestroyed` (keepiq#877).

#### Scenario: A copy on the second suite of a migration warns its source owner
@e2e exclude Needs two vault users and an open migration; covered by PHPUnit on CompromiseContainmentService with the real revoke listener.
- **GIVEN** user A holds a copy of C's secret, sealed under B, the new end of A's in-progress migration
- **WHEN** an administrator force-revokes A's old suite with `markCompromised: true`
- **THEN** C MUST be warned about the source secret
- **AND** the source secret MUST be stamped `possibly_compromised_at` and flagged for rotation

#### Scenario: One failing notification does not stop the cascade
@e2e exclude Failure injection; covered by PHPUnit on CompromiseContainmentService and EncryptionSuiteController.
- **GIVEN** the notification for the first owner fails
- **WHEN** the compromise cascade runs
- **THEN** the second owner's secrets MUST still be stamped, flagged and warned
- **AND** the response MUST carry `cascadeIncomplete: true`

#### Scenario: An owner with several affected secrets is told how many
@e2e exclude Notification content; covered by PHPUnit on CompromiseContainmentService and KeepiqNotifier.
- **GIVEN** three of A's secrets are in the blast radius
- **WHEN** the compromise cascade runs
- **THEN** A MUST get one notification naming the first secret and counting the two others

#### Scenario: Grantors and share recipients are warned
@e2e exclude Needs several vault users; covered by PHPUnit on CompromiseContainmentService.
- **GIVEN** D designated A as emergency contact and approved A's request, and B holds a copy of A's secret
- **WHEN** an administrator force-revokes A's suite with `markCompromised: true`
- **THEN** D MUST be warned that their vault may be exposed
- **AND** B MUST be warned about the copy

#### Scenario: The account is contained
@e2e exclude Ends the sessions of the user under test; covered by PHPUnit on CompromiseContainmentService.
- **GIVEN** A has a link share and an active session
- **WHEN** an administrator force-revokes A's suite with `markCompromised: true`
- **THEN** A's link shares and passkeys MUST be revoked
- **AND** every session and app password of A MUST be invalidated

#### Scenario: The owner learns their emergency contacts are gone
@e2e exclude Notification side effect; covered by PHPUnit on CompromiseContainmentService and EncryptionSuiteController.
- **GIVEN** A's suite has two usable emergency contacts
- **WHEN** an administrator force-revokes it, with or without `markCompromised`
- **THEN** A MUST be notified that two emergency contacts were deleted

### Requirement: A Suite Revoked As Compromised Cannot Be Reinstated
Reinstating a suite re-opens every secret under its key. The admin `reinstate()` endpoint MUST therefore carry `#[PasswordConfirmationRequired]` like force-revoke does, and the admin UI MUST run the password confirmation before the request (keepiq#865).

The system MUST refuse to reinstate a suite whose last `SUITE_REVOKED` audit entry carries `markCompromised: true`. The compromise decision stays off the suite row (ADR-005), so the audit entry is the record. When no `SUITE_REVOKED` entry can be found for the suite, the system MUST refuse as well: nothing shows the revocation was a harmless one. The system MUST also refuse when the suite's owner already has another active suite, because a reinstate would leave them with two. Each refusal MUST answer `409` with `error` set to `revoked_as_compromised` or `owner_has_active_suite`, and MUST change nothing.

The admin UI MUST NOT offer Reinstate for a suite it has just revoked as compromised.

#### Scenario: Reinstating a compromise revoke is refused
@e2e exclude Server-side refusal on an admin API route behind sudo; covered by PHPUnit on EncryptionSuiteService and EncryptionSuiteController, and vitest on AdminSuiteSection.
- **GIVEN** an administrator force-revoked suite S with `markCompromised: true`
- **WHEN** an administrator reinstates S
- **THEN** the system MUST refuse with `409` and `error: revoked_as_compromised`
- **AND** S MUST stay revoked

#### Scenario: Reinstating next to an active suite is refused
@e2e exclude Server-side refusal; covered by PHPUnit on EncryptionSuiteService.
- **GIVEN** suite S was revoked without compromise and its owner has since set up a new active suite
- **WHEN** an administrator reinstates S
- **THEN** the system MUST refuse with `409` and `error: owner_has_active_suite`

#### Scenario: No Reinstate button after a compromise revoke
@e2e exclude Covered by vitest on AdminSuiteSection; the live flow needs sudo.
- **GIVEN** an administrator just force-revoked a suite with `markCompromised: true`
- **WHEN** the result is shown
- **THEN** the Reinstate button MUST NOT be shown

### Requirement: Re-Enrolment After A Revocation Requires A Fresh Password Confirmation
A user whose suites are all revoked or replaced, with none active, MUST NOT be able to enrol a new suite with only a session. `POST /api/v1/suites` MUST refuse such a user with `403` and `error: reauthentication_required`, and the user MUST enrol through `POST /api/v1/suites/reenrol`, which carries `#[PasswordConfirmationRequired]` (keepiq#860). Without this, a stolen session that survived an administrator's containment could enrol a key pair it owns and receive every new share and emergency designation meant for the user. The browser MUST confirm the password and retry on the re-enrol route when it gets that refusal.

Accounts that cannot confirm a password (single sign-on) pass the sudo guard. For them the protection is that a compromise force-revoke ends every session and app password (see Requirement: A Compromise Force-Revoke Contains The Account).

#### Scenario: A session alone cannot replace a revoked identity
@e2e exclude Needs a revoked user session; covered by PHPUnit on EncryptionSuiteController and vitest on the encryptionSuite store.
- **GIVEN** user A's only suite was force-revoked
- **WHEN** a session of A posts a new suite to `/api/v1/suites`
- **THEN** the system MUST refuse with `403` and `error: reauthentication_required`
- **AND** MUST NOT create a suite

#### Scenario: Re-enrolment after a password confirmation
@e2e exclude Needs the Nextcloud password confirmation dialog; covered by PHPUnit and vitest.
- **GIVEN** user A's only suite was revoked
- **WHEN** A confirms their Nextcloud password and posts the new suite to `/api/v1/suites/reenrol`
- **THEN** the system MUST create the suite

### Requirement: A Suite In An In-Progress Migration Cannot Be Revoked
The system MUST refuse to revoke a suite, by an administrator's force-revoke that is not marked as a compromise or by its owner, while that suite is the old or the new end of a key migration that is still `in_progress` (keepiq#803). Revoking the old end blocks the reads the owner's browser needs to re-encrypt; revoking the new end makes records that were already re-encrypted, or are being written, unreadable. Either way the migration and the vault write lock would stay `in_progress` with no way to finish. The refusal MUST happen before anything is changed, MUST answer `409` with `error: migration_in_progress`, and MUST say that the migration has to be completed or aborted first.

A force-revoke marked as a compromise (`markCompromised: true`) is the exception. The owner's abort is refused once any record has moved, and every migration route is owner-only, so whoever holds the session and the leaked password could otherwise keep the administrator's containment blocked for good by leaving a migration open. A compromise force-revoke therefore MUST NOT be refused for an in-progress migration. Instead it MUST revoke the migration's other end as compromised too, because during a compromise either end may be the one the attacker controls, and only then end the migration (status `terminated`, which releases the write lock and unlocks the SecretRequests the migration locked, leaving them on the old suite). A SecretRequest whose suite is no longer `active` MUST NOT be shown or filled through its public link: the fill page would otherwise hand out the certificate of a suite revoked as compromised, possibly one whose private key the attacker holds. This holds whether or not a migration was open. Ending it last means that if revoking the other end fails, a retry of the force-revoke still finds the open migration and completes the containment.

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

#### Scenario: A request on a revoked suite cannot be filled
@e2e exclude Server-side refusal on a public endpoint; covered by PHPUnit on SecretRequestPolicy and SecretRequestFillController.
- **GIVEN** a SecretRequest whose suite was force-revoked as compromised, with or without an open migration
- **WHEN** someone opens or submits its public fill link
- **THEN** the system MUST refuse with `410` and reason `unavailable`
- **AND** MUST NOT return that suite's certificate

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
