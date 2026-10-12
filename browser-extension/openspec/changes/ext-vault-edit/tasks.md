## 1. Field model and messages

- [x] 1.1 Create `src/vault/fields.ts` with the reserved names (`key`, `login`, `url`, plus `notes` for custom rows), the 255, 4096 and 65536 caps, per-type form fields, card and identity serialize and parse, TOTP seed validation and URI rewrite, the draft diff and `validate`, with its test.
  - Card and identity member lists match `src/cardIdentity/cardIdentity.js` of the Keepiq app verbatim; unset members serialize as `''`.
  - A bare base32 seed is kept as typed; only a changed advanced field rewrites the seed into an `otpauth://totp/` URI.
- [x] 1.2 Add `ItemDraft`, `ItemPatch`, `EditableItem`, `FolderChildren`, `FolderDeletePlan`, `VaultWriteError`, `WriteResult` and the nine write requests from design.md to `src/messages.ts`, and `defaultSecretType` and `folderNoticeDismissed` to the snapshot reply.

## 2. Background write path

- [x] 2.1 Create `src/vault/write.ts` with `createItem`: encrypt `key`, `login` and the `additionalFields` JSON with the cached suite certificate through `src/crypto/`, `POST /api/v1/secrets`, then sync and return the new id, with its test.
  - Empty `additionalFields` encrypts `{}`; an empty `login` is omitted; an empty `url` is `null`.
- [x] 2.2 Add `fetchItem` (fresh `GET /api/v1/secrets/{id}`, decrypt in the background, withhold `key` for `passkey`, map `blocked` rows to their cached reason) and `updateItem` (encrypt only the ciphertext members present in the patch, sparse `PUT` with `baseUpdatedAt`, never a passkey's `key`, sync).
- [x] 2.3 Add `deleteItem` (`DELETE /api/v1/secrets/{id}`, sync) and the folder functions: `createFolder`, `renameFolder`, `folderChildren`, `deleteFolder` building the query or the resolution body from `FolderDeletePlan`.
- [x] 2.4 Map failures to `VaultWriteError` codes in one place, and record every request's outcome in the sync's offline flag through `recordOutcome`; a write replies after `syncAfterWrite`.
- [x] 2.5 Fetch `GET /api/settings/user` during a full sync and keep `default_secret_type` in the snapshot, which logout and account removal clear; a failed read keeps `null`.
- [x] 2.6 Handle the write requests in `src/background/requests.ts`, and keep the notice flag as `folderNotice.<id>`, removed with the account.

## 3. Popup views

- [x] 3.1 Create the hooks `useItemDraft.ts` (values, dirty tracking, validation and the patch through `src/vault/fields.ts`) and `useUnsavedChangesGuard.ts` (registers a dirty form with Shell, which asks "You have unsaved changes. Discard them?" before Back, Pop out or the account switcher).
- [x] 3.2 Create the components `TypeSelect`, `AdditionalFieldsEditor` (repeatable rows, per-row masking, the refusals as given), `ConfirmDialog` (the in-page modal every dialog uses), `FolderTree`, `FolderNameDialog` and `TextArea`.
  - No component imports `src/api` or `src/crypto`; writes go through `request` (ADR-004).
- [x] 3.3 Create `views/ItemForm.tsx`: default type, required Name, type-specific fields, the Folder field opening `FolderPicker`, Notes, per-field messages, the offline explanation disabling Save, and error banners with Retry that keep the typed values.
  - Passkey items show only Name, Folder and Website URL plus the read-only notice; Type is fixed on edit.
- [x] 3.4 Prefill Website URL from the current tab when adding. The generate button is ext-generator's (its task 4.6).
- [x] 3.5 Add a primary "New" button to the header on the Vault list, left of Pop out, and make Edit, Clone, Move and Delete live in the More menu and on item detail, through item actions in Shell: Edit and Clone fetch first, Clone suffixes " - Clone" and excludes passkeys, Delete confirms, Move sends a `folderId`-only patch.
- [x] 3.6 Create `views/FolderPicker.tsx` and, at Settings → Vault → Folders, `views/settings/Vault.tsx` and `FolderManager.tsx`: Add folder per level, rename, the empty, cascade and resolution delete flows, the offline state and the dismissible plaintext-names notice.
  - A 409 re-reads the children and opens the matching dialog; a 400 on a plan offers Retry, which re-reads them.

## 4. Review fixes

- [x] 4.1 Prove the suite certificate against the unlocked private key before the first write with it, and refuse writes with `key_mismatch` otherwise.
- [x] 4.2 Carry `useOnly`, `readOnly` and `accessExpiresAt` into `ItemMeta`; block Edit, Clone and Move as each limit requires in the popup, `item.fetch` and `updateItem`; withhold a use-only value and fields from `item.decrypt`, the detail and the copy menu.
- [x] 4.3 Ask the server for a folder's children on every delete, and say archived and trashed items count too.
- [x] 4.4 Record the per-recipient fan-out as its own change, `ext-share-sync`.
- [x] 4.5 Make every write name its account, and refuse it with `account_changed` when that is no longer the active one.
- [x] 4.6 Prefill Website URL with the tab's origin and path only.
- [x] 4.7 Word Delete as a move to the trash that ends sharing, in the dialog, toast, docs, spec and ADR-003.
- [x] 4.8 Catch a kept subfolder's name clash before a folder delete, and on a 409 to a plan say so, sync and stop.
- [x] 4.9 Word a refusal by its code: a policy refusal, or a neutral refusal for the rest.
- [x] 4.10 Read the server's active suite before encrypting, and sync and refuse with `locked` when it is not the cached one.
- [x] 4.11 Refuse an update for an item the cache does not hold, and leave the offline flag alone on a refusal of our own.
- [x] 4.12 Keep a custom field named `__proto__`, name a folder made in the move picker in its toast, and put the account switcher behind the unsaved-changes prompt.
- [x] 4.13 Read Keepiq's 428 refusal like a 403, with its `error` as the code, in writes and in the manifest fallback.
- [x] 4.14 Answer an overtaken snapshot read with the newer one, and leave "New folder" for the picker once the folder exists.
- [x] 4.15 Sync when the fresh fetch for Edit or Clone is refused, and name the blocked reason when the sync marks the item blocked.

## 5. Docs and checks

- [x] 5.1 Update `ARCHITECTURE.md`, ADR-002 (the certificate proof), ADR-003 (`baseUpdatedAt`, the 255 cap, folder delete, shared copies), and `../docs/browser-extension/using.md` and `privacy.md`.
- [x] 5.2 Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`; no `chrome.*` in executable code, no decrypted value written to storage, no encryption in popup code.
- [x] 5.3 Walk every scenario of both specs against the dev backend in Chrome and Firefox, including a `card`, an `identity` and a `totp` item opened afterwards in the Keepiq web app.
