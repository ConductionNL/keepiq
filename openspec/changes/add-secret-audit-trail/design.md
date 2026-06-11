## Context

FEATURES.md promises an audit trail at V1; nothing exists. `secret-export-gdpr` D5 already made the architectural call this change inherits: accountability flows through **typed `OCP\EventDispatcher` events**, and the audit change "registers listeners and gets export/deletion coverage retroactively-free". Three event classes (`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent`) are therefore the existing contract this change consumes — their payloads (counts/modes/trigger only, never secret material) also set the privacy bar for every new event added here.

Two constraints shape the design. First, ADR-003 always-E2E: the server observes API calls, never plaintext — so "audit trail on all secret operations" honestly means *all server-observable operations* plus *client-reported flows* (export), and the spec says so instead of implying browser-level surveillance. Second, the secrets-spec notes already sketch a `doriath_access_log` table for the dashboard "Recently accessed" widget that was never built — building both that and an audit log would store the same fact twice.

## Goals / Non-Goals

**Goals:**
- Append-only `doriath_audit_log` covering every server-observable secret operation
- Consume the three `secret-export-gdpr` events unchanged (their scoped consumer)
- Configurable retention + automated purge; account-deletion anonymization
- Per-secret activity tab, personal activity view, admin audit view with filters + CSV export
- Hard guarantee: no secret values, login fields, additional fields, or ciphertext in any entry

**Non-Goals:**
- Cryptographic tamper-evidence (hash chains, signed entries) — open question for an Enterprise tier; append-only-at-API-surface is the v1 posture
- SIEM/syslog forwarding, Nextcloud Activity app integration, and admin alerting rules — the typed events make all three attachable later without schema changes
- Logging client-internal actions (clipboard copy, in-browser decrypt, reveal-password toggle) — not server-observable under E2E; export stays the one client-reported event family, as established by secret-export-gdpr
- IP address / user-agent capture (privacy by default in v1; revisit behind an admin toggle if operators ask)
- Link-visitor identification — link-share access entries record the share and outcome, not the anonymous visitor

## Decisions

### D1: One Append-Only Table, One Write Path

```
doriath_audit_log
  id            bigint autoincrement PK
  occurred_at   datetime              (indexed)
  actor_type    string(16)            user | application | system | link_visitor
  actor_id      string(64) nullable   (indexed; null for link_visitor/system)
  event_type    string(64)            (indexed; dot-namespaced, e.g. secret.read)
  object_type   string(32)            secret | folder | share | link_share | secret_request | suite | application | vault
  object_id     string(36) nullable   (indexed with object_type)
  object_name   string(255) nullable  denormalized non-sensitive name (survives object deletion)
  metadata      text nullable         JSON, whitelisted keys only
```

All writes go through `AuditService::record(AuditEntry)` — the **only** code path that inserts. The mapper exposes insert + query + purge + anonymize; there is no update-by-API and no per-entry delete. `object_name` is denormalized deliberately: after a secret is deleted, the trail must still read "secret *GitHub deploy key* deleted", and `Secret.name` is plaintext metadata per the secrets spec (explicitly "safe to display"), so storing it does not widen exposure.

**Why a string `event_type`, not an enum column:** new event families (D2) must not require migrations. The PHP-side enum lives in a single `AuditEventTypes` constants class that the metadata whitelist (D3) keys off.

### D2: Typed Events In, Rows Out — Services Never Touch the Table

Every audited operation dispatches a typed event subclassing a common `AbstractAuditEvent` (`lib/Event/Audit/`), and a single registered `AuditListener` maps events to rows via `AuditService`. Event families:

| Family | Events | Dispatched by |
|---|---|---|
| Secret | `secret.created`, `secret.updated`, `secret.read`, `secret.deleted` | SecretService (read = encrypted-blob fetch of an individual secret; list/search calls are not per-secret reads) |
| Folder | `folder.deleted_cascade` | FolderService (only the destructive cascade is audit-relevant) |
| Sharing | `share.granted`, `share.revoked`, `share.delegated`, `share.delegation_reclaimed` | ShareService / DelegationService |
| Link share | `link_share.created`, `link_share.accessed`, `link_share.access_failed`, `link_share.revoked`, `link_share.auto_deleted` | LinkShareService |
| Secret request | `request.created`, `request.fulfilled`, `request.re_requested`, `request.revoked` | SecretRequestService |
| Suite | `suite.revoked`, `suite.reinstated`, `suite.recovery_started`, `suite.recovery_completed` | EncryptionSuiteService |
| Application | `application.registered`, `application.approved`, `application.rejected`, `application.deleted`, `application.token_issued`, `application.secret_retrieved` | ApplicationService / JwtAuthService |
| Export & deletion | `vault.exported`, `vault.gdpr_exported`, `vault.account_deleted` | **Consumed from secret-export-gdpr's `SecretExportedEvent` / `GdprExportPerformedEvent` / `AccountDataDeletedEvent` — no new dispatch sites; the listener maps the existing payloads (mode, scope, counts, trigger) into `metadata` as-is** |

**Why events instead of direct `AuditService` calls in every service:** it keeps the secret-export-gdpr contract symmetric (those events arrive the same way), makes audit non-blocking for business logic (a listener failure must never roll back the audited operation — the listener catches and logs internally), and leaves a stable hook for future SIEM forwarding.

### D3: Metadata Whitelist — the No-Secret-Material Guarantee Is Structural

