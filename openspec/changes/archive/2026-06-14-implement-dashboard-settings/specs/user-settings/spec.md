## MODIFIED Requirements

### Requirement: User Settings Dialog [MVP] — MODIFIED
The existing placeholder UserSettings.vue (NcEmptyContent "No settings available yet") MUST be replaced with functional preference controls. The dialog MUST retain the NcAppSettingsDialog component (NOT NcDialog) and the gear icon trigger in the app navigation.

#### Scenario: User opens the settings dialog
- GIVEN a user clicks the gear icon trigger in the app navigation
- THEN the NcAppSettingsDialog MUST open with functional preference controls
- AND the placeholder NcEmptyContent MUST NOT be shown

## ADDED Requirements

### Requirement: Session Timeout Preference [MVP]
The user settings dialog MUST provide a session timeout selector with three options stored via `OCP\IConfig::setUserValue()`.

| Option | Value | Behaviour |
|--------|-------|-----------|
| Nextcloud session | `session` | CryptoKey persists until NC session expires |
| 10 minutes | `10min` | CryptoKey cleared after 10 min inactivity |
| 30 minutes | `30min` | CryptoKey cleared after 30 min inactivity |

The default value falls back to the admin-configured `default_session_timeout` IAppConfig value (from admin settings).

#### Scenario: User selects 10-minute timeout
- GIVEN a user selects "10 minutes" in the session timeout dropdown
- WHEN the preference is saved via PUT /api/settings/user
- THEN IConfig MUST store `session_timeout = 10min` for the user
- AND the session store MUST update its timeout interval

#### Scenario: User has no preference set
- GIVEN a user has never configured session_timeout
- WHEN the dialog opens
- THEN the dropdown MUST show the admin default from IAppConfig `default_session_timeout`

### Requirement: Notification Toggles [MVP]
The user settings dialog MUST provide toggles for notification categories. Each toggle controls a set of notification subjects via the NotificationService SUBJECT_SETTING_MAP (from implement-user-sharing).

| Toggle | Setting Key | Default | Tier | Controlled Subjects |
|--------|-------------|---------|------|---------------------|
| Share notifications | `notify_shares` | true | MVP | secret_shared, share_request, share_request_result |
| Request notifications | `notify_requests` | true | MVP | request_fulfilled |
| Group share notifications | `notify_group_shares` | true | V1 | group_member_added |
| Security notifications | `notify_security` | true | V1 | secret_compromised, suite_revoked |

#### Scenario: User disables share notifications
- GIVEN a user toggles notify_shares to false
- WHEN the preference is saved
- THEN IConfig MUST store `notify_shares = false` for the user
- AND the NotificationService MUST skip delivery for subjects secret_shared, share_request, share_request_result

#### Scenario: User re-enables security notifications
- GIVEN a user toggles notify_security back to true
- WHEN the preference is saved
- THEN IConfig MUST store `notify_security = true` for the user

### Requirement: Default Secret Type [V1]
The user settings dialog MUST provide a dropdown to select the default secret type when creating new secrets.

| Option | Value |
|--------|-------|
| Login | `login` |
| API Key | `api_key` |
| SSH Key | `ssh_key` |
| Note | `note` |
| Certificate | `certificate` |

#### Scenario: User sets default to api_key
- GIVEN a user selects "API Key" as the default secret type
- WHEN they create a new secret without specifying a type
- THEN the type selector MUST default to api_key instead of login

### Requirement: Default View Preference [V1]
The user settings dialog MUST provide a toggle or dropdown to choose the default vault display mode.

| Option | Value |
|--------|-------|
| List view | `list` |
| Folder view | `folders` |

#### Scenario: User prefers folder view
- GIVEN a user sets default_view to folders
- WHEN they navigate to the vault
- THEN the system MUST display the folder tree view by default

### Requirement: User Settings API Endpoints
The SettingsController MUST expose endpoints for per-user preferences:

- `GET /api/settings/user` — returns all user preferences (NoAdminRequired)
- `PUT /api/settings/user` — updates user preferences (NoAdminRequired)

Both endpoints MUST use SettingsService.getUserPreferences() and SettingsService.updateUserPreferences() which delegate to IConfig.

#### Scenario: Fetch user preferences
- GIVEN a user calls GET /api/settings/user
- THEN the response MUST include session_timeout, notify_shares, notify_requests, and (V1) notify_group_shares, notify_security, default_secret_type, default_view

#### Scenario: Update user preference
- GIVEN a user calls PUT /api/settings/user with { "session_timeout": "30min" }
- THEN only session_timeout MUST be updated
- AND all other preferences MUST remain unchanged
