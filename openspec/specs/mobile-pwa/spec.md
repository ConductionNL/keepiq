# Mobile PWA Specification

**Status**: done

**Feature tier**: V1

**OpenSpec changes:** [mobile-pwa](../../changes/archive/2026-07-20-mobile-pwa/)

## Purpose

Doriath is web-first with no mobile story of its own — every mobile client for a Nextcloud vault today is third-party, and the app ships no web app manifest and no service worker. A full native app on two platforms is disproportionate to v1, and Doriath already runs entirely in the browser and decrypts client-side via WebCrypto (ADR-003), so a Progressive Web App delivers mobile access at a fraction of native cost while matching the architecture exactly. This feature is the pragmatic v1 alternative to native apps: an installable, themed, mobile-first web vault — **no native app and no app-store presence** — plus an honest audit of what WebCrypto and the clipboard can and cannot do on mobile browsers. Offline access is explicitly out of scope here and belongs to the `offline-readonly-cache` feature.

## Requirements

### Requirement: Installable Web App Manifest
The system MUST serve and link a Doriath web app manifest (distinct from the internal page/router manifest) declaring `display: standalone`, themed NL Design System colours, a `start_url`/`scope` targeting the vault, maskable + any-purpose icons, and a vault shortcut, together with `theme-color` and `apple-mobile-web-app-*` meta on the app page. It MUST NOT register a service worker of its own; installability relies on Nextcloud's instance service worker.

#### Scenario: The app is installable and launches standalone
- GIVEN the app is served over HTTPS on a mobile browser
- WHEN the user adds Doriath to the home screen and launches it
- THEN it MUST open in a standalone window using the themed manifest, with no service worker registered by Doriath itself

### Requirement: Maskable and Themed App Icons
The system MUST provide a maskable icon with an adequate safe zone, an any-purpose icon (192×192 and 512×512), and an iOS `apple-touch-icon`, all derived from the Doriath app icon.

#### Scenario: Maskable icon is not clipped
- GIVEN the app is installed to the home screen
- WHEN the launcher applies an adaptive-icon mask
- THEN the Doriath glyph MUST remain fully visible within the safe zone

### Requirement: Mobile-First Responsive Core Flows
The vault list, secret detail with reveal/copy, search, TOTP display, and lock/unlock MUST be usable on a narrow mobile viewport — single-column, no horizontal overflow, and a minimum 44×44 CSS-px touch target for reveal, copy, and primary actions — using the NL Design System double-fallback CSS pattern.

#### Scenario: Core flows are usable one-handed on mobile
- GIVEN a narrow mobile viewport
- WHEN the user opens the vault list, a secret detail, search, the TOTP display, and the lock screen
- THEN each MUST render single-column without horizontal scrolling and expose ≥44px touch targets for reveal/copy/primary actions

### Requirement: Mobile WebCrypto and Clipboard Verification
The unlock → decrypt → reveal → copy path MUST work on iOS Safari and Android Chrome. Copy MUST run inside the user's tap gesture. A secure context (HTTPS) is a WebCrypto prerequisite, and an unavailable `crypto.subtle` MUST surface an honest error, never a fabricated success.

#### Scenario: Copy inside the tap gesture; honest failure without a secure context
- GIVEN a revealed secret on mobile Safari over HTTPS
- WHEN the user taps copy
- THEN the value MUST be written to the clipboard synchronously within the gesture
- AND in a non-secure context where `crypto.subtle` is unavailable, the unlock flow MUST show an explicit secure-context error instead of a fabricated success

### Requirement: Offline and Native Boundaries
The feature MUST NOT introduce offline caching, offline vault access, an app-shell service worker, a Web Share Target, push notifications, a native app, or an app-store listing. Offline read caching is the `offline-readonly-cache` feature's responsibility.

#### Scenario: No offline caching is introduced
- GIVEN the installed PWA without connectivity
- WHEN the user opens it
- THEN it MUST behave like the live-fetch web app with no cached secrets, offline caching remaining with `offline-readonly-cache`

## User Stories

- As a mobile user, I want to install Doriath to my home screen so that I can open my vault like a native app
- As a mobile user, I want the vault list and secret detail to fit my phone screen so that I can read and copy secrets without pinch-zooming
- As a mobile user, I want reveal and copy buttons large enough to tap reliably so that I do not mis-tap
- As an iOS/Android user, I want unlock and decrypt to work in my mobile browser so that my secrets are actually usable on the go
- As a security-conscious user, I want an honest error if the crypto cannot run so that I am never shown a fake unlocked state

## Acceptance Criteria

- [ ] A linked Doriath web app manifest (distinct from `src/manifest.json`) declares standalone display, themed colours, vault `start_url`/`scope`, maskable + any-purpose icons, and a vault shortcut
- [ ] `theme-color` and `apple-mobile-web-app-*` meta are emitted so a home-screen launch runs standalone
- [ ] No service worker is registered by this feature; installability relies on Nextcloud's instance service worker
- [ ] Maskable and iOS touch icons are provided and survive the adaptive-icon mask
- [ ] Vault list, secret detail, search, TOTP, and lock/unlock are single-column, overflow-free, and meet the 44px touch-target minimum on a narrow viewport
- [ ] Unlock → decrypt → reveal → copy works on iOS Safari and Android Chrome, with copy inside the tap gesture
- [ ] A non-secure context surfaces an honest secure-context error, never a fabricated success
- [ ] No offline caching, service worker, share-target, push, native app, or app-store listing is introduced

## Notes

- Nextcloud already ships an instance-level web app manifest and service worker; this feature makes Doriath itself present and behave as a first-class mobile vault on top of that platform.
- The web app manifest (`manifest.webmanifest`) is distinct from Doriath's internal `src/manifest.json` page/router manifest — different consumer and schema.
- The app-shell/offline seam is deliberately left to the `offline-readonly-cache` feature to avoid scope collision with Nextcloud's service worker.
- Related ADRs: ADR-001 (own DB tables), ADR-003 (encryption architecture). Related specs: secrets, dashboard, offline-readonly-cache.
