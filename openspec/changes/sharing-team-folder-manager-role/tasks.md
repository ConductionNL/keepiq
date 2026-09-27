# Tasks: team-folder manager role

## 1. Grade model

- [ ] 1.1 Accept `manage` in `TeamFolderMember::effectiveGrade()` and `TeamFolderService::setMemberGrade()`, and rank `read` < `write` < `manage` in `TeamFolderQueryService::resolveGrade()`. Verify: PHPUnit for each grade and for an ancestor `manage` above a subfolder `read`.
- [ ] 1.2 Make every write check accept `manage` (`ShareService::listSharesForSecret()`, `ShareSyncService` at the two grade checks). Verify: PHPUnit asserts a manager can run a value update fan-out like an editor.

## 2. Guards

- [ ] 2.1 Add `TeamFolderQueryService::loadManageableTeamFolder()` and switch add, remove, grade, approve-join, reconcile and register-shares to it; keep unshare and delete on the owner guard. Verify: PHPUnit for owner, manager, editor and viewer on each action.
- [ ] 2.2 Refuse a manager setting or clearing `manage`, removing or changing a manager, or touching the owner; allow a manager to remove their own membership. Verify: PHPUnit for each refusal and for self-removal.
- [ ] 2.3 Accept a manager's fan-out rows in `registerFanOutShares()` with the existing subtree and not-the-owner checks. Verify: PHPUnit asserts accepted rows for a manager and refused rows outside the subtree.

## 3. Interface

- [ ] 3.1 Show Viewer, Editor and Manager in `src/modals/TeamFolderDialog.vue`, with member controls for managers limited as in D2 and "Added by" on each member. Verify: vitest renders the owner, manager and viewer views.
- [ ] 3.2 Let a manager's browser run the fan-out from its own copies when adding a member, and report skipped secrets. Verify: vitest with a mocked store asserts the rows come from the manager's copies and skipped ones are listed.
- [ ] 3.3 Add a Playwright flow: the owner makes Olga a manager; Olga adds Bob as a viewer; Bob reads a folder secret; Olga cannot make Bob a manager. Verify: the Playwright spec passes in the E2E job.

## 4. Audit

- [ ] 4.1 Assert that member added, member removed and grade changed events carry the manager as actor. Verify: PHPUnit on the audit metadata.

## Acceptance criteria

- A team-folder membership can be Viewer (`read`), Editor (`write`) or Manager (`manage`).
- A manager can add and remove viewers and editors, change them between the two, approve group joins and run the fan-out for new members.
- Only the owner can grant or revoke Manager, remove a manager, stop sharing the folder or delete it.
- The effective grade along the ancestor chain ranks `manage` above `write` above `read`.
- The server never decrypts anything for a manager action, and every manager action is audited with the manager as actor.
