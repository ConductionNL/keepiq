---
kind: code
depends_on: [rotation-expiry-policies]
---

# Proposal: Auditor-facing BIO2/NIS2 compliance reporting

## Why

Dutch public-sector buyers must **evidence** credential-hygiene conformity to auditors, and no self-hosted OSS vault ships the artifact they show. `docs/FEATURES.md:486` records that **BIO2 v1.3 (Jan 2026) explicitly names "een wachtwoordmanager aanbieden" as a measure** for Dutch government bodies, and `docs/FEATURES.md:487` records that **NIS2 / Cyberbeveiligingswet (in force ~2026-08-15 for ~8,000 organisations including all municipalities)** requires demonstrable cyber-hygiene and access-management measures (Art. 21(2)). The municipal-CISO stakeholder logged in the Spectr register lists "audit logs, BIO2 conformity" as a primary goal. Keeper is the only competitor row that lists "compliance reports" (`docs/FEATURES.md:41`) — and it is SaaS-only; no self-hosted category vault ships a BIO2/NIS2-framed report.

Doriath already holds every server-visible metadata source such a report aggregates, but nothing composes them into an evidence artifact — verified: no `openspec/changes/*` (active or archived) proposal covers compliance reporting, and no `Compliance` symbol exists in `lib/` or `src/`. The sources exist and are queryable today: adoption and secret counts (`lib/Db/SecretMapper.php:237` `countByOwner`, `lib/Db/EncryptionSuiteMapper.php:116` `findAllActive`), share hygiene (`lib/Db/ShareTargetMapper.php:92` `findByTargetUser`, `lib/Db/GroupShareMapper.php:75` `findBySecret`, `lib/Db/LinkShareMapper.php:112` `findByCreatedBy`), rotation posture from the sibling wave-1 change (`doriath_rotation_flags` / `doriath_expiry_policies` / `expires_at`, per `openspec/changes/rotation-expiry-policies/design.md:26`), audit-trail integrity (`lib/Service/AuditService.php:227` `adminQuery`, retention config `lib/Service/SettingsService.php:60`), and emergency-access coverage (`lib/Db/EmergencyContactMapper.php:79` `findByGrantor`). This change composes them into one org-level, timestamped, config-snapshotted evidence report.

**Zero-knowledge honesty boundary (must be stated in the report itself).** The server holds no health knowledge: password-health's "No Server-Side Health Knowledge" invariant (`openspec/specs/password-health/spec.md:111`) guarantees the server never stores strength scores, reuse data, or breach verdicts. The compliance report therefore MUST NOT include password strength or breach statistics — none are persisted. It MAY include only server-visible metadata: ciphertext-age counts (`key_updated_at`, labelled as ciphertext-age, not strength), `possibly_compromised_at` counts, and rotation/expiry posture. This boundary is a differentiator (honest, auditable) and MUST be printed on the report.

## What Changes

- Add an **org-level compliance report** for admins/CISOs aggregating ONLY server-visible metadata: adoption (users with vaults/suites), secrets-per-user counts, share hygiene (per-recipient share counts, group shares, outstanding link shares), rotation posture (expiry policies configured, overdue counts, open rotation flags — from `rotation-expiry-policies`), audit-trail integrity status (retention window, append-only confirmation, entry count, first-entry date), and emergency-access coverage (users with configured contacts).
- Persist each generated report as an **immutable evidence snapshot** (`doriath_compliance_reports`, own table per ADR-001) carrying a generation timestamp, the generating admin, an app-version stamp, and a **config snapshot** (audit retention, expiry-policy settings, breach-check gate state) so the evidence is reproducible.
- Add a nightly **metrics-refresh background job** (`TimedJob`, mirroring `lib/BackgroundJob/PurgeAuditLogJob.php:44`) that pre-computes the org aggregate into an `IAppConfig` cache so on-demand generation and the admin dashboard do not full-scan on every request.
- **Export** the persisted snapshot as **CSV and PDF**, rendered client-side from the stored aggregate (mirroring the audit view's client-side CSV per `openspec/specs/secret-audit-trail/spec.md:121`), each carrying the generation timestamp and config snapshot.
- **Explicit zero-knowledge honesty**: the report includes no per-secret verdicts and no strength/breach statistics (none are persisted); it prints its own metadata-only boundary statement.
- Add **audit events** for report generation/export using the existing string-typed whitelist (`lib/Event/Audit/AuditEventTypes.php`).

## Capabilities

### New Capabilities
- `compliance-reporting`: an admin-only, org-level compliance evidence report over server-visible metadata (adoption, secret counts, share hygiene, rotation posture, audit integrity, emergency-access coverage), persisted as an immutable timestamped snapshot with a config snapshot and exportable as CSV/PDF, framed for BIO2/NIS2 auditors and honest about the zero-knowledge metadata-only boundary.

### Modified Capabilities
- _(none — this change reads existing capabilities (`rotation-expiry-policies`, `secret-audit-trail`, `emergency-access`, `password-health`, sharing) by reference and adds no MODIFIED requirement to their scenarios.)_

## Impact

- **New table** (own DB per ADR-001): `doriath_compliance_reports`. **No** OpenRegister. **New `IAppConfig` cache keys**: `compliance_metrics_cache` (JSON), `compliance_metrics_computed_at`.
- **Services**: new `ComplianceReportService` (aggregate + persist snapshot); reads `SecretMapper`, `EncryptionSuiteMapper`, `ShareTargetMapper`, `GroupShareMapper`, `LinkShareMapper`, `EmergencyContactMapper`, `AuditService`, and the `rotation-expiry-policies` `RotationPolicyService`. Extends `SettingsService` read-only for the config snapshot.
- **Background job**: new `RefreshComplianceMetricsJob` registered in `appinfo/info.xml` `<background-jobs>` alongside the CA/audit/emergency jobs.
- **Routes/controllers**: new `ComplianceReportController`, all `#[AuthorizedAdminSetting(AdminSettings::class)]` (admin-only, mirroring `AuditController::index`).
- **Frontend**: an admin-settings compliance panel (generate/list/view snapshots, CSV/PDF export), reusing `CnSettingsSection`.
- **Audit**: new event types added to `AuditEventTypes` (no DB migration — string types).
- **OpenConnector**: none — the machine API is untouched.
