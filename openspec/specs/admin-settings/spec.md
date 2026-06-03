# Admin Settings Specification

**Status**: in-progress

**Standards**: OCP\Settings\ISettings, OCP\IAppConfig
**Feature tier**: MVP | V1

**OpenSpec changes:** [implement-dashboard-settings](../../changes/implement-dashboard-settings/)

## Purpose

The Doriath admin settings page provides configuration and monitoring for vault administrators. It covers master password policy enforcement, Certificate Authority health and management, and application approval queue access. The admin settings use `CnSettingsSection` and `CnVersionInfoCard` from `@conduction/nextcloud-vue`.

## Data Model

No new entities. Admin settings use Nextcloud's `IAppConfig` for storage:

| Setting Key | Type | Default | Notes |
|-------------|------|---------|-------|
| `min_password_length` | int | 12 | Range: 12–20 |
| `min_password_score` | int | 3 | Range: 3–4 (zxcvbn score) |
| `default_session_timeout` | string | `session` | Enum: `session`, `10min`, `30min` |
| `ca_auto_renew_enabled` | bool | true | Auto-renew intermediate certificate |

CA status is derived from `CACertificate` entity queries, not from `IAppConfig`.

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for entity definitions.

## Requirements

### Requirement: Version Info Card [MVP]
The admin settings page MUST start with a `CnVersionInfoCard` showing the app name, version, and update status.

#### Scenario: Admin opens settings
- GIVEN the admin navigates to Doriath settings
- WHEN the page loads
- THEN the first section MUST be a `CnVersionInfoCard` with app name "Doriath" and current version

### Requirement: Master Password Policy [MVP]
The admin MUST be able to configure the minimum master password strength requirements.

#### Scenario: Admin raises minimum length
@e2e exclude The settings form is rendered in the admin page, but verifying rejection of a user's password requires running the lock-screen setup flow with a too-short password — cross-page flow that crosses into the encryption-suites spec; covered by PHPUnit SettingsControllerTest.
- GIVEN an admin sets `min_password_length` to 16
- WHEN a user creates or changes their master password
- THEN the system MUST reject passwords shorter than 16 characters

#### Scenario: Admin raises minimum score
@e2e exclude Same cross-page dependency as admin-raises-minimum-length — password rejection is validated server-side; covered by PHPUnit SettingsControllerTest.
- GIVEN an admin sets `min_password_score` to 4
- WHEN a user creates or changes their master password
- THEN the system MUST reject passwords with zxcvbn score below 4

#### Scenario: Cannot lower below app minimum
@e2e exclude Server-side validation of below-minimum values — the settings form API response is tested in PHPUnit; the UI does not surface a separate rejection message panel in v0.1.
- GIVEN the hardcoded app minimum is length 12 and score 3
- WHEN an admin attempts to set values below these minimums
- THEN the system MUST reject the change

### Requirement: CA Health Display [MVP]
The admin settings MUST display the current CA status with health indicator.

#### Scenario: CA healthy
- GIVEN the CA is bootstrapped and no renewal is needed
- WHEN admin views settings
- THEN the CA section MUST show "Healthy" status with root and intermediate expiry dates

#### Scenario: CA not configured
@e2e exclude Requires the CA bootstrap to have failed — cannot reliably put the test environment into a CA-absent state without destroying the running instance's CA; covered by PHPUnit with a mocked CACertificateService.
- GIVEN the CA bootstrap failed
- WHEN admin views settings
- THEN the CA section MUST show "Not configured" with a "Retry bootstrap" button

#### Scenario: CA expiring soon
@e2e exclude Requires the intermediate certificate to be within 30 days of expiry — cannot manipulate certificate timestamps in a running test environment without replacing the DB record; covered by PHPUnit with a mocked CA status.
- GIVEN the intermediate certificate is within 30 days of expiry
- WHEN admin views settings
- THEN the CA section MUST show "Expiring soon" in warning state

### Requirement: CA Management Actions [V1]
The admin MUST be able to perform CA management actions.

#### Scenario: Force intermediate renewal
@e2e exclude The "Force renew intermediate" button is a V1 admin action; triggering it in a live test environment would revoke the running instance's intermediate certificate and re-sign all suites — destructive side-effect not safe in a shared dev environment; covered by PHPUnit CACertificateControllerTest.
- GIVEN the admin clicks "Force renew intermediate"
- WHEN the operation completes
- THEN the old intermediate MUST be revoked
- AND all active EncryptionSuites MUST be re-signed
- AND a confirmation MUST show how many suites were re-signed

#### Scenario: Retry CA bootstrap
@e2e exclude Requires the CA to be in "Not configured" state (see ca-not-configured exclude); covered by PHPUnit CACertificateControllerTest.
- GIVEN the CA is in "Not configured" state
- WHEN the admin clicks "Retry bootstrap"
- THEN the system MUST attempt to generate root + intermediate certificates

### Requirement: Application Approval Queue [MVP]
The admin settings MUST provide access to the application approval queue.

#### Scenario: Pending applications exist
@e2e exclude Requires seeding an Application row with status=pending — no registration UI built in v0.1 and no public test-seed API; covered by PHPUnit.
- GIVEN one or more applications have `status = pending`
- WHEN admin views settings
- THEN the applications section MUST list pending applications with approve/reject actions

#### Scenario: No pending applications
@e2e exclude Application section rendering depends on the approval-queue UI sub-component not yet built in v0.1; the empty state is not rendered at the admin settings page in this version.
- GIVEN no applications are pending
- WHEN admin views settings
- THEN the applications section MUST show an empty state

## User Stories

- As an administrator, I want to set the minimum master password strength so that all users meet our security policy
- As an administrator, I want to see CA health at a glance so that I know when certificates need attention
- As an administrator, I want to force-renew the intermediate certificate if it's compromised
- As an administrator, I want to retry CA bootstrap if it failed during installation
- As an administrator, I want to approve or reject application registrations from the settings page

## Acceptance Criteria

- [ ] Admin settings page starts with CnVersionInfoCard
- [ ] Master password length is configurable (12–20, default 12)
- [ ] Master password score is configurable (3–4, default 3)
- [ ] Values below app minimums are rejected
- [ ] CA health status is displayed with root and intermediate expiry dates
- [ ] CA status shows appropriate state: Healthy, Expiring soon, Action required, Not configured
- [ ] "Not configured" state shows retry bootstrap button
- [ ] Force intermediate renewal button is available (V1)
- [ ] Force renewal revokes old intermediate and re-signs all suites
- [ ] Pending applications are listed with approve/reject actions
- [ ] Empty state shown when no applications are pending
- [ ] All settings are stored via IAppConfig
- [ ] Settings page uses CnSettingsSection for each logical group

## Notes

- Admin settings are registered via `OCP\Settings\ISettings` and `OCP\AppFramework\Http\TemplateResponse`.
- The admin settings page is accessible at `/settings/admin/doriath`.
- Related specs: encryption-suites (CA management), application-mgmt (approval queue)
