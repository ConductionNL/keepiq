## 1. Manifest and messages

- [ ] 1.1 Extend `wxt.config.ts` with permissions `tabs`, `contextMenus`, `clipboardWrite`, `alarms`, Chrome-only `offscreen`, the `commands.autofill_login` entry (`Ctrl+Shift+L`, mac `Command+Shift+L`) and `web_accessible_resources` for `notification.html`
  - Firefox build manifest shows the MV2 string form of `web_accessible_resources` and no `offscreen` permission
  - Content script `matches` stays `*://*/*`
- [ ] 1.2 Add the `fill.*`, `capture.*` and `clipboard.write` envelopes from design.md to `src/messages.ts`, with new `NotificationToBackground` and `BackgroundToOffscreen` unions
  - Every listener keeps the `unknown` cast at the boundary

## 2. Matching and last used

- [ ] 2.1 Add `tldts` and write `src/autofill/matcher.ts` with the six rules over `src/vault/match.ts`, plus `candidatesFor()` that filters out empty `url`, `blocked` rows and non-`login` types and sorts by last used then name
  - Base domain: `accounts.example.co.uk` matches `www.example.co.uk`; `a.github.io` does not match `b.github.io`
  - Invalid regex never matches and never throws; only `http:` and `https:` page URLs are matchable
- [ ] 2.2 Write `src/last-used.ts` storing `lastUsed[accountId][itemId]` in `storage.local`, cleared with the account by the ext-accounts-and-unlock purge

## 3. Fill pipeline

- [ ] 3.1 Write `src/autofill/fields.ts`: username and password detection by type, `autocomplete`, name/id/placeholder/label heuristics, open shadow roots, visibility filter, and `fillField()` using the native value setter plus key, `input` and `change` events
  - Hidden and zero-size fields are skipped; username-only and password-only forms are supported
- [ ] 3.2 Rewrite `entrypoints/content.ts`: answer `fill.collect`, `fill.confirmInsecure` (window.confirm with Bitwarden's wording) and `fill.execute`; drop the credential after writing; keep the context-invalidated latch and `page_ready`
- [ ] 3.3 Write `src/autofill/fill.ts` and wire `fill.request` in `entrypoints/background.ts`: verify sender, collect frames, select top frame plus frames whose `sender.url` matches, insecure check, decrypt `login` and `key`, send `fill.execute` per frame, record last used, return `FillResult`
  - Plaintext is held only inside the handler; cross-origin non-matching frames receive nothing
- [ ] 3.4 Popup (React, ADR-004): add `entrypoints/popup/hooks/useFill.ts` and `useActiveTab.ts`, an `onFill` prop on `ItemCard.tsx` and `Suggestions.tsx`, a Fill button in `views/ItemDetail.tsx`, the insecure warning through the existing `ConfirmDialog.tsx`, the "Unable to autofill on this page" toast, `window.close()` on success, and the "Autofill is not available on this page" state via the `tabs.active` message
  - No component imports `src/api` or `src/crypto`; all calls go through `src/messages.ts` kinds
- [ ] 3.5 Fill on page load: on `page_ready` from a top frame, when the setting is on and the vault is unlocked, fill the single candidate or the last-used one, otherwise do nothing

## 4. Context menu, shortcut, clipboard

- [ ] 4.1 Write `src/menus.ts`: build the "Keepiq" menu on `editable` contexts (Autofill submenu capped at 10 plus "Open Keepiq", "No matching logins", Copy username, Copy password, Generate password and copy, "Unlock vault" when locked); rebuild on install, tab activation, active tab URL change, lock change and sync; honour the show-context-menu setting
- [ ] 4.2 Handle `commands.onCommand` for `autofill_login`: fill the last used or single candidate, else open the popup; add `openPopupOrPopout()` to `src/browser-action.ts` with the `windows.create` fallback
- [ ] 4.3 Add `entrypoints/offscreen/` (plain TypeScript, no React) and `writeFromBackground()` in `src/clipboard.ts`: offscreen document on Chrome guarded by `browser.offscreen`, direct `navigator.clipboard` on Firefox, clear via `browser.alarms` after the settings delay only if the clipboard still holds the value

## 5. Login capture

- [ ] 5.1 Write `src/autofill/capture-collector.ts` and wire it in `entrypoints/content.ts`: detect submit, submit-button click, Enter in a password field and `beforeunload`; report `capture.submitted` once per submission with `changed` for password change forms; skip empty passwords
- [ ] 5.2 Write `src/autofill/capture-queue.ts` in the background: verify sender, drop when locked or excluded or the ask settings are off, compare against decrypted `login` and `key` of matching items to pick add, update or nothing, hold the entry in memory keyed by tab id with a five minute expiry, drop on tab close and lock, show on `tabs.onUpdated` complete within the same base domain, hide on leaving it
- [ ] 5.3 Add `entrypoints/notification/` (bar UI as a vanilla DOM bundle, no React, per ADR-004) and `src/autofill/notification-host.ts` (iframe in a closed shadow root, fixed at the top of the page): the bar loads its state with `capture.state`, renders add and update modes, folder dropdown, Save, Update, Never for this site, close, and the saved and error states
  - Bar never receives the password; `capture.*` decisions are accepted only from the extension origin sender
- [ ] 5.4 Implement `capture.save` (encrypt, `POST /api/v1/secrets` with host name, origin url, `login` type, folder, then sync), `capture.update` (`GET` then `PUT /api/v1/secrets/{id}` with only `key`, 404 falls back to add), and `capture.dismiss` with `never` appending the base domain to excluded domains

## 6. Verify

- [ ] 6.1 Run `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`; load `.output/chrome-mv3/` and `.output/firefox-mv2/` and walk through popup fill, iframe fill, insecure warning, context menu copy with clear, shortcut, save bar, update bar and never-for-this-site on both browsers
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments
