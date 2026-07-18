# Design: Credential rotation policies and expiry reminders

## Context

Doriath is zero-knowledge (ADR-003): the server holds ciphertext only and never decrypts a secret value. Any rotation/expiry feature therefore has to work exclusively on **server-visible metadata**. Three such fields already exist: `key_updated_at` (ciphertext-age, advanced only when the `key` blob changes — `lib/Service/SecretService.php:411`), `possibly_compromised_at` (set during suite-compromise recovery — `lib/Listener/SuiteCompromiseListener.php:83`), and the secrets' `updated_at`. Password-health already flags "stale" secrets client-side against a per-user threshold (`openspec/specs/password-health/spec.md:58`, default 365 days, `lib/Service/SettingsService.php:80`) but produces no reminder and tracks no rotation completion. This change adds the policy, the reminder, and the completion loop on top of those existing fields — it introduces no new decryption path and reads no plaintext.

## Goals / Non-Goals

**Goals:**
- Per-secret `expires_at` and per-folder/type max-age policy, with an admin instance default and a per-user override, resolved to one effective expiry per secret from server-visible fields only.
- Approaching-expiry and overdue reminders via Nextcloud notifications + a dashboard surface, on a `TimedJob` cadence.
- A "mark rotated" flow that leverages the existing key-change → `key_updated_at` advance and the existing secret-requests re-request mechanism.
- A rotate-after-breach/compromise loop that flags secrets and tracks completion without recording any breach verdict server-side.

**Non-Goals:**
- Automated rotation / dynamic credential minting (Doriath stores static secrets — impossible under ADR-003; this is the honest boundary vs. Vault/Keeper).
- Server-side password-strength, reuse, or breach knowledge (owned by password-health; its "No Server-Side Health Knowledge" invariant is preserved).
- Machine-lease TTL semantics (sibling `machine-secret-leases` change).

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns all its tables and does not use OpenRegister. Expiry policies and rotation flags are new **own Doctrine entities** with `ISchemaWrapper` migrations; there is no register/schema seed-data step and no declarative object model.

## Data model (own tables per ADR-001)

**New column on `doriath_secrets`:**

| Column | Type | Notes |
|--------|------|-------|
| `expires_at` | datetime, nullable | Per-secret hard expiry. Set/cleared without touching ciphertext, so `key_updated_at` is unaffected. |

