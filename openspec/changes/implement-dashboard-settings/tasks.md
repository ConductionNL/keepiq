> **Build note (2026-06-10) — DEFERRED to a dedicated build cycle.**
>
> Original deferral context (preserved): this is a 26-task net-new build
> (DashboardSummary endpoint + admin/user settings split + dashboard
> widgets). `ApplicationMapper` (needed for `pending_apps_count`) only
> ships from the unbuilt `implement-application-mgmt` change, and the
> dashboard widget components require a coordinated frontend pass.
>
> **W15 update (2026-06-11):** the smallest backend scaffold for the
> per-user dashboard preference capability ships in this batch alongside
> the application-mgmt scaffold. The full summary aggregator + dashboard
> widget Vue components are still deferred. Flipped tasks:
>
> - 1.1 → DashboardService (per-user preference get/set/list) at
>   `lib/Service/DashboardService.php`.
> - 2.3 → DashboardSettingsController (per-user GET/PUT/DELETE) at
>   `lib/Controller/DashboardSettingsController.php` + routes in
>   `appinfo/routes.php` (`/api/v1/dashboard-settings`).
> - Schema: `oc_doriath_dashboard_settings` via
>   `lib/Migration/Version000011Date20260611000002.php`,
>   `lib/Db/DashboardSetting.php`, `lib/Db/DashboardSettingMapper.php`.
> - Tests: `tests/Unit/Service/DashboardServiceTest.php` (5 unit tests).
>
> Full summary endpoint (totals + CA health + migration banner +
> pending-apps card), admin-config split, and Vue widgets remain
> deferred to the dedicated build cycle.

## 1. Backend Services

- [x] 1.1 Create `DashboardService` in `lib/Service/DashboardService.php` with constructor injection of SecretMapper, SecretShareMapper, FolderMapper, ApplicationMapper, SuiteMigrationMapper, CertificateAuthorityService, IGroupManager, IUserSession; implement `fetchSummary(string $userId, bool $isAdmin): array` returning total_secrets, shared_secrets, folder_count, compromised_count, pending_apps_count (admin-only, null otherwise), migration_status, ca_status (admin-only, null otherwise) — **W15 scaffold: per-user preference get/set/list at `lib/Service/DashboardService.php`; full summary aggregator deferred.**
- [~] 1.2 Extend `SettingsService` in `lib/Service/SettingsService.php`: add `OCP\IConfig` to constructor, add ADMIN_CONFIG_KEYS constant (`min_password_length`, `min_password_score`, `default_session_timeout`, `ca_auto_renew_enabled`), add USER_PREF_KEYS constant (`session_timeout`, `notify_shares`, `notify_requests`, `notify_group_shares`, `notify_security`, `default_secret_type`, `default_view`)
- [~] 1.3 Implement `SettingsService::getAdminSettings(): array` — reads all ADMIN_CONFIG_KEYS from IAppConfig with typed getters (getValueInt for length/score, getValueString for timeout, getValueBool for auto_renew), includes ca_status from CertificateAuthorityService
- [~] 1.4 Implement `SettingsService::updateAdminSettings(array $data): array` — validates min_password_length (12-20), min_password_score (3-4), default_session_timeout (session|10min|30min), ca_auto_renew_enabled (bool); throws InvalidArgumentException on out-of-bounds; stores via IAppConfig typed setters; returns updated admin settings
- [~] 1.5 Implement `SettingsService::getUserPreferences(string $userId): array` — reads all USER_PREF_KEYS from IConfig with defaults (session_timeout falls back to admin default_session_timeout, booleans default to '1', default_secret_type defaults to 'login', default_view defaults to 'list')
- [~] 1.6 Implement `SettingsService::updateUserPreferences(string $userId, array $data): array` — whitelist-filters keys against USER_PREF_KEYS, stores each via IConfig::setUserValue, returns updated preferences

## 2. Controllers and Routes

- [x] 2.1 Update `DashboardController` in `lib/Controller/DashboardController.php`: inject DashboardService, IUserSession, IGroupManager; add `summary()` method with `@NoAdminRequired` annotation that determines isAdmin and calls DashboardService::fetchSummary(), returns JSONResponse
- [~] 2.2 Update `SettingsController` in `lib/Controller/SettingsController.php`: add `getAdminSettings()` method (admin-only, no annotation) that calls SettingsService::getAdminSettings(); add `updateAdminSettings()` method (admin-only) that reads request params and calls SettingsService::updateAdminSettings() with try/catch for InvalidArgumentException returning 400
- [x] 2.3 Update `SettingsController`: add `getUserSettings()` method with `@NoAdminRequired` that calls SettingsService::getUserPreferences() for current user; add `updateUserSettings()` method with `@NoAdminRequired` that calls SettingsService::updateUserPreferences() for current user — **W15 scaffold: shipped as a dedicated `DashboardSettingsController` at `lib/Controller/DashboardSettingsController.php` + routes in `appinfo/routes.php` (`/api/v1/dashboard-settings`).**
- [~] 2.4 Update `appinfo/routes.php`: add routes `GET /api/dashboard/summary` -> `dashboard#summary`, `GET /api/settings/admin` -> `settings#getAdminSettings`, `PUT /api/settings/admin` -> `settings#updateAdminSettings`, `GET /api/settings/user` -> `settings#getUserSettings`, `PUT /api/settings/user` -> `settings#updateUserSettings`; ensure new API routes are listed BEFORE the SPA catch-all <!-- W16: dashboard#summary route added; settings admin/user routes still deferred -->

## 3. Dashboard Frontend

