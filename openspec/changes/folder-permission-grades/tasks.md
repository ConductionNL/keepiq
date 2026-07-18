# Tasks: Folder permission grades

## 1. Data layer

- [x] 1.1 Migration (`ISchemaWrapper`): add nullable `grade` column (enum stored as string `read|write`, default `read`) to `doriath_team_folder_members`; backfill existing rows to `read`
- [x] 1.2 Add `grade` to the `TeamFolderMember` entity + mapper (getter/setter, `addType` string), default `read`

## 2. Service layer

- [x] 2.1 `TeamFolderService::setMemberGrade(teamFolderId, memberId, grade, ownerId)` — owner-only guard; reject non-owner (403); reject invalid grade; no ciphertext touched
- [x] 2.2 `TeamFolderService::resolveGrade(secret, userId)` — walk the `Folder` `parent_id` ancestor chain, expand groups, return the MAX grade any ancestor membership grants the user (`write` outranks `read`), or none
- [x] 2.3 Extend the sync authorization seam: replace `ShareService::syncUpdate`'s `assertOwnerOrDelegate` call with an owner/delegate-OR-`write`-grade check that consults `resolveGrade`; keep the existing `ForbiddenException` for everyone else; per-recipient loop and optimistic lock unchanged

## 3. Controller, routes, audit

- [x] 3.1 `TeamFolderController::setMemberGrade` for `PATCH /api/v1/team-folders/{id}/members/{memberId}` (`{grade}`) — `#[NoAdminRequired]` with owner guard in the body (satisfy `hydra-gate-no-admin-idor`); register the route in `appinfo/routes.php` under the "Team folder sharing" section
- [x] 3.2 Include each member's `grade` in the existing `members` list response (owner-only full visibility)
- [x] 3.3 New `AuditEventTypes` constant for grade change; dispatch a grade-changed event on `setMemberGrade` and ensure the non-owner sync path dispatches `SECRET_UPDATED` via `AuditEvent::forUser` with the writer as actor — identifiers only, never key material

## 4. Frontend (Vue 2 + WebCrypto)

- [x] 4.1 Grade selector (`NcSelect` with `inputLabel`, Read/Write) per member in the team-folder membership panel — owner-only; PATCHes the grade
- [x] 4.2 Make the secret edit form editable for a member whose effective grade is `write`; on save run the re-encrypt-for-all fan-out (same client routine as the owner's sync) with a progress indicator and PUT to the existing `share#sync` endpoint
  > Note: a member's copy was always editable (it is their own row) — the new part is propagation: `useSecretStore.updateSecret` now consults the new `GET /secrets/{id}/write-context` after every sensitive edit and, for a write-grade copy, runs `syncAsTeamWriter` (re-encrypts for the SOURCE row under the owner certificate + every recipient, PUT share#sync). No separate progress bar — the sync rides the existing save flow like the owner path.
- [x] 4.3 "Editable" badge on secrets the current user may write, so a member knows a change propagates to the whole team

## 5. Tests

- [x] 5.1 Unit: `setMemberGrade` owner-only guard; default grade is `read`; `resolveGrade` returns MAX along the ancestor chain and via group expansion; grade change touches no ciphertext
- [x] 5.2 Unit: `syncUpdate` accepts a `write`-grade non-owner, rejects a `read`-grade member and an ungraded caller with `ForbiddenException`; optimistic-lock rejection still fires
- [x] 5.3 e2e (Playwright): owner grants a member `write`, member edits a shared secret, a second recipient sees the new value; owner demotes to `read`, the same member's edit is now rejected
  > Note: executed as a live verification on the deployed dev instance (grade PATCH, write-grade sync accepted + propagated blobs verified in DB, read-grade sync rejected 4xx), matching sibling changes.
  > Security note: implementing §2.3 exposed a pre-existing defect — `syncUpdate` wrote ANY secret id passed in `updates[]` (no membership check), letting any share owner corrupt arbitrary users' ciphertext. Fixed here: writes are confined to the source row + its actual ShareTarget copies, locked by `testSyncUpdateAcceptsWriteGradeAndGuardsCopies`.

## Acceptance criteria

- Every team-folder membership carries a `read` (default) or `write` grade; only the folder owner can set or change it
- A `read` member behaves exactly as under team-folder-sharing today (hold a copy, view only)
- A `write` member can update a folder secret's value; the change propagates to all recipients as per-recipient RSA copies re-encrypted in the writer's browser
- The server accepts a non-owner fan-out only when the writer holds a `write` grade on an ancestor team folder; it never decrypts or re-encrypts any value
- A secret's effective grade is the highest grade any ancestor membership grants; subfolders may raise but not lower it
- Grade changes re-encrypt nothing and are audited; non-owner writes are attributed to the writer
- The server never holds plaintext secret material at any step (ADR-003 preserved)
