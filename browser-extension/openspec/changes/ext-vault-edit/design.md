## Context

ext-vault-browse leaves the vault read-only: sync fills `storage.local` with ciphertext rows, the popup lists and decrypts on render, and item detail shows Edit, Delete, Clone and Move as disabled. This change adds the write path. The constraints come from ADR-002 and ADR-003: the private key and the certificate live with the background, `PUT /api/v1/secrets/{id}` is a sparse last-write-wins patch whose `additionalFields` is one blob, and the server has no ETag or revision. Bitwarden's forms are the UI reference (ADR-001); the Keepiq web app's `secrets-write-ui` behaviour is the reference for what the server and the web app expect in the blobs.

## Goals / Non-Goals

**Goals:**
- Add, edit, delete, clone and move items with all encryption in the background.
- Notes, additional fields and the composite types `totp`, `card`, `identity` round-trip with the Keepiq web app.
- Folder create, rename and delete following Keepiq's three-mode delete protocol.
- Every write is followed by the existing sync, so the cache never holds a locally invented row.

**Non-Goals:**
- Password history (`GET /api/v1/secrets/{secretId}/versions`), a later change.
- Creating or editing passkey credentials, attachments, sharing, expiry and compromise flags.
- Folder move (re-parent), custom folder icon and colour.
- Draft persistence across popup close.
- Saving credentials captured from a page (ext-autofill reuses `item.create`).

## Decisions

- **The background encrypts, the popup sends plaintext drafts.** The certificate and the private key are the background's per ADR-002, and one encrypt path is easier to audit. Alternative: ship the certificate to the popup; rejected because it doubles the crypto surface.
- **Edit fresh-fetches and decrypts before the form opens.** ADR-002 names this as the mitigation for the whole-blob `additionalFields` patch. Alternative: edit the cached row and PUT; rejected because a stale cache silently deletes members another client added.
- **The popup computes the sparse patch, the background encrypts only what is in it.** The popup knows the fresh values and the user's edits; `additionalFields` is always sent whole when any member or the notes changed. Alternative: background diffs; rejected because it would need to hold the fresh plaintext across two messages.
- **Empty `additionalFields` is an encrypted `{}`, not `null`.** The Keepiq web app does this (`secrets-write-ui`, "Remove the last additional field") and its detail view treats `{}` as "no fields". Scalar fields (`login`, `url`, `folderId`) clear with `null`, as ADR-003 defines.
- **Notes live in `additionalFields.notes`.** The Keepiq importer already maps Bitwarden notes there (`src/import/parsers/bitwarden.js`), so imported vaults and extension-written notes agree. The editor hides the `notes` member from the custom-field rows and refuses a custom field named `notes`.
- **Composite payloads mirror `src/cardIdentity/cardIdentity.js` and `src/totp/totp.js` of the web app.** Card and identity serialize exactly the web app's member lists with empty strings for unset members; TOTP keeps the seed as typed (URI or bare base32) and only rewrites the URI when the user changed an advanced field. Alternative: a richer Bitwarden-shaped payload; rejected because the web app would render it as invalid.
- **Passkey is read-only.** The edit form never decrypts `key` for a `passkey` and the patch never contains `key`, so the credential JSON cannot be corrupted from the extension.
- **Clone is fetch plus create in the popup.** No server clone exists (ADR-003); the popup fresh-fetches, prefills the add form and calls `item.create`. Passkeys are excluded because the clone would duplicate a credential the RP only knows once.
- **Folder delete follows Keepiq's three modes.** Empty: plain `DELETE`. Non-empty leaf: `?cascade=delete|move`. With subfolders: `GET /children` then a `DELETE` with the resolution body (`FolderController::destroy` reads `subfolders` and `directSecrets` from the body). The picker and manager decide the mode from the cached rows, the server's 409 is the fallback when the cache is behind.
- **After every successful write, run the ext-vault-browse sync instead of merging the response row.** ADR-002 says sync runs after every local write and the manifest replaces the snapshot atomically; merging one row would need a second code path for the same state.
- **`default_secret_type` is fetched with `GET /api/settings/user` during sync and cached in `storage.local` with the account.** ext-settings later reuses the same cache. Alternative: wait for ext-settings; rejected because the add form needs a default now.
- **Confirm and resolution dialogs are the `ConfirmDialog` React component, never `window.confirm`.** Firefox closes an extension popup when a native dialog opens; an in-popup component behaves the same in both browsers.
- **The popup is React per ADR-004.** Views compose components, components read state and trigger writes only through hooks that wrap the typed messages; no component imports `src/api` or `src/crypto`, so the encryption boundary stays in the background. Draft state, dirty tracking and validation live in `useItemDraft`, which calls `src/vault/fields.ts` (pure logic, allowed).
- **Offline is derived from the last request outcome, reported by the background in the vault state.** `navigator.onLine` is unreliable in a service worker and says nothing about the Keepiq host.

