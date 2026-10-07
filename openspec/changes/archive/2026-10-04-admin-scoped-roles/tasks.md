## 1. Areas

- [x] 1.1 Add `PolicyAdminSettings`, `ApplicationAdminSettings`, `PeopleAdminSettings` and `AuditAdminSettings` under `lib/Settings/`, each implementing `IDelegatedSettings` with a translated name, the Keepiq section and an area in initial state; give `AdminSettings` its General name. Verify with a PHPUnit test per class for name, section, priority and initial state.
- [x] 1.2 Register the four classes in `appinfo/info.xml` and bump `<version>`; register all five as themselves in `DomainOverrideRegistrar` (done, `AdminAreaSettingsTest`). Verified live 4 Oct on a fresh Nextcloud 35.0.1: "Administration privileges" lists Keepiq - General, Keepiq - Policies, Keepiq - Applications and machine access, Keepiq - People and offboarding, Keepiq - Audit and compliance; a group delegated only Audit sees only the Audit area (see 3.4).
- [x] 1.3 Add `AdminAreaAuthorizer::holds()` on top of `IManager::getAllowedAdminSettings()` with the `vault_admin` alias for People. Verify with a PHPUnit test for admin, delegated user, alias member and outsider.

## 2. Guards

- [x] 2.1 Split `GET/PUT /api/settings/admin` into one route pair per settings area (general, policies, applications, audit), each guarded by its area and writing only its keys (decision of 2 Oct). Move the 14 `#[AuthorizedAdminSetting(AdminSettings::class)]` guards in `SettingsController`, `CACertificateController`, `EncryptionSuiteController` and `AuditController` to their area classes. Verify with the route-auth and semantic-auth hydra gates and one guard test per controller.
- [x] 2.2 Replace the inline `isAdmin()` checks in `ApplicationController`, `ApplicationRequestAdminController`, `LeaseAdminController` and `DashboardController` with the Applications area. Verify with PHPUnit tests where an Applications holder approves an application and an Audit holder is refused.
- [x] 2.3 Replace the inline checks in `ComplianceReportController`, `SiemSinkController` and `HoneyController` with the Audit area, and in `CertificateController` and `SecretTypeController` with General. Verify with PHPUnit guard tests per controller.
- [x] 2.4 Route `TeamFolderOffboardingService`, `DelegationAuthorizer` and `DelegationController::capabilities()` through `holds(PeopleAdminSettings)`. Verify with PHPUnit tests that the capabilities flag and the enforcement agree for all four user kinds.
- [x] 2.5 Replace the admin checks in `ApplicationService` and `SettingsService` with the matching area. Verify with the no-admin-idor and unsafe-auth-resolver hydra gates.

## 3. Frontend

- [x] 3.1 Render only the sections of the mounted area in `Settings.vue`, and the shell in General only. Verify with a vitest per area and the initial-state and admin-router hydra gates.
- [x] 3.2 Switch `AdminHandoverPanel.vue` and the delegation store to `canHandover`. Verify with a vitest in `tests/store/`.
- [x] 3.3 Add an "Admin areas" note in the General area that lists the five areas, links to "Administration privileges", and warns while `vault_admin` has members. Verify with a vitest.
- [x] 3.4 Cover delegation end to end. Verify with a Playwright test in `tests/e2e/workflows/` where a user in a group delegated only the Audit area sees the audit sections and gets 403 from `PUT /api/settings/admin/policies`. `tests/e2e/workflows/admin-area-delegation.spec.ts`: seeds the group, member and Audit-only delegation, checks the audit sections render and no other area mounts, `PUT .../policies` answers 403, an audit write with a policy key answers 400, nothing changes. Red-before/green-after: red (200 instead of 403) with the policies guard pointed at the Audit area, green on development.

## 4. Alias removal

- [x] 4.1 One minor release after 1.2, remove the `vault_admin` alias and its notice. Verify with a PHPUnit test that a `vault_admin` member without a delegation is refused. Moved to keepiq#1043 on 4 Oct 2026: it waits for the next minor release after #962 (decision 4 Oct: outside steps go to follow-up issues).

## Acceptance criteria

- Nextcloud's "Administration privileges" page lists five named Keepiq areas.
- A user in a group delegated only the Audit area can read the audit log and compliance reports and is refused every other Keepiq admin endpoint.
- A user in a group delegated only People can offboard, force-revoke a suite after password confirmation, and use the admin handover panel.
- An instance administrator keeps every Keepiq admin action.
- No Keepiq admin endpoint keeps an inline `isAdmin()` check or the `vault_admin` literal after task 4.1.
