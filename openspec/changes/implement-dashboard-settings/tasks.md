> **Build note (hydra adaptation).** The dependency changes this spec assumed
> were merged (implement-secrets, implement-user-sharing, implement-secret-requests)
> are NOT yet in `development`: there is no Secret/SecretShare/Folder/Application
> mapper, no NotificationService, and the dashboard/admin-settings/user-settings
> frontend is a manifest-v2 declarative app (no vue-router, no `Dashboard.vue`,
> no `UserSettings.vue`, no `src/views/settings/` Settings shell beyond
> `AdminRoot`/`Settings`). The implementation was adapted to the REAL app:
> - Dashboard summary aggregates from the data that exists today
>   (EncryptionSuite active/compromised counts, LinkShare share count, in-progress
>   SuiteMigration, admin-only CA health). `folder_count` and `pending_apps_count`
>   are returned as 0 placeholders (the backing mappers do not exist yet — see
>   deferred items below).
> - User settings live in the existing `App.vue` `#user-settings` CnAppRoot slot
>   (the app's user-preferences surface), not an `NcAppSettingsDialog` view.
> - The dashboard is rendered by a manifest-v2 widget (`doriath-summary`), not a
>   `Dashboard.vue` + custom KPI widget instances on a vue-router page.
> - Per-user preference storage was extracted into a dedicated
>   `UserPreferenceService` (PHPMD class-complexity) rather than living inside
>   `SettingsService`; `SettingsService` delegates to it.

## 1. Backend Services

- [x] 1.1 Create `DashboardService` in `lib/Service/DashboardService.php` — `fetchSummary(string $userId, bool $isAdmin): array` returning total_secrets, shared_secrets, folder_count, compromised_count, pending_apps_count (admin-only, null otherwise), migration_status, ca_status (admin-only, null otherwise). Injects EncryptionSuiteMapper, SuiteMigrationMapper, LinkShareMapper, CertificateAuthorityService (the SecretMapper/SecretShareMapper/FolderMapper/ApplicationMapper named in the spec do not exist yet; counts are derived from the suites/link-shares that do).
- [x] 1.2 Extend `SettingsService` — admin-config validation (length/score floors, session-timeout enum), delegates user preferences to the new `UserPreferenceService` (USER_PREF keys: `session_timeout`, `notify_shares`, `notify_requests`, `notify_group_shares`, `notify_security`, `default_secret_type`, `default_view`). Admin keys use `master_password_min_length` / `master_password_min_score` (the keys the existing `PasswordPolicySection.vue` already posts) plus `default_session_timeout` / `ca_auto_renew_enabled`.
- [x] 1.3 Implement `SettingsService::getAdminSettings(): array` — typed IAppConfig getters (getValueInt length/score, getValueString timeout, getValueBool auto_renew).
- [x] 1.4 Implement `SettingsService::updateAdminSettings(array $data): array` — validates min length (12-20), score (3-4), session-timeout enum, ca_auto_renew_enabled (bool); throws InvalidArgumentException on out-of-bounds; typed IAppConfig setters.
- [x] 1.5 Implement user preference read (`UserPreferenceService::getUserPreferences`) — defaults: session_timeout → admin default, booleans → '1', default_secret_type → 'login', default_view → 'list'.
- [x] 1.6 Implement user preference write (`UserPreferenceService::updateUserPreferences`) — whitelist-filters keys, '1'/'0' boolean coercion, IConfig::setUserValue.

## 2. Controllers and Routes

- [x] 2.1 `DashboardController::summary()` — `#[NoAdminRequired]`, IDOR-safe (scoped to session user, 401 when anonymous), determines isAdmin via IGroupManager, delegates to DashboardService.
- [x] 2.2 `SettingsController::getAdminSettings()` / `updateAdminSettings()` — `#[AuthorizedAdminSetting(AdminSettings::class)]`; update maps InvalidArgumentException to a 400 (no stack trace).
- [x] 2.3 `SettingsController::getUserSettings()` / `updateUserSettings()` — `#[NoAdminRequired]`, scoped to the session user (401 when anonymous).
- [x] 2.4 `appinfo/routes.php` — GET /api/dashboard/summary, GET/PUT /api/settings/admin, GET/PUT /api/settings/user; the specific `/api/settings/{admin,user}` routes precede the generic `/api/settings` collection, and all precede the SPA `/{path}` catch-all.

## 3. Dashboard Frontend

- [x] 3.1 Create `src/components/dashboard/DashboardKpiCard.vue` — custom KPI card (NOT CnStatsBlock) with title/count/icon/variant (primary|default|warning|success), NL-Design CSS variables.
- [~] 3.2 Migration banner — implemented inline inside `DashboardSummaryWidget.vue` (NcNoteCard, warning for in_progress / error for completed_with_errors). Not a separate `MigrationBanner.vue` file because the manifest-v2 dashboard is a single widget, not a `Dashboard.vue` composing child components; the banner is a NoteCard (not a modal), so modal-isolation does not apply.
- [~] 3.3 Pending-apps surface — implemented inline in `DashboardSummaryWidget.vue` (admin-only NoteCard shown when pending_apps_count > 0). No `$router.push('/settings')` link: settings is the NC admin settings page, not an in-app route.
- [ ] 3.4 (V1) `CaHealthCard.vue` — DEFERRED. Admin CA health is surfaced as a status line in `DashboardSummaryWidget.vue`; the richer V1 card is out of MVP scope and the dedicated admin `CaHealthSection.vue` already covers CA management.
- [ ] 3.5 (V1) `RecentSecretsWidget.vue` — DEFERRED. No Secret entity/mapper exists yet (implement-secrets not merged); cannot list recently-accessed secrets.
- [x] 3.6 Create `src/store/modules/dashboard.js` — Pinia store: summary/isLoading/error state, `fetchSummary()` action, `isEmpty` getter.
- [~] 3.7 Dashboard rendered via manifest-v2 widget `DashboardSummaryWidget.vue` (registered as `doriath-summary` in `src/registry.js`, referenced from the Dashboard page in `src/manifest.json`) instead of rewriting a `Dashboard.vue`: loading spinner, empty state (NcEmptyContent), migration banner, four DashboardKpiCard instances, admin pending-apps + CA health. The static sample `stats-block` tiles were removed from the manifest.

## 4. Admin Settings Frontend

- [x] 4.1 `src/components/settings/PasswordPolicySection.vue` (pre-existing) — re-wired to the new dedicated `/api/settings/admin` endpoint via the settings store (was posting to the generic `/api/settings`, where the backend silently dropped the keys); server now validates and persists.
- [x] 4.2 `src/components/settings/CaHealthSection.vue` (pre-existing) — already renders CA status + retry-bootstrap / force-renew actions against the CA endpoints; no change needed.
- [ ] 4.3 `ApplicationQueueSection.vue` — DEFERRED. No Application entity/mapper/controller exists yet (implement-secret-requests not merged).
- [x] 4.4 `src/views/settings/Settings.vue` — already composes PasswordPolicySection + CaHealthSection (no register form present in current code).
- [x] 4.5 `src/views/settings/AdminRoot.vue` — retains CnVersionInfoCard header and gates Settings on `storesReady`; no structural change needed.

## 5. User Settings Frontend

- [x] 5.1 Session-timeout control — the existing `App.vue` `#user-settings` Session section now persists the choice through the new `/api/settings/user` endpoint (was in-memory only) and hydrates from it on load.
- [x] 5.2 Notification toggles — added a Notifications section (notify_shares, notify_requests) to the `#user-settings` slot, each persisting via `/api/settings/user`. The V1 toggles (notify_group_shares, notify_security) are whitelisted server-side but not surfaced in the UI until the sharing/request features land.
- [~] 5.3 User-settings surface is the CnAppRoot `#user-settings` slot in `App.vue` (the app's actual user-preferences dialog), not a separate `UserSettings.vue` NcAppSettingsDialog view (which does not exist in this manifest-v2 app). Default-secret-type / default-view sections are V1 (DEFERRED — no Secret feature yet).

## 6. Settings Store Extension

- [x] 6.1 Extend `src/store/modules/settings.js` — adminSettings/userPreferences state; fetchAdminSettings/saveAdminSettings/fetchUserPreferences/saveUserPreferences actions; passwordPolicy/sessionTimeout/notificationToggles getters (isAdmin/caStatus already derived elsewhere).

## 7. Tests & i18n

- [x] DashboardServiceTest, SettingsServiceTest, UserPreferenceServiceTest, DashboardControllerTest, and extended SettingsControllerTest — 187 unit tests green.
- [x] nl + en translations added for all new user-facing strings (and the pre-existing untranslated admin/session strings in the touched files).
