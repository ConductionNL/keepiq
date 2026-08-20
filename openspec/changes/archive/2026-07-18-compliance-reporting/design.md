# Design: Auditor-facing BIO2/NIS2 compliance reporting

## Context

Doriath is zero-knowledge (ADR-003): the server holds ciphertext only and never decrypts a secret value, so any reporting works exclusively on **server-visible metadata and aggregates**. All the sources a BIO2/NIS2 compliance report needs already exist as own-table mappers and services (adoption/secret counts, share records, link shares, emergency contacts, audit entries) plus the sibling wave-1 `rotation-expiry-policies` tables (`doriath_rotation_flags`, `doriath_expiry_policies`, `expires_at`). What is missing is the composition layer: a service that aggregates these into one org-level, timestamped, config-snapshotted evidence artifact an auditor can be shown, and the persistence that makes that evidence immutable and reproducible.

The hard constraint is the honesty boundary. Password-health guarantees "No Server-Side Health Knowledge" (`openspec/specs/password-health/spec.md:111`) — the server stores no strength score, reuse data, or breach verdict. The report therefore cannot include those, and must say so on its face. What it *can* include is genuinely server-visible: ciphertext-age counts derived from `key_updated_at`, `possibly_compromised_at` counts, and the rotation/expiry posture the wave-1 change persists.

## Goals / Non-Goals

**Goals:**
- One admin-only, org-level compliance report aggregating adoption, secrets-per-user counts, share hygiene, rotation posture, audit-trail integrity status, and emergency-access coverage — from server-visible metadata only.
- Each generated report persisted as an immutable snapshot carrying a generation timestamp, generating admin, app version, and a config snapshot, so the evidence is reproducible and defensible to an auditor.
- CSV + PDF export from the stored snapshot, each carrying the timestamp and config snapshot.
- BIO2/NIS2 framing with an explicit, printed zero-knowledge honesty statement.

