## 0. Context (read first)

The write surface already exists in the Pinia stores and the backend:
`secret.createSecret` / `updateSecret`, `folder.createFolder`, and
`linkShare.createLinkShare` are all implemented, encrypt client-side, and hit
working, tested routes. The two server-side bugs the old e2e fixmes mentioned
(owner_type NOT-NULL on insert; importPublicKey on an X.509 cert) are already
FIXED on `development`. This change is purely the **presentation layer** —
dialogs + affordances — over those stores.

## 1. Create-secret dialog

- [x] 1.1 Add `src/dialogs/SecretCreateDialog.vue` — `CnFormDialog`-based form: name (required), type (NcSelect from secretType store), value (required, password field), optional URL + login, optional folder (NcSelect from folder store, defaults to `folderId` prop).
- [x] 1.2 On submit call `secret.createSecret({...})` — the store RSA-encrypts the value/login via `importPublicKey(session.certificate)` + `rsaEncrypt` before the POST.
- [x] 1.3 Disable submit while the vault is locked or name/value empty; surface store errors inline.
- [x] 1.4 Register `secret-create` as `kind:"modal"` in `src/registry.js`.

## 2. Edit-secret dialog

- [x] 2.1 Add `src/dialogs/SecretEditDialog.vue` — `CnFormDialog`-based; receives a decrypted `secret` prop, pre-fills name/type/url/login/value.
- [x] 2.2 On save, send only changed fields via `secret.updateSecret(id, diff)`; sensitive fields (value/login) re-encrypted by the store, metadata-only edits skip encryption.
- [x] 2.3 Register `secret-edit` as `kind:"modal"`.

## 3. Folder-create + move dialogs

- [x] 3.1 Add `src/dialogs/FolderCreateDialog.vue` — name (required) + optional parent (NcSelect from folder tree); calls `folder.createFolder(name, parentId)`.
- [x] 3.2 Add `src/dialogs/SecretMoveDialog.vue` — folder NcSelect (incl. a "Vault root" / null option); calls `secret.updateSecret(id, { folderId })`.
- [x] 3.3 Register `folder-create` and `secret-move` as `kind:"modal"`.

## 4. Share dialog (link share)

- [x] 4.1 Add `src/dialogs/SecretShareDialog.vue` — bespoke `NcDialog` (multi-step generate-then-reveal): usage-limit select (1–10), "Create link" button.
- [x] 4.2 On create, decrypt the secret (re-`fetchSecret` for a clean snapshot), build the snapshot object, call `linkShare.createLinkShare(secretId, snapshot, usageLimit)`; show the returned `createdLinkUrl` + `createdPassword` once each with `CopyButton`.
- [x] 4.3 List existing link shares (`linkShare.fetchLinkShares`) with a revoke (`deleteLinkShare`) control.
- [x] 4.4 Render a DISABLED "Share with a Nextcloud user (coming soon)" affordance. [~] User-to-user share is DEFERRED — the `implement-user-sharing` backend + store are unbuilt, so there is nothing to drive. Flagged here; build when that change lands.
- [x] 4.5 Clear the transient password on close (`clearCreatedPassword`).
- [x] 4.6 Register `secret-share` as `kind:"modal"`.

## 5. Wire affordances (reachability)

- [x] 5.1 `SecretList.vue`: add a "New secret" + "New folder" toolbar; open the dialogs via the injected `cnOpenModal('secret-create', { folderId })` / `cnOpenModal('folder-create')`. Refresh the list/tree on the dialog's success event.
- [x] 5.2 `SecretDetail.vue`: add Edit / Move / Share buttons that open `secret-edit` / `secret-move` / `secret-share` via `cnOpenModal`, forwarding the secret context. Reload the detail on edit/move success.
- [x] 5.3 Confirm `cnOpenModal` is injected from `CnAppRoot` (it is — `provide.cnOpenModal`); no manifest `actions[]` needed since affordances are in-view buttons, not nav entries.

## 6. Build + lint + verify

- [x] 6.1 `npm run lint` (eslint) clean for the touched files.
- [x] 6.2 `npm run build` — auto-deploys via the bind mount; bump `appinfo/info.xml` version for cache-bust.
- [x] 6.3 Un-fixme the write-UI legs in `tests/e2e/workflows/secret-crud-encryption.spec.ts` (zero-knowledge round-trip via the UI) and `folder-sharing.spec.ts` (folder create, move, link share); drive them through the real dialogs and assert the create→encrypt→persist→retrieve round-trip + token resolution.
- [x] 6.4 Run both specs FOREGROUND under `flock /tmp/uiaudit-doriath.lock`; confirm green.
