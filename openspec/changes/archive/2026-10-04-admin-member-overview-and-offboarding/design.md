# Design: admin member overview and complete offboarding

## Context

Read at development `4c214a9d`.

Offboarding today:

- `lib/Controller/TeamFolderController.php:236` `offboard()` is `#[NoAdminRequired]` and hands the session user to the service, which asserts the caller is an instance admin or in `vault_admin` (`lib/Service/TeamFolderOffboardingService.php:139`).
- `lib/Service/TeamFolderOffboardingService.php:83` `offboard()` runs two steps: `revokeTeamSharesForUser()` at line 95, then the owned-secret transfer at line 98. It audits at line 112 and returns `revoked`, `transferred`, `skipped`.
- `lib/Service/TeamFolderShareService.php:232` revokes only share rows that carry a `team_folder_id`. The membership rows are untouched.
- `lib/Db/TeamFolderMemberMapper.php:132` `findUserMemberships()` finds the direct `user` rows of a user; `:115` `findGroupMemberships()` finds group rows.
- `lib/Service/TeamFolderMembershipResolver.php:149` `effectiveUsers()` expands a folder's rows to users; `:174` `eligibleRecipients()` keeps every user with an active suite and ignores whether the Nextcloud account is enabled; `:202` `membershipRowsForUser()` returns direct plus group rows covering a user.
- `lib/Service/TeamFolderService.php:386` `reconcile()` computes missing pairs for every effective user. So a leaver whose direct row survives is re-shared the next time the owner runs the fan-out. This is the defect the matrix records at `TeamFolderOffboardingService.php:95`.
- `lib/Event/Audit/AuditEventTypes.php:266` whitelists `leavingUserId`, `successorUserId`, `revokedCount`, `transferredCount` for `TEAM_FOLDER_OFFBOARDED`.
- `src/components/settings/OffboardingSection.vue:35` to `:49` asks for both user ids as free text.

Vault visibility today:

- `lib/Service/ComplianceReportService.php:107` counts distinct owners of active user suites. `src/components/settings/ComplianceSection.vue:44` prints that count.
- `src/components/settings/AdminSuiteSection.vue:24` asks for a suite id as free text; no screen lists suites.
- `lib/Controller/EncryptionSuiteController.php:89` `index()` lists only the caller's own suites.
- `lib/Db/EncryptionSuiteMapper.php:160` `findActiveByOwners()` already resolves the active suite for a batch of owners in one query.
- Issue #37 (open) asks for a list of users with an active vault for the share picker.

## Goals / Non-Goals

**Goals:**

- Offboarding removes every direct team folder membership of the leaver in the same action.
- The administrator learns which groups still cover the leaver, and a disabled account is never re-shared.
- An administrator sees, per user, whether a vault is set up, and acts on a row without typing ids.

**Non-Goals:**

