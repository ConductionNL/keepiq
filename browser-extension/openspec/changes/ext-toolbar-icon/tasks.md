## 1. Art and manifest

- [ ] 1.1 Add `public/icon/toolbar/`, `toolbar-locked/` and `toolbar-logged-out/`, each with `16.png` and `32.png`, rendered from `img/app-store.svg` in the Keepiq app: unchanged, with a lock in the bottom right, and greyscale
  - All six files open at their stated pixel size
- [ ] 1.2 Point `action.default_icon` in `wxt.config.ts` at `toolbar-logged-out`
  - `npm run build` and `npm run build:firefox` both succeed
  - `.output/chrome-mv3/manifest.json` has `action.default_icon`
  - `.output/firefox-mv2/manifest.json` has `browser_action.default_icon`

## 2. Icon module

- [ ] 2.1 Write `src/toolbar-icon.ts` with `refreshToolbar(scope)`, a 100 ms coalescing timer per scope, the state icon and title set globally, and the badge text and count title set per tab
  - Badge text is `''` at 0, the digit for 1–9, and `'9+'` above
  - The count comes from `candidatesFor()` with an empty last-used map, and no decrypt or API import exists in the file
  - Nothing is written to `storage.local` or `storage.session`
- [ ] 2.2 Add `registerToolbarListeners()`:
  - `tabs.onActivated`, `tabs.onUpdated` (url or `status: 'complete'`), `windows.onFocusChanged`;
  - `storage.onChanged` on `local` for `vault:<activeAccountId>`, `activeAccountId`, the account settings record and the Appearance record;
  - `runtime.onStartup` and `runtime.onInstalled`;
  - badge colours set once, with `setBadgeTextColor` guarded.
- [ ] 2.3 Wire it into `entrypoints/background.ts`:
  - call `registerToolbarListeners()` at top level;
  - call `refreshToolbar('state')` from the unlock, lock, logout and account switch hooks;
  - on lock or logout, clear the badge globally and on every tab from `tabs.query({})`.

  Acceptance:
  - Both targets build.
  - On the test site, with three logins saved for `http://localhost:8100`, the badge reads "3".
  - Locking shows the lock icon and an empty badge.
  - Logging out the only account turns the icon grey.

## 3. Setting

- [ ] 3.1 Add `showBadgeCounter` (global scope, default `true`) to the ext-settings schema, and add the toggle "Show number of login autofill suggestions on extension icon" to the Appearance view
  - Turning it off empties the badge on every tab without reopening the popup, and the icon stays coloured
  - Both targets build

## 4. Verification

- [ ] 4.1 Walk every scenario in `specs/toolbar-icon/spec.md` on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`). The walk uses the test site, a `chrome://` or `about:` page, an MV3 worker stopped from `chrome://serviceworker-internals`, and a browser restart.
  - Every scenario holds on both browsers
  - Typecheck and lint are clean
  - Both targets build
