---
kind: code
depends_on: [ext-vault-edit]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
---

## Why

ext-vault-edit saves an item with a sparse `PUT /api/v1/secrets/{id}`, which changes only the editor's own row. In Keepiq every recipient of a shared item holds a separate copy, encrypted to their own key, and the web app re-encrypts each changed value for every recipient after the `PUT` (`src/store/modules/secret.js` `updateSecret`). Without that, a password changed in the extension stays old for everyone it is shared with, and nobody is told. Found in the ext-vault-edit review (must-fix 3); kept as its own change because it is a feature of its own.

## What Changes

- After an edit that changes `key`, `login` or `additionalFields`, the background re-encrypts the new plaintext for every recipient, the way the web app does, before it replies.
- Three paths, as in the web app:
  - Owner with direct recipients: `GET /api/v1/secrets/{id}/shares` gives each recipient's copy id and certificate; `PUT /api/v1/secrets/{id}/sync` with `expectedUpdatedAt` and one `{secretId, encryptedKey, encryptedLogin, encryptedAdditionalFields}` per copy (`share.js` `syncUpdate`).
  - Owner with recipients at partner organisations: per active federated share, fetch and verify the recipient's certificate, then `PUT /api/v1/federated-shares/{id}` with `certFingerprint`; suspend a share whose certificate no longer verifies (`federatedShare.js` `syncUpdate`, `verifyFederatedCertificate`).
  - Team member with a `write` or `manage` grade editing their copy: `GET /api/v1/secrets/{id}/write-context` gives the source and the owner's certificate; re-encrypt for the source and every other recipient, then `PUT /api/v1/secrets/{sourceId}/sync` (`share.js` `syncAsTeamWriter`).
- A failed fan-out keeps the editor's own update, as in the web app, but the popup says which recipients did not get the change instead of failing silently.
- Plaintext fields (name, URL, folder) are not fanned out; the web app does not either.

## Capabilities

### New Capabilities

- `share-sync`: an edit of a shared item reaches every recipient copy.

### Modified Capabilities

- `item-editing` (ext-vault-edit): Save reports the fan-out result.

## Deviations from Bitwarden

Mirrored feature: an edit of an item in a Bitwarden organisation collection, which every member sees at once.

- Per-recipient copies. Bitwarden encrypts a shared item once under the organisation key; Keepiq has no shared key and stores one copy per recipient, so the editor's client re-encrypts for each (forced).
- Recipient certificates come from the server. The extension can check the editor's own certificate against the unlocked private key (ext-vault-edit), but not another user's; it trusts local recipients' certificates as the web app does, and verifies federated ones as the web app does (open question).

## Keepiq API used

- `GET /api/v1/secrets/{id}/shares`
- `PUT /api/v1/secrets/{id}/sync`
- `GET /api/v1/secrets/{id}/write-context`
- `GET /api/v1/secrets/{id}/federated-shares`
- `POST /api/v1/federation/recipient-certificate`
- `PUT /api/v1/federated-shares/{id}`
- `POST /api/v1/federated-shares/{id}/suspend`

Server rules: `ShareSyncService::syncUpdate` refuses a stale `expectedUpdatedAt` with 409, a read-only source, and a use-only copy (Keepiq specs `user-sharing`, `folder-permission-grades`, `federated-sharing`, `use-only-shares`).

## Open questions

- Should a group share or a team-folder membership need its own path, or are their copies all listed by `GET …/shares`?
- How should the extension verify a federated recipient's certificate, given `verifyFederatedCertificate` in the web app?
- What does the popup show when some recipients were updated and others failed, and can the user retry only those?

## Impact

- New: `src/vault/share-sync.ts`, with its test.
- Edited: `src/vault/write.ts` (`updateItem` runs the fan-out), `src/messages.ts` (the fan-out result in the `item.update` reply), the item form's save result, ADR-003 (the sharing routes).
