## 0. Dependency Note (read first)

This change is the scoped consumer of `secret-export-gdpr`'s typed events
(`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent` —
its design D5 defers audit storage to exactly this change). It also adds
dispatch calls inside the services built by `implement-secrets`,
`implement-user-sharing`, `implement-link-sharing`,
`implement-secret-requests`, and `implement-application-mgmt` — those changes
must be landed (or the dispatch tasks deferred per service) first. It
coordinates with `implement-dashboard-settings`: the "Recently accessed
secrets" widget sources from `secret.read` audit entries instead of the
never-built `doriath_access_log` (design D4).

## 1. Database Migration and Entity

- [ ] 1.1 Create ISchemaWrapper migration (next free version number) adding `doriath_audit_log` (id, occurred_at, actor_type, actor_id, event_type, object_type, object_id, object_name, metadata) with indexes on occurred_at, actor_id, (object_type, object_id), event_type
- [ ] 1.2 Create `AuditEntry` entity + `AuditEntryMapper` exposing insert, scoped query methods (bySecret, byActor, adminFiltered with pagination + total count), `purgeOlderThan(date, batchSize)`, and `anonymizeUser(userId)` — no generic update/delete
- [ ] 1.3 Enqueue `PurgeAuditLogJob` via `IJobList` from the migration/repair step (NOT via a non-existent `IRegistrationContext::registerJob()` — fleet gotcha: jobs registered that way never run)

## 2. Backend — Events and Listener

- [ ] 2.1 Create `lib/Event/Audit/AbstractAuditEvent.php` (actorType/actorId/objectType/objectId/objectName/metadata accessors) and the typed event classes per family in design D2 (secret, folder, sharing, link share, secret request, suite, application)
- [ ] 2.2 Create `AuditEventTypes` constants class with the dot-namespaced event type strings and the per-event-type metadata whitelist map
- [ ] 2.3 Create `AuditService` with `record()` as the single insert path: whitelist validation (unknown keys dropped), forbidden-key rejection (`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload` → exception), and query methods delegating to the mapper
- [ ] 2.4 Create `AuditListener` handling all `lib/Event/Audit/` events PLUS the three secret-export-gdpr events (`SecretExportedEvent` → `vault.exported`, `GdprExportPerformedEvent` → `vault.gdpr_exported`, `AccountDataDeletedEvent` → `vault.account_deleted`, payloads mapped as-is); wrap `record()` in try/catch with error-level logging so audit failure never propagates into the audited operation
- [ ] 2.5 On `AccountDataDeletedEvent`, additionally call `AuditService::anonymizeUser()` (actor ids and whitelisted user-referencing metadata → `deleted-account` marker)
- [ ] 2.6 Register `AuditListener` for every event class in `Application::register()`

## 3. Backend — Dispatch Sites

- [ ] 3.1 SecretService: dispatch `secret.created` / `secret.updated` / `secret.deleted`, and `secret.read` on individual encrypted-blob fetch only (explicitly NOT on list/search)
- [ ] 3.2 FolderService: dispatch `folder.deleted_cascade` with secret/subfolder counts in metadata
- [ ] 3.3 ShareService / DelegationService: dispatch `share.granted` (recipientType/recipientId), `share.revoked`, `share.delegated`, `share.delegation_reclaimed`
- [ ] 3.4 LinkShareService: dispatch `link_share.created` / `accessed` / `access_failed` (reason only, never the attempted password) / `revoked` / `auto_deleted`
- [ ] 3.5 SecretRequestService: dispatch `request.created` / `fulfilled` / `re_requested` / `revoked`
- [ ] 3.6 EncryptionSuiteService: dispatch `suite.revoked` / `reinstated` / `recovery_started` / `recovery_completed`
- [ ] 3.7 ApplicationService + JwtAuthService: dispatch `application.registered` / `approved` / `rejected` / `deleted` / `token_issued` / `secret_retrieved`

## 4. Backend — Controllers, Settings, Purge Job

