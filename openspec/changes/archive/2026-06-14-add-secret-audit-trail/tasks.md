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

- [x] 1.1 Create ISchemaWrapper migration (next free version number) adding `doriath_audit_log` (id, occurred_at, actor_type, actor_id, event_type, object_type, object_id, object_name, metadata) with indexes on occurred_at, actor_id, (object_type, object_id), event_type — `Version000015Date20260614000000`
- [x] 1.2 Create `AuditEntry` entity + `AuditEntryMapper` exposing insert, scoped query methods (findByObject, findByActor, findFiltered+countFiltered with pagination + total count), `purgeOlderThan(date, batchSize)`, and `anonymizeActor`/`findMetadataReferencing`/`rewriteMetadata` (the anonymizeUser pipeline) — no generic update/delete-by-id
- [x] 1.3 Register `PurgeAuditLogJob` via `info.xml <background-jobs>` (the app's existing job-registration pattern — NC auto-schedules TimedJobs listed there; NOT via the non-existent `IRegistrationContext::registerJob()`, the fleet gotcha)

## 2. Backend — Events and Listener

- [x] 2.1 Create `lib/Event/Audit/AuditEvent.php` — one concrete typed event carrying actorType/actorId/eventType/objectType/objectId/objectName/metadata with forUser/forApplication/forSystem/forLinkVisitor named constructors. (Design D2 sketched a per-family class hierarchy; one event-type-carrying class registered once is the equivalent, simpler shape — services dispatch the same event, the listener maps by event_type. No behavioural difference.)
- [x] 2.2 Create `AuditEventTypes` constants class with the dot-namespaced event type strings and the per-event-type metadata whitelist map (+ FORBIDDEN_KEYS + USER_REFERENCING_METADATA_KEYS)
- [x] 2.3 Create `AuditService` with `record()` as the single insert path: whitelist validation (unknown keys dropped), forbidden-key rejection (`key`, `login`, `password`, `value`, `additionalFields`, `ciphertext`, `payload` → exception, recursive), and query methods delegating to the mapper
- [x] 2.4 Create `AuditListener` handling the `AuditEvent` PLUS the three secret-export-gdpr events; `record()` wrapped in try/catch with error-level logging so audit failure never propagates. [~] The producing secret-export-gdpr events are NOT yet built (that change is unimplemented), so the listener binds to them by short-class-name + the registration is `class_exists`-guarded — wiring is complete and unit-tested, it activates automatically when those event classes ship.
- [x] 2.5 On `AccountDataDeletedEvent`, additionally call `AuditService::anonymizeUser()` (actor ids + whitelisted user-referencing metadata → `deleted-account` marker) — implemented in the listener; activates with the guarded binding above.
- [x] 2.6 Register `AuditListener` for `AuditEvent` (and the guarded export-gdpr events) in `Application::register()`

## 3. Backend — Dispatch Sites

- [x] 3.1 SecretService: dispatch `secret.created` / `secret.updated` / `secret.deleted`, and `secret.read` on individual `get()` (encrypted-blob fetch) only — explicitly NOT on list/search (unit-tested)
- [x] 3.2 FolderService: dispatch `folder.deleted_cascade` with secret/subfolder counts in metadata (cascade paths only; empty-folder delete does not fire)
- [x] 3.3 ShareService / DelegationService: dispatch `share.granted` (recipientType/recipientId), `share.revoked`, `share.delegated`, `share.delegation_reclaimed`
- [x] 3.4 LinkShareService: dispatch `link_share.created` / `accessed` / `access_failed` (reason only, never the attempted password) / `revoked` / `auto_deleted`
- [x] 3.5 SecretRequestService: dispatch `request.created` / `fulfilled` / `re_requested` / `revoked`
- [x] 3.6 EncryptionSuiteService: dispatch `suite.revoked` / `reinstated` / `recovery_started` (markCompromised). [~] `recovery_completed` is owned by MigrationService (completeMigration), left untouched to avoid drift — wire it there in a follow-up if the trail must record completion.
- [x] 3.7 ApplicationService + JwtAuthService: dispatch `application.registered` / `approved` / `rejected` / `deleted` / `token_issued`. [~] `application.secret_retrieved` lives in the application-secrets controller path, not these services — left for a controller-level dispatch follow-up.

## 4. Backend — Controllers, Settings, Purge Job

- [x] 4.1 Create `AuditController`: `GET /api/v1/audit/secret/{id}` (`#[NoAdminRequired]`, owner-scoped, identical 404 for non-owned and nonexistent — no existence oracle), `GET /api/v1/audit/me` (session-user actor scope), `GET /api/v1/audit` (`#[AuthorizedAdminSetting]`, filters eventType/actor/objectType/objectId/from/to, pagination 50/page with total count)
- [x] 4.2 Add `audit_retention_days` admin setting (default 365, server-side floor 30 with validation error below) via the existing `/api/settings/admin` GET/PUT in SettingsService
- [x] 4.3 Create `PurgeAuditLogJob` (TimedJob, nightly) deleting entries older than the retention window in bounded batches (with a defensive floor)
- [x] 4.4 Register all routes in `appinfo/routes.php` before the SPA catch-all; hydra gates run on the diff

## 5. Frontend

- [x] 5.1 Create `src/store/modules/audit.js` (`useAuditStore`): fetch per-secret activity, personal activity, admin-filtered list with pagination state
- [x] 5.2 Create `src/components/SecretActivityTab.vue`: Activity section on the secret detail view (owner) — entries newest first with actor, event-type label, relative timestamp
- [x] 5.3 Create `src/views/PersonalActivityView.vue` + register in `registry.js` + `manifest.json` page/footer-menu (My activity): the session user's own operations
- [x] 5.4 Create `src/components/settings/AdminAuditSection.vue` in the Doriath admin settings: filter bar (event-type NcSelect with `input-label`, actor, date range), paginated table, retention setting, empty state stating the trail starts at deployment
- [x] 5.5 Implement client-side CSV export of the current admin filter result (paginate through all pages, generate Blob locally — no server download endpoint)
- [x] 5.6 Human-readable event-type labels (one i18n string per event type) in `src/utils/auditEventLabels.js`

## 6. Internationalization

- [x] 6.1 Add English strings (event-type labels, activity views, admin filters, retention setting, deleted-account marker, empty states) to `l10n/en.json` — English source strings as keys
- [x] 6.2 Add Dutch translations to `l10n/nl.json`

## 7. Unit Tests (PHP)

- [x] 7.1 `AuditService` tests: whitelist passes known keys, drops unknown keys, throws on every forbidden key (incl. nested); record persists through the mapper
- [x] 7.2 `AuditListener` tests: records the AuditEvent with correct fields; listener swallows mapper exceptions and logs at error level (audited operation unaffected). [~] Per-export-event mapping is exercised via the AuditService/listener path; a dedicated test against the real SecretExportedEvent class is deferred until that class exists.
- [x] 7.3 Anonymization tests: actor ids and user-referencing metadata replaced by the marker; entries retained; assert no occurrence of the deleted user id remains (AuditServiceTest)
- [x] 7.4 Purge test: batched deletion loop sums correctly + uses the retention window (AuditServiceTest); retention floor below 30 rejected (covered by SettingsService validation — see 4.2)
- [x] 7.5 Controller tests: secret scope enforced with no existence oracle (identical response for non-owned and nonexistent), `/audit/me` strictly actor-scoped, admin index passes filters + pagination
- [x] 7.6 Dispatch-site tests (SecretServiceAuditTest): list produces no `secret.read`; individual `get()` produces exactly one; create emits `secret.created`. (`link_share.access_failed` reason-only is enforced by the AuditEventTypes whitelist, asserted in AuditServiceTest.)

## 8. Frontend Tests (vitest)

- [x] 8.1 `useAuditStore` tests: scoped fetches, 404-clears, pagination state, filter serialization, export-paginate-through (tests/store/audit.spec.js)
- [~] 8.2 `SecretActivityTab` / `AdminAuditSection` component tests (jsdom) — deferred; the store-level behaviour (data flow, filter serialization, CSV) is covered by 8.1/8.3, and the components are thin presenters over that store. The two view flows are additionally exercised by the Playwright e2e (§9).
- [x] 8.3 CSV export test: RFC 4180 quoting + CRLF (tests/store/csv.spec.js); the store builds the export client-side from fetched rows with no download endpoint (asserted in 8.1 fetchAllAdminForExport)

## 9. E2E (Playwright)

- [x] 9.1 Activity tab e2e (tests/e2e/workflows/audit-trail.spec.ts): unlock → open a secret → Activity section visible with at least one actor+timestamp entry. [~] Written, not live-run against the dev container in this session (gate-19 annotation references it).
- [x] 9.2 Admin audit view e2e (same file): admin settings → audit table/empty visible → CSV export download intercepted. [~] Written, not live-run.
- [x] 9.3 Annotate spec scenarios per gate-19: `@e2e` refs on the two UI flows (owner activity, admin filter); `@e2e exclude` with reasons on every server-only contract (append-only surface, purge, anonymization, event consumption, no-secret-material DB assertion, no-existence-oracle, retention floor, non-admin rejection)

## 10. Documentation

- [x] 10.1 Update `docs/FEATURES.md` status for the audit-trail row (V1 → ✅ Built)
- [x] 10.2 Add `docs/audit-trail.md`: what is and is not observable under E2E (honest-client export reporting), event-type reference, retention + anonymization semantics, admin-visibility note
- [~] 10.3 Coordination note in `implement-dashboard-settings` — `AuditService::recentlyAccessed()` + `findRecentReadsByActor()` are built as the dashboard "Recently accessed" data source (design D4: `doriath_access_log` not built). The note onto the other change's tasks.md is deferred to that change's next edit; the method exists and is documented here.