- Removing the leaver from a Nextcloud group. Group membership belongs to Nextcloud's user management.
- Transferring ownership of team folders the leaver owns. The existing transfer covers owned secrets; folder ownership is unchanged.
- A share picker list for non-admin users (issue #37). That list reveals who uses the vault to every user and needs its own privacy decision.
- Invitations or a "pending" state. Keepiq has no invitation flow; a user without a suite shows as `none`.

## Decisions

### D1: Remove direct membership rows as offboarding step three

After the transfer, the service loads `findUserMemberships(leaver)` and deletes each row, across every team folder. The count is returned as `membershipsRemoved` and written to the audit event.

Step three runs after the transfer so a transfer failure leaves the memberships intact for a re-run. The share revocation already ran in step one, so the order does not widen access in any window.

Alternative considered: call `TeamFolderService::removeMember()` per row. Rejected: that method asserts the caller owns the folder (`TeamFolderService.php:313`) and would revoke shares a second time. The offboarding service already holds the admin authority and has revoked every derived share.

### D2: Report group coverage instead of deleting group rows

A group row covers every member of the group. Deleting it would cut access for colleagues. The service reads the leaver's group rows through `membershipRowsForUser()` and returns them as `stillCoveredByGroups` (team folder id plus group id). The UI shows them as a warning with the advice to remove the leaver from the group or disable the account.

Alternative considered: a per-folder exclusion list for offboarded users. Rejected: a second access list next to Nextcloud groups drifts from them, and it needs a new table.

### D3: The fan-out skips disabled accounts

`eligibleRecipients()` skips a user whose Nextcloud account is disabled (`IUserManager::get()` then `IUser::isEnabled()`). Disabling the account is the standard Nextcloud offboarding step, so a leaver still in a member group gets no new copy from `reconcile()`.

Alternative considered: skip users without an active suite only (today's rule). Rejected: offboarding does not revoke the suite, so the leaver still qualifies.

### D4: Member overview as a paged admin endpoint

`GET /api/v1/admin/members?status=&search=&limit=&offset=` is guarded by `#[AuthorizedAdminSetting(AdminSettings::class)]`. `MemberOverviewService` pages Nextcloud users through `IUserManager::search()`, then resolves per page: active suites through `findActiveByOwners()`, the newest non-active suite status, secret counts with one grouped count on `keepiq_secrets`, direct membership counts, and whether a row exists in `keepiq_emergency_contacts` for the user as grantor. Each row returns `userId`, `displayName`, `enabled`, `vaultStatus`, `activeSuiteId`, `suiteCreatedAt`, `secretCount`, `teamFolderMemberships`, `hasEmergencyContact`.

The path sits under `/api/v1/admin/` so the `admin-public-api` change can document it in the public admin API without a rename.

Alternative considered: extend the compliance metrics with a user list. Rejected: compliance snapshots are immutable evidence and aggregate only (`compliance-reporting` spec, "Org-level metadata-only compliance report"); a per-user list does not belong in a snapshot.

### D5: One admin section drives the existing actions

`MemberOverviewSection.vue` (`CnSettingsSection` plus `CnDataTable`) lists the rows with an `NcSelect` status filter (with `inputLabel`) and a search field. The row action "Offboard" writes the user id into a small shared Pinia store that `OffboardingSection.vue` reads; "Revoke suite" does the same for `AdminSuiteSection.vue` with `activeSuiteId`. The offboarding user fields become user pickers fed by the same endpoint.

## Security and zero-knowledge

- The server never holds plaintext here. The member endpoint returns identifiers, counts, statuses and dates. It never returns a certificate, a private key blob or any ciphertext.
- Stored encrypted versus plain: nothing new is stored. Deleting membership rows removes plain identifiers (`team_folder_id`, `member_type`, `member_id`, `grade`).
- Offboarding stays admin only (`TeamFolderOffboardingService.php:139`). The new endpoint is admin only through the Nextcloud middleware before the controller runs.
- Removing rows narrows access. A leaver who already read a secret still knows it; the offboarding summary keeps pointing at rotation, as `admin-suite-revocation` does for revoked suites.

## Risks / Trade-offs

- Listing every Nextcloud user on a large instance is slow. The endpoint pages (default 50, maximum 200) and resolves suites per page in one query.
- The list tells an administrator who uses the vault. That is the point of the row, and the data was already derivable from the database. It stays admin only.
- A leaver in a member group stays covered until an administrator acts on the warning or disables the account. The summary names the groups so this is never silent.

## Seed data

No new fixture. On the dev instance the seeded `admin` vault (`lib/Repair/SeedDevelopmentData.php:41`) shows as `active`, and every other Nextcloud user shows as `none`. PHPUnit tests build their own users and suites with mocks.

## Migration

None. No new table or column; the change deletes rows from the existing `keepiq_team_folder_members` table and adds a route. `<version>` in `appinfo/info.xml` does not need a bump for schema reasons.
