# Tasks: Installable Mobile PWA Vault

## 0. Scope Note (read first)

Make Doriath an installable, mobile-first PWA on top of Nextcloud's platform. Deliver a web app manifest (distinct from `src/manifest.json`), maskable/themed icons, a viewport/touch audit of the core flows, and honest mobile WebCrypto/clipboard verification. **No native app, no app-store presence, no offline caching, no service worker of our own, no share-target, no push.** Offline caching is the sibling `offline-readonly-cache` change — reference the seam only. Verify against HEAD first: no manifest/SW exists today (`grep -rln "webmanifest|serviceWorker|standalone|maskable" src templates appinfo` is empty), `templates/index.php` serves the app page, and the core views/components (`SecretList.vue`, `SecretDetail.vue`, `PasswordField.vue:5-12`, `CopyButton.vue`, `TotpDisplay.vue`, `LockScreen.vue`).

## 1. Web app manifest

- [ ] 1.1 Add `manifest.webmanifest` (distinct from the internal `src/manifest.json`) with `name`/`short_name`, `display: standalone`, `theme_color`/`background_color` from NL Design System tokens (no hardcoded hex), `start_url`/`scope` targeting the vault route, an `icons` array, and a vault `shortcuts` entry.
- [ ] 1.2 Serve the manifest with the correct `application/manifest+json` MIME type (route or static asset).

## 2. Page wiring (mobile meta)

- [ ] 2.1 In `templates/index.php`, add via `Util::addHeader`: `<link rel="manifest">`, `<meta name="theme-color">`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`, `apple-mobile-web-app-title`, and an `apple-touch-icon` link.
- [ ] 2.2 Confirm this change registers NO service worker; installability relies on Nextcloud's instance service worker (document the reliance).

## 3. Icons

- [ ] 3.1 Produce maskable (≥20% safe zone) + any-purpose PWA icons at 192×192 and 512×512, plus an iOS `apple-touch-icon`, derived from `img/app.svg` / `img/app-dark.svg`; place in `img/` and reference from the manifest/meta.

## 4. Responsive / touch audit

- [ ] 4.1 Vault list: single-column rows, tappable targets, no horizontal overflow (`SecretList.vue`, `SecretListItem.vue`).
- [ ] 4.2 Secret detail: stacked field layout; reveal (eye) and copy controls at a ≥44×44 CSS-px touch target (`SecretDetail.vue`, `PasswordField.vue`, `CopyButton.vue`).
- [ ] 4.3 Search, TOTP display, and lock/unlock: full-width, legible, tappable one-handed at mobile widths (`TotpDisplay.vue`, `LockScreen.vue`).
- [ ] 4.4 Introduce a minimal narrow-viewport breakpoint using the NL Design System double-fallback CSS pattern (`cn-` variables); ensure no fixed widths overflow small screens and the app content honours NC's viewport meta.

## 5. Mobile WebCrypto + clipboard verification

- [ ] 5.1 Exercise unlock → decrypt → reveal → copy on iOS Safari and Android Chrome; document the results.
- [ ] 5.2 Ensure copy-to-clipboard runs synchronously within the tap gesture (decrypt on reveal, copy the already-available value on tap) so mobile Safari permits the write; fix `CopyButton.vue` if it defers behind an async decrypt.
- [ ] 5.3 Confirm the unlock flow surfaces an honest secure-context error when `crypto.subtle` is unavailable, never a fabricated success; document the HTTPS prerequisite.

## 6. Tests

- [ ] 6.1 vitest / DOM: the app page emits the manifest link + mobile meta; the manifest declares `standalone`, themed colours, `start_url`/`scope`, and maskable + any-purpose icons at 192/512.
- [ ] 6.2 e2e (Playwright, mobile viewport): vault list and secret detail render single-column with no horizontal scroll; reveal and copy controls meet the 44px target; copy places the value on the clipboard within the tap.
- [ ] 6.3 vitest: the non-secure-context branch surfaces an explicit secure-context error and no unlocked/success state.

## 7. Quality Gates

- [ ] 7.1 Frontend lint + vitest pass; run hydra gates (spec-coverage, nc-input-labels, visual-coverage) on the diff — `@spec openspec/changes/mobile-pwa/specs/mobile-pwa/spec.md` on changed methods/components.
- [ ] 7.2 `composer check:strict` passes if `templates/index.php` or any PHP is touched; fix any pre-existing issues in touched files in the same batch.
- [ ] 7.3 Confirm no offline caching, no service worker, no share-target, and no push were introduced; the app-shell/offline seam is left to `offline-readonly-cache`.

## Acceptance Criteria

- A Doriath web app manifest (distinct from `src/manifest.json`) is served and linked, declaring `standalone`, themed NL Design System colours, a vault `start_url`/`scope`, maskable + any-purpose icons, and a vault shortcut.
- The app page emits `theme-color` and `apple-mobile-web-app-*` meta so a home-screen launch runs standalone on iOS Safari and Android Chrome.
- This change registers no service worker of its own; installability relies on Nextcloud's instance service worker.
- Maskable icons survive Android's adaptive-icon mask; an iOS touch icon is provided.
- The vault list, secret detail (reveal/copy), search, TOTP display, and lock/unlock are single-column, overflow-free, and meet the 44px touch-target minimum on a narrow viewport.
- Unlock → decrypt → reveal → copy works on iOS Safari and Android Chrome; copy runs inside the tap gesture; a non-secure context fails with an honest secure-context error, never a fabricated success.
- No offline caching, service worker, Web Share Target, push, native app, or app-store listing is introduced; the offline/app-shell seam is left to `offline-readonly-cache`.
