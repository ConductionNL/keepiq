# Tasks: Compliance Reporting

## 1. Data layer

- [ ] 1.1 Migration: `doriath_compliance_reports` table (`id`, `generated_by`, `generated_at`, `app_version`, `config_snapshot` text/JSON, `aggregate` text/JSON), index on `generated_at`
- [ ] 1.2 `ComplianceReport` entity + `ComplianceReportMapper` (standard `QBMapper` pattern matching `AuditEntryMapper`; expose a decoded `getAggregateArray()`/`getConfigSnapshotArray()` like `AuditEntry::getMetadataArray`)

## 2. Aggregation service

- [ ] 2.1 `ComplianceReportService::aggregate()` — compose the six sections from `SecretMapper::countByOwner`, `EncryptionSuiteMapper::findAllActive`, `ShareTargetMapper`, `GroupShareMapper`, `LinkShareMapper`, `EmergencyContactMapper`, `AuditService::adminQuery`; counts/aggregates only, no row hydration of values
- [ ] 2.2 Rotation-posture section reads `rotation-expiry-policies` (`RotationPolicyService` / `doriath_rotation_flags` / `expires_at`), class-existence-guarded so the report degrades to "rotation posture unavailable" if that capability is absent
- [ ] 2.3 Ciphertext-age bands from `key_updated_at` + `possibly_compromised_at` counts, labelled ciphertext-age (never strength); NO strength/reuse/breach figures included
- [ ] 2.4 Aggregate key allowlist validation before persistence — reject any non-metadata key so no secret name/value/ciphertext can enter a snapshot
- [ ] 2.5 `ComplianceReportService::generate(adminUid)` — build the config snapshot (audit retention, expiry-policy settings, breach-check gate, app version) and persist an immutable `ComplianceReport` snapshot

## 3. Background job

- [ ] 3.1 `RefreshComplianceMetricsJob extends TimedJob` (daily `setInterval(86400)`, mirroring `PurgeAuditLogJob`) — recompute the aggregate into `IAppConfig` `compliance_metrics_cache` + `compliance_metrics_computed_at`
- [ ] 3.2 Register the job in `appinfo/info.xml` `<background-jobs>`

## 4. Controllers + routes

- [ ] 4.1 `ComplianceReportController` — `generate` (POST), `index` (GET list), `show` (GET one), `metrics` (GET warm cache); all `#[AuthorizedAdminSetting(AdminSettings::class)]`
- [ ] 4.2 Register routes in `appinfo/routes.php` under a commented "Compliance reporting" section
- [ ] 4.3 Client-side export beacon endpoint for `compliance.report_exported` (admin-only POST)

## 5. Audit events

- [ ] 5.1 Add `compliance.report_generated` (whitelist `reportId`) and `compliance.report_exported` (whitelist `reportId`, `format`) to `AuditEventTypes`; dispatch on generate/export

## 6. Frontend

- [ ] 6.1 Admin-settings compliance panel (`CnSettingsSection`): live posture card from the warm metrics cache, generate button, snapshot list
- [ ] 6.2 Snapshot detail view rendering all six sections with the printed zero-knowledge metadata-only boundary statement
- [ ] 6.3 Client-side CSV + PDF export from the stored snapshot, stamping generation timestamp + config snapshot; fire the export beacon

## 7. Tests

- [ ] 7.1 Unit: aggregate contains only whitelisted keys; no strength/reuse/breach key can appear; ciphertext-age labelling present
- [ ] 7.2 Unit: snapshot is append-only (no edit surface); config snapshot recorded; retention purge reuses the audit window; rotation-posture section degrades gracefully when `rotation-expiry-policies` is absent
- [ ] 7.3 Unit: every endpoint rejects a non-admin caller before report logic runs
- [ ] 7.4 e2e (Playwright): admin generates a report, views the six sections + boundary statement, exports CSV and PDF carrying the timestamp

## Acceptance Criteria

- Report aggregates the six sections from server-visible metadata only, with no secret value/name/login/ciphertext present
- No password strength, reuse, or breach statistic appears anywhere in the report; the metadata-only boundary statement is printed
- Ciphertext-age figures are labelled as ciphertext-age, not strength
- Each generated report is persisted as an immutable snapshot with generation timestamp, generating admin, app version, and config snapshot
- CSV and PDF exports carry the timestamp + config snapshot and report identical figures
- All compliance endpoints reject non-admins before report logic runs
- `compliance.report_generated` / `compliance.report_exported` audit events carry only identifiers/format, no aggregate body
