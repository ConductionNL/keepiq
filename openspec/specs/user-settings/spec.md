# User Settings Specification

**Status**: in-progress

**Standards**: OCP\IConfig, NcAppSettingsDialog
**Feature tier**: MVP | V1

**OpenSpec changes:** [implement-dashboard-settings](../../changes/implement-dashboard-settings/)

## Purpose

Doriath user settings allow each user to configure their personal vault preferences: session timeout duration and notification toggles. User settings are accessed via `NcAppSettingsDialog` (the gear icon in the app navigation) and stored using Nextcloud's `OCP\IConfig` for per-user values.

## Data Model

No new entities. User settings use Nextcloud's `OCP\IConfig` for per-user storage:

| Setting Key | Type | Default | Tier | Notes |
|-------------|------|---------|------|-------|
| `session_timeout` | string | (admin default) | MVP | Enum: `session`, `10min`, `30min` |
| `notify_shares` | bool | true | MVP | Notification when a secret is shared with me |
| `notify_requests` | bool | true | MVP | Notification when a secret request is fulfilled |
| `notify_group_shares` | bool | true | V1 | Notification for group share additions |
| `notify_security` | bool | true | V1 | Notification for compromise/security alerts |
| `default_secret_type` | string | `login` | V1 | Default type when creating a new secret |
| `default_view` | string | `list` | V1 | Default vault view (list or folders) |

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the notification event mapping.

## Requirements

### Requirement: User Settings Dialog [MVP]
The app MUST provide user settings via `NcAppSettingsDialog` (NOT `NcDialog`), accessible from the app navigation gear icon.

#### Scenario: User opens settings
- GIVEN a user clicks the gear icon in the Doriath navigation
- WHEN the dialog opens
- THEN the system MUST display `NcAppSettingsDialog` with user preference sections

### Requirement: Session Timeout Preference [MVP]
The user MUST be able to configure how long their master password session stays active.

#### Scenario: User selects 10-minute timeout
@e2e exclude Verifying the AES-key clear after 10 minutes of inactivity requires waiting or fast-forwarding a timer — not reliably testable via Playwright DOM interaction; covered by unit tests of the session-timeout timer logic.
- GIVEN a user sets session timeout to "10 minutes"
- WHEN 10 minutes of inactivity pass
- THEN the AES-derived key MUST be cleared from session
- AND the user MUST be redirected to the lock screen

#### Scenario: User selects Nextcloud session duration
@e2e exclude Verifying that the Doriath session stays active while the NC session is valid requires inspecting the in-memory WebCrypto key lifetime — not DOM-observable; covered by unit tests of the session-timeout guard.
- GIVEN a user sets session timeout to "Nextcloud session"
- WHEN the Nextcloud session is valid
- THEN the Doriath session remains active

### Requirement: Notification Toggles [MVP]
The user MUST be able to toggle notification categories on or off.

#### Scenario: User disables share notifications
@e2e exclude Verifying that the NC notification is suppressed after toggling off requires triggering a share (unbuilt sharing UI) and asserting no bell notification was sent — not a DOM-only Playwright flow; covered by PHPUnit (NotificationService tests).
- GIVEN a user sets `notify_shares` to false
- WHEN another user shares a secret with them
- THEN the system MUST NOT send a notification for this event

#### Scenario: Notification toggle respects categories
@e2e exclude Category-level notification suppression (V1 feature, `notify_group_shares` unbuilt) requires triggering both share types and asserting delivery differences — covered by PHPUnit, not a single-browser Playwright flow.
- GIVEN a user has `notify_shares` enabled but `notify_group_shares` disabled (V1)
- WHEN a direct share is created → notification sent
- AND when a group share addition occurs → notification NOT sent

### Requirement: Default Secret Type [V1]
The user MUST be able to set a default secret type for new secrets.

#### Scenario: User sets default to api_key
@e2e exclude V1 feature — default_secret_type preference depends on the secret-creation UI (unbuilt in v0.1); verified via PHPUnit settings persistence test.
- GIVEN a user sets `default_secret_type` to `api_key`
- WHEN they create a new secret without specifying a type
- THEN the type SHOULD default to `api_key` instead of `login`

### Requirement: Default View Preference [V1]
The user MUST be able to choose between list view and folder tree view as their default vault display.

#### Scenario: User prefers folder view
@e2e exclude V1 feature — folder-view preference depends on the secrets-list/folder-tree UI (unbuilt in v0.1); verified via PHPUnit settings persistence test.
- GIVEN a user sets `default_view` to `folders`
- WHEN they navigate to the vault
- THEN the system SHOULD display the folder tree view by default

## User Stories

- As a user, I want to choose how long my vault session stays active so that I can balance security with convenience
- As a user, I want to control which notifications I receive so that I'm not overwhelmed by alerts
- As a user, I want to set my preferred secret type so that I don't have to change it every time I create a secret
- As a user, I want to choose my default vault view so that I see my secrets the way I prefer

## Acceptance Criteria

- [ ] User settings are accessible via NcAppSettingsDialog (NOT NcDialog) from the gear icon
- [ ] Session timeout is configurable per user: Nextcloud session, 10 minutes, or 30 minutes
- [ ] Session timeout preference is stored via OCP\IConfig
- [ ] Notification toggle for `notify_shares` controls secret shared notifications
- [ ] Notification toggle for `notify_requests` controls request fulfilled notifications
- [ ] Disabling a notification category prevents delivery of matching events
- [ ] Default secret type preference changes the default when creating new secrets (V1)
- [ ] Default view preference controls initial vault display mode (V1)
- [ ] Settings dialog uses NcAppSettingsSection for each logical group
- [ ] Settings are loaded from backend on dialog open and saved immediately on change

## Notes

- `NcAppSettingsDialog` is used (NOT `NcDialog`) per the shared nextcloud-app spec. See `openspec/specs/nextcloud-app/spec.md` for the full pattern.
- Backend integration: settings are read/written via the existing `SettingsController`/`SettingsService` pattern. The service uses `OCP\IConfig::setUserValue()` / `getUserValue()`.
- The `NotificationService` checks user settings via a `SUBJECT_SETTING_MAP` constant that maps notification subject keys to the corresponding user setting keys.
- Related specs: encryption-suites (session timeout behavior), secrets (notification events)