**Non-Goals:**
- Any per-secret verdict, password strength statistic, reuse statistic, or breach statistic — none are persisted server-side and the report MUST NOT invent them (preserves password-health's invariant).
- Per-user or per-tenant sub-reports, scheduled email delivery, or external attestation signing (future).
- Re-computing rotation/expiry logic — that is owned by `rotation-expiry-policies`; this change reads its outputs.

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns all its tables and does not use OpenRegister. The report snapshot is a new own Doctrine entity with an `ISchemaWrapper` migration; there is no register/schema seed-data step and no declarative object model.

## Data model (own tables per ADR-001)

**`doriath_compliance_reports`** (index on `generated_at`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID | Primary key |
| `generated_by` | string | Admin uid that generated the report |
| `generated_at` | datetime | Generation timestamp (printed on every export) |
| `app_version` | string | Doriath version stamp at generation time |
| `config_snapshot` | text (JSON) | `audit_retention_days`, expiry-policy settings (`expiry_default_max_age_days`, `expiry_reminder_days`, `expiry_policy_enforced`), `breach_check_enabled` — the settings the aggregate was computed under |
| `aggregate` | text (JSON) | The full aggregate (sections below); metadata/counts only, never a secret value, name, or ciphertext |

Rows are **append-only evidence**: no endpoint or service method edits an existing snapshot; the only permitted mutation is a retention purge (reusing the audit retention window, so evidence and audit age out together).

**Metrics cache (no table — `IAppConfig`):** `compliance_metrics_cache` (JSON aggregate) and `compliance_metrics_computed_at` (datetime), refreshed nightly by the background job so the admin panel and on-demand generation read a warm value instead of full-scanning.

## Aggregate sections (server-visible metadata only)

1. **Adoption** — count of users with an active EncryptionSuite (`EncryptionSuiteMapper::findAllActive`), count with at least one secret, count with a configured emergency contact.
2. **Secrets per user** — per-owner secret counts (`SecretMapper::countByOwner`), plus min/median/max and total; no names, no values.
3. **Share hygiene** — outstanding user shares per recipient (`ShareTargetMapper::findByTargetUser`), group-share count (`GroupShareMapper`), outstanding link shares and how many are password-protected / expiring (`LinkShareMapper::findByCreatedBy`).
4. **Rotation posture** — expiry policies configured, secrets with an `expires_at`, overdue count, and open rotation-flag counts by reason — read from `rotation-expiry-policies` (`RotationPolicyService` / `doriath_rotation_flags`). Includes ciphertext-age bands from `key_updated_at` and `possibly_compromised_at` counts, **labelled as ciphertext-age, not password strength**.
5. **Audit-trail integrity** — retention window (`audit_retention_days`), total entry count and first-entry date (`AuditService::adminQuery`), and confirmation the log is append-only (per `secret-audit-trail`'s "Append-Only Log" invariant). No entry bodies.
6. **Emergency-access coverage** — count of grantors with ≥1 active contact and count of pending break-glass requests (`EmergencyContactMapper`).

Every section carries counts only. The `aggregate` JSON is validated against a key allowlist before persistence so no secret name/value/ciphertext can enter a snapshot.

## Endpoints (`appinfo/routes.php`, all admin-only)

All methods carry `#[AuthorizedAdminSetting(AdminSettings::class)]`, mirroring `AuditController::index` (`lib/Controller/AuditController.php:183`); NC middleware rejects non-admins before the controller runs.

- `POST /api/v1/compliance/reports` — generate a report now: computes (or reads the warm cache), persists a snapshot, returns it. Dispatches `compliance.report_generated`.
- `GET /api/v1/compliance/reports` — list persisted snapshots (newest first, paginated).
- `GET /api/v1/compliance/reports/{id}` — fetch one snapshot (aggregate + config snapshot + timestamp).
- `GET /api/v1/compliance/metrics` — read the warm cache for the live admin-panel posture card.

Export is rendered **client-side** from the fetched snapshot (CSV and PDF), mirroring the audit view's client-side CSV; the client stamps the generation timestamp and config snapshot onto each export and dispatches `compliance.report_exported` via a lightweight POST beacon.

## Background job (mirrors existing `TimedJob` patterns)

`RefreshComplianceMetricsJob extends TimedJob`, registered in `appinfo/info.xml` `<background-jobs>` next to `PurgeAuditLogJob`/`ApproveElapsedEmergencyRequests`. Daily interval (`setInterval(86400)`), matching `PurgeAuditLogJob` (`lib/BackgroundJob/PurgeAuditLogJob.php:63`). Each run recomputes the org aggregate from the mappers/services and writes `compliance_metrics_cache` + `compliance_metrics_computed_at`. On-demand `POST /reports` may recompute live for a fresh evidence snapshot; the cache exists to keep the panel cheap on large instances.

## Audit events

Add to `lib/Event/Audit/AuditEventTypes.php` (string types, migration-free): `compliance.report_generated` (whitelist `reportId`), `compliance.report_exported` (whitelist `reportId`, `format`). Both inherit the `FORBIDDEN_KEYS` guard so no ciphertext/value can leak.

## Risks / Trade-offs

- **Full-scan cost on large instances** → the nightly `RefreshComplianceMetricsJob` warms an `IAppConfig` cache; the panel reads the cache, and only explicit evidence generation may recompute live. Counts use existing `count*` mappers (aggregate queries), never row hydration.
- **Snapshot drift vs. live state** → each snapshot stamps `generated_at` and a config snapshot; an auditor reads the report as "the posture at that instant under those settings", not a live dashboard. Stated on the export.
- **Honesty-boundary regression** → an aggregate key allowlist rejects any non-metadata key before persistence, and the report prints its metadata-only boundary; a reviewer/test asserts no strength/breach/reuse key can appear (mirrors the audit whitelist test discipline).
- **Dependency on an unbuilt sibling** → `rotation-expiry-policies` is a wave-1 change; the rotation-posture section is class-existence-guarded so the report degrades to "rotation posture unavailable" if that capability is absent, rather than failing generation.

## Migration Plan

1. Add `doriath_compliance_reports` via an `ISchemaWrapper` migration (nullable-safe, additive).
2. Ship the service, controller, routes, and background job; register the job in `info.xml`.
3. Register the two new audit event types.
4. No backfill — reports start empty; the first snapshot is generated on demand or after the first nightly job run.
5. Rollback: remove the routes/job registration; the additive table is inert and can be dropped in a follow-up migration. No existing data is mutated.

## Decisions made under uncertainty

- **The report includes ciphertext-age counts but no strength/breach statistics.** Password-health guarantees "No Server-Side Health Knowledge" (`openspec/specs/password-health/spec.md:111`); the server literally cannot report strength/reuse/breach because it stores none. Ciphertext age (`key_updated_at`) and `possibly_compromised_at` *are* server-visible, so they are included — but labelled explicitly as ciphertext-age, not password strength, so an auditor is not misled. Alternative (surface a client-computed strength summary) rejected: it would require the client to persist verdicts server-side, breaking the invariant.
- **Reports are persisted as immutable snapshots, not regenerated on demand only.** An auditor needs a defensible artifact fixed in time; a live dashboard is not evidence. The snapshot carries the config it was computed under, so "why did the numbers change" is answerable. Cost: storage growth, bounded by reusing the audit retention window to purge old snapshots.
- **Export renders client-side from the stored snapshot.** Mirrors the audit view's client-side CSV (`openspec/specs/secret-audit-trail/spec.md:121`) and keeps PDF layout out of PHP; the snapshot is the single source of truth, so CSV and PDF are guaranteed identical numbers. Cost: the export beacon (`compliance.report_exported`) is best-effort, since rendering is in the browser.
- **The admin panel reads a nightly-warmed cache; only explicit generation may recompute live.** Aggregating secrets-per-user across every user on each panel load would be a full scan on large instances. The cache is refreshed by a daily `TimedJob` mirroring `PurgeAuditLogJob`; freshness is stamped (`compliance_metrics_computed_at`) so the admin knows how stale the panel is.
- **Admin-only, instance-scoped, no per-user drill-down in v1.** BIO2/NIS2 evidence is org-level; per-user secret contents are never in scope (zero-knowledge). Scoping every endpoint with `#[AuthorizedAdminSetting]` (like `AuditController::index`) keeps the surface minimal and avoids any IDOR question.
