---
kind: code
depends_on: [ext-vault-browse]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-generator
  - ext-send
  - ext-settings
  - ext-autofill
---

## Why

After ext-vault-browse the extension can show the vault but not change it: the Edit, Delete, Clone and Move actions on item detail are disabled and there is no way to add an item or a folder. Bitwarden users expect to save a new login from the extension without opening the web app, and autofill (ext-autofill) will need the same write path to save credentials it captures.

## What Changes

- Add item from the Vault tab via a "+" button, with a type select and type-specific fields, mirroring Bitwarden's "Add item" form.
- Edit, Delete, Clone and Move to folder on item detail become live.
- Notes, additional (custom) fields and composite types (`totp`, `card`, `identity`) are editable; `passkey` items are editable only in name, folder and URL.
- Folder management: create, rename and delete folders from Settings, and create a folder inline from the folder picker.
- All encryption happens in the background, which holds the suite certificate. The popup sends plaintext drafts over runtime messages and never encrypts.
- Every successful write triggers the sync that ext-vault-browse provides, so the cached vault in `storage.local` reflects the server state.

## Capabilities

### New Capabilities

- `item-editing`: add, edit, delete, clone and move vault items, including notes, additional fields and composite types.
- `folder-management`: create, rename and delete folders as a tree, and pick a folder for an item.

### Modified Capabilities

None.

## Deviations from Bitwarden

Mirrored feature: Bitwarden's browser extension "Add item" and item edit forms, the item detail menu (Edit, Clone, Move, Delete) and Settings → Vault → Folders.

- No trash. Bitwarden soft-deletes into a 30-day trash; Keepiq has no trash, so Delete is a hard delete after a confirm dialog (forced by the API).
- Notes have no column. Bitwarden stores notes as a first-class field; Keepiq has none, so the extension stores notes under `additionalFields.notes` for non-note types and in `key` for the `note` type. This follows the Keepiq importer, which already maps Bitwarden notes that way (deferred question).
- Folder names are plaintext on the server. Bitwarden encrypts folder names; Keepiq's folder rows are plaintext by design (ADR-003, forced).
- Folders are a real tree. Bitwarden emulates nesting with `/` in names; Keepiq rejects slashes and stores `parentId`, so the extension renders a tree (forced, Keepiq's spec wins per ADR-001).
- Deleting a non-empty folder asks what to do with its items. Bitwarden silently leaves the items without a folder; Keepiq's delete protocol requires an explicit cascade or a per-subfolder resolution (forced).
- TOTP is a separate item type. Bitwarden keeps the authenticator key as a field of a login item; Keepiq has a `totp` system type whose `key` holds the seed (forced).
- Card and identity have Keepiq's smaller field sets (`number, expiry, cvv, pin, cardholder` and `firstName, lastName, address, phone, email, bsn`) instead of Bitwarden's, so items stay readable in the Keepiq web app (forced, Keepiq's spec wins).
- Additional field masking is not persisted. Bitwarden stores a field type (text, hidden, boolean, linked); Keepiq's `additionalFields` blob is name to value only, so the per-row masking toggle is a view state (forced, deferred question).
- Passkeys are read-only. Bitwarden lets the user remove a passkey from a login; the extension edits only name, folder and URL of a `passkey` item and never touches the credential JSON (chosen, deferred question).
- No password history. Bitwarden shows previous passwords; Keepiq has `GET /api/v1/secrets/{secretId}/versions`, which a later change can surface (chosen).
- No draft persistence. Bitwarden keeps a draft when the popup is popped out; the extension warns before navigating away inside the popup and loses the draft when the popup closes (chosen, deferred question).

## Keepiq API used

All from ADR-003.

- `GET /api/v1/secrets/{id}`
- `POST /api/v1/secrets`
- `PUT /api/v1/secrets/{id}`
- `DELETE /api/v1/secrets/{id}`
- `GET /api/v1/folders`
- `POST /api/v1/folders`
- `PUT /api/v1/folders/{id}`
- `GET /api/v1/folders/{id}/children`
- `DELETE /api/v1/folders/{id}`
- `GET /api/v1/secret-types`
- `GET /api/settings/user`

## Impact

- New: `src/vault/write.ts`, `src/vault/fields.ts`; React views `ItemForm`, `FolderPicker`, `FolderManager` under `entrypoints/popup/views/`; components `TypeSelect`, `AdditionalFieldsEditor`, `ConfirmDialog`, `FolderTree` under `entrypoints/popup/components/`; hooks `useItemDraft`, `useUnsavedChangesGuard` under `entrypoints/popup/hooks/` (ADR-004).
- Reused: `MaskedField`, `TextField`, `Button`, `ErrorBanner` and `useMessage` from ext-vault-browse and ext-accounts-and-unlock.
- Edited: `src/messages.ts` (write messages), `entrypoints/background.ts` (write handlers), the vault list and item detail views from ext-vault-browse (the "+" button and the enabled actions).
- Depends on the API client and `src/crypto/` from ext-accounts-and-unlock and on sync, cached `secret-types`, the vault list and item detail from ext-vault-browse.
- The generate button depends on ext-generator's "pick a password" mode; until that change lands the button is hidden.
- No new manifest permissions. The current-tab URL prefill uses the `tabs` permission ext-vault-browse already needs for URL matching.
