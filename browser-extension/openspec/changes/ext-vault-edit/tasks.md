## 1. Field model and messages

- [ ] 1.1 Create `src/vault/fields.ts` with the reserved names (`key`, `login`, `url`, plus `notes` for custom rows), the 4096 and 65536 caps, per-type field schemas, card and identity serialize and parse, TOTP seed validation and URI rewrite, and `validateDraft`.
  - Card and identity member lists match `src/cardIdentity/cardIdentity.js` of the Keepiq app verbatim; unset members serialize as `''`.
  - A bare base32 seed is kept as typed; only a changed advanced field rewrites the seed into an `otpauth://totp/` URI.
- [ ] 1.2 Add `ItemDraft`, `ItemPatch`, `DecryptedItem`, `FolderDeletePlan`, `VaultWriteError`, `WriteResult` and the eight write arms from design.md to `src/messages.ts`, and add `online` and `defaultSecretType` to the vault state.

## 2. Background write path

- [ ] 2.1 Create `src/vault/write.ts` with `createItem`: encrypt `key`, `login` and the `additionalFields` JSON with the active suite certificate through `src/crypto/`, `POST /api/v1/secrets`, then trigger sync and return the new id.
  - Empty `additionalFields` encrypts `{}`; an empty `login` is omitted; an empty `url` is `null`.
- [ ] 2.2 Add `fetchItem` (fresh `GET /api/v1/secrets/{id}`, decrypt in the background, withhold `key` for `passkey`, map `blocked` rows) and `updateItem` (encrypt only the ciphertext members present in the patch, sparse `PUT`, trigger sync).
- [ ] 2.3 Add `deleteItem` (`DELETE /api/v1/secrets/{id}`, trigger sync) and the folder functions: `createFolder`, `renameFolder`, `folderChildren`, `deleteFolder` building the query or the resolution body from `FolderDeletePlan`.
- [ ] 2.4 Map responses to `VaultWriteError` (423, 403, 400, 409, 404, no response) in one place and set the `online` flag from every request outcome.
- [ ] 2.5 Fetch `GET /api/settings/user` during sync and cache `default_secret_type` in `storage.local` with the account; clear it with the account data.
- [ ] 2.6 Register the eight arms in `entrypoints/background.ts` as request/response arms returning the Promise, following the `get_state` pattern.

## 3. Popup views

- [ ] 3.1 Create the hooks `entrypoints/popup/hooks/useItemDraft.ts` (draft state, dirty tracking, validation via `src/vault/fields.ts`, sparse patch against the fresh item) and `useUnsavedChangesGuard.ts` (the "You have unsaved changes. Discard them?" prompt on in-popup navigation while dirty).
- [ ] 3.2 Create the components `TypeSelect.tsx`, `AdditionalFieldsEditor.tsx` (repeatable rows, per-row masking, reserved, blank and duplicate refusals), `ConfirmDialog.tsx` (in-popup dialog with a plain confirm, a two-choice cascade and a resolution-plan layout) and `FolderTree.tsx` under `entrypoints/popup/components/`.
  - No component imports `src/api` or `src/crypto`; writes go through `useMessage` (ADR-004).
- [ ] 3.3 Create `entrypoints/popup/views/ItemForm.tsx` composing `TypeSelect`, `TextField`, `MaskedField`, `AdditionalFieldsEditor`, `ErrorBanner` and `Button`: default type, required Name, type-specific fields, Folder field opening `FolderPicker`, Notes, per-field cap messages, the offline explanation disabling Save, and error banners with Retry that keep the typed values.
  - Passkey items show only Name, Folder and Website URL plus the read-only notice.
- [ ] 3.4 Prefill Website URL from `browser.tabs.query({ active: true, currentWindow: true })` when adding from a tab, add Password reveal through `MaskedField`, and wire the generate button to ext-generator's pick mode returning the value into the draft; hide the button while that mode does not exist.
- [ ] 3.5 Add the "+" button to the Vault tab list and make Edit, Clone, Move and Delete live on item detail: Edit and Clone call `item.fetch` first, Clone suffixes " - Clone" and excludes passkeys, Delete uses `ConfirmDialog`, Move opens `FolderPicker` and sends a `folderId`-only patch.
- [ ] 3.6 Create `entrypoints/popup/views/FolderPicker.tsx` ("No folder", `FolderTree`, current folder marked, inline "New folder" calling `folder.create` and selecting the result) and `FolderManager.tsx` at Settings → Vault → Folders ("Add folder" per level, rename, the empty, cascade and resolution delete flows through `ConfirmDialog` fed by `folder.children`, the offline state, and the dismissible plaintext-names notice stored in `storage.local` via the background).
  - A 409 "resolution required" re-fetches children and opens the resolution dialog instead of failing.

## 4. Verification

- [ ] 4.1 Walk every scenario of both specs against the dev backend in Chrome and Firefox, including a round-trip of a `card`, an `identity` and a `totp` item opened afterwards in the Keepiq web app.
  - No `chrome.*` in executable code, no decrypted value written to any storage, no encryption in popup code.
- [ ] 4.2 Run `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`, then load `.output/chrome-mv3/` and `.output/firefox-mv2/` in both browsers and confirm add, edit, delete and folder delete each work once.
