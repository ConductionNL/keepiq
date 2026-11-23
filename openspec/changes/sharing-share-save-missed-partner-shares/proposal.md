---
kind: code
---

# Share-save no longer misses partner shares without saying so

## Why

A share-save can commit while a partner share of the secret keeps its old value, and nobody is told. Four paths lead there, all ending in `ShareSaveScope::requirement()`:

- After team offboarding the successor owns the secret, but edits it through their recipient copy, because the source is still encrypted for the leaver. The copy-editor path writes no partner shares and tells the successor that partners get the change "when the owner next saves", while the successor is that owner.
- After an account deletion the delegate owns the secret, but `AccountShareCleanupService::detachGrantedShares` tombstones and unlinks every copy of it, the delegate's own included. The delegate is left with a detached copy and a source they can't decrypt, so no share-save can reach the partner shares at all.
- On the owner path, `requirement()` drops every active partner share whose owner isn't the caller and still answers `ownerOnly: false`, so the client says everyone has the change.
- A recipient can share their own copy with a partner organisation through `FederatedShareService::create`. share-save reads only the partner shares of the source, so that partner share is never written.

Issue: #1263, which merges #1279 and #1280.

## What Changes

- **The secret's owner writes partner shares, through whichever row they edit.** A copy editor who owns the source takes the owner's partner-share rules: share-save requires and accepts the source's partner shares, and the web app and browser extension look up and verify their certificates.
- **A partner share that isn't the secret owner's stops the save.** share-save answers 409 `partner_share_owner_mismatch`, writes nothing and logs the share ids, until the share is handed to the secret's owner. The `ReownTransferredPartnerShares` repair step does that.
- **A received copy can't be shared with a partner organisation.** `FederatedShareService::create` refuses a source that is a recipient's copy, as `register-batch` and group shares already do.
- **Partner shares made from a copy before this change are reported.** share-save counts the active partner shares hanging off any copy it writes in `federated.notReached`, and the clients say that this many recipients at partner organisations didn't get the change.
- **Account deletion keeps the delegate's own copy linked** when the secret passes to them and nothing limits that copy, so their saves take the owner's path above.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `federated-sharing`: the secret's owner writes partner shares through a copy too; a partner share that isn't the owner's stops the save; a received copy is not shared with a partner; partner shares made from a copy are reported.
- `user-sharing`: the completeness check requires partner shares from a copy editor who owns the source; the federation path applies the received-copy refusal.
- `gdpr-compliance`: the delegate's copy of a secret that passes to them stays linked.

## Impact

- **Backend:** `ShareSavePath`, `ShareSaveScope`, `ShareSaveService` (the copy-editor request check and the answer), `ShareRowJudge` (the received-copy test, shared with group shares), `FederatedShareService::create`, `AccountDeletionService` and `AccountShareCleanupService`. No migration and no new route; the share-save answer gains `federated.notReached` and a 409 error code, and creating a partner share can answer 409 `share_scan_pending`.
- **Web app:** `src/services/shareSave.js` looks up partner certificates on the copy-editor path whenever the server requires partner shares, and shows the new refusal and the not-reached count. A queued offline change that gets the refusal is kept as failed, with its reason, not offered as a version conflict. The partner share form explains the new refusal.
- **Browser extension:** `browser-extension/src/vault/share-save.ts` does the same.
- **Order:** this change modifies requirements that `sharing-atomic-share-save` adds, so it is archived after that change.
- **Security:** the server still receives ciphertext only, and partner certificates are still verified in the browser against the pinned root. The refusal of onward partner sharing narrows who can send a secret to another organisation; nothing is widened. A copy editor learns partner share ids only when they own the source.
- **OpenConnector:** unaffected; it doesn't edit shared secrets.
- **Feature tier:** a fix inside the existing sharing features; no tier change.
