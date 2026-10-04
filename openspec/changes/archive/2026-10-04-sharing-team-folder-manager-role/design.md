# Design: team-folder manager role

## Context

Code at development `4c214a9d`:

- `lib/Service/TeamFolderQueryService.php:110` `loadOwnedTeamFolder()` refuses anyone but the owner. It guards `unshareFolder` (`lib/Service/TeamFolderService.php:149`), `addMember` (`:222`), `removeMember` (`:314`), `reconcile` (`:387`), `registerFanOutShares` (`:424`), `approveJoin` (`:497`) and `setMemberGrade` (`:582`).
- `setMemberGrade()` accepts `read` or `write` only (`:578`).
- `lib/Db/TeamFolderMember.php:144` `effectiveGrade()` returns `write` for `write` and `read` for anything else, so a stored `manage` would silently act as `read` today.
- `lib/Service/TeamFolderQueryService.php:256` `resolveGrade()` walks the ancestor chain and returns `write` at the first write grant, else `read`.
- Write-grade checks: `lib/Service/ShareService.php:312` (`!== 'write'` hides the recipient list), `lib/Service/ShareSyncService.php:174` and `:211` (`write` or `owner`).
- `addMember()` returns the new users' certificates and the subtree's secret refs; the caller's browser encrypts every secret for every new user and posts them to `registerFanOutShares()`, which accepts rows only for secrets in the subtree and never for the owner (`lib/Service/TeamFolderShareService.php:278` to `:281`). A member already holds a recipient copy of each folder secret (`TeamFolderShareService.php:295`).
- The `team_folder_members.grade` column is `STRING(8)`, default `read` (`lib/Migration/Version001000Date20260908000000.php:732`).
- `src/modals/TeamFolderDialog.vue:65` to `:74` renders the grade select (Read, Write) for the owner only.

## Goals / Non-Goals

**Goals:**

- A team folder can have managers who keep its membership current without the owner.
- Viewer, Editor and Manager as the words people see.
- The owner keeps the last word: only the owner makes or unmakes managers and ends the folder.

**Non-Goals:**

- A manager adding their own secrets to the owner's folder. Secrets in a team folder stay owned by the folder owner; moving a secret in stays an owner action.
- Ownership transfer. The existing handover and offboarding paths cover it.
- Per-secret roles, and narrowing a subfolder's grade below an ancestor's (still out of scope, as in the existing spec).
- Managers on user shares or group shares outside team folders.

## Decisions

### D1: `manage` is a grade, ranked above `write`

The membership `grade` takes `read`, `write` or `manage`. `effectiveGrade()` returns the stored value when it is one of the three and `read` otherwise. `resolveGrade()` returns the highest along the ancestor chain with the ranking `read` < `write` < `manage`, stopping early only at `manage`. Every check that asks for `write` accepts `manage` too.

Alternative considered: a separate `is_manager` flag beside the grade. Rejected: a manager must also be able to edit, so a flag would allow the meaningless pair "read plus manager" and need a second ranking rule.

### D2: One guard for manageable folders

`TeamFolderQueryService::loadManageableTeamFolder(teamFolderId, userId)` returns the folder when the caller is the owner or has an effective `manage` grade on it (through its own membership or an ancestor's, per D1). `addMember`, `removeMember`, `setMemberGrade`, `approveJoin`, `reconcile` and `registerFanOutShares` switch to it. `unshareFolder` and team-folder deletion keep `loadOwnedTeamFolder()`.

Within that guard, a manager who is not the owner is refused when they try to: set or clear `manage` on anyone, remove or change a member whose grade is `manage`, or touch the owner. A manager may remove their own membership.

### D3: Managers fan out from their own copies

When a manager adds a member, `addMember()` returns the same recipients and secret refs it returns the owner. The manager's browser decrypts its own recipient copy of each folder secret with its own key and encrypts for each new user; `registerFanOutShares()` accepts the rows under the manage guard with the existing subtree and not-the-owner checks. A secret the manager holds no copy of (for example added after the manager joined and not yet fanned out to them) is skipped and appears in the owner's reconcile as missing, which the owner fills as today.

### D4: Attribution and visibility

`memberAdded`, member removal and `gradeChanged` audit events already carry an `actorId`; with managers acting, the actor is the manager. The member list already stores `added_by`; the dialog shows it ("Added by Olga"). No extra notification to the owner in this change; the audit trail and the list are the record.

### D5: Words in the interface

`TeamFolderDialog.vue` shows Viewer, Editor and Manager. The owner sees all three options; a manager sees Viewer and Editor and sees Manager rows as read-only. A viewer or editor sees no member controls.

## Security and zero-knowledge

No new key material, no new ciphertext type. A manager's fan-out is the same operation the owner performs today: decrypt in the browser with the caller's own key, encrypt for recipient certificates, post ciphertext (ADR-003). The server authorizes on grade metadata only and never decrypts.

What a manager gains is authority, not keys: they could already read every folder secret as a member. The new risk is a manager adding someone the owner would not; D2 keeps the owner above every manager, and D4 records who did what.

Stored in plain, as today: membership rows with `grade` and `added_by`. Nothing stored encrypted changes.

## Risks / Trade-offs

- **A manager can add a member who then reads every folder secret.** That is the point of the role; the owner chooses managers, and the audit trail names the manager.
- **Fan-out gaps when a manager lacks a copy.** Reported through the owner's reconcile rather than failing the add.
- **An old client that sends only `read` and `write`** keeps working; an older server build that sees `manage` would treat it as `read` (D1's context), so this change ships server and client together.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests create a team folder with an owner, a manager, an editor and a viewer.

## Migration

None. The `grade` column already holds up to eight characters, so `manage` fits; no table or column changes and no `<version>` bump. When this change is archived, the note at `openspec/specs/folder-permission-grades/spec.md:82` that calls `manage` out of scope must be updated.