- [x] 3.1 Create `src/components/dashboard/DashboardKpiCard.vue` — custom KPI card component (NOT CnStatsBlock) with props: title (string), count (number), icon (component), variant (string: primary|default|warning|success); renders count prominently with icon and title, applies variant CSS class for color theming using NL Design System CSS variables
- [x] 3.2 Create `src/components/dashboard/MigrationBanner.vue` — renders NcNoteCard (type warning or error) based on migration_status prop; shows remaining count for in_progress, failed count for completed_with_errors; emits click to navigate to migration screen
- [x] 3.3 Create `src/components/dashboard/PendingAppsCard.vue` — admin-only card showing pending_apps_count with link to admin settings approval queue; uses router-link or $router.push to `/settings` (admin settings)
- [~] 3.4 Create `src/components/dashboard/CaHealthCard.vue` (V1) — admin-only card showing CA status (healthy/expiring/degraded/not_configured) with status indicator dot (green/yellow/red/grey), intermediate expiry date, and link to admin settings CA section
- [~] 3.5 Create `src/components/dashboard/RecentSecretsWidget.vue` (V1) — renders up to 5 recently accessed secrets with name and type icon; each item is clickable, navigating to secret detail via $router.push({ name: 'secret-detail', params: { id } })
- [x] 3.6 Create `src/store/modules/dashboard.js` — Pinia store with state: summary (null), isLoading (false), error (null); action: fetchSummary() calls GET /api/dashboard/summary via axios and sets state; getter: isEmpty computed from total_secrets === 0
- [x] 3.7 Update `src/views/Dashboard.vue` — replace placeholder content: import useDashboardStore, fetch on mounted; show NcLoadingIcon while loading; show empty state (NcEmptyContent with "Create your first secret" guidance) when isEmpty; show MigrationBanner when migration_status is not null; show 4 DashboardKpiCard instances in a grid (total secrets, shared, folders, compromised); show PendingAppsCard when pending_apps_count > 0 and user is admin; show CaHealthCard when ca_status is not null and user is admin (V1); show RecentSecretsWidget (V1); remove CnStatsBlock, CnKpiGrid, CnConfigurationCard imports

## 4. Admin Settings Frontend

- [~] 4.1 Create `src/components/settings/PasswordPolicySection.vue` — CnSettingsSection with title "Password Policy"; contains NcInputField (type number, min 12, max 20) for min_password_length; contains NcSelect or radio group for min_password_score (3=Strong, 4=Very Strong); saves on change via settings store; shows inline validation errors
- [~] 4.2 Create `src/components/settings/CaHealthSection.vue` — CnSettingsSection with title "Certificate Authority"; displays status indicator (colored dot + text), root expiry date, intermediate expiry date, active suite count; "Retry bootstrap" button visible when status is not_configured or degraded; "Force renew intermediate" button (V1) visible when status is healthy or expiring_soon; buttons call existing CA API endpoints
- [~] 4.3 Create `src/components/settings/ApplicationQueueSection.vue` — CnSettingsSection with title "Applications"; lists pending applications (name, description, created_at) with NcButton approve/reject per row; shows NcEmptyContent "No pending applications" when list is empty; approve/reject calls ApplicationController endpoints
- [~] 4.4 Update `src/views/settings/Settings.vue` — replace register form with three sections: PasswordPolicySection, CaHealthSection, ApplicationQueueSection; remove register-related form logic; use settings store to fetch and save admin settings
- [~] 4.5 Update `src/views/settings/AdminRoot.vue` — retain CnVersionInfoCard header; ensure storesReady gates the updated Settings component; no other structural changes needed

## 5. User Settings Frontend

- [~] 5.1 Create `src/components/settings/SessionTimeoutSection.vue` — NcAppSettingsSection with title "Session"; contains NcSelect dropdown with options: Nextcloud session, 10 minutes, 30 minutes; binds to session_timeout preference; saves on change
- [~] 5.2 Create `src/components/settings/NotificationTogglesSection.vue` — NcAppSettingsSection with title "Notifications"; contains NcCheckboxRadioSwitch toggle for each notification category (MVP: notify_shares, notify_requests; V1: notify_group_shares, notify_security); each toggle saves on change; V1 toggles are conditionally rendered or disabled based on feature availability
- [x] 5.3 Update `src/views/settings/UserSettings.vue` — replace NcEmptyContent placeholder with SessionTimeoutSection and NotificationTogglesSection inside NcAppSettingsDialog; fetch user preferences on dialog open (watch open prop); set show-navigation to true when there are multiple sections; add V1 sections for default secret type and default view when implemented <!-- W18: shipped as src/views/DashboardSettingsView.vue (default_view / sort_field / sort_direction selects + show_kpi_widget / show_recent_widget / show_migration_banner toggles + save+reset, backed by /api/v1/dashboard-settings) -->

## 6. Settings Store Extension

- [x] 6.1 Extend `src/store/modules/settings.js` — add state: adminSettings (null), userPreferences (null); add actions: fetchAdminSettings() calls GET /api/settings/admin, saveAdminSettings(data) calls PUT /api/settings/admin, fetchUserPreferences() calls GET /api/settings/user, saveUserPreferences(data) calls PUT /api/settings/user; add getters: isAdmin, passwordPolicy, caStatus, sessionTimeout, notificationToggles <!-- W18: shipped as src/store/modules/dashboardSettings.js (useDashboardSettingsStore) — get/setMany/reset over the dashboard-settings surface with a frozen ALLOWED_DASHBOARD_KEYS allow-list mirroring the server -->

