## Context

ext-vault-browse leaves the vault read-only: sync fills `storage.local` with ciphertext rows, the popup lists and decrypts on render, and item detail shows Edit, Delete, Clone and Move as disabled. This change adds the write path. The constraints come from ADR-002 and ADR-003: the private key and the certificate live with the background, `PUT /api/v1/secrets/{id}` is a sparse patch whose `additionalFields` is one blob, and the server has no ETag; only `baseUpdatedAt` stops a write over a newer version. Bitwarden's forms are the UI reference (ADR-001); the Keepiq web app's `secrets-write-ui` behaviour is the reference for what the server and the web app expect in the blobs.

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
- **Edit sends `baseUpdatedAt`.** The server refuses a `PUT` whose `baseUpdatedAt` differs from the stored `updatedAt` with a 409 (`OfflineEditGuard::assertUnchangedSince`), so an edit made elsewhere while the form was open is not overwritten. Move sends only `folderId` and no base, because it cannot clobber another field.
- **The certificate is proven before it encrypts.** Sync stores whatever certificate the server sends under the same suite id and epoch, so `write.ts` encrypts a random value with it and decrypts it with the private key once per certificate, and refuses writes on a mismatch. The private key is the one thing a compromised server cannot swap, since only the master password opens its envelope.
- **The active suite is read before each write.** The server stamps a write with its active suite, not the key it was encrypted to, so a key rotated elsewhere since the last sync locks instead of writing an item no one can open. It costs one request per save.
- **Shared copies keep their limits in two places.** The popup hides what a use-only, read-only or expiring copy may not do, and the background refuses it again: `item.decrypt` withholds a use-only value, `item.fetch` refuses restricted copies by purpose, and `updateItem` refuses what the server would.
- **Every write names its account.** A pop-out window can show account A while the toolbar popup switched to B, so the background refuses a write whose `accountId` is not the active account rather than saving it into B's vault.
- **The URL prefill keeps only origin and path.** The URL is stored unencrypted, and a query or fragment can hold a token.
- **A kept subfolder's name clash is caught before the delete.** The dialog checks the names it can see first, so the choice can change before anything is sent. A 409 to a plan changed nothing; it says so and syncs, which lets the check name the clash, instead of re-reading in a loop.
- **A refusal is worded by its code.** Keepiq's OCS routes refuse with 428 and an `error`, the rest with 403 and a `code` (ADR-003); the API client reads both as one refusal, as the web app does. Only a policy refusal names itself; a blocked suite, someone else's folder and a read-only copy look the same, so they get a neutral message.
- **Shared edits reach only the editor's copy.** The web app's per-recipient fan-out is `ext-share-sync`.
- **Server messages are never shown.** ext-i18n words every error from a code, so 400 and 409 get the extension's own text, chosen by what was being written.
- **The popup computes the sparse patch, the background encrypts only what is in it.** The popup knows the fresh values and the user's edits; `additionalFields` is always sent whole when any member or the notes changed. Alternative: background diffs; rejected because it would need to hold the fresh plaintext across two messages.
- **Empty `additionalFields` is an encrypted `{}`, not `null`.** The Keepiq web app does this (`secrets-write-ui`, "Remove the last additional field") and its detail view treats `{}` as "no fields". Scalar fields (`login`, `url`, `folderId`) clear with `null`, as ADR-003 defines.
- **Notes live in `additionalFields.notes`.** The Keepiq importer already maps Bitwarden notes there (`src/import/parsers/bitwarden.js`), so imported vaults and extension-written notes agree. The editor hides the `notes` member from the custom-field rows and refuses a custom field named `notes`.
- **Composite payloads mirror `src/cardIdentity/cardIdentity.js` and `src/totp/totp.js` of the web app.** Card and identity serialize exactly the web app's member lists with empty strings for unset members; TOTP keeps the seed as typed (URI or bare base32) and only rewrites the URI when the user changed an advanced field. Alternative: a richer Bitwarden-shaped payload; rejected because the web app would render it as invalid.
- **Passkey is read-only.** The edit form never decrypts `key` for a `passkey` and the patch never contains `key`, so the credential JSON cannot be corrupted from the extension.
- **Clone is fetch plus create in the popup.** No server clone exists (ADR-003); the popup fresh-fetches, prefills the add form and calls `item.create`. Passkeys are excluded because the clone would duplicate a credential the RP only knows once.
- **Folder delete follows Keepiq's three modes.** Empty: plain `DELETE`. Non-empty leaf: `?cascade=delete|move`. With subfolders: a `DELETE` with the resolution body (`FolderController::destroy` reads `subfolders` and `directSecrets` from the body). The manager reads `GET /children` before every delete and picks the mode from it, never from the cache, which holds only live items while a cascade also destroys archived and trashed ones; a 409 means something landed since, and reads them again.
- **After every successful write, run the ext-vault-browse sync instead of merging the response row.** ADR-002 says sync runs after every local write and the manifest replaces the snapshot atomically; merging one row would need a second code path for the same state.
- **The default type is the extension's Default item type.** ext-settings stores it per account in `settings.<accountId>`, and the snapshot reply carries it as `defaultSecretType` when the cached types hold it, else `null`, which means `login`. Keepiq's `GET /api/settings/user` is not read.
- **Confirm and resolution dialogs are the `ConfirmDialog` React component, never `window.confirm`.** Firefox closes an extension popup when a native dialog opens; an in-popup component behaves the same in both browsers. The folder picker is a dialog too, so the form under it keeps its state without a page stack.
- **Item actions live in Shell.** The list's More menu and item detail open the same form, picker and delete dialog through the shell context, so both behave the same.
- **The popup is React per ADR-004.** Views compose components, components read state and trigger writes only through hooks that wrap the typed messages; no component imports `src/api` or `src/crypto`, so the encryption boundary stays in the background. Draft state, dirty tracking and validation live in `useItemDraft`, which calls `src/vault/fields.ts` (pure logic, allowed).
- **Offline is derived from the last request outcome.** Every write records its outcome in the same `SyncStatus.offline` the sync sets, so one flag drives the vault list's banner and the disabled writes. `navigator.onLine` is unreliable in a service worker and says nothing about the Keepiq host.
- **A write waits for the sync it triggers.** The reply comes after the snapshot holds the change, so the popup can open the new item; a sync already running is awaited first, because it may have started before the write.

