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

### D1: The server encrypts for members

The backend already builds each member copy, so the form drops the per-member client encryption loop and only sends the group id. This removes the `members` prop nobody supplied.

### D2: Pick, do not type

A group search select replaces the free text field so a typo cannot target the wrong group.
