## Context

Doriath is an encrypted secrets manager for Nextcloud. After implement-encryption-suites, implement-secrets, implement-user-sharing, and implement-secret-requests, the app has a full encryption layer, secrets CRUD, sharing, and request infrastructure. However, the dashboard is a placeholder with sample KPIs, admin settings only have an OpenRegister register ID field, and user settings show "No settings available yet."

The existing codebase includes: DashboardController (shell returning TemplateResponse), SettingsController (index/create/load for register config), SettingsService (register key management), AdminSettings ISettings implementation, Dashboard.vue (CnStatsBlock sample), AdminRoot.vue (CnVersionInfoCard + Settings.vue), Settings.vue (register form with CnSettingsSection), UserSettings.vue (NcAppSettingsDialog placeholder with NcEmptyContent). Routes serve `GET /`, `GET/POST /api/settings`, and `POST /api/settings/load`.

Entities available from dependency changes: Secret, SecretType, Folder, SecretShare, GroupShare, SecretDelegation, EncryptionSuite, CACertificate, SuiteMigration, Application. Services: SecretService, EncryptionSuiteService, CertificateAuthorityService, MigrationService, ShareService, NotificationService, ApplicationService.

## Goals / Non-Goals

**Goals:**
- Implement a vault summary dashboard with KPI cards, migration banner, empty state, and admin-only sections
- Implement admin settings for master password policy, CA health display, CA management actions, and application approval queue
- Implement user settings for session timeout, notification toggles, and V1 preferences
- Expose a dashboard summary API and user settings API
- Use IAppConfig for admin settings and IConfig for user settings (no new database tables)

**Non-Goals:**
- Dashboard widgets for Nextcloud's native dashboard system (Doriath's dashboard is in-app only)
- Secrets search from the dashboard (search is in the secrets list view)
- Bulk admin operations (bulk approve applications)
- Admin audit log of setting changes (Enterprise tier)
- Custom notification sound/vibration settings

## Decisions

### D1: DashboardService for Aggregation Queries

Create a new DashboardService that aggregates data from existing mappers (SecretMapper, SecretShareMapper, FolderMapper, ApplicationMapper, SuiteMigrationMapper) and the CertificateAuthorityService. The DashboardController delegates to this service.

**Why:** Controller -> Service layering per ADR-008. The aggregation logic involves multiple mapper queries and permission checks (admin-only fields) that do not belong in the controller. A dedicated service keeps the DashboardController thin and makes the aggregation testable.

**Alternatives considered:**
- Inline queries in DashboardController: Rejected — violates ADR-008 and makes testing harder.
- Reuse SettingsService: Rejected — dashboard aggregation is conceptually separate from settings management.

### D2: Custom KPI Cards Instead of CnStatsBlock

The dashboard uses custom Vue components for KPI cards instead of `CnStatsBlock` from `@conduction/nextcloud-vue`.

**Why:** Per the dashboard spec note, Doriath implements its own KPI cards to match the security-focused design language. CnStatsBlock is designed for generic stat display; Doriath's cards need security-specific variants (warning color for compromised count, conditional highlighting).

The custom component (`DashboardKpiCard.vue`) accepts title, count, icon, and variant props. Variants: `primary`, `default`, `warning`, `success`.

### D3: SettingsService Extension for Admin and User Config

Extend the existing SettingsService (not create a new service) with admin config (IAppConfig) and user preference (IConfig) methods. The CONFIG_KEYS array is expanded. Admin settings use `IAppConfig::setValueInt()` / `setValueString()`. User settings use `OCP\IConfig::setUserValue()` / `getUserValue()`.

**Why:** SettingsService already owns the settings domain. Adding a second settings service would split the concern. The existing `getSettings()` / `updateSettings()` methods handle the register key — they are extended (not replaced) to also handle admin config and user preferences.

The service constructor adds `OCP\IConfig $config` for per-user values (IAppConfig is already injected).

### D4: Admin Config Validation with Hardcoded Floors

Admin-configurable values have hardcoded minimum bounds that cannot be lowered:
- `min_password_length`: floor 12, ceiling 20
- `min_password_score`: floor 3, ceiling 4

Validation runs in SettingsService.updateAdminSettings(). Out-of-bounds values throw `\InvalidArgumentException`. The frontend also validates before submission (dual validation).

