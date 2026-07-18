---
kind: code
---

# Proposal: Credential rotation policies and expiry reminders

## Why

`docs/FEATURES.md:108` lists "Password expiry reminders for secrets" (rationale column: "Credential rotation prompts") as an **Enterprise**-tier feature with no spec and no implementation — verified: no `openspec/changes/*` (active or archived) proposal covers expiry policies or rotation reminders, and no `expires_at`/expiry-policy column exists on `doriath_secrets` (the migration `lib/Migration/Version000016Date20260614000001.php:82` added only `key_updated_at`, not an expiry field). Doriath already has the two server-side primitives a rotation feature needs but never wires them into a reminder: `key_updated_at` (ciphertext-age, set only when the `key` blob changes — `lib/Service/SecretService.php:411`; the password-health spec's "Password Age Tracking" requirement, `openspec/specs/password-health/spec.md:58`, already flags secrets whose key is older than a per-user staleness threshold of 90/180/365/never days, `lib/Service/SettingsService.php:80`) and `possibly_compromised_at` (`lib/Db/Secret.php:153`, set by `lib/Listener/SuiteCompromiseListener.php:83` during suite-compromise recovery). Nothing turns "this secret is stale/compromised" into a **reminder** or tracks whether the user actually rotated it.

The regulatory pull is concrete and near. NIS2 Article 21(2)(j) (transposed into the Dutch **Cyberbeveiligingswet**, expected in force ~August 2026 for roughly 8,000 organisations including municipalities) requires cyber-hygiene and access-management measures; the Dutch baseline **BIO2** (v1.3, January 2026) literally names "provide a password manager to employees" as a control measure. Automated/prompted credential rotation is a compliance-checkbox item for exactly Doriath's Dutch public-sector target. Best-in-class secret managers make it table stakes: Keeper Secrets Manager ships scheduled automated rotation, and HashiCorp Vault ships leased/dynamic secrets. Doriath cannot mint dynamic credentials (it stores static, zero-knowledge secrets — ADR-003), but it *can* own the policy, the reminder, and the rotation-tracking loop entirely on server-visible metadata (`key_updated_at`, `expires_at`, `possibly_compromised_at`) without ever decrypting a value.

## What Changes

- Add a **per-secret expiry**: an owner MAY set `expires_at` on a secret (and a "rotate every N days" max-age intent). Setting/clearing expiry never touches ciphertext, so it never resets `key_updated_at`.
- Add **expiry policies** scoped to a secret type or a folder subtree, plus an **admin instance default** max-age and reminder cadence (new `SettingsService` admin keys) and a **per-user override**. Effective expiry for a secret = the most specific of {per-secret `expires_at`, folder policy, type policy, user override, admin default}, computed from server-visible fields only.
- Add a **reminder background job** (`TimedJob`, mirroring `lib/BackgroundJob/CheckRootCertificateExpiry.php`'s 90/30/7-day threshold sweep) that finds secrets crossing an approaching-expiry threshold or already overdue and dispatches Nextcloud notifications via the existing `DoriathNotifier`/`NotificationService`, gated by the existing `notify_security` user preference (`lib/Service/SettingsService.php:74`).
- Add a **rotation-tracking model** (`doriath_rotation_flags`): a secret can be flagged rotation-needed with a neutral reason (`policy_expiry` | `suite_compromise` | `user_flagged`); the flag resolves to `rotated` when the secret's `key` ciphertext next changes (advancing `key_updated_at`), or `dismissed` by the owner.
- Add a **"mark rotated" flow** that records completion and, for write-without-read secrets, offers a one-click **re-request** using the existing secret-requests rotation mechanism (`openspec/specs/secret-requests/spec.md:113` "Re-request (Update in Place)") rather than reinventing rotation.
- Add a **rotate-after-breach / rotate-after-compromise flow**: the client (which alone holds HIBP verdicts per password-health's "No Server-Side Health Knowledge" invariant) can batch-flag a set of secret IDs for rotation, and the suite-compromise path auto-flags every `possibly_compromised_at` secret; a dashboard surface tracks completion.
- Add **audit events** for expiry/rotation actions using the existing string-typed, migration-free audit whitelist (`lib/Event/Audit/AuditEventTypes.php`).
- Surface **rotation-due / overdue** counts on the Doriath dashboard and inside the existing password-health report categories.

## Capabilities

### New Capabilities
- `rotation-expiry-policies`: per-secret and per-folder/type credential expiry, admin-default and user-override max-age policy, approaching/overdue expiry reminders (dashboard + Nextcloud notifications), a mark-rotated flow, and a rotate-after-breach/compromise flagging-and-completion loop — all computed on server-visible metadata with no decryption.

### Modified Capabilities
- _(none — the feature composes with `password-health` and `secret-requests` by reference; it adds no MODIFIED requirement to their existing scenarios.)_

## Impact

- **New tables** (own DB per ADR-001): `doriath_expiry_policies`, `doriath_rotation_flags`. **New column**: `expires_at` on `doriath_secrets` (nullable). No OpenRegister.
- **Services**: new `RotationPolicyService`; extends `SettingsService` (admin keys `expiry_default_max_age_days`, `expiry_reminder_days`, `expiry_policy_enforced`; user override `expiry_max_age_days`), `SecretService` (resolve effective expiry; resolve rotation flag on key change), `NotificationService`/`DoriathNotifier` (new reminder subject).
- **Background jobs**: new `ScanExpiringSecretsJob` registered in `appinfo/info.xml` `<background-jobs>` alongside the CA/audit/emergency jobs.
- **Routes/controllers**: new `ExpiryPolicyController` and `RotationFlagController`; new per-secret expiry + mark-rotated endpoints; all `#[NoAdminRequired]`, owner/admin-scoped per method.
- **Frontend**: expiry field on the secret detail; policy admin panel; dashboard rotation-due card; health-report rotation category; per-secret "mark rotated"/"re-request rotation" actions.
- **Audit**: new event types added to `AuditEventTypes` (no DB migration — string types).
- **OpenConnector**: none directly; the machine API is untouched by this change (leasing is the sibling `machine-secret-leases` change).