## Module layout

- `src/vault/fields.ts` (new): reserved names, the 255, 4096 and 65536 caps, per-type form fields, card and identity serialize and parse, TOTP seed validation and URI rewrite, form values to draft, draft diff, validation as codes.
- `src/vault/write.ts` (new): encrypt a draft or patch with the cached suite certificate via `src/crypto/`, call the API client for secrets and folders, map failures to `VaultWriteError`, record the outcome, sync.
- `src/vault/sync.ts` (edit): `recordOutcome` for writes and `syncAfterWrite`.
- `src/messages.ts` (edit): the write requests below, and `defaultSecretType` and `folderNoticeDismissed` on the unlocked snapshot reply.
- `src/background/requests.ts` (edit): the write arms, through the existing `PopupRequest` path.
- `entrypoints/popup/views/ItemForm.tsx` (new): add, edit and clone page; edit and clone fetch first.
- `entrypoints/popup/views/FolderPicker.tsx` (new): dialog with "No folder", `FolderTree`, the current folder marked, inline "New folder".
- `entrypoints/popup/views/settings/Vault.tsx` (new): the Vault section with its Folders entry; ext-settings adds the rest.
- `entrypoints/popup/views/settings/FolderManager.tsx` (new): `FolderTree` with create, rename and the three delete flows.
- `entrypoints/popup/components/`: `TypeSelect`, `AdditionalFieldsEditor`, `ConfirmDialog` (the modal every dialog uses), `FolderTree`, `FolderNameDialog`.
- `entrypoints/popup/hooks/useItemDraft.ts` (new): form values, dirty tracking, validation and the patch, all through `src/vault/fields.ts`.
- `entrypoints/popup/hooks/useUnsavedChangesGuard.ts` (new): registers a dirty form with Shell, which asks before Back, Pop out or the account switcher drops it.
- `entrypoints/popup/views/Shell.tsx` (edit): the form page, the item actions, the move and delete dialogs, the discard prompt.
- Header (edit): the primary "New" button, which Shell shows on the Vault list only.
- Item card and item detail (edit): the live Edit, Delete, Clone and Move actions.
- No component imports `src/api` or `src/crypto`; writes go through `request` from `useMessage.ts`.

## Message contract

Additions to `PopupRequest` in `src/messages.ts`, with their replies in `PopupReplies`:

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

/** A fresh fetch, decrypted for the form. `DecryptedItem` is ext-vault-browse's partial decrypt. */
export interface EditableItem extends ItemDraft {
	id: string
	updatedAt: string
	/** The type the fetch checked the item under, absent for a type the vault does not know; `passkey`, with `key` empty, when the server or the cache says so. */
	typeName?: string
	/** Edit only: names this form's record of what it opened (ext-share-sync). */
	recordId?: string
}

