## Context

The earlier changes provide:
- the timeout options, `enforce()` on the `vault-timeout` alarm and On system lock on `browser.idle` (ext-accounts-and-unlock, ext-settings);
- `src/clipboard.ts`: the popup copies under the click, the background writes and clears through the offscreen document on Chrome and its own page on Firefox, `clipboardCode` clears a copied code at its account's lock (ext-vault-browse, ext-settings, ext-autofill);
- the `privacy` toggle on Chromium (ext-default-password-manager) and the shortcut entry (ext-settings);
- the passkey page script, `MAIN` world on Chrome and injected on Firefox, and the background's close-then-focus before a fallback (ext-passkey-provider);
- the server origin requested in the click of "Add account" (ext-accounts-and-unlock, ADR-003).

Facts below come from MDN browser-compat-data (`webextensions/`), Apple's Safari documentation and release notes, WebKit's source, the GitHub runner images, and the Safari code of Bitwarden and Proton Pass. Anything not confirmed there is marked **unverified** and listed in § Unverified.

## Goals / Non-Goals

**Goals:**
- One source builds Chrome MV3, Firefox MV2 and Safari 26 MV3 on macOS.
- Every feature that works on Chrome works on Safari, except the Chromium-only ones and On system lock.
- CI builds the Safari app and loads the extension in Safari on every change.

**Non-Goals:**
- iOS and iPadOS: a separate target, no `windows.create` or `menus` there.
- Signing, notarisation, the App Store listing and the Apple Developer Program enrollment (owned by people; tasks.md § 8).
- A designed containing app: the packager's template stays as generated.
- System-lock detection in Swift; it can come later through the same native handler.
- Dev mode with HMR on Safari: WXT has no Safari runner.

## Decisions

### Target and manifest

