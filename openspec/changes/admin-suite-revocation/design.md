# Design — admin-suite-revocation

## Context

Owner-initiated revocation is now `revoke()` on `EncryptionSuiteController` (lib/Controller/EncryptionSuiteController.php:315), guarded by `#[NoAdminRequired]` + `#[VaultKeyProofRequired(...PURPOSE_REVOKE_SUITE)]` and self-scoped via `validateOwnership()`. It calls the owner-agnostic `EncryptionSuiteService::revokeSuite($id, $reason, $revokedBy)` (lib/Service/EncryptionSuiteService.php:160), which sets `status='revoked'`, stamps `revoked_at`/`revoked_reason`/`revoked_by`, dispatches `EncryptionSuiteRevokedEvent` (cascade: delete inbound `ShareTarget`s, promote temporary delegations, clear emergency envelopes) and a `SUITE_REVOKED` audit event whose metadata today carries only `['reason']`.

The vault-key proof is unproducible by an administrator: the server never holds a usable private key (ADR-003, zero-knowledge). So an administrator has no revocation path at all today, even though ADR-005 identifies three real situations that demand one (forgotten password, de-authorisation, compromise) plus application-owned suites, which have no human owner to sign a proof.

The compromise-signalling infrastructure already exists but is wired to the migration/recovery path, not to revocation:

- `Secret.possibly_compromised_at` (field + `jsonSerialize` + compliance count).
- `RotationPolicyService::flagCompromisedSecrets($ownerId)` → `RotationFlagService::flagCompromisedSecrets` — idempotent, raises `suite_compromise` flags.
- `NotificationService` subject `secret_compromised` (routed via `notify_security`).
- `SecretMapper::findByEncryptionSuiteId($suiteId)` — every secret sealed under a suite's key (owner's own plus received shared copies) = the exact blast radius.
- `SuiteCompromiseListener` — walks the **new** suite's secrets on `SuiteMigrationCompletedEvent` and notifies; keyed on migration, absent for revoke.
- `EmergencyEnvelopeInvalidationService::countUsableForGrantorSuite($grantorSuiteId)` (lib/Service/EmergencyEnvelopeInvalidationService.php:118).

`revoked_reason` is an existing free-form `STRING(255)` column, read only by GDPR export and `jsonSerialize`, consumed by no behavioural code.

## Goals / Non-Goals

**Goals:**
- One administrator endpoint that revokes any suite by id (user- or application-owned), guarded by admin + sudo, with a required free-form reason.
- An explicit, transient compromise decision (`markCompromised`) that, when set, drives the existing flag/rotation/notification cascade over the revoked suite's blast radius — no new persistence.
- Emergency access cleared unconditionally, with the destroyed-usable count surfaced (audit + warning), never gating the revocation.
- No schema change, no migration, no `<version>` bump.

**Non-Goals:**
- Changing the owner path's behaviour — `revoke()` calls `revokeSuite()` without `markCompromised`, so it stays behaviourally identical (`revokeSuite()` gains an optional param defaulting off, and the new listener no-ops when the flag is false).
- Persisting the compromise decision as a suite column, or deriving it from the reason text.
- Reproducing the owner path's `acceptEmergencyLoss` gate — administrator revocation is authoritative and is frequently *itself* the offboarding/compromise response.
- Re-onboarding logic — a user left with no active suite re-onboards through the existing onboarding flow.
- **Temporary / vacation suspension** — a reversible "lock the owner out but keep emergency break-glass working during their absence" mode is explicitly deferred to a future change. It is not a flag on force-revoke: `fetchEnvelope()` does not gate on grantor suite status, but the emergency-envelope clear is a separate listener on `EncryptionSuiteRevokedEvent` and the grantee's read of the grantor's secret ciphertext throws `SuiteBlockedException` on a `revoked`/`compromised` suite (`SecretService`), so a usable suspension needs a new owner-locked-but-break-glass-permitted suite state and read-path gating — genuinely its own mechanism. Omitting it does not weaken security: all three cases this change serves (forgotten-password, de-authorisation, compromise) are permanent revocations for which clearing emergency access is correct.

## Decisions

### D1: New `forceRevoke()` controller method, admin + sudo guarded

Add `EncryptionSuiteController::forceRevoke(string $id, string $reason, bool $markCompromised = false)` with `#[AuthorizedAdminSetting(AdminSettings::class)]` (mirroring the existing `reinstate()` at line 383) and `#[PasswordConfirmationRequired]`. The route `POST /api/v1/suites/{id}/force-revoke` is registered in `appinfo/routes.php` alongside the other `encryptionSuite#…` suite routes and before the SPA catch-all wildcard. The acting administrator is resolved via `OCP\IUserSession` and recorded as `revokedBy`. A missing/empty `reason` is rejected (`STATUS_BAD_REQUEST`). This is the app's first use of `PasswordConfirmationRequired`; the middleware enforces sudo before the controller body runs, so no in-body password handling is needed.

`forceRevoke()` deliberately does **not** call `validateOwnership()` — the whole point is cross-owner revocation, and the `AuthorizedAdminSetting` guard is the authorization (the existing `reinstate()` establishes this admin-only-by-guard pattern). This must be visible to the `no-admin-idor` gate as an admin-guarded method, not an unguarded `NoAdminRequired` one.

### D2: Compromise cascade as a revoke-event listener

