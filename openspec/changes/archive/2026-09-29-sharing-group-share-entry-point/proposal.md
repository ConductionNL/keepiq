---
kind: code
---

# Share a secret with a Nextcloud group from the secret sidebar

## Why

A user cannot share a secret with a Nextcloud group today. The backend is finished: `POST /api/v1/secrets/{secretId}/group-shares` (`appinfo/routes.php:134`) and `GroupShareService::createGroupShare()` (`lib/Service/GroupShareService.php:100`). The form `src/components/share/GroupShareForm.vue` is registered but has no opener, and its submit path calls the per-user store rather than the group route. Five competitors rate yes. It is a share-path completion, the same class as the shipped user sharing.

One row, one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `sharing-02` | Share a secret with a Nextcloud group. | `no`: `no`: `GroupShareController` and `GroupShareService` are complete, but nothing in the app opens the group share form or calls the group-share routes |

### Demand

- `sharing-02`: no demand row.

### Competitors rated yes

- `sharing-02`, bitwarden: "bitwarden/server@v2026.9.1 src/Api/AdminConsole/Controllers/GroupsController.cs:23 organizations/{orgId}/groups, :123 POST, :147 PUT with collection access; bitwarden/clients@web-v2026.9.0 apps/web/src/app/admin-console/organizati"
- `sharing-02`, onepassword: "https://support.1password.com/custom-groups/ : 'give everyone in a group access to specific vaults and assign vault permissions'"
- `sharing-02`, passbolt: "passbolt/passbolt_api@v5.16.0 src/Controller/Share/ShareController.php:101 share (groups are AROs); passbolt/passbolt_styleguide@v5.16.0 src/react-extension/components/Share/GroupPermissionItem.js, ShareDialog.js:515 Note: Groups "
- `sharing-02`, keeper: "https://docs.keeper.io/enterprise-guide/teams : 'Teams can be added to Shared Folders in the vault', teams provisioned from the IdP via SCIM or AD Bridge"
- `sharing-02`, hashicorp-vault: "hashicorp/vault@v2.1.1 ui/app/models/identity/group.js:15 fields name, type, policies, metadata (internal or external group); ui/app/router.js access.identity create/edit routes; vault/identity_store_util.go:3164 refreshExternalGr"

## What Changes

- Add a Share with group action to the sharing section of the secret sidebar, next to Share with user.
- Rework `GroupShareForm.vue` to pick a group (search over the caller's Nextcloud groups) and to call a `useGroupShareStore` action that posts to the group-share route.
- List the group shares of a secret with a revoke action, using `GET /api/v1/secrets/{secretId}/group-shares` and `DELETE /api/v1/group-shares/{id}`.

## Capabilities

### New Capabilities

- `sharing-group`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `SecretDetailSidebar.vue`, `GroupShareForm.vue`, a new `src/store/modules/groupShare.js`.
- **Backend**: a group search endpoint limited to the caller's visible groups if none exists; the group-share routes are unchanged.
- **Database**: none.
- **Security**: the group id comes from a picker but the server already validates membership visibility; the change adds a test that a user cannot share into a group they cannot see.
