## Why

Doriath now has EncryptionSuites, Secrets, and Sharing infrastructure but no way for users to see their vault summary at a glance, no admin settings for password policy or CA health, and no user preferences for session timeout or notification control. The dashboard is the default landing page after unlock — without it, users land on a placeholder page with sample data. Admin settings are critical for enforcing organisational security policy (minimum password strength) and approving application registrations. User settings give individual control over session security and notification noise. These are all MVP-tier blockers for a production-ready encrypted vault.

## What Changes

- Replace the placeholder Dashboard.vue with a vault summary dashboard showing KPI cards (total secrets, shared, folders, compromised count), migration status banner, pending applications counter (admin only), CA health card (admin, V1), recently accessed secrets widget (V1), and empty state for new users
- Extend DashboardController with a summary API endpoint that aggregates counts from existing Secret, SecretShare, Folder, Application, CACertificate, and SuiteMigration mappers
- Create a DashboardService to encapsulate aggregation queries and permission checks
- Extend SettingsController and SettingsService with admin settings endpoints for master password policy (min length 12-20, min score 3-4) via IAppConfig, plus user settings endpoints for session timeout and notification toggles via IConfig
- Replace the placeholder admin Settings.vue with master password policy configuration, CA health display with status indicator, CA management actions (retry bootstrap, force intermediate renewal V1), and application approval queue
- Replace the placeholder UserSettings.vue with session timeout preference, notification toggles (MVP: notify_shares, notify_requests; V1: notify_group_shares, notify_security), default secret type (V1), and default view preference (V1)

## Capabilities

### New Capabilities
- `dashboard`: Personal vault summary with KPI cards, migration status banner, empty state guidance, and admin-only pending applications counter and CA health card
- `admin-password-policy`: Administrator-configurable master password strength requirements (minimum length and zxcvbn score) enforced during password creation and change
- `user-preferences`: Per-user session timeout preference (Nextcloud session / 10 min / 30 min), notification category toggles, default secret type, and default view preference

### Modified Capabilities
- `admin-settings`: The existing admin settings page (CnVersionInfoCard + placeholder register form) is replaced with password policy configuration, CA health display, CA management actions, and application approval queue sections
- `user-settings`: The existing placeholder NcAppSettingsDialog is replaced with functional session timeout, notification, and preference controls

## Impact

- **Database**: No new tables. Dashboard aggregates existing data. Admin settings use IAppConfig. User settings use IConfig.
- **Backend**: New DashboardService for aggregation queries. Extended SettingsController with admin and user setting endpoints (password policy, session timeout, notification toggles). Extended SettingsService with validation logic for admin config bounds and user preference storage.
- **Frontend**: Dashboard.vue rewritten with custom KPI cards (not CnStatsBlock), migration banner component, pending apps counter, CA health card, recently accessed widget, and empty state. AdminRoot.vue / Settings.vue rewritten with CnSettingsSection groups for password policy, CA health, and application queue. UserSettings.vue rewritten with NcAppSettingsDialog sections for session timeout and notification toggles.
- **API**: New GET `/api/dashboard/summary` endpoint. Extended settings endpoints for admin config and user preferences.
- **Dependencies**: Depends on implement-encryption-suites (CA health queries, session timeout, EncryptionSuite status), implement-secrets (Secret counts, folder counts), implement-user-sharing (share counts, NotificationService SUBJECT_SETTING_MAP), implement-secret-requests (request notification preferences).
- **Security**: No encryption changes. Password policy is enforcement-only (validation at suite creation/change). Session timeout controls are per-user convenience settings that clear the client-side CryptoKey.
