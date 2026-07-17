# Tasks: Credential rotation policies and expiry reminders

## 1. Data layer

- [ ] 1.1 Migration: add nullable `expires_at` to `doriath_secrets`; new tables `doriath_expiry_policies` (`id`, `owner_id` nullable, `scope` enum `type|folder`, `scope_id`, `max_age_days` nullable, `reminder_days` JSON nullable, `created_by`, `created_at`, `updated_at`; unique `(owner_id, scope, scope_id)`) and `doriath_rotation_flags` (`id`, `secret_id`, `reason` enum, `status` enum, `flagged_at`, `flagged_by` nullable, `resolved_at` nullable, `key_updated_at_at_flag` nullable; unique `(secret_id)`)
- [ ] 1.2 `ExpiryPolicy` + `RotationFlag` entities and `ExpiryPolicyMapper` + `RotationFlagMapper` (standard `QBMapper` pattern matching `SecretMapper`); add `expiresAt` accessor to `Secret`

## 2. Policy + resolution service

- [ ] 2.1 `RotationPolicyService::resolveEffectiveExpiry(secret)` — earliest of per-secret `expires_at` and each applicable policy's `key_updated_at + max_age_days` (fallback `created_at` when `key_updated_at` null); server-visible fields only, no decryption
- [ ] 2.2 `RotationPolicyService` CRUD for type/folder policies; extend `SettingsService` with admin keys `expiry_default_max_age_days` (default 0), `expiry_reminder_days` (default `[30,7,1]`), `expiry_policy_enforced` (default false) and user override `expiry_max_age_days`
- [ ] 2.3 `SecretService`: set/clear per-secret `expires_at` without touching ciphertext or `key_updated_at`; owner-only guard

## 3. Rotation flags + mark-rotated

- [ ] 3.1 `RotationPolicyService::flagBatch(userId, secretIds)` (reason `user_flagged`, IDs only) and `flag(secretId, reason)` idempotent one-open-flag-per-secret
- [ ] 3.2 `markRotated(flagId, ownerId)` — transition to `rotated` only when `key_updated_at` advanced past `key_updated_at_at_flag`; else return the re-request path; `dismiss(flagId, ownerId)`
- [ ] 3.3 Suite-compromise handling auto-raises `suite_compromise` flags for every `possibly_compromised_at` secret (reuse `SuiteCompromiseListener` sweep)

## 4. Background job

- [ ] 4.1 `ScanExpiringSecretsJob` (`TimedJob`, daily `setInterval(86400)`, mirrors `CheckRootCertificateExpiry`): notify on approaching-threshold + overdue via `NotificationService`, one notification per secret+threshold, gated by `notify_security`; auto-raise `policy_expiry` flags for overdue; register the job in `appinfo/info.xml` `<background-jobs>` alongside the CA/audit/emergency jobs

## 5. Notifications + audit

- [ ] 5.1 `DoriathNotifier` case + `NotificationService` method for expiry reminders (deep-link to secret, approaching vs overdue subjects)
- [ ] 5.2 Add `secret.expiry_set`, `secret.rotation_flagged`, `secret.rotated`, `secret.rotation_dismissed`, `policy.expiry_changed` to `AuditEventTypes` with non-sensitive-only whitelists; dispatch from the service

## 6. Controllers + routes

- [ ] 6.1 `ExpiryPolicyController` (index/create/update/destroy) and per-secret `secret#setExpiry` (`PUT /api/v1/secrets/{id}/expiry`) — `#[NoAdminRequired]`, per-object owner guard
- [ ] 6.2 `RotationFlagController` (`index`, `batch`, `markRotated`, `dismiss`); admin instance-default policy managed via the settings surface; register all routes under a commented "Rotation & expiry" section in `appinfo/routes.php`

## 7. Frontend

- [ ] 7.1 Expiry field + status chip on the secret detail view; policy admin panel (type/folder rules, admin default) via `CnSettingsSection`
- [ ] 7.2 Dashboard rotation-due card + rotation category in the password-health report, each finding deep-linking to the secret
- [ ] 7.3 Per-secret "mark rotated" / "re-request rotation" actions and a client batch-flag action wired from the health breach findings (IDs only)

## 8. Tests

- [ ] 8.1 Unit: effective-expiry resolution precedence (per-secret > folder > type > user > admin); no decryption; no-policy = never expiring
- [ ] 8.2 Unit: mark-rotated closes only on a real `key_updated_at` advance; batch-flag persists IDs only with `user_flagged`; compromise auto-flag
- [ ] 8.3 Unit: `ScanExpiringSecretsJob` notifies only crossing/overdue secrets, respects `notify_security`, no duplicate per threshold, raises `policy_expiry` idempotently
- [ ] 8.4 e2e (Playwright): owner sets expiry, dashboard shows rotation-due, rewrites value, marks rotated → flag clears

## Acceptance criteria

- Per-secret `expires_at` is set/cleared without changing `key_updated_at`; setting expiry on another user's secret is rejected
- Effective expiry resolves to the earliest applicable instant from server-visible fields only; admin default ships off; no-policy secrets never expire
- Approaching-expiry and overdue reminders fire on the daily job, gated by `notify_security`, with no duplicate per secret+threshold
- Overdue secrets and `possibly_compromised_at` secrets raise idempotent rotation flags (`policy_expiry` / `suite_compromise`)
- Client batch-flag stores secret IDs only with reason `user_flagged`; no breach verdict, score, or digest is persisted server-side
- Mark-rotated closes a flag only on a proven `key_updated_at` advance; otherwise it offers the re-request path
- Rotation-due and overdue counts surface on the dashboard and in the password-health report with deep links
- Expiry/rotation/policy actions emit audit events carrying no key, login, value, or ciphertext
