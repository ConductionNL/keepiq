# ADR-004: Keepiq stores secrets in app-local encrypted tables, not OpenRegister

**Status**: accepted

**Date**: 2026-07-27

- **References:** hydra ADR-022 (Apps consume OpenRegister abstractions), exception clause
- **Gate 23 rules:** 7

## Context

Org ADR-070 (`hydra/openspec/architecture/adr-070-or-backed-persistence-default.md`)
makes OpenRegister-backed persistence the fleet default and requires every
off-OR app to carry an app-local exception ADR naming the hard requirement OR
cannot satisfy, listing the OR-owned capabilities the app consequently
re-implements, and bounding the drift. Keepiq is one of the two sanctioned
whole-app exceptions (confirmed by the product owner, 2026-07-27). Local
ADR-001 (own-database-tables) recorded the original choice; this ADR is the
formal ADR-070 exception record that extends it.

The hard requirement: **end-to-end / client-side encryption**. Keepiq is a
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
with AES-GCM-encrypted metadata); no secret material ever flows through OR.
(This ADR originally named `lib/Repair/InitializeSettings.php`'s register
*scaffold* import as the only OR touchpoint. That import is gone: Keepiq is
independently usable and runs its own app shell, see ADR-006.)

## Decision

Keepiq persists all vault data in app-local encrypted tables under
`lib/Db/`, managed by its own Nextcloud migrations. This is a whole-app
exception under org ADR-070 Decision 2 and ADR-022 §Exclusivity's exception
clause; the paths named below are the suppression scope for
`hydra-gate-or-abstraction-anti-patterns` (gate-23).

### OR-owned capabilities Keepiq re-implements (and why)

| Capability | Keepiq implementation | Why OR's version cannot be used |
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

### Vault audit and share checks stay app-local (gate-23 rules 2 and 6)

Decided 4 October 2026 by the product owner (keepiq#673 follow-up). OpenRegister's
audit trail and RBAC act on objects the server can read. Keepiq's audit events
and share checks are about vault structure: who reached which secret, through
which share, delegation or team folder. Writing them through OpenRegister would
put that structure in OpenRegister's audit store, search index and exports, the
same exposure the persistence exception above exists to prevent. So the audit
trails and the share authorization stay app-local:

- `lib/Service/ShareAuditTrail.php` (gate 23 rules: 2)
- `lib/Service/LinkShareAuditTrail.php` (gate 23 rules: 2)
- `lib/Service/EmergencyAccessAuditTrail.php` (gate 23 rules: 2)
- `lib/Service/ApplicationAuditTrail.php` (gate 23 rules: 2)
- `lib/Service/SiemAuditTrail.php` (gate 23 rules: 2)
- `lib/Service/FederatedShareAuditTrail.php` (gate 23 rules: 2)
- `lib/Listener/AuditListener.php` (gate 23 rules: 2)
- `lib/Service/ShareAuthorizationService.php` (gate 23 rules: 6)

These classes log identifiers only, never a value, login or ciphertext, and the
share checks decide who may receive a re-wrapped key, which no row-level ACL can
express.

### Drift boundary — what Keepiq still consumes org-wide

The exception covers **persistence only**. Keepiq remains bound to:
ADR-050 (response envelope), ADR-051 (controller exception translation),
ADR-069 (background-job and repair conventions — its jobs and
`InitializeSettings` repair step follow them), ADR-005 security rules, and
the shared `@conduction/nextcloud-vue` frontend stack (ADR-004 org-wide).
All other hydra gates apply unmodified; new non-secret features must justify
staying off-OR per entity or use OR.

## Consequences

- Keepiq maintains its own migrations, search, audit, RBAC, and export —
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
