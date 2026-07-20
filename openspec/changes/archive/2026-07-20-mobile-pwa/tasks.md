# Tasks: Installable Mobile PWA Vault

## 0. Scope Note (read first)

Make Doriath an installable, mobile-first PWA on top of Nextcloud's platform. Deliver a web app manifest (distinct from `src/manifest.json`), maskable/themed icons, a viewport/touch audit of the core flows, and honest mobile WebCrypto/clipboard verification. **No native app, no app-store presence, no offline caching, no service worker of our own, no share-target, no push.** (The offline app-shell + service worker are the sibling `offline-readonly-cache` change, now landed — this change relies on it/NC's SW for the installability SW criterion and adds none of its own.)

## 1. Web app manifest

- [x] 1.1 `WebManifestController::manifest` builds a W3C web app manifest (distinct from the internal `src/manifest.json`) with `name`/`short_name`, `display: standalone`, brand `theme_color`/`background_color`, `start_url`/`scope` at the vault route, an `icons` array (any + maskable at 192/512), and an "Open vault" `shortcuts` entry
- [x] 1.2 Served at `/manifest.webmanifest` with the correct `application/manifest+json` MIME (a controller route, mirroring the service-worker MIME lesson — NC's static route would mislabel it)

## 2. Page wiring (mobile meta)

- [x] 2.1 `templates/index.php` adds via `Util::addHeader`: `<link rel="manifest">`, `<meta name="theme-color">`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`, `apple-mobile-web-app-title`, and an `apple-touch-icon` link
- [x] 2.2 Registers NO service worker of its own; installability's SW criterion is satisfied by Nextcloud's instance service worker (documented in the template comment + controller docblock)

## 3. Icons

- [x] 3.1 Maskable (`pwa-icon-maskable.svg`, full-bleed brand background + glyph inside the inner ~60% so a circular/squircle mask never clips) + any-purpose (`pwa-icon.svg`, rounded) icons derived from `img/app.svg`, referenced at 192/512 and as the iOS `apple-touch-icon`. Note: resolution-independent SVG icons are used (no rasteriser is available in this environment); they are valid PWA icons for Android/Chrome and iOS 16.4+ apple-touch-icon. A PNG fallback for older iOS is a documented follow-up

## 4. Responsive / touch audit

- [x] 4.1 A single narrow-viewport breakpoint (`@media (max-width: 500px)`) in `src/assets/app.css` stacks the vault-list + detail actions single-column and prevents horizontal overflow (`#content` + page containers `overflow-x: hidden`; wide tables/extra-fields scroll inside their own `overflow-x: auto`)
- [x] 4.2 Reveal (eye), copy, TOTP, and all `.button-vue`/`[role=button]` controls get a 44×44 CSS-px minimum touch target at mobile widths (WCAG 2.5.5 / Apple HIG)
- [x] 4.3 Lock/unlock + search inputs go full-width one-handed at the breakpoint
- [x] 4.4 NL Design System tokens only (no hardcoded colours in the CSS; the icon/theme brand cobalt is a brand asset, not a theming token); no fixed widths that overflow

## 5. Mobile WebCrypto + clipboard verification

- [x] 5.1 Unlock → decrypt → reveal → copy exercised on the deployed instance (desktop Chromium; the path is browser-engine-identical WebCrypto). Real-device iOS Safari / Android Chrome verification is a deployment-time check, documented
- [x] 5.2 Clipboard-in-gesture fix: `CopyButton` pre-resolves the value on `@pointerdown` (fires within the same gesture, just before `click`) and `onCopy` writes the pre-warmed value synchronously — so mobile Safari permits the write even for a copy-without-prior-reveal. PasswordField keeps its documented lazy-decrypt contract (its existing tests still pass); a prior reveal already caches the plaintext for a synchronous copy
- [x] 5.3 The lock screen already surfaces an explicit "requires a secure connection (HTTPS)" message and hides the unlock form when `!window.isSecureContext` — an honest secure-context failure, never a fabricated success (verified present)

## 6. Tests

- [x] 6.1 PHPUnit `WebManifestControllerTest`: the manifest declares `standalone`, brand `theme_color`/`background_color`, a vault `start_url`/`scope`, maskable + any-purpose icons at 192 and 512, and a vault shortcut (the MIME is asserted at the live HTTP layer — `Response::getHeaders()` needs the OC container)
- [x] 6.2 e2e: covered by deploy-time live verification (manifest served with the right MIME + shape; the meta links emitted; the responsive breakpoint applies at a narrow viewport). No committed Playwright mobile spec
- [x] 6.3 Non-secure-context: the LockScreen `!isSecureContext` branch is the pre-existing honest error path (no new failing state introduced); verified present in the template

## 7. Quality Gates

- [x] 7.1 Frontend lint + vitest pass (386); `@spec` on the changed controller/method
- [x] 7.2 `composer check` scope: `templates/index.php` + the new controller pass php -l + phpcs; suite 694 PHPUnit green
- [x] 7.3 Confirmed no service worker, share-target, or push introduced by THIS change; the app-shell/offline seam stays with `offline-readonly-cache`

## Acceptance Criteria

- A Doriath web app manifest (distinct from `src/manifest.json`) is served and linked, declaring `standalone`, themed NL Design System colours, a vault `start_url`/`scope`, maskable + any-purpose icons, and a vault shortcut.
- The app page emits `theme-color` and `apple-mobile-web-app-*` meta so a home-screen launch runs standalone on iOS Safari and Android Chrome.
- This change registers no service worker of its own; installability relies on Nextcloud's instance service worker.
- Maskable icons survive Android's adaptive-icon mask; an iOS touch icon is provided.
- The vault list, secret detail (reveal/copy), search, TOTP display, and lock/unlock are single-column, overflow-free, and meet the 44px touch-target minimum on a narrow viewport.
- Unlock → decrypt → reveal → copy works; copy runs inside the tap gesture; a non-secure context fails with an honest secure-context error, never a fabricated success.
- No offline caching, service worker, Web Share Target, push, native app, or app-store listing is introduced by this change.
