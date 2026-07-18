---
status: proposed
---

# Compliance Reporting

## Purpose

Give Doriath admins/CISOs a BIO2/NIS2-framed, auditor-facing compliance evidence report — an org-level aggregate over server-visible metadata (adoption, secret counts, share hygiene, rotation posture, audit-trail integrity, emergency-access coverage), persisted as an immutable timestamped snapshot with a config snapshot and exportable as CSV/PDF — while honestly stating the zero-knowledge boundary that no password strength or breach statistic exists server-side.

## ADDED Requirements

### Requirement: Org-level metadata-only compliance report

Doriath SHALL provide an administrator an org-level compliance report aggregating only server-visible metadata across these sections: adoption (users with an active suite, users with secrets, users with an emergency contact), secrets-per-user counts, share hygiene (per-recipient user shares, group shares, outstanding link shares), rotation posture (expiry policies configured, overdue count, open rotation-flag counts), audit-trail integrity status, and emergency-access coverage. The report MUST contain counts and aggregates only, and MUST NOT contain any secret value, secret name, login field, or ciphertext.

#### Scenario: Report aggregates the metadata sections

- **WHEN** an administrator generates a compliance report
- **THEN** the report MUST include the adoption, secrets-per-user, share-hygiene, rotation-posture, audit-integrity, and emergency-access-coverage sections
- **AND** every value MUST be a count or aggregate, with no secret value, secret name, login field, or ciphertext present

#### Scenario: Aggregation never decrypts

- **WHEN** the compliance aggregate is computed
- **THEN** the computation MUST use only server-visible metadata (counts, share records, policy rows, audit metadata, `key_updated_at`, `possibly_compromised_at`)
- **AND** it MUST NOT require or perform any decryption of a secret value

### Requirement: Zero-knowledge honesty boundary stated in the report

Because the server holds no password strength, reuse, or breach knowledge (password-health "No Server-Side Health Knowledge"), the report MUST NOT include any password strength statistic, reuse statistic, or breach statistic, and MUST NOT include any per-secret verdict. Any ciphertext-age figure derived from `key_updated_at` MUST be labelled as ciphertext-age and not as password strength. The report MUST print a statement of this metadata-only boundary.

#### Scenario: No strength or breach statistics appear

- **WHEN** a compliance report is generated on a vault whose passwords a client has locally scored and breach-checked
- **THEN** the report MUST NOT contain any strength score, reuse figure, or breach count
- **AND** it MUST print the metadata-only zero-knowledge boundary statement

#### Scenario: Ciphertext age is labelled honestly

- **WHEN** the rotation-posture section reports counts derived from `key_updated_at`
- **THEN** those counts MUST be labelled as ciphertext-age
- **AND** MUST NOT be presented as password strength

### Requirement: Immutable timestamped evidence snapshot with config snapshot

Doriath SHALL persist each generated report as an immutable snapshot carrying a generation timestamp, the generating administrator, an app-version stamp, and a config snapshot of the settings the aggregate was computed under (at minimum the audit retention window, the expiry-policy settings, and the breach-check gate state). No endpoint or service method MAY edit a persisted snapshot; the only permitted removal is the retention purge.

#### Scenario: Snapshot records timestamp and config

- **WHEN** an administrator generates a report
- **THEN** the persisted snapshot MUST record the generation timestamp, the generating admin, the app version, and the config snapshot
- **AND** the same values MUST appear on every export of that snapshot

#### Scenario: Snapshots are append-only

- **WHEN** the persisted compliance snapshots and the service API are enumerated
- **THEN** no operation MUST exist that edits an individual snapshot's aggregate or config snapshot

### Requirement: CSV and PDF export

Doriath SHALL let an administrator export a persisted snapshot as CSV and as PDF, each export carrying the snapshot's generation timestamp and config snapshot. Exports MUST be produced from the stored snapshot so CSV and PDF report identical figures.

#### Scenario: Export carries provenance

- **WHEN** an administrator exports a persisted snapshot as CSV or PDF
- **THEN** the export MUST carry the snapshot's generation timestamp and config snapshot
- **AND** the CSV and PDF MUST report identical figures because both derive from the same stored snapshot

### Requirement: Admin-only access

Doriath SHALL restrict all compliance-report endpoints to administrators; a non-administrator MUST NOT be able to generate, list, read, or export a compliance report.

#### Scenario: Non-admin rejected

- **WHEN** a regular user calls any compliance-report endpoint
- **THEN** the request MUST be rejected by the admin authorization check before the report logic runs

### Requirement: Report generation and export are audited

Doriath SHALL emit audit events for report generation and export using the existing string-typed audit whitelist, and these events MUST carry only a report identifier and export format — never any secret value, aggregate body, or ciphertext.

#### Scenario: Generation is audited without report contents

- **WHEN** an administrator generates a compliance report
- **THEN** a `compliance.report_generated` audit event MUST be recorded carrying only the report identifier
- **AND** it MUST NOT contain any aggregate body, secret value, or ciphertext
