# Tasks: Team folder sharing

## 1. Data layer

- [ ] 1.1 Migration (`ISchemaWrapper`): `doriath_team_folders` (`id`, `folder_id` FK, `owner_id`, `created_at`, `updated_at`) and `doriath_team_folder_members` (`id`, `team_folder_id` FK, `member_type` enum `user|group`, `member_id`, `added_by`, `created_at`)
- [ ] 1.2 Migration: add nullable `team_folder_id` column (indexed) to the existing share table, parallel to `group_share_id`
- [ ] 1.3 `TeamFolder` + `TeamFolderMember` entities and `TeamFolderMapper` + `TeamFolderMemberMapper` (`QBMapper` pattern matching `GroupShareMapper`/`FolderMapper`)

## 2. Service layer

- [ ] 2.1 `TeamFolderService::shareFolder(folderId, ownerId)` — owner-only; creates the team-folder record; rejects non-owner
- [ ] 2.2 `TeamFolderService::addMember`/`removeMember` — owner-only; expand groups to eligible users; skip members without an active EncryptionSuite silently
- [ ] 2.3 `TeamFolderService::resolveRecipients(secret)` — walk the `Folder` `parent_id` chain and union all ancestor team-folder member sets (nested, union-only)
- [ ] 2.4 Fan-out add-to-folder via `ShareService::createBatchShares` with `team_folder_id`, idempotent upsert on `(source_secret_id, target_user_id, team_folder_id)`; revoke on remove/delete via `ShareService::revokeShare` leaving direct shares intact; reconciliation pass re-derives the expected set on folder open
- [ ] 2.5 `TeamFolderService::offboard(leavingUserId, successorUserId)` — admin-only; revoke leaver's derived shares, then `DelegationService::createDelegation` + `makePermanent` for each team secret the leaver owned

## 3. Membership propagation

- [ ] 3.1 Team-folder branch in `UserAddedToGroupListener`: owner-approval notification per affected secret on join; create the fan-out share only on approval
- [ ] 3.2 Team-folder branch in `UserRemovedFromGroupListener`: auto-revoke the user's `team_folder_id`-derived shares for that folder on leave

## 4. Controllers, routes, notifications, audit

- [ ] 4.1 `TeamFolderController` (`index`, `create`, `members`, `addMember`, `removeMember`, `destroy`, `offboard`) — `#[NoAdminRequired]` with per-object owner/admin guards in the body (satisfy `hydra-gate-no-admin-idor`); register routes in `appinfo/routes.php` under a commented "Team folder sharing" section
- [ ] 4.2 `NotificationService` cases (new-member approval to owner; team-folder-shared to recipient) and typed audit events (share/unshare/member-add/member-remove/offboard) via `OCP\EventDispatcher`, identifiers only — never key material

## 5. Frontend (Vue 2 + WebCrypto)

- [ ] 5.1 Share-folder dialog under `src/modals/` (isolated `.vue`, `NcSelect` with `inputLabel`) + client fan-out routine: per (secret × recipient) decrypt with in-memory `CryptoKey`, re-encrypt under recipient public cert, POST via batch endpoint — chunked, cancellable, resumable with a progress bar
- [ ] 5.2 Team-folder membership panel on the folder view: members list (owner-only full visibility), add/remove, "needs re-share" indicators
- [ ] 5.3 Offboarding admin action in Doriath admin settings (`CnSettingsSection` + `CnVersionInfoCard`): leaving-user + successor pickers, confirm, revoke+transfer summary

## 6. Tests

- [ ] 6.1 Unit: `shareFolder` owner-only guard; group expansion skips suite-less members; `resolveRecipients` union along ancestor chain; add fans out with `team_folder_id`; remove revokes only derived copies; retry upsert is a no-op; leave auto-revokes; join requires approval; offboarding revokes + delegates
- [ ] 6.2 e2e (Playwright): owner shares a folder with a user + a group, adds a secret (recipients see it), removes a member (their copy gone), runs offboarding (leaver's access revoked, owned secret transferred to successor)

## Acceptance criteria

- A folder owner (and only the owner) can share an owned folder with Nextcloud users and groups
- Every secret in a shared folder is shared to all eligible recipients as per-recipient RSA copies — no shared symmetric key
- Adding a secret to a shared folder fans out shares; removing it revokes the derived copies while leaving independent direct shares intact
- Secrets in subfolders inherit the union of all ancestor team-folder memberships; subfolders may widen but not narrow membership in v1
- A user joining a member group triggers owner approval before inheriting; a user leaving auto-loses inherited access
- The admin offboarding action revokes a leaver's inherited access and transfers their owned team secrets via existing permanent-delegation mechanics
- Fan-out server writes are idempotent (retry never double-shares); a partial fan-out self-heals on next folder open
- Team-folder events are audited with identifiers only — never key material or secret plaintext
- The server never holds plaintext secret material at any step (ADR-003 preserved)
