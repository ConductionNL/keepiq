---
kind: code
---

# Proposal: Installable mobile PWA vault

## Why

Doriath is **web-first with no mobile story of its own**. Its own competitive analysis names the gap twice: `docs/FEATURES.md:356` rates "Feature gap vs. Bitwarden (browser extension, mobile, FIDO2)" as **High**, and `docs/FEATURES.md:358` records "No mobile app | Medium | Nextcloud's mobile apps provide the session; Doriath is web-first. Mobile vault is a future consideration." Every mobile client for a Nextcloud vault today is third-party. The canonical feature `mobile-apps` is logged at demand 62 — the highest-demand item with no wave-1 change — and public-sector employees increasingly work from managed mobile devices, so a usable phone experience is table stakes.

A full native app on two platforms (plus app-store review, release trains, and a second crypto implementation) is disproportionate to v1. Doriath already runs entirely in the browser and decrypts client-side via WebCrypto (ADR-003), so **a Progressive Web App delivers mobile access at a fraction of native cost and matches the architecture exactly** — the same code, the same E2E guarantees, installable to the home screen. Verified: there is no web app manifest and no service worker in the repo today (`grep -rln "webmanifest|serviceWorker|standalone|maskable" src templates appinfo` returns nothing; the sibling `offline-readonly-cache` change confirms `serviceWorker|caches.|indexedDB` is absent from `src/`), and the app is served through a single un-audited-for-mobile page (`templates/index.php`). Nextcloud already ships an instance-level web app manifest and service worker, so the installability primitive exists at the platform level — this change makes **Doriath itself** present and behave as a first-class mobile vault on top of it.

This is explicitly the **pragmatic v1 alternative to native apps**: no native app, no app-store presence. It delivers an installable, themed, mobile-first web vault and an honest audit of what WebCrypto and the clipboard can and cannot do on mobile browsers.

## What Changes

- **Ship a Doriath web app manifest** (`manifest.webmanifest`, distinct from the app's internal `src/manifest.json` page/router manifest) declaring `display: standalone`, `name`/`short_name`, `theme_color`/`background_color` matching the NL Design System tokens, a `start_url` and `scope` targeting the Doriath vault route, maskable + themed icons, and an app shortcut into the vault. Link it from the app page via `Util::addHeader` in `templates/index.php`, together with `theme-color` and `apple-mobile-web-app-*` meta so iOS Safari treats the home-screen launch as a standalone app.
- **Add maskable + themed app icons** with correct safe-zone padding (Android adaptive-icon mask + iOS touch icon), derived from the existing `img/app.svg` / `img/app-dark.svg`, so the installed icon renders cleanly across launcher shapes.
- **Rely on Nextcloud's existing instance service worker for the installability criterion — this change registers NO service worker of its own.** Installability (manifest + secure context + a fetch-handling service worker) is satisfied by NC's platform SW; Doriath does not register a competing SW. If a minimal app-shell SW is ever warranted it MUST NOT collide with NC's SW scope, and **offline read caching is explicitly out of scope here** — it is the separate `offline-readonly-cache` capability's surface. This change only documents the seam.
- **Audit and fix the core flows for mobile viewport and touch**: the vault list (`src/views/SecretList.vue`), secret detail with reveal/copy (`src/views/SecretDetail.vue`, `src/components/PasswordField.vue`, `src/components/CopyButton.vue`), search, the TOTP display (`src/components/TotpDisplay.vue`), and the lock/unlock screen (`src/views/LockScreen.vue`). Define responsive breakpoints, ensure single-column layouts on narrow viewports, and meet a minimum touch-target size (WCAG 2.5.5 / 44×44 CSS px) for reveal, copy, and primary actions — using the NL Design System double-fallback CSS pattern, no hardcoded colours.
- **Verify WebCrypto and clipboard on mobile browsers and document the constraints honestly**: confirm the unlock → decrypt → reveal/copy path works on iOS Safari and Android Chrome; document that WebCrypto `subtle` requires a secure context (HTTPS), that mobile Safari's clipboard write requires a direct user gesture, and any other observed constraint — without hiding failures behind a fabricated success state.

### Non-Goals

- **No native app and no app-store presence.** This is the PWA alternative to native; it explicitly does not ship an Android/iOS binary or a store listing.
- **No offline caching.** No secret persistence, no offline vault, no app-shell caching in this change — that is the `offline-readonly-cache` capability. This change only references the seam.
- **No service worker registered by this change.** Installability rides NC's platform service worker.
- **No Web Share Target.** Receiving shared content into Doriath is out of scope for v1.
- **No push notifications.** Mobile push is out of scope for v1.
- **No new crypto.** The existing WebCrypto path is reused verbatim; this change verifies it on mobile, it does not reimplement it.

## Capabilities

### New Capabilities

- `mobile-pwa`: the installable, themed web app manifest and maskable icons; the mobile-first viewport/touch audit and responsive breakpoints for the core vault flows; the mobile-browser WebCrypto/clipboard verification and documented constraints; and the explicit boundaries (no native app, no store, no offline cache, no service worker of its own, no share-target/push).

## Impact

- **Database**: none.
- **Backend**: `templates/index.php` gains manifest + mobile meta headers via `Util::addHeader`; a route may serve `manifest.webmanifest` with the correct MIME type. No controller logic, no encryption change.
- **Frontend**: responsive CSS + touch-target fixes across the core views/components listed above; no change to the crypto or store logic.
- **Assets**: new maskable/themed PWA icons in `img/`.
- **API**: none.
- **Cross-capability**: shares the app-shell seam with `offline-readonly-cache` (that change owns offline caching and any app-shell SW); the responsive fixes benefit every in-app flow. No impact on OpenConnector.
- **Security**: unchanged — the E2E model (ADR-003) is untouched; WebCrypto runs in the same secure context. The manifest and icons expose no secret material. Documenting the HTTPS/secure-context requirement is a correctness note, not a new exposure.
