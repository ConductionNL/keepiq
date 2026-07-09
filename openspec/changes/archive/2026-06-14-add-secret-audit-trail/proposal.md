## Why

`docs/FEATURES.md` promises "Audit trail on all secret operations" at the **V1** tier (Security & Compliance, "Accountability") — for an app marketed to Dutch municipalities, knowing *who did what, when* to a credential is not a nice-to-have but the difference between a security incident being investigable and being a guess. Nothing is specced or built: today a secret can be read, shared, link-shared, exported, or deleted without leaving any reviewable trace.

The groundwork already exists by design: `secret-export-gdpr` deliberately scoped its accountability obligation to **emitting typed events** (`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent`) and explicitly deferred storage, retention, and UI to "the future `add-secret-audit-trail` change" (its design D5). This change is exactly that consumer: it registers listeners for those three events, extends the same typed-event pattern to every other server-observable secret operation, and adds the append-only store, retention policy, and user/admin views on top.

The E2E architecture (ADR-003) shapes what an audit trail can honestly claim: the server observes API operations (encrypted blob reads, writes, share/link/request lifecycle, suite lifecycle, application API access) but can never observe what happens inside an unlocked browser. The trail therefore covers server-observable operations completely and client-reported operations (export) on a best-effort honest-client basis — stated openly, exactly as `secret-export-gdpr` D5 does.

## What Changes

- Implement an **append-only audit log** (`doriath_audit_log` table): one row per audited operation with actor, event type, object reference, non-sensitive object name, and a whitelisted metadata payload (counts, modes, share targets) — **never** secret values, login fields, additional fields, or ciphertext
- Implement **typed audit events** (`lib/Event/Audit/`) dispatched from the service layer for all server-observable operations: secret created / updated / read (encrypted blob fetched) / deleted; folder deleted with cascade; share granted / revoked / ownership delegated / delegation reclaimed; link share created / accessed / access-attempt-failed / revoked / auto-deleted; secret request created / fulfilled / re-requested / revoked; suite revoked / reinstated / compromise recovery started and completed; application registered / approved / rejected / deleted; application token issued; application secret retrieved
- Implement **listeners that consume the three already-scoped `secret-export-gdpr` events** (`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent`) and persist them through the same pipeline — export/deletion coverage lands retroactively-free, as that change intended
- Implement **retention**: admin-configurable retention window (default 365 days, minimum 30), enforced by a nightly purge background job; purge and account-deletion anonymization are the only two paths that may mutate the log
- Implement **account-deletion anonymization**: on `AccountDataDeletedEvent`, existing entries referencing the deleted user as actor or subject are anonymized in place (IDs replaced by a `deleted-account` marker), consistent with the no-personal-data tombstone principle from `secret-export-gdpr`
- Implement a **per-secret activity view** (an "Activity" tab on the secret detail) and a **personal activity view** (the user's own recent operations), both strictly scoped to objects the user owns or operations the user performed
- Implement an **admin audit view** in the Doriath admin settings: instance-wide, paginated, filterable by event type / actor / object / date range, with client-generated CSV export of the current filter result
- Source the dashboard "Recently accessed secrets" widget (dashboard spec V1) from audit `secret.read` entries instead of introducing the separate `doriath_access_log` table sketched in the secrets-spec notes — one access-history store, not two

## Capabilities

### New Capabilities
- `secret-audit-trail`: Append-only audit logging of all server-observable secret operations plus client-reported export events — typed event capture, no-secret-material guarantee, configurable retention with automated purge, account-deletion anonymization, per-secret and personal activity views, and an admin audit view with filtering and CSV export

### Modified Capabilities
_(none in delta form — services gain event dispatch calls but no existing requirement changes; the dashboard "Recently accessed" data source swap is an implementation coordination noted in design, not a behavioural change to the dashboard spec)_

## Impact

- **Database**: One new table `doriath_audit_log` (append-only; indexed on `occurred_at`, `actor_id`, `object_type + object_id`, `event_type`). Supersedes the planned-but-never-built `doriath_access_log` from the secrets-spec notes
- **Backend**: New `AuditService` (single write path + query API), typed event classes under `lib/Event/Audit/`, one `AuditListener` registered for all audit events plus the three `secret-export-gdpr` events, dispatch calls added in SecretService, FolderService, ShareService, LinkShareService, SecretRequestService, EncryptionSuiteService, ApplicationService, JwtAuthService, new `AuditController` (user + admin endpoints), nightly `PurgeAuditLogJob`
- **Frontend**: New Pinia store (`useAuditStore`), `SecretActivityTab.vue`, `PersonalActivityView.vue`, `AdminAuditSection.vue` (admin settings), client-side CSV generation for the admin export
- **API**: `GET /api/v1/audit/secret/{id}` (owner-scoped), `GET /api/v1/audit/me`, `GET /api/v1/audit` (admin, filterable), `GET /api/v1/settings/audit-retention` + `PUT` (admin)
- **Dependencies**: Depends on `implement-secrets`, `implement-user-sharing`, `implement-link-sharing`, `implement-secret-requests`, `implement-application-mgmt` (the services that dispatch), and `secret-export-gdpr` (the three consumed event classes — this change is their scoped consumer). Coordinates with `implement-dashboard-settings` (recently-accessed widget source)
- **Security**: Log entries carry metadata only — a database dump of the audit table must reveal nothing about secret values. Read access is strictly scoped (own objects / own actions for users; instance-wide for admins only). The log is append-only at the API surface; no endpoint can edit or delete entries
- **Privacy**: No IP addresses or user agents recorded in v1 (privacy by default); account deletion anonymizes the deleted user out of the trail; retention bounds how long behavioural data lives
- **Cross-app**: None beyond the Nextcloud event dispatcher. The trail also gives operators a single place to attach SIEM forwarding later (out of scope here)
