# Tasks: share-save no longer misses partner shares without saying so

## 1. Backend

- [x] 1.1 Give a copy editor who owns the source the owner's partner-share rules in `ShareSaveScope::requirement()`, and answer partner entries from any other copy editor with 409 `incomplete` requiring none, on save and stage (D1). Verify: new cases in `ShareSaveServiceTest` and `ShareSaveScopeCompletenessTest` for an owner saving through their own copy (the state both team offboarding and account deletion leave), and cases showing a delegate, a write-grade editor and a former owner who don't own the source get that 409 with nothing written and no partner share named.
- [x] 1.2 Refuse a share-save with 409 `partner_share_owner_mismatch` when an active partner share of the source isn't the source owner's, and log it (D2). Verify: `ShareSaveServiceTest` cases on the owner path and on the D1 path, asserting nothing is written.
- [x] 1.3 Refuse a received copy as the source in `FederatedShareService::create`, including when its row is unjudged (D3). Verify: `FederatedShareService` tests for a plain recipient, a delegate's copy, a pending-scan row and an original that is still accepted.
- [x] 1.3a Create the partner share on the source when the caller shares their own received copy of a secret they own, following only the trusted share row (`FederatedShareSources::ownedSourceOf`); a copy of someone else's secret is still refused (D3). Verify: `FederatedShareSendTest` cases for the source's owner sharing their own copy, a copy holder who doesn't own the source, a source an organisation owns under the caller's id, a copy the pending scan can't judge, and a second share to a recipient the owner's copy already has, refused with `already_shared` until that share is revoked.
- [x] 1.3b List the source's partner shares with those of the caller's own copy when the caller owns the source, so a partner share made through the copy shows and is revoked where it was made; a holder who doesn't own the source still sees only their copy's (D3). Verify: `FederatedShareSendTest` cases for the owner listing and revoking through their copy and for a holder who doesn't own the source.
- [x] 1.4 Count the active partner shares hanging off every row a share-save writes as `federated.notReached`, and for a caller who doesn't own the source only those of their own row (D4). Verify: `ShareSaveServiceTest` cases for the owner path and the copy-editor path, and a zero count when there are none.
- [x] 1.5 Keep the delegate's judged share row and copy linked when account deletion transfers a secret to them (D5). Verify: `AccountDeletionServiceTest` cases for the kept delegate copy, the detached copies of other recipients, untrusted delegate rows that are still detached, every kind of limited delegate copy (use-only, expiring, read-only, limited through a group share or team folder only, a full grant beside a limited one) staying detached with its limits, an expiry run after the end date that lifts nothing, and the deleted-account marker on the kept row; a re-run of the cascade changes nothing more.
- [x] 1.6 Document the new error code and `federated.notReached` in `openapi.json`. Verify: `composer check:strict`.

## 2. Web app

- [x] 2.1 Look up and verify partner certificates on the copy-editor path when the server requires partner shares, and send them (D1). Verify: new cases in `tests/store/shareSave.spec.js`.
- [x] 2.2 Show `partner_share_owner_mismatch` and `federated.notReached` with translated messages. Verify: `tests/store/shareSave.spec.js`, `SecretEditDialog.shareSave.spec.js`, `npm run test:l10n`.
- [x] 2.3 Keep a queued offline change the server refuses with `partner_share_owner_mismatch` as failed, with its reason, in `src/store/modules/offline.js`. Verify: `tests/store/offlineQueue.spec.js`, `tests/components/OfflineEdits.spec.js`.
- [x] 2.4 Explain `not_shareable` (a received copy, D3) and `share_scan_pending` in the partner share form, `src/components/share/FederatedShareForm.vue`. Verify: `tests/components/FederatedShareForm.more.spec.js`.

## 3. Browser extension

- [x] 3.1 Do 2.1 and 2.2 in `browser-extension/src/vault/share-save.ts`. Verify: the extension's share-save tests.
- [x] 3.2 Tell the user what a save left to partner shares on every extension path that saves an item: the notification bar's password update and the passkey window's sign-in and save to a login, which stay open until closed; a sign-in leaves out the owner's-next-save note and closes as before when nothing else is left. Verify: `src/autofill/capture-queue.test.ts`, `entrypoints/notification/bar.test.ts`, `src/passkey/sign.test.ts`, `src/passkey/create.test.ts`, `entrypoints/popup/views/PasskeyWindow.test.tsx`.
- [x] 3.3 Describe the new answers in the extension guide, `docs/browser-extension/using.md`. Verify: read against 2.2, 3.1 and 3.2.

## Acceptance criteria

- The successor of an offboarded user and the delegate of a deleted user write the secret's partner shares with their next save.
- A share-save never commits while skipping a partner share it should write, without saying so.
- A recipient can't share their copy with a partner organisation.
