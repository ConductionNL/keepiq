## 1. Policy switch

- [x] 1.1 Add `team_folder_auto_confirm` to the admin settings validation, the policy audit and `getPolicy()`. Verify with a PHPUnit test in `tests/Unit/Service/AdminSettingsServiceTest.php`.
- [x] 1.2 Add the switch to the Policies area of the admin settings. Verify with a vitest in `tests/components/`.

## 2. Server

- [x] 2.1 Add `TeamFolderService::pendingConfirmations($userId)` and `GET /api/v1/team-folders/pending-confirmations`, returning folders where the user is owner or `write` member with missing pairs, certificates and own-copy ids. Verify with PHPUnit tests for owner, `write` member, `read` member, switch off and a disabled recipient.
- [x] 2.2 Let `registerFanOutShares()` accept a `write`-grade confirmer: check the grade with `resolveGrade()`, the pair is missing and covered, and the confirmer's copy is not older than the source's `key_updated_at`. Verify with PHPUnit tests for each refusal and for an accepted row.
- [x] 2.3 Send `team_folder_member_confirmed` to the owner and record the confirmer as audit actor. Verify with a PHPUnit test on the notification and audit metadata.
- [x] 2.4 Run the no-admin-idor and route-auth hydra gates on the new and changed endpoints. Verify by a green gate run. Full-scope hydra-gates run 4 Oct (base origin/development): `[gate-5] route-auth: PASS` (every routes.php entry judged, 0 unresolved, including `teamFolder#pendingConfirmations` and `teamFolder#registerShares`) and `[gate-7] no-admin-idor: PASS`. Positive control: with `registerShares` passing a request parameter as the user, `check_no_admin_idor.py` reports `method=registerShares rule=no-auth-guard-in-body`; on the real file it reports nothing.

## 3. Browser

- [x] 3.1 Add `autoConfirm()` to `src/store/modules/teamFolder.js`: fetch pending, decrypt the owner source or the member's own copy once, encrypt per recipient, post in chunks. Verify with a vitest that mocks the crypto and asserts no plaintext leaves the store.
- [x] 3.2 Start `autoConfirm()` after `unlockFromBlob()` when the switch is on, repeat every 15 minutes, stop on lock. Verify with a vitest using fake timers.
- [x] 3.3 Show who confirmed and the waiting state in `TeamFolderDialog.vue`. Verify with a vitest.
- [x] 3.4 Cover the flow end to end. Verify with a Playwright test in `tests/e2e/workflows/`: the switch is on, a user joins a member group, a `write` member unlocks, and the new member can open a folder secret without the owner acting. `tests/e2e/workflows/team-folder-auto-confirm.spec.ts`: the owner shares a folder with a write member and a group and leaves; a newcomer joins the group; the write member is offered the newcomer, unlocks, and the background run posts one copy; the newcomer decrypts the value. Red-before/green-after: red (the writer is offered nobody) with `ConfirmerCopyResolver` refusing write-grade confirmers, green on development.

## Acceptance criteria

- With the switch on, a new member of a team folder can read its secrets after any `write` member or the owner unlocks, with no click by anyone.
- With the switch off, behaviour is unchanged.
- A `read` member's browser never receives pending confirmations and cannot register a row for another user.
- A confirmer whose copy is older than the source cannot hand it out.
- No request from the auto-confirm flow carries plaintext; the server stores only recipient ciphertext.
- The owner is notified of every automatic confirmation.
