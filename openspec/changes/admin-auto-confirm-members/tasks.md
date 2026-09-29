## 1. Policy switch

- [ ] 1.1 Add `team_folder_auto_confirm` to the admin settings validation, the policy audit and `getPolicy()`. Verify with a PHPUnit test in `tests/Unit/Service/AdminSettingsServiceTest.php`.
- [ ] 1.2 Add the switch to the Policies area of the admin settings. Verify with a vitest in `tests/components/`.

## 2. Server

- [ ] 2.1 Add `TeamFolderService::pendingConfirmations($userId)` and `GET /api/v1/team-folders/pending-confirmations`, returning folders where the user is owner or `write` member with missing pairs, certificates and own-copy ids. Verify with PHPUnit tests for owner, `write` member, `read` member, switch off and a disabled recipient.
- [ ] 2.2 Let `registerFanOutShares()` accept a `write`-grade confirmer: check the grade with `resolveGrade()`, the pair is missing and covered, and the confirmer's copy is not older than the source's `key_updated_at`. Verify with PHPUnit tests for each refusal and for an accepted row.
- [ ] 2.3 Send `team_folder_member_confirmed` to the owner and record the confirmer as audit actor. Verify with a PHPUnit test on the notification and audit metadata.
- [ ] 2.4 Run the no-admin-idor and route-auth hydra gates on the new and changed endpoints. Verify by a green gate run.

## 3. Browser

- [ ] 3.1 Add `autoConfirm()` to `src/store/modules/teamFolder.js`: fetch pending, decrypt the owner source or the member's own copy once, encrypt per recipient, post in chunks. Verify with a vitest that mocks the crypto and asserts no plaintext leaves the store.
- [ ] 3.2 Start `autoConfirm()` after `unlockFromBlob()` when the switch is on, repeat every 15 minutes, stop on lock. Verify with a vitest using fake timers.
- [ ] 3.3 Show who confirmed and the waiting state in `TeamFolderDialog.vue`. Verify with a vitest.
- [ ] 3.4 Cover the flow end to end. Verify with a Playwright test in `tests/e2e/workflows/`: the switch is on, a user joins a member group, a `write` member unlocks, and the new member can open a folder secret without the owner acting.

## Acceptance criteria

- With the switch on, a new member of a team folder can read its secrets after any `write` member or the owner unlocks, with no click by anyone.
- With the switch off, behaviour is unchanged.
- A `read` member's browser never receives pending confirmations and cannot register a row for another user.
- A confirmer whose copy is older than the source cannot hand it out.
- No request from the auto-confirm flow carries plaintext; the server stores only recipient ciphertext.
- The owner is notified of every automatic confirmation.
