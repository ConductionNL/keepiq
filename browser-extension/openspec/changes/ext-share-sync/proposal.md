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

ext-vault-edit saves an item with a sparse `PUT /api/v1/secrets/{id}`, which changes only the editor's own row. In Keepiq every recipient of a shared item holds a separate copy, encrypted to their own key, so the editing client must encrypt each changed value again for every copy. Without that, a password changed in the extension stays old for everyone it is shared with, and nobody is told. Found in the ext-vault-edit review (must-fix 3); kept as its own change because it is a feature of its own.

Keepiq now saves a shared secret and every copy of it in one transaction, through `PUT /api/v1/secrets/{id}/share-save` ([keepiq#1254](https://github.com/ConductionNL/keepiq/issues/1254), [keepiq#1258](https://github.com/ConductionNL/keepiq/pull/1258)). A save either reaches everyone or writes nothing, so the extension needs no Try again, no records that outlive the request and no per-item save queue. The web app saves through the same route (`src/services/shareSave.js`), which the extension ports.

## What Changes

- An edit that changes `key`, `login` or `additionalFields` saves through `PUT /api/v1/secrets/{id}/share-save`; a rename, URL change or move stays on `PUT /api/v1/secrets/{id}`.
- `GET /api/v1/secrets/{id}/write-context` picks the path:
  - The editor owns the item: the save carries every local copy and every active partner share.
  - The editor holds a copy with an `owner`, `write` or `manage` grade: the save goes through their copy and carries the source and every other copy. Partner shares stay with the owner's next save, and the popup says so.
  - Any other copy is view-only: Edit is unavailable with the reason, and the background refuses to save anything but a move to a folder.
- The first request carries empty lists, so an unshared item saves at once. The server answers 409 `incomplete` with the copies and partner shares it requires; the background then reads their certificates, encrypts the change for each holder and sends again.
- Partner certificates are verified in the background against the pinned partner root, ported from the web app. A certificate that fails, or a partner that no longer knows the recipient, suspends that share in the same save.
- A partner that can't be reached stops the save; the form names it and offers Save without them, which suspends that share.
- A failed save writes nothing: the form stays open with the reason, and the user saves again.

## Capabilities

### New Capabilities

- `share-sync`: an edit of a shared item reaches every recipient copy in the same request.

### Modified Capabilities

- `item-editing` (ext-vault-edit): Save of a value change goes through share-save and reports the note to show, and a copy without write permission is view-only.

## Deviations from Bitwarden

Mirrored feature: an edit of an item in a Bitwarden organisation collection, which every member sees at once.

- Per-recipient copies. Bitwarden encrypts a shared item once under the organisation key; Keepiq has no shared key and stores one copy per recipient, so the editor's client encrypts for each (forced).
- Recipient certificates come from the server. The extension proves only the user's own certificate (ext-vault-edit); it trusts local recipients' certificates as the web app does and as Bitwarden trusts organisation membership, and verifies partner-organisation certificates against the pinned partner root, as the web app does (forced).
- An unreachable partner organisation stops the save until the user saves without them. Bitwarden has no partner organisations; the web app does the same (forced, Keepiq `federated-sharing`).
- A recipient without a write grade can't edit the item, as in Bitwarden's "Can view". Keepiq's server would let them change their own copy alone, which leaves it out of step with everyone else's; the extension doesn't offer that (chosen, keepiq#1287 for the web app). Group members are meant to be writers; until keepiq#1285 the server doesn't treat them as such.

## Keepiq API used

- `GET /api/v1/secrets/{id}/write-context`
- `PUT /api/v1/secrets/{id}/share-save`
- `POST /api/v1/shares/recipient-certificates`
- `POST /api/v1/federation/recipient-certificate`
- `PUT /api/v1/secrets/{id}` (a copy without a write grade, and every save without a value change)

Server rules: Keepiq `user-sharing`, `federated-sharing` and `folder-permission-grades` as changed by `openspec/changes/sharing-atomic-share-save`.

## Impact

- New: `src/vault/share-save.ts`, `src/vault/verified-partners.ts`, `src/vault/write-error.ts`, `src/crypto/federated-certificate.ts` and the popup component `ShareSaveNote`, each with its test.
- Edited: `src/api/client.ts` (`ApiError.body`), `src/vault/write.ts` (`fetchItem` records what the form opened, `updateItem` saves through share-save), `src/messages.ts` (the `item.update` request and reply, `item.editable`), `src/background/requests.ts`, Shell, item form and item detail, both locales, ADR-002 and ADR-003, `SECURITY.md`, `ARCHITECTURE.md`, and `../docs/browser-extension/using.md` and `privacy.md`.
- ext-autofill's `capture.update` saves through `updateItem`. With no edit form behind it, an item with partner shares can't be saved there, and the prompt says to change it in the extension's edit form or the web app.
- Keepiq: once the extension has moved, `PUT /api/v1/secrets/{id}/sync` and `PUT /api/v1/federated-shares/{id}` can go (keepiq#1258).