- **`npm run build:safari` is `wxt build -b safari --mv3`,** so no global `manifestVersion` is set (WXT-AND-BROWSERS.md § 2). `zip:safari` likewise.
- **`browser_specific_settings: { safari: { strict_min_version: '26.0' } }`** on Safari only. 26 is the first Safari that parses `wasm-unsafe-eval` (Argon2id for sends), lets users change extension shortcuts, and loads extensions over WebDriver (Safari 26 release notes). `browserslist` gains `Safari >= 26`.
- **`src/platform.ts`:** `isChromium(target = import.meta.env.BROWSER)` is every target but `firefox` and `safari`; `isSafari(target = import.meta.env.BROWSER)` is `safari` only; `hasIdle()` is `browser.idle !== undefined`. Every "is this Chrome?" check goes through `isChromium()`, including `wxt.config.ts`, and every "is this Safari?" through `isSafari()`, except where other builds must drop the code (the website access card and entry, the passkey page script): an inline `import.meta.env.BROWSER === 'safari'` folds at build time, a call does not. `src/clipboard.ts` keeps its inline checks.
- **Safari manifest:** `service_worker` background (WXT's MV3 output), `nativeMessaging`, no `idle`, `offscreen` or `privacy`, `optional_host_permissions: ['https://*/*', 'http://*/*']`, no `host_permissions`, content scripts on `*://*/*` as on Chrome. `entrypoints/offscreen/` is excluded from the Safari build.

### Chromium-only features

| Feature | Chrome | Firefox | Safari |
| --- | --- | --- | --- |
| `offscreen` permission and document | yes | no | no |
| `privacy` and "Make Keepiq your default password manager" | yes | no | no (MDN: no `privacy`) |
| Shortcut Change | opens `chrome://extensions/shortcuts` | steps, Copy `about:addons` | steps (`settings.shortcutSafari`), no Copy |
| Rate the extension | `rate.chrome` | `rate.firefox` | `rate.safari` (`null` until the App Store listing) |

Safari has no `commands.openShortcutSettings` (MDN), so the steps are text only.

### Idle

- **Only On system lock needs `idle`.** The timed options and the organisation maximum run on `lastInteractionAt` and the `vault-timeout` alarm, which Safari has. Bitwarden's `BrowserSessionTimeoutTypeService` likewise marks only On locked unavailable on Safari. No alarm fallback is needed.
- **The background registers `idle.onStateChanged` only when `hasIdle()`;** the timeout picker leaves out On system lock then, and `account.settings.set` drops it from a patch, keeping the rest.
- **No promotion of a stored On system lock.** Settings live in this browser's `storage.local` and Safari never offers the option, so such a value cannot arise there.

### Native clipboard

The background is a service worker on Safari, with no document to write from, and Safari has no `offscreen`. Bitwarden (`SafariWebExtensionHandler.swift`, `copyToClipboard`) and Proton Pass (`clipboard.ts`, `writeToClipboard`) both write through the containing app.

**Path:** `src/safari-native.ts` → `browser.runtime.sendNativeMessage('nl.conduction.keepiq.safari', request)` → `SafariWebExtensionHandler.beginRequest` → `NSPasteboard.general`. Safari ignores the application id and talks to the containing app's extension; content scripts cannot send native messages (Apple, "Messaging between the app and JavaScript"). The popup and the background both call it; it is an extension page and a background, both allowed.

**Requests** (`userInfo[SFExtensionMessageKey]`), dotted kinds like runtime messages:

| Request | Handler does | Reply |
| --- | --- | --- |
| `{ kind: 'clipboard.write', text: string }` | `prepareForNewContents(with: .currentHostOnly)`, `setString(text, forType: .string)`, then `setString("", forType: "org.nspasteboard.ConcealedType")` | `{ ok: true, changeCount }` read after the writes, so anything that moved the count since makes the clear fail toward clearing; `{ ok: false, error: 'write_failed' }` when `setString` for `.string` returns false |
| `{ kind: 'clipboard.clear', changeCount: number }` | clears only when `pasteboard.changeCount == changeCount` | `{ ok: true, cleared: true }`, or `cleared: false` when someone copied since |
| `{ kind: 'clipboard.clear' }` | clears unconditionally | `{ ok: true, cleared: true }` |
| anything else | nothing | `{ ok: false, error: 'unknown_kind' }`, or `'bad_request'` for a missing `text`, a non-string `text`, a non-integer `changeCount` or a message that is not a dictionary |

- **The handler always calls `completeRequest`,** also for an error, so a promise never hangs. Bitwarden's returns without completing on an unknown command.
- **Stateless:** nothing stored, no App Group, `SFExtensionProfileKey` ignored. The message key is the literal `"message"` (`SFExtensionMessageKey`'s value), so the file needs no SafariServices and the test binary links none. The text is never logged; `os_log` gets only the outcome, `ok` or the error code, not the kind, which is untrusted input.
- **This Mac only:** `.currentHostOnly` keeps Universal Clipboard from sending the secret to the user's other Apple devices.
- **Concealed type:** the `org.nspasteboard.ConcealedType` marker (nspasteboard.org convention) asks clipboard managers not to keep the value in their history.
- **`changeCount` instead of comparing the value:** the clear needs neither the secret sent back nor a pasteboard read. Proton Pass reads and compares; Bitwarden clears unconditionally.
- **Testable core:** the file holds a `Pasteboard` protocol (`changeCount`, `clearContents()`, `prepareForNewContents(with:)`, `setString(_:forType:)`), `extension NSPasteboard: Pasteboard {}`, a pure `func reply(to message: Any?, pasteboard: Pasteboard) -> [String: Any]`, and the `SafariWebExtensionHandler: NSObject, NSExtensionRequestHandling` class that calls it with `NSPasteboard.general`. The class name stays, as the packager's `Info.plist` names it as `NSExtensionPrincipalClass`.

**Extension side:**
- `src/safari-native.ts` exports `nativeWrite(text): Promise<number>` (the `changeCount`) and `nativeClear(changeCount?): Promise<boolean>`. Each races a 2 s timeout, checks the reply shape (payload `unknown`) and rejects on anything else.
- **Background (`src/clipboard.ts`):** on Safari `writeFromBackground` calls `nativeWrite` instead of `inDocument`; a rejection is a failed copy, as today. When the outcome is unknown (no reply in 2 s, or a reply of another shape, as opposed to `{ ok: false }` or a rejected `sendNativeMessage`), it is still a failed copy, but it schedules a clear without a count after the account's delay, since the app may write late. `clearClipboard` calls `nativeClear` with the stored count, or without one when none is stored.
- **Popup (`useClipboard`):** on Safari it calls `nativeWrite` first, then `navigator.clipboard.writeText` when that rejects (an extension loaded without its app, as under WebDriver). It sends `clipboard.copied` with `changeCount` when the native write gave one. Bitwarden and Proton Pass also copy through the app from the popup; it does not depend on the click's user activation surviving the decrypt round trip.
- **`clipboard.copied`** becomes `{ kind: 'clipboard.copied'; changeCount?: number }`; the background accepts a non-negative integer only and ignores anything else.
- **`scheduleClipboardClear(ms, now, changeCount?)`** stores `clipboardChangeCount` in `storage.session` with the alarm, and removes it when a copy brings none. The clear removes it. One queue runs the native write, storing the count, and the clear (read, remove, `nativeClear`) one at a time, so a clear firing during a copy neither removes the new count nor clears with it.
- **Chrome and Firefox are unchanged:** no count, unconditional clear.

### Containing app

The repo keeps only what the packager cannot make. Everything generated lives under `.output/` (gitignored).

| Path | Purpose |
| --- | --- |
| `safari/SafariWebExtensionHandler.swift` | The handler above; replaces the packager's |
| `safari/tests/main.swift` | Plain-assert tests of `reply(to:pasteboard:)` with a fake pasteboard; exits non-zero on a failure |
| `safari/package.sh` | Packager, overlay, `xcodebuild`, checks; `npm run package:safari` |
| `scripts/safari-smoke.ts` | Installs the build in Safari over WebDriver; `npm run test:safari-smoke` |

- **Names:** app name `Keepiq`, app bundle id `nl.conduction.keepiq.safari`. The packager (Xcode 26.6) names the extension `nl.conduction.keepiq.safari.Extension` but the app target `nl.conduction.keepiq.Keepiq` (the id's prefix plus the app name), which Xcode's embedded-binary check refuses; `package.sh` prints both and fails unless the extension's starts with `nl.conduction.keepiq.safari.`. Not `nl.conduction.keepiq`, which the iOS app in `mobile/ios/project.yml` holds.
- **`package.sh`** (`set -euo pipefail`, run from `browser-extension/`):
  1. Fail unless `.output/safari-mv3/manifest.json` exists.
  2. `xcrun safari-web-extension-packager .output/safari-mv3 --project-location .output/safari-app --app-name Keepiq --bundle-identifier nl.conduction.keepiq.safari --swift --macos-only --copy-resources --no-open --no-prompt --force` (flags from Apple's "Packaging a web extension for Safari").
  3. Find `SafariWebExtensionHandler.swift` under `.output/safari-app`; fail unless exactly one; copy ours over it. In the `.xcodeproj`'s `project.pbxproj`, set every `PRODUCT_BUNDLE_IDENTIFIER` that is neither `nl.conduction.keepiq.safari` nor under it, the app target's, to `nl.conduction.keepiq.safari`; fail unless the app then has that id and an extension id under it remains. No plist or entitlement changes: the template's App Sandbox suffices for `NSPasteboard`.
  4. `xcodebuild -project <the one .xcodeproj> -scheme <scheme> -configuration Release -derivedDataPath .output/safari-build CODE_SIGN_IDENTITY=- CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM= MARKETING_VERSION=<package.json version> CURRENT_PROJECT_VERSION=${BUILD_NUMBER:-1} build`. Ad hoc ("Sign to Run Locally"), as `mobile/ios/project.yml` does, so the app also runs on a test Mac with "Allow unsigned extensions"; Apple Silicon runs no unsigned code. `<scheme>` is the one named `Keepiq` in `xcodebuild -list -json`; the script fails listing the schemes when there is none.
  5. Print `CFBundleIdentifier` of `Keepiq.app` and of its `.appex` with `PlistBuddy`, check them, and `ditto -c -k --keepParent` the app to `.output/keepiq-safari.zip`.

### Passkeys

- **`entrypoints/passkey-page-main.content.ts`** uses `exclude: ['firefox']` instead of `include: ['chrome']`, so Safari (and Edge) get the `MAIN` world script. Safari supports `world` from 18 (MDN). Firefox keeps the injected script.
- **Focus wait, Safari only** (`import.meta.env.BROWSER === 'safari'`), in `src/passkey/page.ts`. It applies to a `fallback` that the relay or the ack timeout answered for a modal request. A fallback before the relay (no `publicKey`, a throw, an aborted signal) and the conditional race don't wait, as in Bitwarden.
  - When `window.top.document.hasFocus()` is true, call the original method at once.
  - When there is no top window or reading its focus throws, wait 500 ms (`FRAME_FOCUS_MS`, Bitwarden's `fallbackWait`), then call it. The provider hands frames with a cross-origin ancestor to the browser before the relay, so this is a guard only.
  - Otherwise wait for the first `focus` on `window.top` or on the calling window, then call it. A browser fires `focus` only on the focused frame's window, so a same-origin frame that calls would otherwise wait for the deadline.
  - The page's `AbortSignal` rejects the wait with its reason, and the ceremony's existing deadline with `NotAllowedError`. The listeners and timer go in every case, one wait per call.
- **The background's close-then-focus stays** for every browser; the page-side wait covers a Safari whose `windows.update({ focused: true })` or `windows.onFocusChanged` acts differently (**unverified**).

### Website access

Safari applies content scripts only where the user granted access through the toolbar popover or Safari Settings (MDN `content_scripts` note; Apple, "Managing Safari web extension permissions"). A background `fetch` needs its origin granted too (Safari 18.4 release notes). On Safari, requesting `*://*/*` grants the right to ask per site rather than access to every site (MDN `permissions.request` note).

- **`src/website-access.ts`** (popup side, no `src/api` import): `SITE_ORIGINS = ['https://*/*', 'http://*/*']`, the manifest's optional host patterns. `websiteAccess(serverUrl)` answers `'server'` when `permissions.contains({ origins: serverOrigins(serverUrl) })` is false (`['<server origin>/*']`, from `src/accounts/normalize-server-url.ts`, which "Add account" requests and the background checks too), `'sites'` when `permissions.contains({ origins: SITE_ORIGINS })` is false, else `'all'`. Safari only; other builds answer `'all'` without asking.
- **`WebsiteAccess` component,** two places:
  - The Vault tab, above Autofill suggestions, while unlocked: the card for `'server'` always; for `'sites'` unless `websiteAccessDismissed`.
  - Settings → Autofill, a "Website access" field after the shortcut: the state as text and, unless `'all'`, the same button and steps; never dismissible.
- **Allow on every website** calls `permissions.request({ origins: SITE_ORIGINS })` in the click, then checks again. Still `'sites'`: the steps show, `role="status"`. **Allow `<host>`** requests `['<server origin>/*']` the same way.
- **Re-checks** on mount, on `permissions.onAdded` and `onRemoved`, and after each button. Safari may close the popup for a prompt (**unverified**); the next open checks again.
- **Not now** sets `websiteAccessDismissed: true` in `settings.global` through `settings.set`. It hides only the Vault tab's `'sites'` card.
- **Text keys,** both catalogs (Safari's labels are **unverified**, see § Unverified):

| Key | en | nl |
| --- | --- | --- |
| `websiteAccess.title` | Let Keepiq work on websites | Laat Keepiq werken op websites |
| `websiteAccess.body` | Safari runs Keepiq only on websites you allow. Allow it on every website to fill in logins, save new ones and use passkeys wherever you sign in. | Safari laat Keepiq alleen werken op websites die je toestaat. Sta het toe op alle websites om overal inloggegevens in te vullen, nieuwe op te slaan en passkeys te gebruiken. |
| `websiteAccess.allow` | Allow on every website | Op alle websites toestaan |
| `websiteAccess.dismiss` | Not now | Niet nu |
| `websiteAccess.steps` | In Safari, choose Safari > Settings, then Websites. Select Keepiq in the list and set "When visiting other websites" to Allow. | Kies in Safari Safari > Instellingen en dan Websites. Selecteer Keepiq in de lijst en zet "Bij bezoek aan andere websites" op Sta toe. |
| `websiteAccess.perSite` | To allow one site at a time, click the Keepiq button on that site and choose to always allow it. | Wil je per website toestaan? Klik op die website op de Keepiq-knop en kies om het altijd toe te staan. |
| `websiteAccess.serverTitle` | Keepiq can't reach {host} | Keepiq kan {host} niet bereiken |
| `websiteAccess.serverBody` | Safari doesn't let Keepiq reach your server, so your vault can't sync. | Safari geeft Keepiq geen toegang tot je server, dus je kluis kan niet synchroniseren. |
| `websiteAccess.serverAllow` | Allow {host} | {host} toestaan |
| `settings.websiteAccess` | Website access | Toegang tot websites |
| `settings.websiteAccessAll` | Every website | Alle websites |
| `settings.websiteAccessSome` | Only websites you allowed | Alleen websites die je toestond |
| `settings.websiteAccessServer` | Not your Keepiq server | Niet je Keepiq-server |

### CI

A `safari` job in `.github/workflows/browser-extension.yml`, beside `check-and-build` and `e2e`, same triggers, `runs-on: macos-26` (arm64, macOS 26.6, Xcode 26.6 default, Safari and SafariDriver 26.6.2 per the runner image readme), `timeout-minutes: 30`, `working-directory: browser-extension`. The runner image's `install-safari.sh` already runs `sudo safaridriver --enable` and sets `AllowRemoteAutomation` in `~/Library/WebDriver/com.apple.Safari.plist`.

1. Checkout and `setup-node` (same pinned actions, Node 24, npm cache), `npm ci --ignore-scripts`, `npx wxt prepare`.
2. `npm run build:safari`.
3. Handler tests: `swiftc -o "$RUNNER_TEMP/handler-tests" safari/SafariWebExtensionHandler.swift safari/tests/main.swift && "$RUNNER_TEMP/handler-tests"`.
4. `npm run package:safari` with `BUILD_NUMBER: ${{ github.run_number }}`.
5. Install the extension in Safari, required: start `safaridriver --port 4444 --diagnose &`, then `npm run test:safari-smoke`.
6. Upload `browser-extension-safari`: `.output/safari-mv3/` and `.output/keepiq-safari.zip`, 14 days.
7. Upload `browser-extension-safari-webdriver-logs`: `~/Library/Logs/com.apple.WebDriver/`, `if: failure()`, 14 days.

**`scripts/safari-smoke.ts`** (Node 24, `fetch`, no dependency), WebDriver classic against `http://localhost:4444`, every request cut off after 60 s:
1. Wait up to 30 s for `GET /status`.
2. `POST /session` `{ capabilities: { alwaysMatch: { browserName: 'safari' } } }`.
3. `POST /session/{id}/webextension` `{ type: 'path', path: <absolute .output/safari-mv3> }` (W3C WebExtensions "WebDriver Classic"); when that fails, again as `{ type: 'base64', value }`, a zip of the folder's contents (`ditto -c -k`, no `--keepParent`). Required: an id comes back, as `value` or `value.extension`. Safari 26.6 accepts the path and answers `{ extension: 'com.apple.Safari.UnpackedExtensions.<UUID> (UNSIGNED)' }`, which is not the host of the extension's `safari-web-extension://` URLs.
4. `DELETE /session/{id}` in `finally`. Each failure names its step.

**Why CI doesn't check the content scripts** (runs 42214300200 to 42220015972 on PR #1490, `macos-26`, Safari 26.6.2; screenshots and safaridriver logs in their `browser-extension-safari-webdriver-logs` artifacts):
- Safari runs content scripts only where the user allowed the extension. On `localhost:8100` it asked, as a sheet ("The extension "Keepiq" would like to access localhost.") or as a badge on the toolbar button ("would like access to additional websites"); until answered, `navigator.credentials.get` stayed native.
- The sheet freezes the automation session: the next `Automation.resolveBrowsingContext` and `DELETE /session` got no answer.
- Safari refuses a scripted answer: clicking the toolbar button through System Events showed "This Safari window is remotely controlled by an automated test" and froze the session again.
- Nothing grants access up front: WebKit's `Automation.json` has only the `GetUserMedia` session permission, and no documented preference sets an extension's website access.
- The content scripts are checked by hand on a Mac (tasks.md 8.2).

### Docs

- `../docs/browser-extension/using.md`: Safari in "Getting the extension" (macOS 14 or later with Safari 26, built from source on a Mac until the App Store listing), a new "### Website access in Safari" after it with the card, the steps and the per-site option, a bullet in "When the list does not show", "On system lock is not offered in Safari" in Settings, Safari's shortcut steps, and Language: Safari follows macOS's preferred languages.
- `../docs/browser-extension/privacy.md`: rows "Native messaging (Safari): writes and clears the clipboard through the Keepiq app that installs the extension in Safari, and nothing else", "Idle" marked not in Safari, "Website access (Safari)".
- WXT-AND-BROWSERS.md: a § Safari: build, package, load on a test Mac (run `Keepiq.app` once, Safari Settings → Advanced → "Show features for web developers", Developer → "Allow unsigned extensions", Extensions → turn Keepiq on; the setting resets when Safari quits), `xattr -dr com.apple.quarantine` for a downloaded artifact, no dev mode, `isChromium()`.
- "Chromium MV3 and Firefox MV2" becomes "Chromium MV3, Firefox MV2 and Safari MV3" in CLAUDE.md, README.md, WXT-AND-BROWSERS.md, ADR-002 and `openspec/config.yaml` (context and the design rule on APIs).
- SECURITY.md § Clipboard: Safari's path and its conditional clear. ARCHITECTURE.md: `safari/`, `src/safari-native.ts`, `src/platform.ts`, `src/website-access.ts`, `clipboardChangeCount`, `websiteAccessDismissed`.

### Browser APIs

| Use | API | Safari 26 (MDN) |
| --- | --- | --- |
| Native clipboard | `runtime.sendNativeMessage`, `nativeMessaging` | 14 |
| Website access | `permissions.contains`, `request`, `onAdded`, `onRemoved`; `optional_host_permissions` | 14; 15.5 |
| Passkey page script | `content_scripts.world: 'MAIN'` | 18 |
| Lock | `alarms`, `storage.session` | yes; 16.4 |
| On system lock | `idle` | none |
| Default password manager | `privacy` | none |
| Shortcut | `commands.getAll`; `openShortcutSettings` | 14; none |
| Passkey window | `windows.create`, `update`, `onFocusChanged` | 14 |

### Where data lives

| Data | Lives in | Cleared by |
| --- | --- | --- |
| `clipboardChangeCount`: the pasteboard's `changeCount` after Keepiq's last write, never the value | `storage.session` | The clear, a copy without a count, browser restart |
| `websiteAccessDismissed` | `storage.local`, `settings.global` | Removing the extension |
| Copied value | Popup or background memory for one `sendNativeMessage`, then the system pasteboard | The call returning; the clear |
| Native handler state | none | — |

## Risks / Trade-offs

- [The packager's output changes between Xcode versions] → `package.sh` finds the handler and project instead of assuming paths, and fails loudly; the job pins the image's default Xcode.
- [The containing app is Swift, outside the extension's TypeScript] → One file with a pure core and tests compiled in CI; review by someone who reads Swift before release (tasks.md 8.3).
- [CI cannot check the content scripts: Safari asks a person for website access] → CI requires the install; the content scripts are checked by hand on a Mac (tasks.md 8.2, § CI).
- [macOS runner minutes cost more than Linux] → One job of minutes per change, path-filtered like the others.
- [A user who allows only some sites gets no inline list or save bar elsewhere] → The card, the Settings entry and the docs say why; the toolbar popup and the context menu still fill.
- [A one-day site grant expires] → The card shows again; the server variant cannot be dismissed.
- [A fallback after the passkey window may lack user activation in WebKit] → The page then gets Safari's `NotAllowedError`, as it would without Keepiq; checked on a real Mac (tasks.md 8.2).

## Unverified

Confirm on a real Mac (tasks.md 8.2), then drop each line:

- Safari Settings labels: the Websites pane, "When visiting other websites", Allow, and their Dutch names; the toolbar popover's "always allow" wording; the shortcut path in `settings.shortcutSafari`.
- What `permissions.request({ origins: SITE_ORIGINS })` does on Safari 26: a prompt, an immediate `true` without access, or a grant; and whether `permissions.contains` reports `true` after "Allow on every website".
- Whether the server origin granted at add time stays granted or can expire.
- Whether Safari closes the popup for a permission prompt.
- That `.currentHostOnly` keeps the value off Universal Clipboard.
- `sendNativeMessage` from Safari's MV3 service worker (Proton Pass does it from its worker) and from the popup; `NSPasteboard` writes from the sandboxed extension without user interaction (Bitwarden does it).
- That an ad-hoc signed build loads with "Allow unsigned extensions".
- WebDriver: whether `type: 'base64'` loads; the runner only ever used `type: 'path'`.
- WebKit refusing WebAuthn without focus (from Bitwarden's comment), whether the `MAIN` world script runs before the page's scripts at `document_start`, and `windows.update({ focused: true })` behaviour.
- Whether a `filter` on `<html>` reaches Safari's top layer: open the test site's `root-filter.html` probe. Until then the inline list and save bar refuse a pick while `<html>` has one, as on Firefox (`rootFilterReachesTopLayer`, true for every target but Chromium in `entrypoints/content.ts`); if the popover shows, make it Firefox only.
- Language: Safari follows macOS's preferred languages; with Irish first and no Irish catalog the extension should show English, and Dutch when Dutch is first.
