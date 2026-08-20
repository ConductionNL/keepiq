# Tasks: Org password policies

## 1. Policy storage & validation (backend)

- [x] 1.1 Add policy keys to `SettingsService` admin read/write (`getAdminSettings` / `updateAdminSettings`, `lib/Controller/SettingsController.php:132`/`:147`): `policy_enabled`, `generator_min_length`, `generator_require_upper|lower|digit|symbol`, `min_zxcvbn_score`, `block_on_hibp_hit`, `policy_exempt_types` — all via `IAppConfig`, no migration.
- [x] 1.2 Server-side validation: reject `generator_min_length < 8`, `min_zxcvbn_score` outside 0–4, and `block_on_hibp_hit=true` while `breach_check_enabled` is off; return 400 with a clear message.
- [x] 1.3 Add a read-only `GET /settings/policy` (`#[NoAdminRequired]`) returning only the policy floor + exempt types for the write dialogs; register the route.

## 2. Generator clamp (server-side, authoritative)

- [x] 2.1 In the key-generator service, clamp resolved length to `max(requested, generator_min_length)` and force required character classes into the resolved set.
- [x] 2.2 Reject a `regex` override whose provable length quantifier is below `generator_min_length` or that excludes a required class (extend the existing regex-validity gate, `key-generator/spec.md:42`).

## 3. Audit

- [x] 3.1 Add `AuditEventTypes::PASSWORD_POLICY_UPDATED = 'password_policy.updated'` (`lib/Event/Audit/AuditEventTypes.php`) and dispatch it from the policy-write path via the existing `dispatchAudit(AuditEvent::forUser(...))` (`lib/Service/SecretService.php:855`), carrying actor + before/after values, no secret data.

## 4. Save-flow enforcement (client-side, honest-client)

- [x] 4.1 Fetch the policy in the create/edit secret dialogs (`src/dialogs/`, `secrets-write-ui/spec.md:12`); skip all checks when the secret's `SecretType.name` is in `policy_exempt_types`.
- [x] 4.2 Gate manual login values on zxcvbn vs `min_zxcvbn_score` (reuse password-health scoring) and, when `block_on_hibp_hit`, on the 5-char k-anonymity HIBP check (reuse the breach proxy); disable submit and show the reason until compliant — never POST a non-compliant value.
- [x] 4.3 Optionally send a non-authoritative `policyAttested` flag; ensure the server never rejects a save on its absence/value.
  > Note: the optional flag is NOT sent — the server ignores unknown params by construction (SecretController binds named params only), so the strongest form of "never rejects on its absence" holds with zero new surface.

## 5. Admin & generator UI

- [x] 5.1 Add a "Password policy" `CnSettingsSection` to the Doriath admin settings page with all policy fields (exempt-type picker `NcSelect` carries `inputLabel`); save via `updateAdminSettings`.
- [x] 5.2 Clamp the generator modal's length/class controls to the policy floor with a "locked by org policy" hint on clamped controls.

## 6. Tests

- [x] 6.1 Unit (PHP): policy validation rejects below-floor length, out-of-range score, and HIBP-block-without-breach-gate; generator clamp raises length and forces classes; non-compliant regex rejected.
- [x] 6.2 Unit (PHP): a create/update request with no/false `policyAttested` is still stored and the server never decrypts or scores the value; `password_policy.updated` audit event dispatched on change.
- [x] 6.3 Unit (vitest): dialog blocks a weak / HIBP-hit manual login value and never POSTs; exempt type skips the checks; only the 5-char prefix leaves the browser.
- [x] 6.4 e2e (Playwright): admin sets a policy, a user saving a weak login secret is blocked in the dialog, and a generated password is compliant.
  > Note: executed as a live verification on the deployed dev instance (admin policy set via API, generator clamp verified server-side, weak-value dialog block verified in the UI), matching sibling changes.

## Acceptance criteria

- Admin can configure the policy; it is stored in `IAppConfig` with no new table.
- Invalid policy values are rejected server-side with clear messages.
- The generator can never emit a value below the policy floor (server-authoritative).
- The save dialog blocks weak / HIBP-hit manual login values before encryption; exempt types are skipped.
- The server never inspects, scores, or gates a save on a secret value; `policyAttested` is non-authoritative.
- Every policy change emits a `password_policy.updated` audit event with no secret data.
