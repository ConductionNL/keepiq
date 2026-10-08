## 1. Vault data layer

- [x] 1.1 Add `src/vault/types.ts` and `src/vault/store.ts` with `readSnapshot`, `writeSnapshot`, `clearSnapshot` and `toItemMeta` on one `storage.local` key per account
  - Handles both the full row and the blocked row shape from ADR-003
  - `clearSnapshot` is called from the logout and account-removal paths of ext-accounts-and-unlock
- [x] 1.2 Add `src/vault/sync.ts`: change probe (`updated_at` desc, limit 1, compare `updatedAt` and `total`) with the suite list, manifest fetch, fallback on 403/404 or a full 1000 rows to paginated secrets (second pass on a short count) plus folders, types and suites, suite-change check, single in-flight promise, `SyncStatus`
  - Uses `src/api/client.ts`; 401 is delegated to the account layer, 423 sets `lastError: 'busy'`
  - A failed fallback leaves the previous snapshot untouched
- [x] 1.3 Wire sync triggers in `entrypoints/background.ts`: `vault-sync` alarm with `SYNC_INTERVAL_MINUTES`, unlock and lock hooks, popup-open staleness check, `vault.sync` arm; add `alarms` and `unlimitedStorage` to `wxt.config.ts`
  - Alarm cleared on lock and logout; build both targets
- [x] 1.4 Add `src/vault/match.ts` on `tldts` with `baseDomain`, `matchesBaseDomain`, `suggestionIds`; add the `tldts` dependency
- [x] 1.5 Add the `vault.snapshot` and `item.decrypt` arms in `entrypoints/background.ts` and their types in `src/messages.ts`
  - `vault.snapshot` returns metadata only plus `suggestionIds` for `tabUrl`; refuses with `locked` or `logged_out`
  - `item.decrypt` batches ids, refuses blocked rows, never writes plaintext to storage

## 2. Popup shell

- [x] 2.1 Rework `entrypoints/popup/popup.css`: 380 px width, 600 px height with scrolling content, fixed header and tab bar regions, `data-theme` token overrides, popout fluid width
- [x] 2.2 Add `entrypoints/popup/views/Shell.tsx`, `views/Placeholder.tsx` and `components/TabBar.tsx`; mount `Shell` from `App.tsx` once unlocked; extend the `Header` component with the pop-out button; view stack, locked and logged-out gating, `popup.lastTab` read with the popup state and written with `popup.lastTab.set`
  - Tab bar hidden while locked or logged out; placeholders on Generator, Send, Settings
- [x] 2.3 Add pop out: `popup.popout` arm in the background using `browser.windows.create` with `?popout=1&tabId=`, popup closes itself, button hidden in popout; add `hooks/useCurrentTab.ts` reading `tabs.query` or the `tabId` parameter; add `tabs` permission
  - Verified on Chrome and Firefox builds

## 3. Vault list

- [x] 3.1 Add `components/SearchField.tsx`, `FolderSelect.tsx` (All items, flattened tree, No folder) and `TypeFilterChips.tsx` (chips plus More menu built from snapshot types); filter state lives in `VaultList` and composes
- [x] 3.2 Add `views/VaultList.tsx`, `components/ItemCard.tsx` and `src/vault/icons.ts` with `hooks/useVaultSnapshot.ts` and `hooks/useDecryptedFields.ts`: locale sort by name with id tiebreak, type icon, name, lazy login subtitle via batched `item.decrypt` for rows in view, blocked badge, decrypted state dropped on unmount
- [x] 3.3 Add `components/Suggestions.tsx` rendered by `VaultList` from `suggestionIds` and `useCurrentTab`: host heading, hidden without an http(s) tab, "No items for <host>" when empty
- [x] 3.4 Add `components/Menu.tsx` and the `ItemCard` actions: Launch via `browser.tabs.create` (https prefix), Copy menu (username, password, verification code) through `hooks/useClipboard.ts` and `components/Toast.tsx`, More menu with View enabled and Edit/Clone/Move/Delete disabled with tooltip
- [x] 3.5 Add `clipboard.copied` arm, `clipboard-clear` alarm and `clearClipboard()` in `src/clipboard.ts` with the Chrome offscreen document (`entrypoints/offscreen/`, `offscreen` permission) and the Firefox page path; `clipboardWrite` on both
  - Delay read from `settings.clearClipboardMs`, absent means never; build both targets
- [x] 3.6 Add `Banner` to `components/ErrorBanner.tsx`, `components/EmptyState.tsx` and the list states: syncing on first sync, empty vault, no matches with Clear filters, blocked-only, offline banner with "Last synced <relative>" and "Sync now", first-sync error with retry

## 4. Item detail

- [x] 4.1 Add `src/totp/totp.ts` ported from the web app's `src/totp/totp.js`: otpauth and bare base32 parsing, SHA1/SHA256/SHA512, 6 or 8 digits, period, RFC 6238 code with WebCrypto, `secondsRemaining`
- [x] 4.2 Add `views/ItemDetail.tsx`, `components/MaskedField.tsx` (masked value, reveal, copy) and `components/TotpCode.tsx` (code plus countdown, invalid-seed state): back control restoring list state, header with type label and folder path, Login credentials, TOTP, Website, Additional fields (masked, `notes` routed to Notes), Notes, Metadata; values through `useDecryptedFields`
- [x] 4.3 Add card, identity, passkey, generic and blocked sections to `ItemDetail`: card brand and last four derived in memory, BSN masked, passkey not-yet-supported note and no private key in the DOM, blocked reason with web app link and no decrypt request
- [x] 4.4 Add disabled Edit and Delete with tooltip; `TotpCode` and `useDecryptedFields` cleanup stops timers and drops decrypted state on unmount, and `Shell` unmounts the view on `vault.locked`

## 5. Verification

- [x] 5.1 Add `vitest` with unit tests for `src/totp/totp.ts` (RFC 6238 test vectors), `src/vault/match.ts` (`co.uk` cases), folder flattening and search filtering
- [ ] 5.2 Run `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`; load `.output/chrome-mv3/` and `.output/firefox-mv2/` and walk the scenarios in the four specs
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments
  - Offline check: stop the dev server, reopen the popup, confirm the banner and that Sync now recovers
