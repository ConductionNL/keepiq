# Design: admin scoped roles

## Context

Read at development `4c214a9d`.

- `lib/Settings/AdminSettings.php:32` extends OpenRegister's AppHost `GenericAdminSettings`, which implements `IDelegatedSettings` with `getName()` returning null (`openregister/lib/AppHost/Settings/GenericAdminSettings.php:48` and `:123`). Nextcloud can therefore delegate the whole Keepiq section, and nothing smaller.
- `appinfo/info.xml` registers one `<admin>` class and one `<admin-section>`.
- Nextcloud's `SecurityMiddleware` lets a request through `#[AuthorizedAdminSetting]` when the user is an admin or belongs to a group delegated that settings class (`server/lib/private/AppFramework/Middleware/Security/SecurityMiddleware.php:141` to `:156`). The attribute takes one class.
- `OCP\Settings\IManager::getAllowedAdminSettings(string $section, IUser $user)` (since 23) returns the settings a user may see, including delegated ones.
- 14 methods carry `#[AuthorizedAdminSetting(AdminSettings::class)]`: `SettingsController` (6), `CACertificateController` (5), `EncryptionSuiteController` (2, including `forceRevoke()`), `AuditController` (1).
- Inline admin checks with `IGroupManager::isAdmin()` sit in `ApplicationController`, `ApplicationRequestAdminController`, `CertificateController:80`, `ComplianceReportController:69`, `DashboardController:84`, `HoneyController:81`, `LeaseAdminController:152`, `SecretTypeController`, `SiemSinkController:71`, and in `ApplicationService`, `SettingsService` and `TeamFolderOffboardingService:140`.
- `vault_admin` is hard-coded in `lib/Service/DelegationAuthorizer.php:49` (admin handover) and `lib/Service/TeamFolderOffboardingService.php:45` (offboarding). `lib/Controller/DelegationController.php:203` `capabilities()` returns `isVaultAdmin` for `src/components/share/AdminHandoverPanel.vue`.
- `src/views/settings/Settings.vue:16` to `:30` renders every admin section in one list.

## Goals / Non-Goals

**Goals:**

- A person can be given only the Keepiq admin areas they need.
- One authorisation model for endpoint guards, service checks and in-app panels.
- No second role store next to Nextcloud's.

**Non-Goals:**

- Per-action permissions below the area level. Five areas cover the jobs the competitors name (policies, members, audit, applications); an area can be split later without a schema change.
- Scoping a role to a subset of users or groups, like Keeper nodes. Keepiq has no organisational tree.
- A Keepiq-owned role editor. Nextcloud's "Administration privileges" page already edits delegations.

## Decisions

### D1: A role is a Nextcloud group delegated one or more Keepiq areas

Keepiq registers five settings classes, each implementing `IDelegatedSettings` with a translated `getName()`:

| Class | Area | Sections |
|---|---|---|
| `AdminSettings` | General | version, CA health and actions, attachment limits, offline cache, breach check, secret types |
| `PolicyAdminSettings` | Policies | master password, org password, rotation, session timeout, vault policies |
| `ApplicationAdminSettings` | Applications and machine access | application queue, application requests, machine leases |
| `PeopleAdminSettings` | People and offboarding | members, team offboarding, encryption suites, admin handover |
| `AuditAdminSettings` | Audit and compliance | audit log, compliance, SIEM sinks, honey alerts |

`AdminSettings` keeps its class name, so its existing delegations keep meaning something. Each class returns the Keepiq section from `getSection()` and an ascending priority, so a full administrator sees the page in today's order.

Alternative considered: Keepiq role tables with a permission list per role, a role editor and a middleware. Rejected: it duplicates Nextcloud's delegation, and a non-admin role holder could only use it through an in-app admin route, which the hydra admin-router gate forbids.

### D2: Every admin endpoint names one area

Each of the 14 attribute guards changes to its area class. Each inline `isAdmin()` check becomes `AdminAreaAuthorizer::holds($userId, <Area>::class)`, which is true for instance admins and for users whose `getAllowedAdminSettings('keepiq', $user)` contains the class. Examples: `forceRevoke()` and `reinstate()` go to People; `ComplianceReportController` and `SiemSinkController` to Audit; `LeaseAdminController` and the approval routes to Applications.

Alternative considered: keep `AdminSettings` on every endpoint and add a second check in the body. Rejected: two checks per endpoint drift apart, and the semantic-auth gate reads the attribute.

### D3: The admin bundle renders one area per mount

Each settings class provides `area` through `IInitialState` and returns the same template. `Settings.vue` renders only the sections listed for that area. `CnAdminSettingsShell` with the version card renders in the General area only. No DOM data attribute is read, per the initial-state gate.

### D4: `vault_admin` becomes an alias with an end date

`AdminAreaAuthorizer::holds()` also returns true for People when the user is in `vault_admin`. The admin settings show a notice while that group has members, asking the administrator to delegate the People area to a group instead. The alias is removed one minor release later; the removal is its own task.

Alternative considered: a repair step that turns `vault_admin` into a delegation row. Rejected: Nextcloud offers no public API to create delegations, and writing its table directly bypasses its checks.

### D5: The in-app handover asks the same question

`DelegationController::capabilities()` returns `canHandover` from `holds($userId, PeopleAdminSettings::class)`. `DelegationAuthorizer::requireVaultAdmin()` and `TeamFolderOffboardingService::assertOffboardingAdmin()` call the same method, so the button and the enforcement can never disagree.

## Security and zero-knowledge

- No area grants any access to plaintext or keys. Keepiq administration never had it: the server holds no usable private key (ADR-003), and ADR-005 force revocation works without one. That stays true for every area.
- Stored plain: nothing new in Keepiq. Delegations live in Nextcloud's `authorized_groups` table.
- The guard runs in Nextcloud's middleware before any controller body. A delegated user outside an area gets the same refusal a non-admin gets today.
- `#[PasswordConfirmationRequired]` on `forceRevoke()` stays, so a People holder still re-confirms their own password.

## Risks / Trade-offs

- Five areas are coarser than Bitwarden's thirteen flags. The area table is the unit a later change can split.
- An existing delegation of `AdminSettings` shrinks from the whole section to the General area. The release note tells administrators to delegate the other four areas to the same group if they want the old scope.
- Moving 14 guards and the inline checks in 12 files touches many controllers. Each move is small and covered by a guard test.

## Seed data

None. Delegations are made on Nextcloud's own page. PHPUnit tests mock `IManager::getAllowedAdminSettings()`; the Playwright test creates a group and a delegation through `occ` in `tests/e2e/ci-seed.sh`.

## Migration

No table or column. `appinfo/info.xml` gains four `<admin>` entries; bump `<version>` so existing installs pick up the new settings classes on upgrade.
