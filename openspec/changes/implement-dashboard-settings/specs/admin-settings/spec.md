## MODIFIED Requirements

### Requirement: Admin Settings Page Structure [MVP] — MODIFIED
The existing admin Settings.vue (register form) MUST be replaced with security-focused configuration sections using `CnSettingsSection` from `@conduction/nextcloud-vue`. The AdminRoot.vue CnVersionInfoCard header MUST be retained. The page MUST contain the following sections in order:

1. **Password Policy** — master password minimum length and score configuration
2. **Certificate Authority** — CA health display with status indicator
3. **Applications** — pending application approval queue

Each section MUST use `CnSettingsSection` with appropriate title and description.

#### Scenario: Admin opens the settings page
- GIVEN an admin navigates to the Doriath admin settings
- WHEN the page renders
- THEN the CnVersionInfoCard header MUST be present
- AND the Password Policy, Certificate Authority, and Applications sections MUST appear in that order, each as a `CnSettingsSection`

### Requirement: SettingsController Admin Endpoints — MODIFIED
The existing SettingsController MUST be extended with admin-specific endpoints for reading and writing IAppConfig values. The `create()` method (currently generic) MUST validate admin config bounds (min_password_length: 12-20, min_password_score: 3-4). A new `getUserSettings()` endpoint MUST return per-user settings via IConfig.

#### Scenario: Admin writes config within bounds
- GIVEN an admin POSTs `min_password_length=16` to the SettingsController `create()` endpoint
- WHEN the request is processed
- THEN the value MUST be persisted via IAppConfig and returned in the response

#### Scenario: User reads own settings
- GIVEN an authenticated user
- WHEN the user calls the `getUserSettings()` endpoint
- THEN per-user settings MUST be returned via IConfig

## ADDED Requirements

### Requirement: Master Password Policy Configuration [MVP]
The admin settings MUST provide controls for configuring master password strength requirements stored via IAppConfig.

| Setting | Key | Type | Default | Range | Notes |
|---------|-----|------|---------|-------|-------|
| Minimum length | `min_password_length` | int | 12 | 12–20 | Hardcoded floor of 12 |
| Minimum score | `min_password_score` | int | 3 | 3–4 | zxcvbn score floor of 3 |

#### Scenario: Admin configures minimum length
- GIVEN an admin sets min_password_length to 16 via the slider/input
- WHEN the setting is saved
- THEN IAppConfig MUST store the value
- AND future master password creation/change MUST enforce the new minimum

#### Scenario: Admin attempts value below floor
- GIVEN an admin attempts to set min_password_length to 8
- WHEN the save is attempted
- THEN the backend MUST reject the change with an error response
- AND the UI MUST show a validation error

#### Scenario: Admin sets maximum score
- GIVEN an admin sets min_password_score to 4
- WHEN a user creates a master password with zxcvbn score 3
- THEN the password MUST be rejected as too weak

### Requirement: CA Health Display [MVP]
The admin settings MUST display the current CA status using data from the DashboardService (which calls CertificateAuthorityService.getStatus()). The display MUST show:

- Status indicator: Healthy (green), Expiring Soon (yellow), Degraded (red), Not Configured (grey)
- Root certificate expiry date
- Intermediate certificate expiry date
- Active suite count

#### Scenario: CA healthy
- GIVEN the CA is bootstrapped with valid certificates
- WHEN admin views the CA section
- THEN status MUST show "Healthy" with a green indicator and both expiry dates

#### Scenario: CA not configured
- GIVEN the CA bootstrap failed or has not run
- WHEN admin views the CA section
- THEN status MUST show "Not configured" with a grey indicator
- AND a "Retry bootstrap" button MUST be displayed

#### Scenario: CA degraded
- GIVEN the ca_status app config is set to "degraded"
- WHEN admin views the CA section
- THEN status MUST show "Degraded" with a red indicator and a "Retry bootstrap" button

### Requirement: CA Management Actions [V1]
The admin settings MUST provide management actions for the Certificate Authority.

#### Scenario: Retry bootstrap
- GIVEN the CA is in "Not configured" or "Degraded" state
- WHEN the admin clicks "Retry bootstrap"
- THEN the system MUST call CertificateAuthorityService.retryBootstrap() via the existing CACertificateController
- AND show success/failure feedback

#### Scenario: Force intermediate renewal
- GIVEN the admin clicks "Force renew intermediate"
- WHEN the operation completes
- THEN the system MUST call CertificateAuthorityService.renewIntermediate(forced: true) via the existing CACertificateController
- AND show confirmation with count of re-signed suites

### Requirement: Application Approval Queue [MVP]
The admin settings MUST display a list of applications with `status = pending` and provide approve/reject actions.

#### Scenario: Pending applications exist
- GIVEN 3 applications have status=pending
- WHEN admin views the applications section
- THEN all 3 MUST be listed with application name, description, and created_at
- AND each MUST have "Approve" and "Reject" action buttons

#### Scenario: No pending applications
- GIVEN no applications are in pending status
- WHEN admin views the applications section
- THEN an NcEmptyContent MUST show "No pending applications"

#### Scenario: Admin approves an application
- GIVEN an admin clicks "Approve" on a pending application
- WHEN the operation completes
- THEN the application status MUST change to "approved" via ApplicationController
- AND the application MUST be removed from the pending list

### Requirement: Admin Settings Backend — SettingsService Extension
The SettingsService MUST be extended with methods for admin config management:

- `getAdminSettings(): array` — returns all IAppConfig values (min_password_length, min_password_score, default_session_timeout, ca_auto_renew_enabled) plus CA status
- `updateAdminSettings(array $data): array` — validates and stores admin config with bounds checking
- `getUserPreferences(string $userId): array` — returns per-user IConfig values
- `updateUserPreferences(string $userId, array $data): array` — validates and stores per-user IConfig values

#### Scenario: Bounds validation
- GIVEN updateAdminSettings receives min_password_length=8
- WHEN validation runs
- THEN the method MUST throw an InvalidArgumentException
- AND the existing value MUST NOT be changed