**`doriath_expiry_policies`** (unique index on `(owner_id, scope, scope_id)`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `owner_id` | string, nullable | Nextcloud user id for a user-scoped policy; null = admin instance default row |
| `scope` | enum `type` \| `folder` | What the policy attaches to |
| `scope_id` | string | `type_id` (SecretType) or `folder_id` (Folder) |
| `max_age_days` | int, nullable | Rotate-every-N-days intent; null = no max-age, expiry only via `expires_at` |
| `reminder_days` | JSON | Approaching-expiry thresholds, e.g. `[30, 7, 1]`; null = inherit admin default |
| `created_by` | string | Audit |
| `created_at` / `updated_at` | datetime | — |

**`doriath_rotation_flags`** (unique index on `(secret_id)` — one open flag per secret; index on `status`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | Flagged secret |
| `reason` | enum `policy_expiry` \| `suite_compromise` \| `user_flagged` | Neutral origin — never a breach verdict (see decision below) |
| `status` | enum `pending` \| `rotated` \| `dismissed` | Lifecycle |
| `flagged_at` | datetime | — |
| `flagged_by` | string, nullable | uid; null when raised by the compromise listener/job |
| `resolved_at` | datetime, nullable | Set on rotate/dismiss |
| `key_updated_at_at_flag` | datetime, nullable | Snapshot of the secret's `key_updated_at` when flagged — a later advance proves rotation happened |

**Admin/user settings** (extend `SettingsService`, `IAppConfig`/`IConfig` — no table): admin `expiry_default_max_age_days` (int, default 0 = off), `expiry_reminder_days` (JSON, default `[30,7,1]`), `expiry_policy_enforced` (bool, default false); user override `expiry_max_age_days` (string, default `''` = inherit).

## Effective-expiry resolution

For a secret, the effective "rotate-by" instant is the earliest of:
1. per-secret `expires_at` (if set);
2. `key_updated_at + folder-policy.max_age_days` (nearest ancestor folder with a policy);
3. `key_updated_at + type-policy.max_age_days`;
4. `key_updated_at + user-override.max_age_days`;
5. `key_updated_at + admin-default.max_age_days` (when `> 0`).

Resolution uses `key_updated_at` (falling back to `created_at` when null, per the migration backfill at `lib/Migration/Version000016Date20260614000001.php:102`) and never decrypts. A secret with no applicable policy and no `expires_at` is never flagged.

## Endpoints (`appinfo/routes.php`, all owner/admin-scoped)

- `PUT /api/v1/secrets/{id}/expiry` — set/clear per-secret `expires_at` (owner). `#[NoAdminRequired]`, per-object owner guard (keeps `hydra-gate-no-admin-idor` intact).
- `GET /api/v1/expiry-policies` — list effective policies for the caller; `POST` / `PUT` / `DELETE /api/v1/expiry-policies/{id}` — manage type/folder policies (owner for own vault; admin for the instance-default row via the settings surface).
- `GET /api/v1/rotation-flags` — list the caller's pending rotation flags (dashboard/health feed).
- `POST /api/v1/rotation-flags/batch` — client batch-flags a set of secret IDs (`reason: user_flagged`) for the rotate-after-breach loop; body carries **only** secret IDs, never a verdict.
- `POST /api/v1/secrets/{id}/mark-rotated` — resolve the open flag: if the secret's `key_updated_at` advanced since `key_updated_at_at_flag`, transition to `rotated`; else offer the re-request path. `POST /api/v1/rotation-flags/{id}/dismiss` — owner dismiss.

## Background job (mirrors existing `TimedJob` patterns)

`ScanExpiringSecretsJob extends TimedJob`, registered in `appinfo/info.xml` `<background-jobs>` next to `CheckRootCertificateExpiry`/`ApproveElapsedEmergencyRequests` (`appinfo/info.xml:69`). Daily interval (`setInterval(86400)`), matching `CheckRootCertificateExpiry`. Each run:
1. iterates secrets with an effective expiry (per-secret `expires_at` or a matching policy) — server-visible fields only, no decryption;
2. for each secret crossing an approaching-expiry threshold (from `expiry_reminder_days`, same 90/30/7-style banding as `CheckRootCertificateExpiry.php:37`) or already overdue, dispatches one notification per threshold via `NotificationService`/`DoriathNotifier`, gated by the owner's `notify_security` preference;
3. auto-raises a `policy_expiry` rotation flag for overdue secrets (idempotent — one open flag per secret).

A second listener (not a job) on suite-compromise recovery raises `suite_compromise` flags for every `possibly_compromised_at` secret, reusing `SuiteCompromiseListener`'s existing sweep.

## Audit events

Add to `lib/Event/Audit/AuditEventTypes.php` (string types, migration-free per its design note): `secret.expiry_set`, `secret.rotation_flagged`, `secret.rotated`, `secret.rotation_dismissed`, `policy.expiry_changed`. Each whitelists only non-sensitive keys (e.g. `expiresAt`, `reason`, `scope`) and inherits the `FORBIDDEN_KEYS` guard so no ciphertext/value can leak.

## Decisions made under uncertainty

- **The rotation flag records a neutral reason, never a breach verdict.** Password-health guarantees "No Server-Side Health Knowledge" (`openspec/specs/password-health/spec.md:111`). A client that finds an HIBP hit batch-flags the affected secret IDs with `reason: user_flagged` — the server learns only "the user asked to rotate these", never "these are breached". This keeps the invariant intact at the cost of the audit trail not distinguishing breach-driven from routine user flags. Alternative (store `reason: breach`) rejected as an invariant violation.
- **"Mark rotated" is proven, not asserted.** Because the server can't read values, we don't trust a bare "I rotated it" click; a flag only transitions to `rotated` when `key_updated_at` actually advanced past the snapshot taken at flag time. A click without a real key change routes the user to the re-request/edit flow instead of silently closing the flag.
- **Reminders are best-effort, computed on ciphertext age.** `key_updated_at` reflects when the ciphertext last changed, not when the underlying credential was truly rotated upstream (a connector could rotate a downstream token without re-writing Doriath). We treat ciphertext-age as the rotation proxy and document it as such, consistent with password-health's identical use of the same field.
- **Admin default max-age ships off (`0`).** Enforcing a global rotation cadence out of the box would spray reminders across every existing vault on first cron run; admins opt in. `expiry_policy_enforced` is likewise default-false so a policy is advisory (reminder only) unless an admin turns on enforcement.
- **Per-secret `expires_at` beats every policy.** An explicit owner-set expiry is the most specific signal and always wins the resolution, so a policy loosening can never silently extend a deliberately short-lived secret.
