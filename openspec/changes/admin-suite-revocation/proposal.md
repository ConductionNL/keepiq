---
kind: code
---

## Why

Owner-initiated suite revocation now requires a verified vault-key proof (`harden-vault-key-material-guards`, #673): the caller signs a challenge with the suite's own private key. An administrator cannot produce that proof — the vault is zero-knowledge and the server never holds a usable private key — yet administrators must still be able to revoke a suite they do not own in three real situations: a forgotten master password (the user is locked out and, because #673 blocks a proofless rotation, admin revocation is the *only* way back to a working vault), a de-authorised departure (access must be pulled though the user may still know the secrets), and a compromise (the private key or master password is in an attacker's hands). Application-owned suites have no human owner who can produce a proof at all, so administrator revocation is their only revocation path. See ADR-005.

## What Changes

- Add `POST /api/v1/suites/{id}/force-revoke`, an administrator endpoint that revokes **any** EncryptionSuite by id (user- or application-owned) — the admin counterpart to the owner vault-key-proof `revoke()`. It reuses the owner-agnostic `EncryptionSuiteService::revokeSuite()`, recording the administrator as `revokedBy`.
- Guard it with `#[AuthorizedAdminSetting(AdminSettings::class)]` (administrator only, mirroring the existing admin-only `reinstate()`) **and** `#[PasswordConfirmationRequired]` (Nextcloud sudo mode — the administrator re-confirms their **own** password; there is no vault key to prove). This is the app's first use of `PasswordConfirmationRequired`.
- Require a **free-form reason**, stored in the existing `revoked_reason` field (GDPR: the specific "why" must be recordable).
- Add a transient, **non-persisted** request parameter `markCompromised` (default `false`). When `true`, the revoke path flags every secret sealed under the suite (`SecretMapper::findByEncryptionSuiteId`) as `possibly_compromised_at`, raises `suite_compromise` rotation flags (`RotationPolicyService`/`RotationFlagService::flagCompromisedSecrets`), and notifies affected owners (`NotificationService` subject `secret_compromised`) — reusing the existing compromise cascade primitives, adapted to the revoke path. When `false`, no cascade runs and the UI shows a warning that the revoked user may still know these secrets and rotation may be warranted.
- Clear emergency access **unconditionally** (revocation is authoritative), but record the count of destroyed *usable* emergency contacts (`EmergencyEnvelopeInvalidationService::countUsableForGrantorSuite`) in the audit metadata and surface it to the administrator as an informational warning — **not a gate** (unlike the owner path's `acceptEmergencyLoss`).
- Extend the `SUITE_REVOKED` audit event metadata to carry `{ reason, markCompromised, emergencyContactsDestroyed }`.
- Add an admin-side confirmation UI (reason field, compromise toggle, sudo prompt, and the emergency-contact-count warning) in a new admin "Encryption suites" settings section (under `AdminRoot.vue`).
- Add an admin-side **reinstate** action to the same suite-management surface, wired to the existing admin-only `reinstate()` endpoint (`POST /api/v1/suites/{id}/reinstate`) which has no frontend today. This is frontend-only — no server change — and rounds out the revoke/reinstate lifecycle in one place.

No database migration, no new column, and no `<version>` bump: `revoked_reason` is reused and `markCompromised` is never persisted.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `encryption-suites`: adds a new **Administrator Force-Revocation** requirement — an admin-guarded, sudo-confirmed endpoint that revokes any suite by id with a required reason, an explicit-and-transient compromise decision that drives the existing flag/rotation/notification cascade, and unconditional emergency-access clearing surfaced (not gated) as a count.

## Impact

- **Backend**: new `EncryptionSuiteController::forceRevoke()` guarded by `AuthorizedAdminSetting` + `PasswordConfirmationRequired`; a new route in `appinfo/routes.php` before the SPA catch-all; `revokeSuite()` (or a thin admin wrapper) extended to accept the compromise flag and thread `markCompromised` + `emergencyContactsDestroyed` into the audit metadata; the `SUITE_REVOKED` metadata whitelist in `AuditEventTypes` widened from `['reason']` to `['reason', 'markCompromised', 'emergencyContactsDestroyed']`. The compromise cascade reuses `SecretMapper::findByEncryptionSuiteId`, `RotationPolicyService::flagCompromisedSecrets`, and `NotificationService` (`secret_compromised`), adapted to the revoke (no-migration) path rather than the `SuiteMigrationCompletedEvent` path `SuiteCompromiseListener` rides.
- **Frontend**: an admin suite-management surface with two actions — force-revoke (reason input, `markCompromised` toggle, `@conduction/nextcloud-vue` components + NL Design System double-fallback CSS) that performs the Nextcloud sudo (password-confirmation) flow before calling the endpoint and renders the returned emergency-contact-destroyed count, and reinstate (calling the existing admin-only `reinstate()` endpoint; no sudo, no server change).
- **Database**: none. No schema change, no migration, no `<version>` bump — `revoked_reason` is reused and `markCompromised` is transient.
- **Security**: administrator-only + sudo re-authentication; the administrator holds no vault key (zero-knowledge, ADR-003) so a key proof is impossible and sudo mode is the correct re-authentication. The compromise cascade is a deliberate, audited human decision, never derived from free-form text. Emergency-contact identities never cross the wire — only the count.
- **Cross-app**: none directly. OpenConnector application-owned suites gain a first-class, properly guarded revocation path where none existed.
