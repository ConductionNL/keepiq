## 1. Offboarding removes memberships

- [ ] 1.1 Add step three to `TeamFolderOffboardingService::offboard()`: delete every row from `findUserMemberships($leavingUserId)` after the transfer and return `membershipsRemoved`. Verify with a PHPUnit test in `tests/Unit/Service/TeamFolderOffboardingServiceTest.php` that asserts the rows are deleted and group rows are kept.
- [ ] 1.2 Return `stillCoveredByGroups` (team folder id and group id per remaining group row from `membershipRowsForUser()`). Verify with a PHPUnit test for a leaver covered by one direct row and one group row.
- [ ] 1.3 Widen the `TEAM_FOLDER_OFFBOARDED` whitelist in `lib/Event/Audit/AuditEventTypes.php` with `membershipsRemovedCount` and `coveringGroupIds`, and emit them from `TeamFolderAuditor::offboarded()`. Verify with a PHPUnit test on the dispatched metadata.
- [ ] 1.4 Make `TeamFolderMembershipResolver::eligibleRecipients()` skip disabled Nextcloud accounts. Verify with a PHPUnit test where a disabled user with an active suite is absent from `reconcile()` missing pairs.
- [ ] 1.5 Show `membershipsRemoved` and the covering groups in the `OffboardingSection.vue` summary. Verify with a vitest for the summary text in `tests/components/`.

## 2. Member overview endpoint

- [ ] 2.1 Add `MemberOverviewService` that pages users through `IUserManager::search()` and resolves suite status, secret count, membership count and emergency contact per page. Verify with a PHPUnit test that one page issues one suite query through `findActiveByOwners()`.
- [ ] 2.2 Add `MemberOverviewController::index()` with `#[AuthorizedAdminSetting(AdminSettings::class)]` and register `GET /api/v1/admin/members` in `appinfo/routes.php` before the SPA catch-all. Verify with a PHPUnit test for status and search filters and the route-auth and route-reachability hydra gates.
- [ ] 2.3 Assert that no row carries `certificate`, `privateKey` or any ciphertext field. Verify with a PHPUnit test over the serialized rows.

## 3. Admin UI

- [ ] 3.1 Add `MemberOverviewSection.vue` (`CnSettingsSection`, `CnDataTable`, `NcSelect` status filter with `inputLabel`, search) and mount it in `src/views/settings/Settings.vue`. Verify with a vitest in `tests/components/` for filter and paging.
- [ ] 3.2 Add the row actions "Offboard" and "Revoke suite" that prefill `OffboardingSection.vue` and `AdminSuiteSection.vue` through a shared store. Verify with a vitest that the prefilled values reach both sections.
- [ ] 3.3 Replace the two free-text user id fields in `OffboardingSection.vue` with user pickers fed by the member endpoint. Verify with a vitest and the nc-input-labels hydra gate.
- [ ] 3.4 Cover the flow end to end. Verify with a Playwright test in `tests/e2e/workflows/` where an administrator filters on `none`, then offboards a user from the list and sees the removed membership count.

## Acceptance criteria

- Offboarding a leaver with a direct team folder membership leaves no `user` row for them in `keepiq_team_folder_members`.
- Group rows that cover the leaver stay in place and are named in the offboarding result.
- A disabled Nextcloud account never appears in the missing pairs of a team folder reconcile.
- `GET /api/v1/admin/members` returns each user's vault status and active suite id to an administrator, and refuses a non-administrator.
- No response of the member endpoint contains a certificate, private key blob or ciphertext.
- An administrator can start offboarding and suite revocation from a list row without typing an id.
