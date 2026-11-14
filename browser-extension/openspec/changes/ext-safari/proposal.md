---
kind: code
depends_on: [ext-accounts-and-unlock, ext-settings, ext-autofill, ext-default-password-manager, ext-passkey-provider]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
  - ext-toolbar-icon
  - ext-default-password-manager
  - ext-passkey-provider
  - ext-safari
---

## Why

The extension ships to Chrome and Firefox only, and Mac users who browse in Safari get nothing (keepiq#1489). `npx wxt build -b safari` builds, but the result crashes on `browser.idle`, has no passkeys, shows Chrome-only settings and cannot copy from the background. Bitwarden ships a Safari extension inside a macOS app; Keepiq should too.

## What Changes

- **Safari target:** `npm run build:safari` builds Safari 26 and later as MV3 (`.output/safari-mv3/`), with `browser_specific_settings.safari.strict_min_version: '26.0'`. Chrome stays MV3, Firefox stays MV2.
- **Chromium-only features are left out on Safari:** the `offscreen` and `privacy` permissions, "Make Keepiq your default password manager" and the `chrome://extensions/shortcuts` link. The shortcut's Change shows Safari's steps instead.
- **No `idle` on Safari:** the background checks for it before use and "On system lock" is neither offered nor stored, as in Bitwarden. The timed options run on the `vault-timeout` alarm and stay.
- **Clipboard through the containing app:** on Safari the popup and the background write the clipboard, and the background clears it, with `runtime.sendNativeMessage` to a Swift handler that uses `NSPasteboard`, as Bitwarden and Proton Pass do. The clear only clears what Keepiq wrote.
- **Containing app:** generated in CI by `xcrun safari-web-extension-packager` from the build; the repo keeps only the Swift handler, its test and the script that packages and overlays it.
- **Passkeys on Safari:** the `MAIN` world page script runs on Safari too, and on every other Chromium target, such as Edge, and a fallback to Safari's own WebAuthn waits until the page's window has focus, as in Bitwarden's page script.
- **Website access:** Safari runs content scripts only where the user allowed them. A card in the Vault tab and an entry in Settings → Autofill ask for access to every website, with written steps when Safari does not grant it from the extension. The user docs gain a Safari section.
- **CI:** a `macos-26` job builds, packages, compiles the app unsigned and runs a WebDriver smoke test against Safari.

## Capabilities

### New Capabilities
- `safari-build`: the Safari target, what it leaves out, the containing app and its CI check.
- `safari-clipboard`: copy and clear through the containing app, what crosses that boundary and what a failure does.
- `website-access`: the Vault tab card and the Settings entry that get Keepiq access to websites in Safari.

### Modified Capabilities
- `settings` (ext-settings): On system lock only where `idle` exists; Safari's shortcut steps; Safari's store link in About; the Safari clear only clears what Keepiq wrote.
- `vault-unlock` (ext-accounts-and-unlock): On system lock needs `idle`.
- `default-password-manager` (ext-default-password-manager): shown on Chromium only.
- `passkey-provider` (ext-passkey-provider): the fallback waits for focus on Safari, also in a same-origin frame.
- `inline-list` and `login-capture` (ext-autofill): Safari has no occlusion report, and refuses a pick while `<html>` has a `filter` until a Mac shows it does not reach the top layer.

## Deviations from Bitwarden

- **MV3 on Safari.** Bitwarden builds Safari from its own manifest; Keepiq uses the Chrome code path, which iOS will need, as Proton Pass does.
- **The clear only clears what Keepiq wrote, on Safari only.** It compares `NSPasteboard.changeCount`, so no copied value travels back and no `clipboardRead` is needed. Chrome and Firefox keep the unconditional clear (SECURITY.md).
- **Website access card.** Bitwarden has no such step; Safari's per-site grant would otherwise leave the inline list and save bar silently missing.
- **The focus wait honours the page's `AbortSignal` and the ceremony deadline.** Bitwarden's waits up to five minutes and ignores the signal.

## Keepiq API used

None. The server origin is still requested when an account is added (ADR-003, ext-accounts-and-unlock); nothing is gated in `KEEPIQ_FEATURES`.

## Impact

- `wxt.config.ts`, `package.json` (`build:safari`, `zip:safari`, `package:safari`, `test:safari-smoke`), new `src/platform.ts`.
- `entrypoints/background.ts`, `src/clipboard.ts`, new `src/safari-native.ts`, `entrypoints/popup/hooks/useClipboard.ts`, `src/messages.ts` (`clipboard.copied` gains `changeCount`).
- `entrypoints/passkey-page-main.content.ts`, `src/passkey/page.ts`.
- `entrypoints/content.ts`, `src/autofill/inline-host.ts`, `notification-host.ts` and `visibility.ts`: the root filter refusal on every target but Chromium; `test-site/root-filter.html` and `passkey-frame.html`.
- Popup: new `WebsiteAccess` component on the Vault tab and in the Autofill view; `settings.global` gains `websiteAccessDismissed`; keys in every catalog in `locales/`.
- New `safari/` (Swift handler, its test, `package.sh`) and `scripts/safari-smoke.ts`; a `safari` job in `.github/workflows/browser-extension.yml`.
- Docs: CLAUDE.md, README.md, WXT-AND-BROWSERS.md, ARCHITECTURE.md, SECURITY.md, `openspec/config.yaml`, ADR-002, `../docs/browser-extension/using.md` and `privacy.md`.
- The containing app needs a reviewer who can read Swift.
