# Tasks: share-save no longer misses partner shares without saying so

## 1. Backend

- [ ] 1.1 Give a copy editor who owns the source the owner's partner-share rules in `ShareSaveScope::requirement()`, and answer partner entries from any other copy editor with 409 `incomplete` requiring none, on save and stage (D1). Verify: new cases in `ShareSaveServiceTest` and `ShareSaveScopeCompletenessTest` for an owner saving through their own copy (the state both team offboarding and account deletion leave), and cases showing a delegate, a write-grade editor and a former owner who don't own the source get that 409 with nothing written and no partner share named.
- [ ] 1.2 Refuse a share-save with 409 `partner_share_owner_mismatch` when an active partner share of the source isn't the source owner's, and log it (D2). Verify: `ShareSaveServiceTest` cases on the owner path and on the D1 path, asserting nothing is written.
- [ ] 1.3 Refuse a received copy as the source in `FederatedShareService::create`, including when its row is unjudged (D3). Verify: `FederatedShareService` tests for a plain recipient, a delegate's copy, a pending-scan row and an original that is still accepted.
- [ ] 1.4 Count the active partner shares hanging off every row a share-save writes as `federated.notReached` (D4). Verify: `ShareSaveServiceTest` cases for the owner path and the copy-editor path, and a zero count when there are none.
- [ ] 1.5 Keep the delegate's judged share row and copy linked when account deletion transfers a secret to them (D5). Verify: `AccountDeletionServiceTest` cases for the kept delegate copy, the detached copies of other recipients, untrusted delegate rows that are still detached, every kind of limited delegate copy (use-only, expiring, read-only, limited through a group share or team folder only, a full grant beside a limited one) staying detached with its limits, an expiry run after the end date that lifts nothing, and the deleted-account marker on the kept row; a re-run of the cascade changes nothing more.
- [ ] 1.6 Document the new error code and `federated.notReached` in `openapi.json`. Verify: `composer check:strict`.

## 2. Web app

- [ ] 2.1 Look up and verify partner certificates on the copy-editor path when the server requires partner shares, and send them (D1). Verify: new cases in `tests/store/shareSave.spec.js`.
- [ ] 2.2 Show `partner_share_owner_mismatch` and `federated.notReached` with translated messages. Verify: `tests/store/shareSave.spec.js`, `SecretEditDialog.shareSave.spec.js`, `npm run test:l10n`.

## 3. Browser extension

- [ ] 3.1 Do 2.1 and 2.2 in `browser-extension/src/vault/share-save.ts`. Verify: the extension's share-save tests.

## Acceptance criteria

- The successor of an offboarded user and the delegate of a deleted user write the secret's partner shares with their next save.
- A share-save never commits while skipping a partner share it should write, without saying so.
- A recipient can't share their copy with a partner organisation.
