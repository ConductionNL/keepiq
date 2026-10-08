---
kind: code
depends_on: [ext-accounts-and-unlock]
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

After ext-accounts-and-unlock a user can sign in and unlock, but sees nothing. This change is spec 2 of 7 in the extension chain and delivers the read-only vault: the popup shell every later tab plugs into, a cached snapshot of the vault that works offline, a searchable and filterable item list with quick actions, and an item detail view that decrypts on demand. Editing (ext-vault-edit), filling (ext-autofill) and the other tabs build on the shell, the snapshot and the messages defined here.

## What Changes

- Vault sync: fetches the whole vault from `GET /api/v1/offline/manifest`, falling back to paginated listing when the manifest is unavailable, and stores it atomically in `storage.local` per account (ADR-002). Runs on unlock, on popup open when stale, on a 15 minute alarm while unlocked, after every local write and on "Sync now". Detects a changed encryption suite and purges the cache and key.
- Popup shell: Bitwarden layout at 380 x 600 with a header (title, active-tab host, pop-out button, account avatar), a content area and a bottom tab bar with Vault, Generator, Send and Settings. Generator, Send and Settings render placeholders until their changes land. Light and dark theme tokens follow the system.
- Vault list: search bar, folder dropdown and type filter chips over the cached snapshot, an "Autofill suggestions" section matched by base domain against the active tab, alphabetically sorted item cards with Launch, Copy and More actions, and empty, loading, offline and blocked states.
- Item detail: header with name, type and folder path; per-type sections (login, TOTP with live code, websites, additional fields, notes, card, identity, passkey, generic, blocked) with masked values, reveal toggles and copy; created and updated metadata; disabled Edit and Delete buttons until ext-vault-edit.
- Clipboard: copy happens in the popup with `navigator.clipboard.writeText`; the background schedules the clipboard-clear alarm when a clear delay is configured (the setting UI arrives in ext-settings).
- Messages added to `src/messages.ts`: `vault.sync`, `vault.snapshot`, `item.decrypt`, `clipboard.copied`, `popup.popout`.
- Manifest permissions added in `wxt.config.ts`: `alarms`, `tabs`, `unlimitedStorage`; `offscreen` on Chrome only; `clipboardWrite` on Firefox only.

## Capabilities

### New Capabilities

- `vault-sync`: fetching, caching, refreshing and invalidating the encrypted vault snapshot, including offline behaviour and blocked rows.
- `popup-shell`: the popup frame, bottom tab bar, locked and logged-out gating, pop-out window, sizing and theme tokens.
- `vault-list`: the Vault tab: search, folder and type filters, autofill suggestions, item cards with quick actions, sorting and empty states.
- `item-detail`: the read-only item view with per-type sections, masking, TOTP code generation, copy and metadata.

### Modified Capabilities

None. `openspec/specs/` is empty today.

## Deviations from Bitwarden

Mirrored Bitwarden features: the popup shell with bottom tabs and pop-out, the vault tab with search, filters and autofill suggestions, the item card actions (Launch, Copy, More), the item view with masked fields, TOTP countdown and copy, scheduled and on-unlock full sync, clipboard auto-clear.

- Search matches only plaintext `name` and `url`. Bitwarden also matches username and notes; Keepiq stores those as RSA ciphertext and matching them would mean decrypting every row on every keystroke. Forced by the crypto model (ADR-003).
- No website icons. Bitwarden loads favicons from its own icon server; this extension never fetches icons from sites or icon services because that reveals the vault's domains outside the user's server (ADR-002). Type icons are used instead. The future path is a favicon stored on the secret in Keepiq and rendered from the cached row; there is no toggle until that exists.
- A "Last synced <relative time>" line and an offline indicator appear when the snapshot is served from cache. Bitwarden syncs silently. Keepiq's own `offline-readonly-cache` spec requires the stale-data banner, so this follows ADR-001's "Keepiq's spec wins" clause.
- All additional field values are masked by default. Bitwarden distinguishes text, hidden, boolean and linked custom fields; Keepiq's `additionalFields` is an untyped name-to-value map, so the safe default is to mask. Forced by the API.
- There is no notes column. The Notes section shows the decrypted `key` of a `note` type item and, for other types, an additional field named `notes`. Forced by the API.
- Blocked rows (`blocked: true`) are shown with a blocked badge and reason and offer no values or copy actions. Bitwarden has no equivalent state. Forced by the API.
- Passkeys are view-only, with a note that passkey use is not yet supported. Bitwarden's extension acts as a passkey provider. Deferred to a later change, not a permanent deviation.
- The type filter is a row of chips rather than Bitwarden's dropdown, so filters compose visibly in the narrow popup. Not forced.
- The full sync interval is a named constant of 15 minutes. Bitwarden syncs on a schedule as well; the exact value is a project choice and is listed under design open questions.
- Bitwarden allows nothing while locked. This shell hides the tab bar while locked too, but the unlock view carries a "Generate a password" link into the generator (ext-generator), a deliberate deviation because generation needs no key material.

Out of scope rather than deviations (ADR-001): favourites, organisations, collections, Bitwarden Premium features.

## Keepiq API used

All from ADR-003, relative to `https://<host>/index.php/apps/keepiq`:

- `GET /api/v1/offline/manifest`
- `GET /api/v1/secrets` (paginated fallback, `limit=100`, `page` until `total`)
- `GET /api/v1/folders`
- `GET /api/v1/secret-types`
- `GET /api/v1/suites`

## Impact

- New modules: `src/vault/types.ts`, `src/vault/store.ts`, `src/vault/sync.ts`, `src/vault/match.ts`, `src/totp/totp.ts`, `src/clipboard.ts`, `entrypoints/offscreen/` (Chrome only); React views `Shell`, `VaultList`, `ItemDetail`, `Placeholder` under `entrypoints/popup/views/`, components `TabBar`, `SearchField`, `FolderSelect`, `TypeFilterChips`, `ItemCard`, `Menu`, `MaskedField`, `TotpCode`, `Banner`, `EmptyState` under `entrypoints/popup/components/`, hooks `useVaultSnapshot`, `useDecryptedFields`, `useCurrentTab`, `useClipboard` under `entrypoints/popup/hooks/` (ADR-004).
- Edited: `entrypoints/background.ts` (message arms, alarms, sync triggers), `entrypoints/popup/App.tsx` and the `Header` component from ext-accounts-and-unlock, `entrypoints/popup/popup.css`, `src/messages.ts`, `wxt.config.ts` (permissions).
- Depends on ext-accounts-and-unlock for the account store, unlock state, `src/api/client.ts`, `src/crypto/`, the React setup (`main.tsx`, `App.tsx`, `useMessage`, `usePopupState`) and the `Header`, `Button`, `TextField` and `ErrorBanner` components.
- New dependency: `tldts` for public-suffix-aware base domain extraction (bundled list, no network). Optional dev dependency `vitest` for the pure modules.
- Unblocks ext-vault-edit (wires Edit, Clone, Move, Delete), ext-autofill (Fill button, `src/vault/match.ts`), ext-generator, ext-send and ext-settings (tab content, theme switch, clipboard-clear and website-icon settings).
