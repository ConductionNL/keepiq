## Why

Doriath's vault CORE works: a user can unlock their suite, open a secret, decrypt
it client-side, copy fields, and delete it. The Pinia stores already expose the
full write surface — `secret.createSecret` / `secret.updateSecret`, `folder.createFolder`
/ `folder.updateFolder`, and `linkShare.createLinkShare` — all wired to working
backend routes, all encrypting sensitive fields in the browser (RSA-OAEP via
`src/crypto/rsa.js`, AES-GCM + Argon2id for link snapshots).

But there is **no write-UI**. No Vue component imports or wires those store
actions. `SecretList.vue` only lists; `SecretDetail.vue` only views + deletes;
`FolderTree.vue` only renders + selects. So a user cannot create a secret, edit a
secret, create a folder, move a secret, or share a secret from the UI at all.

The DEEP e2e specs (`secret-crud-encryption.spec.ts`, `folder-sharing.spec.ts`)
carry `test.fixme`s blocked solely on this missing UI. The two server-side bugs
those fixmes also referenced (B: `owner_type` NOT-NULL on insert; C:
`importPublicKey` choking on an X.509 cert) are already fixed on `development`
(the entity defaults are `''` so the column is marked dirty, and
`extractSpkiFromCertificate` walks the TBSCertificate DER before `importKey('spki')`).
The only thing left is the dialogs.

## What Changes

- Add a **SecretCreateDialog** — name + type + secret value (+ optional URL / login),
  RSA-encrypted client-side via `secret.createSecret`, with optional target folder.
- Add a **SecretEditDialog** — change name / type / URL / login / secret value of an
  existing secret; re-encrypts changed sensitive fields via `secret.updateSecret`.
- Add a **FolderCreateDialog** — create a folder (optional parent) via
  `folder.createFolder`.
- Add a **SecretMoveDialog** — move a secret into a folder (or to root) via
  `secret.updateSecret({ folderId })`.
- Add a **SecretShareDialog** — create a password-protected public **link share**
  via `linkShare.createLinkShare` (decrypts the secret in-browser, generates the
  one-time password, Argon2id + AES-GCM encrypts the snapshot, POSTs only the
  blob), shows the one-time link URL + password with copy buttons, and lists /
  revokes existing link shares. User-to-user sharing is surfaced as a clearly
  labelled "coming soon" affordance — the backend (`implement-user-sharing`) is
  DEFERRED and unbuilt, so there is no store/route to drive yet.
- Wire the affordances so they are **reachable**: a "New secret" + "New folder"
  toolbar in `SecretList.vue`, a "New secret" item on the row context, and
  **Edit / Move / Share** buttons in `SecretDetail.vue`. Dialogs are registered as
  `kind:"modal"` registry entries (ADR-036) and opened via the injected
  `cnOpenModal(key, props)` dispatcher, with each modal isolated in its own
  `.vue` file under `src/dialogs/` (ADR-004 modal-isolation).
- Reuse `@conduction/nextcloud-vue` `CnFormDialog` where the dialog is a simple
  field form (create / edit / folder / move). The share dialog stays bespoke
  (`NcDialog`) because it owns a multi-step generate-then-reveal flow.

## Capabilities

### New Capabilities
- `secrets-write-ui`: Client-side create / edit / move / folder-create / link-share
  dialogs that drive the existing zero-knowledge stores — sensitive fields are
  RSA-encrypted in the browser before any field leaves the page, and a secret can
  be created, edited, organised into a folder, and shared via a one-time
  password-protected link entirely from the UI.

### Modified Capabilities
_(none — this is purely the presentation layer over already-specced store/backend
capabilities `secrets`, `folders`, and `link-sharing`. No requirement of those
capabilities changes.)_

## Impact

- **Frontend**: New dialogs under `src/dialogs/` (SecretCreateDialog,
  SecretEditDialog, FolderCreateDialog, SecretMoveDialog, SecretShareDialog).
  `SecretList.vue` + `SecretDetail.vue` gain affordances. `src/registry.js` gains
  five `kind:"modal"` entries. No new manifest pages (dialogs are modals, not
  routes). No new menu entries beyond the existing in-view affordances.
- **Backend**: None. All routes, services, entities already exist and are tested.
- **Crypto**: Reuses `src/crypto/rsa.js` (`importPublicKey` / `rsaEncrypt`) and the
  link-share Argon2id path through the existing `linkShare` store. No new crypto.
- **Security**: Plaintext secret values are encrypted in the browser before the
  POST/PUT; the server never sees plaintext (ADR-003 preserved). The share dialog
  decrypts the secret only transiently to build the snapshot and never transmits
  the link password.
- **Deferred**: User-to-user sharing UI is stubbed (disabled affordance) pending
  the `implement-user-sharing` backend; the leg is flagged in tasks.
