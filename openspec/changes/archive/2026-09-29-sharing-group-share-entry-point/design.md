# Design: share a secret with a Nextcloud group from the secret sidebar

## Context

At development `156cd800`:

- `lib/Controller/GroupShareController.php` `index`, `create` (`:103`), `destroy` (`:143`), `approveNewMember` (`:179`), `denyNewMember` (`:233`) are routed at `appinfo/routes.php:133-135` and onward.
- `lib/Service/GroupShareService.php:100` `createGroupShare($secretId, $groupId, $userId)` encrypts per member server-side; `:224` `getGroupMembers`; `:250` `handleNewGroupMember`.
- `src/components/share/GroupShareForm.vue` takes a free text group id and a `members` prop no caller supplies, and calls `useShareStore.encryptForRecipient`.
- `src/components/SecretDetailSidebar.vue:680-687` mounts the sharing components for owners and recipients.
- `grep -rn group-shares src browser-extension cli` finds no caller.

## Goals / Non-Goals

**Goals**
- An owner reaches the finished group share backend from the UI.

**Non-Goals**
- Changing the group share crypto.
- Group roles on a share (`sharing-team-folder-manager-role`).

## Decisions

### D1: The browser encrypts for members, the server keeps the link

Corrected at build time (29 Sep). The first draft said the server encrypts per member; it does not and must not. `GroupShareService::createGroupShare()` returns the eligible members with their PUBLIC certificates, and the plaintext never leaves the browser. So `useGroupShareStore.shareWithGroup()` creates the group share, decrypts the owner's copy in the tab, encrypts it once per member, and registers the copies through `POST /api/v1/shares/register-batch` with a `groupShareId` on each row. `DirectShareRegistrar` accepts that id only for a group share of the same source secret. The old `members` prop and the per-member loop in `GroupShareForm.vue` go.

### D1b: Revoke deletes the copies

`revokeGroupShare()` used to delete the ShareTarget rows only, leaving each member's encrypted copy in place. It now revokes every linked share through `ShareRevocationService::revokeShare()`, which deletes the copy and its attachment grants, then deletes the leftovers and the group share row.

### D1c: Nextcloud's share settings apply

The server refuses a group share when Nextcloud has group sharing off, and when sharing is limited to the sharer's own groups and the sharer is not a member. The second refusal reads "Group not found", the same as a missing group, so it does not confirm the group exists. The picker uses Nextcloud's sharee search, which applies the same settings.

### D2: Pick, do not type

A group search select replaces the free text field so a typo cannot target the wrong group.
