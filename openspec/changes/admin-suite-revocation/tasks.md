## 0. Read First — Scope and Constraints

Scope is the administrator force-revoke endpoint (ADR-005). No database migration, no new column, no `<version>` bump: `reason` reuses the existing `revoked_reason` column and `markCompromised` is a transient request parameter, never persisted. The owner path (`revoke()` with the vault-key proof) MUST stay untouched. This is the app's first use of `#[PasswordConfirmationRequired]`.

## 1. Backend — Endpoint and Guards

- [ ] 1.1 Add `EncryptionSuiteController::forceRevoke(string $id, string $reason, bool $markCompromised = false)` guarded by `#[AuthorizedAdminSetting(AdminSettings::class)]` (mirroring the existing `reinstate()`) AND `#[PasswordConfirmationRequired]`; resolve the acting administrator via `OCP\IUserSession` and record it as `revokedBy`. It MUST reuse `EncryptionSuiteService::revokeSuite()` and MUST NOT call `validateOwnership()` (cross-owner admin action; authorization is the admin guard)
- [ ] 1.2 Register `POST /api/v1/suites/{id}/force-revoke` (`encryptionSuite#forceRevoke`) in `appinfo/routes.php` alongside the other suite routes and before the SPA catch-all wildcard
- [ ] 1.3 Reject an empty or missing `reason` with `STATUS_BAD_REQUEST`; on success store it in the existing `revoked_reason` field (no new column). Handle the same `RuntimeException`/`InvalidArgumentException` mapping the owner `revoke()` uses

## 2. Backend — Compromise Cascade and Audit

- [ ] 2.1 Add a `compromised` flag to `EncryptionSuiteRevokedEvent` and a `bool $markCompromised = false` param to `revokeSuite()` that sets it on the dispatched event; add a new `SuiteCompromiseOnRevokeListener` (sibling to `EncryptionSuiteRevokedListener` on the same event) that reacts only when the flag is true — walk `SecretMapper::findByEncryptionSuiteId($id)`, stamp `possibly_compromised_at`, raise `suite_compromise` flags via `RotationPolicyService::flagCompromisedSecrets` (idempotent), and notify affected owners with the `secret_compromised` subject. The owner `revoke()` path passes no flag, so the listener no-ops there
- [ ] 2.2 When `markCompromised === false`, run no cascade and return the "user may still know these secrets; rotation may be warranted" warning in the response for the UI to surface
- [ ] 2.3 Read `EmergencyEnvelopeInvalidationService::countUsableForGrantorSuite($id)` BEFORE the `EncryptionSuiteRevokedEvent` cascade clears the envelopes; thread the integer into the `SUITE_REVOKED` audit metadata as `emergencyContactsDestroyed` and return it in the response (count only — never contact identities). Emergency access is cleared unconditionally; it MUST NOT be gated
- [ ] 2.4 Widen the `AuditEventTypes` metadata whitelist for `SUITE_REVOKED` from `['reason']` to `['reason', 'markCompromised', 'emergencyContactsDestroyed']`, and emit all three keys from the force-revoke path

## 3. Frontend — Admin Confirmation UI

- [ ] 3.1 Add a new admin "Encryption suites" settings section (a view under `AdminRoot.vue`, alongside `AdminApplicationsView`/`AdminAuditSection`) with a force-revoke action: look up a suite by owner/id, a required reason field and a `markCompromised` toggle, calling the new endpoint via the encryptionSuite store/module
- [ ] 3.2 Perform the Nextcloud sudo (password-confirmation) flow before issuing the request, since the endpoint carries `#[PasswordConfirmationRequired]`
- [ ] 3.3 Render the returned `emergencyContactsDestroyed` count as an informational warning and, when `markCompromised` was off, the "user may still know these secrets" copy; use `@conduction/nextcloud-vue` components + the NL Design System double-fallback CSS pattern
- [ ] 3.4 Add a reinstate action on the same surface, shown only for suites in `revoked` status, wired to the existing admin-only `reinstate()` endpoint (`POST /api/v1/suites/{id}/reinstate`) — frontend-only, no server change, no sudo

## 4. Tests

- [ ] 4.1 Endpoint-guard tests: a non-administrator is refused by `AuthorizedAdminSetting`; the `PasswordConfirmationRequired` posture is asserted; the suite is not revoked in either case
- [ ] 4.2 Request-validation and scope tests: an empty/missing `reason` is rejected; an application-owned suite is force-revoked by the same endpoint with `revokedBy` = the administrator and no vault-key proof required
- [ ] 4.3 Compromise-cascade test (`markCompromised=true`): secrets from `findByEncryptionSuiteId` are flagged `possibly_compromised_at`, `suite_compromise` flags are raised, owners are notified via `secret_compromised`
- [ ] 4.4 No-cascade test (`markCompromised=false`): no secret is flagged, no rotation flag raised, and the warning is present in the response
- [ ] 4.5 Emergency-and-audit test: the usable-contact count is read before the cascade, emergency access is cleared unconditionally (not gated), and `SUITE_REVOKED` metadata carries `{ reason, markCompromised, emergencyContactsDestroyed }` with the count but no identities
- [ ] 4.6 Frontend unit test: the action collects reason + `markCompromised`, runs the sudo flow before the request, and renders the returned count and (when applicable) the no-compromise warning

## 5. Gates and Documentation

- [ ] 5.1 Run the hydra gates locally: route-auth and semantic-auth (the new admin+sudo-guarded route), no-admin-idor (the method is admin-guarded like `reinstate()`, not `NoAdminRequired`), gate-16 spec-coverage (`@spec` on the new backend + frontend methods), route-reachability (route ↔ method)
- [ ] 5.2 Confirm no migration and no `<version>` bump apply (gate-110 does not apply): `revoked_reason` is reused, `markCompromised` is transient, no new column

## Acceptance Criteria

- `POST /api/v1/suites/{id}/force-revoke` revokes any suite by id (user- or application-owned), guarded by `AuthorizedAdminSetting` + `PasswordConfirmationRequired`, recording the administrator as `revokedBy`
- A non-empty `reason` is required and stored in the existing `revoked_reason` column; no new column and no migration are introduced
- `markCompromised=true` flags every secret from `findByEncryptionSuiteId`, raises `suite_compromise` rotation flags, and notifies affected owners; `markCompromised=false` runs no cascade and surfaces the rotation-may-be-warranted warning
- Emergency access is cleared unconditionally; the destroyed-usable count is in the `SUITE_REVOKED` audit metadata and the response, and contact identities never cross the wire
- `markCompromised` is never persisted as a suite column; the owner `revoke()` path is unchanged

## Quality Checklist

- Unit tests cover the guards, required reason, application-suite scope, both compromise branches, and the emergency-count/audit behaviour
- `@spec` tags reference this change on the new backend and frontend methods; every changed method is spec-covered
- Frontend uses `@conduction/nextcloud-vue` + NL Design System double-fallback CSS, consistent with the settings surface
- Every commit carries `Assisted-by: ClaudeCode:<model>`; no `Signed-off-by` (only the human certifies the DCO)
- PR description discloses AI tool use in the contributor's own words and links ADR-005 and the `harden-vault-key-material-guards` change this is the administrator counterpart to
