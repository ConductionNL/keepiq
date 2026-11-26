---
kind: code
---

# Save a shared secret and every copy of it in one request

## Why

When someone changes the value, login or additional fields of a shared secret, every copy has to be encrypted again for its holder, and only the browser can do that. Until now it took separate requests: `PUT /api/v1/secrets/{id}` for the editor's row, `PUT /api/v1/secrets/{sourceSecretId}/sync` for the local copies and `PUT /api/v1/federated-shares/{id}` once per partner share. Those requests are not one transaction, so a copy could lag behind its source without anyone being told, and a partner update had no version check at all. In the web app the fan-out was also broken outright (#1253): it found no recipient certificate, sent field names the server ignores, cleared fields nobody changed and swallowed every error.

Issue: #1254. Fixes all four bugs of #1253.

## What Changes

- **New route `PUT /api/v1/secrets/{id}/share-save`.** It takes the editor's sparse patch with `baseUpdatedAt`, the ciphertext for every local copy (`copies`), the whole value for every active partner share (`federated`), and the copies or partner shares left out on purpose (`skipped`, `suspend`). It writes all of it in one database transaction, or nothing.
- **Completeness check.** The server answers 409 `incomplete` with the required lists when the request does not name exactly the copies and partner shares it must, so the browser encrypts for the missing ones and sends again.
- **Staged save above 100 entries.** A list longer than 100 is sent in chunks of at most 100, matching the batching of the certificate lookup, and the final call commits everything at once.
- **Partner instances are notified after the commit.** The pending notification is stored on the partner share row inside the transaction, so the retry job delivers it after a crash.
- **Partner shares follow their secret.** When a secret passes to a new owner, its partner shares pass with it, so the new owner can revoke or suspend them and the partner keeps pulling them.
- **The web app saves every value change of a shared secret through the new route**, including offline replay and version restore, and shows a failed save as a translated error. It no longer calls `/sync` or `PUT /federated-shares/{id}`.
- **The old routes stay** until the browser extension has moved too.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `user-sharing`: sync on update becomes one atomic share-save, with a completeness check and staging.
- `federated-sharing`: partner copies change only through the owner's share-save, notified after the commit; partner shares follow their secret to a new owner, and a suspended share stays suspended until the owner revokes it or shares again.
- `folder-permission-grades`: a write-grade member's update goes through the share-save copy-editor path.
- `offline-edit-queue`: replay of a value edit goes through share-save, and a failed share-save keeps the queue moving.
- `secret-version-history`: a restore is one share-save carrying the version's value.
- `secrets-write-ui`: the edit dialog sends the version it was opened on and shows a failed share-save as an error.

## Impact

- **Backend:** a new controller, `ShareSaveService` with its request parser, scope and staging classes, a `keepiq_share_save_stages` table with migration and an expiry job, and refactors of `ShareSyncService`, `FederatedShareService` and `SecretService::update` that keep the old routes working. Some things change for them too: a partner notification is stored on the share before it is sent and is never stored over a revoke's pending unshare, suspending a share drops its queued update, and suspending a share that is no longer active answers 404. The partner's decline and accept no longer undo a revoke or end a suspension, and a decline completes a pending revocation. Partner shares pass to a secret's new owner with the secret, a repair step hands over the ones earlier transfers left behind, and a partner share that isn't the secret owner's is no longer served.
- **Frontend:** a new `src/services/shareSave.js`; the fan-out in the share, federated share and offline stores is deleted.
- **Browser extension:** unchanged in this change; it keeps the old routes until it moves (`browser-extension/openspec/architecture/adr-003-keepiq-api-contract.md` on the extension branch).
- **OpenConnector:** unaffected; it doesn't edit shared secrets.