export interface FolderChildren {
	directSecretCount: number
	subfolders: Array<{ id: string; name: string; secretCount: number; subfolderCount: number }>
}

export type FolderDeletePlan =
	| { mode: 'plain' }
	| { mode: 'cascade'; cascade: 'delete' | 'move' }
	| { mode: 'resolution'; directSecrets: 'delete' | 'move'; subfolders: Record<string, 'delete' | 'move' | 'keep'> }

export type WriteErrorCode =
	| 'locked'          // no key
	| 'session_revoked' // 401, already logged out
	| 'write_locked'    // 423
	| 'forbidden'       // a refusal without a code: blocked suite, someone else's folder, read-only copy
	| 'policy'          // a refusal naming a vault policy
	| 'account_changed' // the write named an account that is no longer active
	| 'invalid'         // 400
	| 'conflict'        // 409: duplicate folder, folder not empty, item changed since baseUpdatedAt
	| 'not_found'       // 404
	| 'blocked'         // the fetch was refused and the sync marked the item blocked, with `reason`
	| 'unreadable'      // the fetched item does not decrypt or parse
	| 'offline'         // no response
	| 'server_error'    // 5xx
	| 'restricted'      // a use-only, read-only or (for a clone) expiring shared copy
	| 'key_mismatch'    // the suite certificate does not match the unlocked key
	| 'unknown'

export interface VaultWriteError { code: WriteErrorCode; reason?: BlockReason }

export type WriteResult<T> = { ok: true; value: T } | { ok: false; error: VaultWriteError }

export type PopupRequest =
	| /* existing arms */
	| { kind: 'item.fetch'; id: string; purpose: 'edit' | 'clone' } // → WriteResult<EditableItem>
	| { kind: 'item.create'; draft: ItemDraft }             // → WriteResult<{ id: string }>
	| { kind: 'item.update'; id: string; patch: ItemPatch; baseUpdatedAt?: string } // → WriteResult<{ id: string }>
	| { kind: 'item.delete'; id: string }                   // → WriteResult<null>
	| { kind: 'folder.create'; name: string; parentId: string | null } // → WriteResult<{ id: string }>
	| { kind: 'folder.rename'; id: string; name: string }   // → WriteResult<null>
	| { kind: 'folder.children'; id: string }               // → WriteResult<FolderChildren>
	| { kind: 'folder.delete'; id: string; plan: FolderDeletePlan } // → WriteResult<null>
	| { kind: 'folder.dismissNotice' }                      // → null
```

`FolderChildren` is the `GET /api/v1/folders/{id}/children` payload (`FolderDeletionService::describeChildren`). The unlocked snapshot reply gains `defaultSecretType: string | null` and `folderNoticeDismissed: boolean`. A 401 is handled by the account layer from ext-accounts-and-unlock before any of these arms see it. The notice flag is `folderNotice.<accountId>` in `storage.local`, cleared when the account is removed.

Browser differences: the URL prefill reuses `useCurrentTab` from ext-vault-browse, so a pop-out window prefills from the tab it came from. WebCrypto encrypt runs in the MV3 service worker and the MV2 background page identically. No `action` or `storage.session` use is added here.

## Risks / Trade-offs

- [Two clients edit the same item between fetch and save] → `baseUpdatedAt` makes the second save a 409; the form keeps the typed values and says the item changed elsewhere. The server compares whole seconds, so two saves within one second still race.
- [Notes in `additionalFields.notes` collide with a user's own custom field named `notes`] → The editor refuses that name; older items with such a member show it as Notes.
- [Composite JSON drifts from the web app's serializer] → `fields.ts` copies the member lists verbatim from `cardIdentity.js` and a task pins them in a round-trip check.
- [A draft with a password is lost when the popup closes] → The unsaved-changes guard covers in-popup navigation; persistence would put plaintext in storage, which ADR-002 forbids.
- [The Default item type names a type the cached `secret-types` do not contain] → Fall back to `login`.
- [MV3 worker restarts mid-write] → Each arm is a single `fetch` after encrypt; the popup shows the network error and the next sync tells the truth.

## Open Questions

- Should the folder manager also offer folder move (re-parent) via `PUT` with `parentId` and `move: true`, since Keepiq supports it and Bitwarden's slash-rename is the equivalent?
- Should password history from `GET /api/v1/secrets/{secretId}/versions` land in ext-settings' scope or its own change?
- Should the masking toggle default to masked for every additional field, or only for rows whose name matches a password-like pattern, given nothing is persisted?
