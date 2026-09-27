---
kind: code
---

# Use-only shares and shares that end by themselves

## Why

Every Keepiq recipient who can use a shared secret can also see and copy it, and every share lasts until the owner revokes it by hand. Organisations want to let a colleague or a temporary worker sign in to a shared account without handing over the password, and to give access for a fixed period, such as a project or a replacement during leave.

| Row | Capability | What Keepiq does today |
|---|---|---|
| sharing-24 | Let a colleague sign in with a shared login without being able to see or copy the password. | Every recipient who can use a secret can also reveal it. |
| sharing-25 | Share an item with a colleague for a set period, after which their access ends by itself. | A share to a colleague lasts until it is revoked by hand. |

Matrix: keepiq `openspec/parity/capabilities.json`

Neither is built. A search for `hidePassword`, `useOnly` or `can_view` in `src` and `lib` finds nothing, and team-folder grades are read or write, both of which reveal (`openspec/specs/folder-permission-grades/spec.md`). `lib/Controller/ShareController.php` has no expiry on a user share; time-bound access exists only for public links (sharing-14) and ownership handover (sharing-11).

Use-only in a zero-knowledge vault is honest only as a client-enforced control. To fill a password, the recipient's own device must decrypt it. This change says so in the product, specifies exactly what the web app, the browser extension and the CLI must refuse, and adds the server-side refusals that are enforceable (no onward sharing, no recipient edits, no version reveal). Expiry, by contrast, is enforced by the server.

### Demand

- sharing-24, tender: https://canadabuys.canada.ca/en/tender-opportunities/25260005
- sharing-25, changelog: https://github.com/bitwarden/clients/pull/22921

### Competitors rated yes

sharing-24:

- Bitwarden: "bitwarden/server@v2026.9.1 src/Api/Models/Request/SelectionReadOnlyRequestModel.cs:11 HidePasswords on collection access; bitwarden/clients@web-v2026.9.0 apps/web/src/app/admin-console/organizations/shared/components/access-selector/access-selector.models.ts:134 ViewExceptPass, :136 EditExceptPass ... The password is still decrypted on the device, so this is a UI control, not a cryptographic one."
- 1Password: "https://support.1password.com/create-share-vaults-teams/ : in 1Password Business a group can use items without revealing or copying passwords when the 'View and Copy Passwords' vault permission is removed; https://support.1password.com/permission-enforcement/ notes this permission is client-enforced."

sharing-25:

- Keeper: "https://docs.keeper.io/enterprise-guide/sharing/time-limited-access : share credentials with other Keeper users 'on a temporary basis, automatically revoking access at a specified time'."
- Nextcloud Passwords: "marius-wieschollek/passwords@2026.9.0 src/vue/Components/Sharing/ShareOptionsForm.vue:55 expires date; src/lib/Controller/Api/ShareApiController.php:162 expires; src/lib/Cron/SynchronizeShares.php:133 deleteExpiredShares() Note: Shares take an expiry date and a background job removes them when it passes."

## What Changes

- A share to a user or group, and a team-folder membership with grade `read`, can be marked use-only. The recipient's copy carries the flag.
- The web app, the extension and the CLI refuse to show, copy, export or edit the value of a use-only copy. The extension still fills it on the matching site. The share dialog tells the owner plainly that use-only is enforced by Keepiq's own apps, not by cryptography.
- The server refuses any onward sharing from a use-only copy, recipient edits, version reveal for the recipient, and link shares; it records each use the extension reports.
- A share to a user or group, and a team-folder membership, can carry an end date. From that moment the server stops serving the copy, and a background job revokes the share or removes the membership through the existing paths. Recipients are warned a day before; owners are told when access ended, with a rotation hint when the recipient could see the value.
- A copy with an end date cannot be shared onward either, so expiry cannot be escaped.

## Capabilities

### New Capabilities

- `use-only-shares`: use-only flag on shares and memberships, client refusals, server refusals, use recording, and the honest client-enforcement statement.
- `expiring-shares`: end dates on shares and memberships, read-path enforcement, background revocation, notifications, and offline handling.

### Modified Capabilities

None. `user-sharing`, `team-folder-sharing` and `folder-permission-grades` keep their requirements; this change adds its own.

## Impact

- **Backend**: `ShareController`, `DirectShareRegistrar`, `GroupShareController` and `TeamFolderMemberController` accept `useOnly` and `expiresAt`; a resolver materialises both onto the recipient copy; read paths filter expired copies; share sources refuse flagged copies; an `ExpireSharesJob`; a `POST /api/v1/secrets/{id}/used` route and audit events; notification subjects for ending access.
- **Frontend**: share and team-folder dialogs get the two options; `PasswordField.vue`, `CopyButton.vue`, `SecretDetailSidebar.vue`, the version history, exports and bulk actions respect use-only; the extension popup and worker; the CLI `show`, `get` and `copy`.
- **Database**: new columns on `keepiq_share_targets`, `keepiq_group_shares`, `keepiq_team_folder_members` and `keepiq_secrets`; a migration and a `<version>` bump.
- **Security**: expiry is server-enforced; use-only is client-enforced and documented as such; no key material changes.
- **Cross-app**: none.