- [ ] 4.1 Create `AuditController`: `GET /api/v1/audit/secret/{id}` (`#[NoAdminRequired]`, owner-scoped, 404-as-403 for non-owners — no existence oracle), `GET /api/v1/audit/me` (session-user actor scope), `GET /api/v1/audit` (admin-gated, filters: eventType/actor/objectType/objectId/from/to, classic pagination 50/page with total count)
- [ ] 4.2 Add `audit_retention_days` admin setting (default 365, server-side floor 30 with validation error below) with GET/PUT endpoints alongside the existing Doriath admin settings
- [ ] 4.3 Create `PurgeAuditLogJob` (TimedJob, nightly) deleting entries older than the retention window in bounded batches
- [ ] 4.4 Register all routes in `appinfo/routes.php` before the SPA catch-all; run hydra gates (route-auth, no-admin-idor, semantic-auth, spec-coverage)

## 5. Frontend

- [ ] 5.1 Create `src/store/modules/audit.js` (`useAuditStore`): fetch per-secret activity, personal activity, admin-filtered list with pagination state
- [ ] 5.2 Create `src/components/SecretActivityTab.vue`: Activity tab on the secret detail view — entries newest first with actor, event-type label, relative timestamp
- [ ] 5.3 Create `src/views/PersonalActivityView.vue` (or settings-dialog section per the existing navigation pattern): the session user's own operations
- [ ] 5.4 Create `src/components/AdminAuditSection.vue` in the Doriath admin settings: filter bar (event type NcSelect with `inputLabel`, actor, date range), paginated table, empty state stating the trail starts at deployment
- [ ] 5.5 Implement client-side CSV export of the current admin filter result (paginate through, generate Blob locally — no server download endpoint)
- [ ] 5.6 Human-readable event-type labels (one i18n string per event type)

## 6. Internationalization

- [ ] 6.1 Add English strings (event-type labels, activity views, admin filters, retention setting, deleted-account marker, empty states) to `l10n/en.json` — English source strings as keys
- [ ] 6.2 Add Dutch translations to `l10n/nl.json`

## 7. Unit Tests (PHP)

- [ ] 7.1 `AuditService` tests: whitelist passes known keys, drops unknown keys, throws on every forbidden key; record persists through the mapper
- [ ] 7.2 `AuditListener` tests: one test per event family asserting the row lands with correct event_type/actor/object/metadata; the three secret-export-gdpr events map payloads as-is; listener swallows mapper exceptions and logs (audited operation unaffected)
- [ ] 7.3 Anonymization tests: actor ids and user-referencing metadata replaced by the marker; entries retained; other users' entries intact; assert no occurrence of the deleted user id anywhere in the table afterwards
- [ ] 7.4 Purge job tests: deletes only entries older than the window, batch-bounded; retention floor rejected below 30
- [ ] 7.5 Controller tests: secret scope enforced with no existence oracle (same response for non-owned and nonexistent), `/audit/me` strictly actor-scoped, admin endpoint rejects non-admins, filters and pagination correct
- [ ] 7.6 Dispatch-site tests: list/search produce no `secret.read`; individual fetch produces exactly one; `link_share.access_failed` metadata contains a reason and never a password

## 8. Frontend Tests (vitest)

- [ ] 8.1 `useAuditStore` tests: scoped fetches, pagination state, filter serialization
- [ ] 8.2 `SecretActivityTab` / `AdminAuditSection` component tests (jsdom): rendering order, filter wiring, empty state text
- [ ] 8.3 CSV export test: generated client-side from store rows, RFC 4180 quoting, no network call to a download endpoint

## 9. E2E (Playwright)

- [ ] 9.1 Activity tab e2e: update a secret → open Activity tab → `secret.updated` entry visible with actor and timestamp
- [ ] 9.2 Admin audit view e2e: perform a share + a failed link-share access → admin filters by event type → both entries found; CSV download intercepted
- [ ] 9.3 Annotate spec scenarios per gate-19 (`@e2e` refs for the view flows; `@e2e exclude` with reasons for server-only contracts: append-only surface, purge job, anonymization, event consumption, no-secret-material DB assertion — covered by PHPUnit)

## 10. Documentation

- [ ] 10.1 Update `docs/FEATURES.md` status for the audit-trail row
- [ ] 10.2 Add `docs/audit-trail.md`: what is and is not observable under E2E (honest-client export reporting inherited from secret-export-gdpr), event-type reference, retention and anonymization semantics, admin-visibility note (admins see who read which secret name)
- [ ] 10.3 Coordination note in `implement-dashboard-settings`: "Recently accessed" widget sources from `AuditService::recentlyAccessed()` — `doriath_access_log` is not to be built (design D4)
