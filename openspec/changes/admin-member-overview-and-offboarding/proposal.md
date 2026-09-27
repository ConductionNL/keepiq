---
kind: code
---

# Admin member overview and complete offboarding

## Why

An administrator cannot see which users have set up a vault, and offboarding a leaver leaves their own team folder member rows behind. This change specifies the missing half of two partial rows.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-04 | Remove a leaving user from every team folder in one step | One action revokes every team-folder-derived share and hands owned team secrets to a successor. It does not delete the user's team-folder member rows, so a direct user membership survives. |
| admin-12 | See which users have set up a vault | Admins see how many users have an active vault, not which ones. The encryption-suite admin section needs a suite id typed in by hand. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden (admin-04): "bitwarden/server@v2026.9.1 src/Api/AdminConsole/Controllers/OrganizationUsersController.cs:579 DELETE organizations/{orgId}/users/{id}, :605 POST remove (bulk), :669 revoke. Note: Removing or revoking a member drops every collection and group access in one step."
- 1Password (admin-04): "https://support.1password.com/offboarding/ : suspend or remove an offboarded team member, removing all vault access"
- Passbolt (admin-04): "passbolt/passbolt_api@v5.16.0 src/Model/Table/UsersTable.php:458 softDelete: :522 GroupsUsers deleteAll for the user, :523 Permissions deleteAll for the user, folder relations removed; ... Note: Deleting a leaving user removes every permission, folder relation and group membership in one action, after sole-owned items are transferred ..."
- Keeper (admin-04): "https://docs.keeper.io/enterprise-guide/user-management-and-lifecycle : Delete User removes the user 'from all Roles, Nodes and Teams'; Lock Account or SCIM/AD Bridge suspension blocks access while Account Transfer keeps the records"
- HashiCorp Vault (admin-04): "hashicorp/vault@v2.1.1 ui/app/models/identity/entity.js:15 entity fields name, disabled, policies, metadata (disable or delete in Access > Entities); vault/identity_store_util.go:3164 external groups dropped on next login ..."
- Bitwarden (admin-12): "bitwarden/server@v2026.9.1 src/Core/AdminConsole/Enums/OrganizationUserStatusType.cs:15 Invited, :19 Accepted, :24 Confirmed, :30 Staged; bitwarden/clients@web-v2026.9.0 apps/web/src/app/admin-console/organizations/members/ members list with status filter ..."
- 1Password (admin-12): "https://support.1password.com/add-remove-team-members/ : People list shows invited, pending confirmation and active members"
- Passbolt (admin-12): "passbolt/passbolt_styleguide@v5.16.0 src/react-extension/components/User/DisplayUsers/DisplayUsers.js:470 isRowInactive greys out users who have not completed setup; ... Note: The users workspace shows which invited users have not activated their account yet ..."
- Keeper (admin-12): "https://docs.keeper.io/enterprise-guide/user-management-and-lifecycle : user status Invited ('has not completed their account setup yet'), Active, Locked"

### Missing halves

- admin-04 is partial. Built: `TeamFolderOffboardingService::offboard()` revokes every team-folder-derived share and transfers owned team secrets to a successor. Missing: offboarding also removes the leaver's own team folder member rows.
- admin-12 is partial. Built: the adoption count in the compliance section. Missing: a list of which users have set up a vault.

## What Changes

- Offboarding gains a third step: after revoking derived shares and transferring owned team secrets, it deletes every direct `user` membership row of the leaver (`keepiq_team_folder_members`, `member_type = user`).
- A group membership row is never deleted, because it covers other people. The offboarding result names every team folder group that still covers the leaver, so the administrator can remove them from that Nextcloud group.
- The team folder fan-out skips a recipient whose Nextcloud account is disabled. A disabled leaver who still sits in a member group is never re-shared by a later reconcile.
- The offboarding audit event records the number of removed membership rows and the covering groups.
- A new admin endpoint `GET /api/v1/admin/members` lists every Nextcloud user with their vault status (`none`, `active`, `revoked`, `compromised`), active suite id, secret count, team folder membership count and whether an emergency contact is set. Metadata only.
- A new admin settings section "Members" shows that list with a status filter and search. Each row offers "Offboard" and "Revoke suite", which prefill the existing offboarding and encryption suite sections. The suite id no longer has to be typed by hand.

## Capabilities

### New Capabilities

- `admin-member-overview`: an administrator lists which users have set up a vault, filters by vault status, and starts offboarding or suite revocation from the list.

### Modified Capabilities

- `team-folder-sharing`: offboarding removes the leaver's direct team folder memberships, reports remaining group coverage, and the fan-out never re-shares to a disabled account.

## Impact

- **Backend**: `TeamFolderOffboardingService` gains the membership-removal step; `TeamFolderMembershipResolver::eligibleRecipients()` skips disabled accounts; a new `MemberOverviewService` and `MemberOverviewController` serve `GET /api/v1/admin/members`; the `TEAM_FOLDER_OFFBOARDED` audit whitelist gains two keys.
- **Frontend**: a new `MemberOverviewSection.vue` in the admin settings; `OffboardingSection.vue` and `AdminSuiteSection.vue` accept a prefilled user or suite from the list.
- **Database**: none. Rows are deleted from the existing `keepiq_team_folder_members` table; no new column or table.
- **Security**: the list endpoint is admin only and returns metadata only, never a certificate, private key blob or ciphertext. Removing membership rows narrows access; it never widens it.
- **Cross-app**: none. Issue #37 asks for a list of vault users for the share picker; this change serves the administrator list only, and the share picker variant stays with #37.