`EncryptionSuiteRevokedEvent` gains a `compromised` flag, and `revokeSuite()` accepts a `bool $markCompromised = false` that it sets on the event it already dispatches. A new listener — `SuiteCompromiseOnRevokeListener`, a sibling to the existing `EncryptionSuiteRevokedListener` on the same event — reacts only when the flag is true: it walks `SecretMapper::findByEncryptionSuiteId($id)` (the exact blast radius), stamps `possibly_compromised_at` on each secret, raises `suite_compromise` flags via `RotationPolicyService::flagCompromisedSecrets` (idempotent), and notifies the affected owners with the `secret_compromised` subject — the same three primitives `SuiteCompromiseListener` uses, but driven by the revoke event instead of `SuiteMigrationCompletedEvent` (there is no migration here, and the scope is the revoked suite itself). This is idiomatic to the existing revoke-cascade listeners and keeps the compromise logic in its own separately-testable class, independent of the migration-complete tests (ADR-005 flags this need).

The owner path is behaviourally unchanged: `revoke()` calls `revokeSuite()` without `markCompromised` (default `false`), so the event's `compromised` flag is false and the new listener is a no-op — the owner path never triggers the cascade.

When `markCompromised === false`, the listener does nothing; the response carries a warning (surfaced in the UI) that the revoked user may still know these secrets and rotation may be warranted.

### D3: `reason` in the existing column; `markCompromised` never persisted

`reason` reuses `revoked_reason` — GDPR requires the specific "why" be recordable, and the free-form column already exists. `markCompromised` is a transient request parameter that drives the cascade branch and is written only to the audit metadata; it is never a suite column. This is the ADR-005 decision to reject a `revoked_type` enum column: the durable compromise evidence lives on the per-secret `possibly_compromised_at` flags the cascade raises, and the decision itself is in the audit trail. No new column, no migration.

### D4: Emergency access cleared unconditionally; count audited and warned, never gated

Revocation is authoritative, so emergency envelopes are cleared as part of the existing `EncryptionSuiteRevokedEvent` cascade — no `acceptEmergencyLoss` gate. Before (or as part of) the revoke the path reads `countUsableForGrantorSuite($id)` and threads that integer into the `SUITE_REVOKED` audit metadata as `emergencyContactsDestroyed`, and returns it so the administrator sees an informational warning. This matters most for the forgotten-password case, where emergency access may have been the user's genuine recovery route and revoking deletes it — but ADR-005 makes it a warning, not a block, because administrator revocation is frequently the offboarding/compromise response itself. Only the count crosses the wire; contact identities stay grantor-private.

### D5: Audit metadata widened to three keys

`AuditEventTypes` currently whitelists `SUITE_REVOKED => ['reason']` (lib/Event/Audit/AuditEventTypes.php:217). Widen it to `['reason', 'markCompromised', 'emergencyContactsDestroyed']` and have the revoke path emit all three. Because the owner `revoke()` path emits only `reason` today, the two extra keys are simply absent there (the whitelist permits, it does not require), so the owner path is unaffected.

### D6: New admin "Encryption suites" settings section

There is no admin suite-management UI today (the sibling `reinstate()` is API-only). This change adds a new section to the admin settings area (alongside `AdminApplicationsView` / `AdminAuditSection`, under `AdminRoot.vue`) where an administrator looks up a suite (by owner/id) and force-revokes it. The action presents a required reason field, a `markCompromised` toggle (default off), and — because the endpoint carries `PasswordConfirmationRequired` — the Nextcloud sudo (`OC.PasswordConfirmation` / password-confirmation) flow before the request is sent. On success the returned `emergencyContactsDestroyed` count is rendered as an informational warning, and when `markCompromised` was left off, the "user may still know these secrets" copy is shown. Built with `@conduction/nextcloud-vue` components and the NL Design System double-fallback CSS pattern, consistent with the rest of the settings surface. This section is also the home for `reinstate()`, wired in below.

The same surface also gains a **reinstate** action for revoked suites, wired to the existing admin-only `reinstate()` endpoint (`POST /api/v1/suites/{id}/reinstate`, EncryptionSuiteController:383) which has no frontend today. This is frontend-only — the endpoint and `reinstateSuite()` service are unchanged — and it carries no `PasswordConfirmationRequired`, so no sudo flow is needed; the `AuthorizedAdminSetting` guard is the authorization. Surfacing revoke and reinstate together keeps the admin suite lifecycle in one place; the action is shown only for suites in `revoked` status (mirroring `reinstateSuite()`'s own precondition).

## Risks / Trade-offs

- **`markCompromised` is not queryable off the suite table** — only via the audit trail or the flagged secrets. Accepted (ADR-005): no UI needs it, and the durable evidence lives on the secrets it flags.
- **The compromise cascade adds a revoke-path branch that must be tested for the revoke path specifically** — it cannot ride `SuiteCompromiseListener`'s migration-complete tests. D2 keeps it an explicit, separately-testable branch for exactly this reason.
- **Sudo mode is new to this app** and adds a client-side re-authentication step administrators must complete; D6 owns the confirmation flow.
- **Clearing emergency access on a forgotten-password revoke may destroy the user's genuine recovery route.** Mitigated by surfacing the destroyed-usable count as a warning before the administrator commits, and recording it in the audit trail — but not gated, per ADR-005.
- **`forceRevoke()` omits `validateOwnership()` by design.** This is correct (admin cross-owner action) but must be legible to reviewers and the `no-admin-idor` gate as guarded by `AuthorizedAdminSetting`, mirroring `reinstate()`.

## Migration Plan

No data migration and no `<version>` bump. `revoked_reason` is reused; `markCompromised` is transient. The `AuditEventTypes` whitelist widening is code-only and backward-compatible (extra keys are optional). Existing owner-path revocations are unchanged.

## Open Questions

- **Where the emergency-count read sits relative to the cascade delete.** `countUsableForGrantorSuite` MUST be read before the `EncryptionSuiteRevokedEvent` cascade clears the envelopes (the owner path reads it before `revokeSuite()` for the same reason); apply must order the read before the dispatch so the count is non-zero when contacts existed.
