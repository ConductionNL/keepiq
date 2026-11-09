## 1. Safari target

- [x] 1.1 Add the Safari MV3 build (design.md § Target and manifest): `build:safari` and `zip:safari` as `wxt build -b safari --mv3`, `strict_min_version` `26.0`, `Safari >= 26` in `browserslist`, `src/platform.ts` with `isChromium()`, `isSafari()` and `hasIdle()`, `nativeMessaging` on Safari, no `idle`, `offscreen` or `privacy` there, `entrypoints/offscreen/` excluded
  - A manifest test runs `wxt.config.ts`'s manifest callback for chrome, edge, firefox and safari and checks the safari-build spec's "Three targets" scenario
  - Ends with `npm run build`, `npm run build:firefox` and `npm run build:safari`
- [x] 1.2 Register `idle.onStateChanged` only when `hasIdle()`, leave On system lock out of the timeout picker then, and drop it from an `account.settings.set` patch
  - Tests: background starts with `browser.idle` undefined; the picker with and without `idle`, with and without an organisation maximum; the patch with and without `idle`
- [x] 1.3 Turn every "is this Firefox?" that means "is this Chrome?" into `isChromium()`: the default password manager entry and its listener, the shortcut's Change (Safari shows `settings.shortcutSafari`, no Copy), and Rate the extension with `rate.safari: null`
  - Tests: each on `chrome`, `firefox` and `safari`; `settings.shortcutSafari` in en and nl
  - Ends with building all three targets

## 2. Clipboard

- [x] 2.1 Write `safari/SafariWebExtensionHandler.swift` (design.md § Native clipboard): the `Pasteboard` protocol, `reply(to:pasteboard:)`, the write for this Mac only (`.currentHostOnly`), the concealed type, the `changeCount` check, `completeRequest` on every path, no logging of the text; and `safari/tests/main.swift`
  - Tests: write (this Mac only, the count read after the writes), clear with a matching and a stale count, clear without a count, unknown kind, missing or non-string `text`, non-integer `changeCount`, a message that is not a dictionary
- [x] 2.2 Write `src/safari-native.ts` (`nativeWrite`, `nativeClear`, 2 s timeout, reply shape checked as `unknown`) and the Safari branch of `src/clipboard.ts`: write and clear through it, `clipboardChangeCount` in `storage.session` next to the alarm, removed by the clear and by a copy without a count; an unknown outcome schedules a clear without a count; one queue for the write, the count and the clear
  - Tests: write ok, write rejected, malformed reply and timeout scheduling a clear without a count; clear with and without a stored count; a clear during a copy; a lock clearing a copied code; an unknown outcome keeping a code's account, also under Never; a refused or unreachable app keeping none; Chrome and Firefox paths unchanged
- [x] 2.3 On Safari let `useClipboard` copy through `nativeWrite`, fall back to `navigator.clipboard.writeText` in a development build only, and send `clipboard.copied` with `changeCount`; the background accepts only a non-negative integer
  - Popup tests in both projects: native ok, native failing then fallback, both failing shows the copy-failed toast; in a production build a refused or unreachable app sends nothing, an unknown outcome and a timeout send `clipboard.copied` without a count, and Chrome and Firefox send nothing after a failed copy
  - Ends with building all three targets

## 3. Containing app

- [x] 3.1 Write `safari/package.sh` and `npm run package:safari` (design.md § Containing app): packager flags, exactly one handler found and overwritten, `xcodebuild` ad hoc on the `Keepiq` scheme from `xcodebuild -list -json`, with `MARKETING_VERSION` from `package.json`, bundle id checks, `.output/keepiq-safari.zip`
  - Fails with a named message on a missing build, a missing or doubled handler, a missing scheme, or a wrong bundle id

## 4. Passkeys

- [x] 4.1 Run `passkey-page-main.content.ts` on every target but Firefox (`exclude: ['firefox']`) and add the Safari focus wait to `src/passkey/page.ts` (design.md § Passkeys)
  - Tests: no wait with focus; wait until `focus` on the top window, and on the calling frame's own window; 500 ms when `window.top` throws; abort and deadline during the wait; no wait on Chrome, before the relay, or in the conditional race
  - `.output/safari-mv3/manifest.json` has the `world: "MAIN"` content script; Firefox's does not
  - Ends with building all three targets

## 5. Website access

- [x] 5.1 Add `src/website-access.ts`, the `WebsiteAccess` component on the Vault tab and in Settings → Autofill, `websiteAccessDismissed` in `settings.global`, and the keys in design.md § Website access to every catalog
  - Only on Safari; no component imports `src/api` or `src/crypto`
  - Popup tests in both projects: each state, Not now, a grant, a request that grants nothing showing the steps, `permissions.onAdded` re-rendering, keyboard use and screen reader names
  - Ends with building all three targets

## 6. CI

- [x] 6.1 Write `scripts/safari-smoke.ts` and `npm run test:safari-smoke` (design.md § CI): status wait, session, `webextension` install from `.output/safari-mv3` with a base64 zip fallback and an id required, every request cut off after 60 s, session deleted in `finally`, each failure naming its step; no content script check (design.md § CI)
- [x] 6.2 Add the `safari` job on `macos-26` to `.github/workflows/browser-extension.yml`: build, handler tests, package, install in Safari with `safaridriver --diagnose`, artifacts `browser-extension-safari` and, on failure, the WebDriver logs
  - The job is green on a pull request
- [x] 6.3 Make the install step required: no `continue-on-error`, WebDriver logs uploaded on failure

## 7. Docs

- [x] 7.1 Update `../docs/browser-extension/using.md` and `privacy.md` as in design.md § Docs
  - Every line follows CLAUDE.md § Comments & docs
- [x] 7.2 Update CLAUDE.md, README.md, WXT-AND-BROWSERS.md (new § Safari), ARCHITECTURE.md, SECURITY.md § Clipboard, ADR-002, `openspec/config.yaml` and the change chain in `openspec/README.md` to "Chromium MV3, Firefox MV2 and Safari MV3"
  - `npm test`, typecheck and lint are clean; all three targets build

## 8. Follow-ups owned by people

- [ ] 8.1 Apple Developer Program membership for Conduction (D-U-N-S number, 99 USD a year)
- [ ] 8.2 Manual check on a real Mac with Safari 26: copy and clear, the console shows `Keepiq native message: ok` for a popup copy, passkeys and their fallback, also in `passkey-frame.html`, inline list, save bar, the test site's `root-filter.html` probe, the website access card, macOS set to Dutch and to Irish, and every line of design.md § Unverified
- [ ] 8.3 Review of `safari/SafariWebExtensionHandler.swift` by someone who reads Swift
- [ ] 8.4 Mac App Store listing with keepiq#1040: signing, App Privacy details, the guideline 4.4.2 reason for `*://*/*` (ADR-001), `rate.safari`, a Safari release job
- [ ] 8.5 iOS: a separate target, without `windows.create` and `menus`
