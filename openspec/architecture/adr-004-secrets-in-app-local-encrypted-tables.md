# ADR-004: Doriath stores secrets in app-local encrypted tables, not OpenRegister

**Status**: accepted

**Date**: 2026-07-27

## Context

Org ADR-070 (`hydra/openspec/architecture/adr-070-or-backed-persistence-default.md`)
makes OpenRegister-backed persistence the fleet default and requires every
off-OR app to carry an app-local exception ADR naming the hard requirement OR
cannot satisfy, listing the OR-owned capabilities the app consequently
re-implements, and bounding the drift. Doriath is one of the two sanctioned
whole-app exceptions (confirmed by the product owner, 2026-07-27). Local
ADR-001 (own-database-tables) recorded the original choice; this ADR is the
formal ADR-070 exception record that extends it.

The hard requirement: **end-to-end / client-side encryption**. Doriath is a
secrets vault whose security model demands that the server cannot read secret
values — clients encrypt before upload (RSA/AES suite, local ADR-002/003) and
only ciphertext ever reaches PHP. OpenRegister objects are server-readable
JSON validated against schemas; storing secrets there would put plaintext-
readable structure, OR's audit serialisation, its search index, and its
export surface in the read path of material that must stay opaque to the
server. This falls under ADR-070's first recognised exception class.

At HEAD, `lib/Db/` holds ~32 Entity+QBMapper pairs (64 files) over 30 schema
migrations, with zero `ObjectService` data-path references. Ciphertext lives
in typed columns (`Secret`, `SecretVersion`, encrypted `Attachment` blobs
with AES-GCM-encrypted metadata); the only OR touchpoint is
`lib/Repair/InitializeSettings.php`, which imports a register *scaffold* when
OR happens to be installed and skips cleanly when it is not — no secret
material ever flows through OR.

## Decision

Doriath persists all vault data in app-local encrypted tables under
`lib/Db/`, managed by its own Nextcloud migrations. This is a whole-app
exception under org ADR-070 Decision 2 and ADR-022 §Exclusivity's exception
clause; the paths named below are the suppression scope for
`hydra-gate-or-abstraction-anti-patterns` (gate-23).

### OR-owned capabilities Doriath re-implements (and why)

| Capability | Doriath implementation | Why OR's version cannot be used |
|---|---|---|
| Audit trail | `lib/Service/AuditService.php` + `AuditEntry`/`AuditEntryMapper`, SIEM pipeline `SiemService` + `SiemQueueItem`/`SiemSink` | OR's audit serialises object payloads; vault audit must log access events without ever touching plaintext, and stream to external SIEM sinks |
| RBAC / grants | `ShareService`, `GroupShareService`, `LinkShareService`, `DelegationService`, `TeamFolderService`, `AttachmentGrant` + share/delegation entities | Grants are cryptographic (key re-wrapping per recipient), not row-level ACLs — OR RBAC gates reads the server can already perform |
| Settings storage | `lib/Service/SettingsService.php` + `DashboardSetting` | Vault config (suites, expiry/rotation policies) must work with OR absent |
| Search provider | `lib/Search/SecretSearchProvider.php` | Searches owned metadata only; OR full-text search would require server-readable values |
| Export / compliance | `GdprService`, `ComplianceReportService`, `ImportService` | Exports must emit ciphertext + key envelopes, not OR's JSON object dumps |
| Machine auth | `lib/Service/JwtAuthService.php` + `MachineLease`/`ApplicationLeasePolicy` | Lease-scoped machine credentials are part of the encryption boundary |

### Affected paths (gate-23 suppression scope)

`lib/Db/**`, `lib/Migration/**`, `lib/Search/SecretSearchProvider.php`, and
`lib/Service/{AuditService,SiemService,SettingsService,JwtAuthService,AttachmentService,ShareService,GroupShareService,LinkShareService,DelegationService,TeamFolderService,GdprService,ComplianceReportService,ImportService,SecretService,SecretVersionService}.php`.

### Drift boundary — what Doriath still consumes org-wide

The exception covers **persistence only**. Doriath remains bound to:
ADR-050 (response envelope), ADR-051 (controller exception translation),
ADR-069 (background-job and repair conventions — its jobs and
`InitializeSettings` repair step follow them), ADR-005 security rules, and
the shared `@conduction/nextcloud-vue` frontend stack (ADR-004 org-wide).
All other hydra gates apply unmodified; new non-secret features must justify
staying off-OR per entity or use OR.

## Consequences

- Doriath maintains its own migrations, search, audit, RBAC, and export —
  the duplication is deliberate, bounded to the paths above, and priced in.
- Gate-23 findings within the named paths are suppressed by this ADR;
  findings outside them are real drift and must be fixed or the ADR amended.
- Any future feature that stores *non-secret* data must default to OR per
  ADR-070; this ADR does not grandfather new tables outside the vault domain.

## Related

Org ADR-070 (OR-backed persistence default), org ADR-022 (+§Exclusivity
exception clause), org ADR-050/051/069 (conventions still consumed), local
ADR-001 (own database tables — extended by this ADR), local ADR-002/003
(encryption suite ownership, RSA/AES architecture).