`AuditService::record()` validates `metadata` against a per-event-type whitelist (e.g. `share.granted` → `{recipientType, recipientId}`; `vault.exported` → `{mode, scope, secretCount}`; `link_share.access_failed` → `{reason}`). Unknown keys are dropped, and the keys `key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload` are rejected with an exception in any position — defense in depth so a future dispatch site cannot accidentally leak. Unit tests assert both directions (whitelist passes, blacklist throws).

### D4: Audit Log Subsumes the Planned Access Log

The secrets-spec notes sketch a `doriath_access_log` (secret_id, user_id, accessed_at) to be created "when implementing the V1 dashboard features", solely to power the "Recently accessed secrets" widget. `secret.read` entries carry exactly that information. Decision: **do not create `doriath_access_log`**; the dashboard widget queries the 5 most recent `secret.read` entries for the session user via `AuditService`. Coordination point with `implement-dashboard-settings` (its widget task should target `AuditService::recentlyAccessed(userId)`); recorded here rather than as a delta on the dashboard spec because the widget's observable behaviour is unchanged.

### D5: Retention and the Two Permitted Mutations

Retention window is an admin setting (`audit_retention_days`, default 365, hard minimum 30 — below that the trail cannot serve its incident-investigation purpose). A nightly `PurgeAuditLogJob` (TimedJob) deletes entries older than the window in bounded batches. Scheduling note: the job is enqueued via `IJobList` from a migration/repair step — **not** via a non-existent `IRegistrationContext::registerJob()` call (fleet-wide gotcha: three apps shipped jobs that never ran).

The only other mutation is anonymization (D6). Both run inside `AuditService`; no controller exposes any mutating verb on entries.

### D6: Account-Deletion Anonymization, Not Deletion

On `AccountDataDeletedEvent`, the listener (same one that records `vault.account_deleted`) calls `AuditService::anonymizeUser(userId)`: entries with the user as `actor_id` get `actor_id = null` + `metadata.actor = 'deleted-account'`; whitelisted metadata fields referencing the user (e.g. `recipientId`) are replaced by the same marker; `object_name` values are left alone (they name objects, not people).

**Why anonymize rather than delete:** other users' entries legitimately reference interactions with the departed account (a share they received, a delegation they hold) — destroying those rows would punch holes in *their* accountability record. Anonymization keeps the operational facts while removing the personal data, the same principle as secret-export-gdpr's tombstone ("no user ID retained"). **Why not retain under Art. 17(3)(b):** Doriath has no statutory retention obligation to point at; the conservative default is to scrub.

### D7: Read Access Scoping

- `GET /api/v1/audit/secret/{id}` — entries for one secret; permitted for the secret's current owner (post-delegation owner included) and recipients see only entries they actored. 404-as-403 for non-owners (no existence oracle).
- `GET /api/v1/audit/me` — entries where the session user is the actor.
- `GET /api/v1/audit` — admin only (`#[AuthorizedAdminSetting]`-gated alongside the other Doriath admin endpoints), filterable by event type, actor, object type/id, date range; classic pagination (50/page, consistent with the secrets list).

CSV export of the admin view is generated **client-side** from the fetched (filtered, paginated-through) rows — no server-side file generation, no new download endpoint, consistent with the export-stays-in-the-browser house pattern.

## Risks / Trade-offs

- **[Risk] `secret.read` volume** — read is the hottest path; one insert per individual secret fetch. Mitigated by: list/search excluded (only individual blob fetches count), indexes designed for the queries we actually run, retention purge bounding table size. If volume still hurts, batching inserts in a write-behind queue is a contained optimization inside `AuditService`.
- **[Risk] Listener failure silently losing entries** — the listener never throws into the business operation, so a broken mapper could drop events quietly. Mitigated by logging at error level on record failure + a unit test per event family asserting the row lands.
- **[Trade-off] Append-only is policy, not cryptography** — a DBA can still edit rows. Hash-chain tamper-evidence is deferred (open question); the spec claims append-only at the application surface and no more.
- **[Trade-off] Client-reported events are honest-client only** — inherited verbatim from secret-export-gdpr D5; stated in the spec so the trail never overclaims.
- **[Trade-off] No IP/user-agent** — weaker forensics, stronger privacy default. Operators needing source attribution can correlate with Nextcloud's own access logs via timestamps.

## Migration Plan

1. **Database migration**: ISchemaWrapper migration creating `doriath_audit_log` with the four indexes (next free version number at implementation time); `occ upgrade`
2. **Job scheduling**: same migration (or a repair step) enqueues `PurgeAuditLogJob` via `IJobList`
3. **Listener registration**: `AuditListener` registered in `Application::register()` for all `lib/Event/Audit/` events and the three secret-export-gdpr events
4. **Frontend build**: no new dependencies; `npm run build`
5. **Rollback**: deregister listener + job; the table is inert
6. **Greenfield**: no historical backfill — the trail starts at deployment, stated in the admin view's empty state

## Open Questions

- **Tamper-evidence**: per-entry hash chaining (each row stores `hash(prev_hash + row)`) is cheap to add later but verification tooling is not — Enterprise-tier candidate, aligned with FEATURES.md's Enterprise audit rows.
- **Admin visibility into user vaults' activity**: the admin view shows all entries, including which user read which secret *name* — acceptable for an accountability tool (and the names are plaintext in the DB anyway), but if works councils object, a config flag could restrict the admin view to non-read events. Default: full visibility, documented.
- **SIEM forwarding**: the typed events are the natural hook; a `doriath_audit_webhook` admin setting is a small follow-up change once someone needs it.
