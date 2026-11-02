## 1. Menu

- [ ] 1.1 In `src/menus.ts`: the contexts of design.md, the entry tree and order of spec § Entries and order, every entry a parent with its `<prefix>-none` line when empty, `add:*` and `open:*` per Autofill submenu, the two separators; `contextMenu.autofill` becomes "Autofill login" and `noMatches` "No logins available"
  - Tests: one, none and twelve matches per submenu; no title holds a decrypted value; locked, logged out, setting off; card and identity parents with an empty vault
- [ ] 1.2 `add:*` and `open:*` handled before any `await` in `onMenuClicked`, with the intent set in memory, cleared on lock and account switch through `onBroadcast`
  - Tests: an Add click calls `openPopupOrPopout` synchronously; the intent expires after 10 seconds and after one read

## 2. No-form notice

- [ ] 2.1 `noFields` on `FillResult` in `src/messages.ts` and `src/autofill/fill.ts`, and in the card and identity branch; `act()` sends `notice.show` to frame 0 after a menu fill with `noFields`
  - Tests: no recorded frame, every frame `none`, a declined question, a lock and a decrypt failure; only the first two notify
- [ ] 2.2 The notice host in `src/autofill/notification-host.ts` and `entrypoints/content.ts`, below an open save bar; the notice mode of `entrypoints/notification/` with `role="status"`, Close and the 8-second timer paused by pointer and focus
  - Tests: the three codes' texts, a second notice replacing the first, focus never moved, the timer paused while hovered
- [ ] 2.3 Add `test-site/no-form.html`, an article page without a form for the test site's login, linked from `test-site/index.html`

## 3. Add forms

- [ ] 3.1 `popup.intent` in `src/background/requests.ts`; the popup's Shell asks once on mount and opens the add form with `fixedType`
  - Tests: the toolbar popup and the pop-out; locked answers `null`
- [ ] 3.2 `fixedType` in `ItemForm.tsx`: no `TypeSelect`, the "New <type>" title, the system type by name, the vault list when it is missing
  - Popup tests in both projects for login, card and identity; Cancel back to the list; Save as from New

## 4. Texts, docs and verification

- [ ] 4.1 Every new text in `locales/en.yml` and `locales/nl.yml`; `locales.test.ts` passes
- [ ] 4.2 Update ARCHITECTURE.md (the menu, the notice, `popup.intent`), SECURITY.md (menu titles in Chrome's extension state, the notice carries a code only), ADR-001's deviations (anywhere, names only), `../docs/browser-extension/using.md`; the context menu row of ext-autofill's proposal deviations points here
  - Every comment and doc line follows CLAUDE.md § Comments & docs
- [ ] 4.3 Walk every scenario in `specs/` on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`): right-click the page, a link, an image and a field on the test site; each empty line; each Add; the notice on `no-form.html` with and without the save bar
  - `npm test`, `npm run test:e2e`, typecheck and lint are clean
  - Both targets build