## Module layout

- `src/vault/fields.ts` (new): reserved names, the 4096 and 65536 caps, per-type field schemas, card and identity serialize and parse, TOTP seed validation and URI rewrite, draft validation.
- `src/vault/write.ts` (new): encrypt a draft or patch with the suite certificate via `src/crypto/`, call the API client for secrets and folders, map status codes to `VaultWriteError`, trigger sync.
- `src/messages.ts` (edit): the write messages below and `online` on the vault state.
- `entrypoints/background.ts` (edit): request/response arms for the write messages, returning the Promise as the existing `get_state` arm does.
- `entrypoints/popup/views/ItemForm.tsx` (new): add, edit and clone screen composed from the components below.
- `entrypoints/popup/views/FolderPicker.tsx` (new): "No folder", `FolderTree`, current folder marked, inline "New folder".
- `entrypoints/popup/views/FolderManager.tsx` (new): `FolderTree` with create, rename, delete and the two delete dialogs.
- `entrypoints/popup/components/TypeSelect.tsx` (new): type select over the cached `secret-types`.
- `entrypoints/popup/components/AdditionalFieldsEditor.tsx` (new): repeatable name and value rows with per-row masking and the reserved, blank and duplicate refusals.
- `entrypoints/popup/components/ConfirmDialog.tsx` (new): in-popup dialog for delete confirms, the cascade choice and the subfolder resolution plan.
- `entrypoints/popup/components/FolderTree.tsx` (new): indented tree by `parentId`, shared by picker and manager.
- Reused from ext-vault-browse and ext-accounts-and-unlock: `MaskedField`, `TextField`, `Button`, `ErrorBanner`.
- `entrypoints/popup/hooks/useItemDraft.ts` (new): draft state, dirty tracking, validation via `src/vault/fields.ts`, patch computation against the fresh item.
- `entrypoints/popup/hooks/useUnsavedChangesGuard.ts` (new): the discard prompt on in-popup navigation while a draft is dirty.
- Write messages are sent through the `useMessage` hook from ext-accounts-and-unlock; no component imports `src/api` or `src/crypto`.
- Vault list and item detail views from ext-vault-browse (edit): the "+" button and the live Edit, Delete, Clone and Move actions.
- `entrypoints/popup/popup.css` (edit): form, row and dialog styles, split per component when a file grows.

## Message contract

Additions to `PopupToBackground` in `src/messages.ts`, request/response:

```ts
/** Plaintext draft. The background encrypts key, login and additionalFields. */
export interface ItemDraft {
	typeId: string
	name: string
	url: string | null
	folderId: string | null
	key: string
	login: string | null
	/** Name to value, `notes` included. `{}` when empty, never absent on create. */
	additionalFields: Record<string, string>
}

/** Fields the user changed. `null` clears. additionalFields is always whole. */
export type ItemPatch = Partial<ItemDraft>

export interface DecryptedItem extends ItemDraft {
	id: string
	updatedAt: string
	/** Present only for passkey, where key is not decrypted. */
	keyWithheld?: true
}

export type FolderDeletePlan =
	| { mode: 'plain' }
	| { mode: 'cascade'; cascade: 'delete' | 'move' }
	| { mode: 'resolution'; directSecrets: 'delete' | 'move'; subfolders: Record<string, 'delete' | 'move' | 'keep'> }

export type VaultWriteError =
	| { code: 'write_locked' }            // 423
	| { code: 'suite_blocked' }           // 403
	| { code: 'invalid'; message: string } // 400
	| { code: 'conflict'; message: string } // 409
	| { code: 'not_found' }               // 404
	| { code: 'blocked'; reason: string } // row.blocked === true
	| { code: 'offline' }                 // no response

export type WriteResult<T> = { ok: true; value: T } | { ok: false; error: VaultWriteError }

export type PopupToBackground =
	| /* existing arms */
	| { kind: 'item.fetch'; id: string }                    // → WriteResult<DecryptedItem>
	| { kind: 'item.create'; draft: ItemDraft }             // → WriteResult<{ id: string }>
	| { kind: 'item.update'; id: string; patch: ItemPatch } // → WriteResult<{ id: string }>
	| { kind: 'item.delete'; id: string }                   // → WriteResult<null>
	| { kind: 'folder.create'; name: string; parentId: string | null } // → WriteResult<{ id: string }>
	| { kind: 'folder.update'; id: string; name: string }   // → WriteResult<null>
	| { kind: 'folder.children'; id: string }               // → WriteResult<FolderChildren>
	| { kind: 'folder.delete'; id: string; plan: FolderDeletePlan } // → WriteResult<null>
```

`FolderChildren` is the `GET /api/v1/folders/{id}/children` payload as ADR-003 and the Keepiq secrets spec define it. The vault state ext-vault-browse returns gains `online: boolean` and `defaultSecretType: string | null`. A 401 is handled by the account layer from ext-accounts-and-unlock before any of these arms see it.

Browser differences: `browser.tabs.query({ active: true, currentWindow: true })` for the URL prefill exists on MV3 and MV2 alike and needs the `tabs` permission ext-vault-browse adds. WebCrypto encrypt runs in the MV3 service worker and the MV2 background page identically. No `action` or `storage.session` use is added here.

## Risks / Trade-offs

- [Last-write-wins: two clients editing the same item between fetch and save lose one edit] → The fresh fetch on Edit shortens the window to the seconds the form is open; the popup shows `updatedAt` in detail so the user can notice; a revision check needs a server change.
- [The cached rows may say a folder is a leaf when the server has a new subfolder] → Treat the 409 "resolution required" as "re-fetch children and show the resolution dialog", never as a fatal error.
- [Notes in `additionalFields.notes` collide with a user's own custom field named `notes`] → The editor refuses that name; older items with such a member show it as Notes.
- [Composite JSON drifts from the web app's serializer] → `fields.ts` copies the member lists verbatim from `cardIdentity.js` and a task pins them in a round-trip check.
- [A draft with a password is lost when the popup closes] → The unsaved-changes guard covers in-popup navigation; persistence would put plaintext in storage, which ADR-002 forbids.
- [`default_secret_type` names a type the cached `secret-types` do not contain] → Fall back to `login`.
- [MV3 worker restarts mid-write] → Each arm is a single `fetch` after encrypt; the popup shows the network error and the next sync tells the truth.

## Open Questions

- Should the folder manager also offer folder move (re-parent) via `PUT` with `parentId` and `move: true`, since Keepiq supports it and Bitwarden's slash-rename is the equivalent?
- Should password history from `GET /api/v1/secrets/{secretId}/versions` land in ext-settings' scope or its own change?
- Should the masking toggle default to masked for every additional field, or only for rows whose name matches a password-like pattern, given nothing is persisted?
