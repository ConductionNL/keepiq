---
kind: code
---

# A manager role on team folders

## Why

A team folder has one person who can change anything about its membership: the owner. Members are viewers (`read`) or editors (`write`). When the owner is on leave or simply busy, nobody else can add a new colleague, remove a leaver, or promote someone to editor. Every competitor that rates yes lets a shared collection have managers.

| Row | Capability | What Keepiq does today |
|---|---|---|
| sharing-18 | Give people roles such as manager or viewer on a shared collection. | The only role-like concept is a team-folder membership grade of read or write (folder-permission-grades spec, sharing-09); there is no manager or viewer role vocabulary and no collection concept beyond team folders. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. The row record gives no `note`; the text above is its evidence. `openspec/specs/folder-permission-grades/spec.md:82` names a `manage` grade out of scope for v1, a scope boundary of that change and not a non-goal. Every membership action goes through the owner-only guard `TeamFolderQueryService::loadOwnedTeamFolder()` (`lib/Service/TeamFolderQueryService.php:110`), and `setMemberGrade()` accepts only `read` and `write` (`lib/Service/TeamFolderService.php:578`).

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/clients@web-v2026.9.0 libs/common/src/admin-console/models/collections/collection-access-selection.view.ts:9 manage vs :7 readOnly; apps/web/src/app/admin-console/organizations/shared/components/access-selector/access-selector.models.ts:108 permission options; bitwarden/server@v2026.9.1 src/Api/AdminConsole/Controllers/CollectionsController.cs:190 PUT access Note: Manager (manage collection), editor and viewer roles per collection ..."
- 1Password: "https://support.1password.com/create-share-vaults-teams/ : Allow Managing versus Allow Viewing per person or group"
- Passbolt: "passbolt/passbolt_api@v5.16.0 plugins/PassboltCe/Folders/config/routes.php:72 folder permissions read, update, owner; passbolt/passbolt_api@v5.16.0 config/routes.php:155 PUT /groups/{id} group managers vs members ..."
- Keeper: "https://docs.keeper.io/enterprise-guide/sharing/nested-share-subfolders : roles Viewer, Share Manager, Content Manager, Content and Share Manager, Full Manager"

## What Changes

- Team-folder membership gets a third grade, `manage`, next to `read` and `write`. The interface names them Viewer, Editor and Manager.
- A manager can do what an editor can, and can add and remove Viewers and Editors, change a member between Viewer and Editor, approve group joins, and run the fan-out for new members from their own copies.
- Only the owner can grant or revoke Manager, remove a manager, stop sharing the folder, or delete it. A manager can leave.
- The grade ranking becomes `read` < `write` < `manage` along the ancestor chain.
- Every manager action is audited with the manager as actor, and the member list shows who added whom.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `folder-permission-grades`: renames and modifies "Team-folder membership carries a read or write grade" to include `manage` and the manager's authority, modifies the ancestor-chain ranking, and adds requirements for manager actions, owner-only actions and manager fan-out.

## Impact

- **Backend**: a manage-aware guard next to `loadOwnedTeamFolder()` for add, remove, grade, approve-join, reconcile and register-shares; `TeamFolderMember::effectiveGrade()`, `TeamFolderQueryService::resolveGrade()`, `ShareService::listSharesForSecret()` and `ShareSyncService` treat `manage` as at least `write`.
- **Frontend**: `src/modals/TeamFolderDialog.vue` shows Viewer, Editor and Manager and shows member controls to managers.
- **Database**: none. The `grade` column (`STRING(8)`) already fits `manage`; no migration, no `<version>` bump.
- **Security**: no new key material; managers fan out from copies they already hold; the server still never sees plaintext.
- **Cross-app**: none.