**Why:** The password policy minimums are security-critical. Even an admin should not be able to set a minimum length below 12 or a score below 3 (these represent the app's own security baseline). The narrow range (12-20 for length, 3-4 for score) is intentional — it prevents misconfiguration while allowing organisational policy to be stricter.

### D5: User Preferences Stored via IConfig with Setting Key Whitelist

User preferences are stored via `OCP\IConfig::setUserValue(Application::APP_ID, $key, $value)`. The service enforces a whitelist of allowed keys:
- MVP: `session_timeout`, `notify_shares`, `notify_requests`
- V1: `notify_group_shares`, `notify_security`, `default_secret_type`, `default_view`

Any key not in the whitelist is silently ignored. Boolean toggles are stored as `'1'` / `'0'` strings (IConfig stores strings).

**Why:** IConfig is the standard Nextcloud mechanism for per-user preferences. A whitelist prevents arbitrary key injection. String storage of booleans matches Nextcloud conventions.

### D6: Dashboard Route and API Route Registration

The existing `GET /` route serves the SPA. A new API route `GET /api/dashboard/summary` returns JSON. User settings get `GET /api/settings/user` and `PUT /api/settings/user`. All new routes are registered in `appinfo/routes.php`.

**Why:** The dashboard view is already routed via Vue Router (hash mode `/#/`). The API endpoint provides data to the Pinia store. User settings endpoints are separate from admin settings to enforce different auth requirements.

### D7: Pinia Store for Dashboard Data

A new `useDashboardStore` Pinia store fetches and caches the summary response. It exposes `isLoading`, `summary`, and `error` reactive state. The store is fetched on Dashboard.vue mount via an `async fetchSummary()` action.

**Why:** Pinia stores are the established pattern in Doriath for data management. The dashboard store is lightweight (single fetch, no mutations to existing data). Caching avoids refetching on tab switches within the same session.

## Seed Data

No seed data applies to this change. The dashboard reads from existing data seeded by implement-encryption-suites, implement-secrets, implement-user-sharing, and implement-secret-requests. Admin settings have defaults set in the InitializeSettings repair step (already updated by implement-encryption-suites). User preferences default to IConfig fallback values in code.

## File Map

### Backend (PHP)

| File | Action | Description |
|------|--------|-------------|
| `lib/Service/DashboardService.php` | Create | Aggregation service: fetchSummary(userId, isAdmin) |
| `lib/Controller/DashboardController.php` | Update | Add summary() API endpoint |
| `lib/Service/SettingsService.php` | Update | Add getAdminSettings(), updateAdminSettings(), getUserPreferences(), updateUserPreferences() |
| `lib/Controller/SettingsController.php` | Update | Add getUserSettings(), updateUserSettings() endpoints |
| `appinfo/routes.php` | Update | Add /api/dashboard/summary, /api/settings/user routes |

### Frontend (Vue/JS)

| File | Action | Description |
|------|--------|-------------|
| `src/views/Dashboard.vue` | Update | Replace placeholder with vault summary dashboard |
| `src/views/settings/Settings.vue` | Update | Replace register form with admin config sections |
| `src/views/settings/UserSettings.vue` | Update | Replace placeholder with functional preferences |
| `src/views/settings/AdminRoot.vue` | Update | Minor: ensure storesReady gates new admin sections |
| `src/components/dashboard/DashboardKpiCard.vue` | Create | Custom KPI card component |
| `src/components/dashboard/MigrationBanner.vue` | Create | Migration status NcNoteCard banner |
| `src/components/dashboard/PendingAppsCard.vue` | Create | Admin-only pending applications card |
| `src/components/dashboard/CaHealthCard.vue` | Create | Admin-only CA health status card (V1) |
| `src/components/dashboard/RecentSecretsWidget.vue` | Create | Recently accessed secrets list (V1) |
| `src/components/settings/PasswordPolicySection.vue` | Create | Admin password policy config section |
| `src/components/settings/CaHealthSection.vue` | Create | Admin CA health display section |
| `src/components/settings/ApplicationQueueSection.vue` | Create | Admin application approval queue section |
| `src/components/settings/SessionTimeoutSection.vue` | Create | User session timeout dropdown section |
| `src/components/settings/NotificationTogglesSection.vue` | Create | User notification toggle section |
| `src/store/modules/dashboard.js` | Create | Pinia store for dashboard summary data |
