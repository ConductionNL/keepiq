## ADDED Requirements

### Requirement: Installable Web App Manifest
The system MUST serve a Doriath web app manifest (`manifest.webmanifest`), distinct from the app's internal page/router manifest, declaring `display: standalone`, a `name` and `short_name`, `theme_color` and `background_color` derived from the NL Design System tokens (no hardcoded colours), a `start_url` and `scope` targeting the Doriath vault route, an `icons` array, and at least one app shortcut into the vault. The app page MUST link the manifest and MUST provide the `theme-color` and `apple-mobile-web-app-*` meta so that a home-screen launch on iOS Safari and Android Chrome runs in a standalone window. This change MUST NOT register a service worker of its own; installability relies on Nextcloud's existing instance service worker.

#### Scenario: App page links an installable manifest
- **WHEN** the Doriath app page is served
- **THEN** it MUST include a `<link rel="manifest">` to `manifest.webmanifest` and the `theme-color` and `apple-mobile-web-app-*` meta
- **AND** the manifest MUST declare `display: standalone`, a `start_url`/`scope` targeting the vault, and themed colours from NL Design System tokens

#### Scenario: No competing service worker is registered
- **WHEN** the app loads on a mobile browser
- **THEN** Doriath MUST NOT register a service worker of its own
- **AND** installability MUST be satisfied by the platform (Nextcloud instance) service worker

### Requirement: Maskable and Themed App Icons
The system MUST provide PWA icons including a maskable icon with a safe zone sufficient that Android's adaptive-icon mask does not clip the glyph, an any-purpose icon, and an iOS `apple-touch-icon`, at least at 192×192 and 512×512 for the web-manifest icons. Icons MUST derive from the existing Doriath app icon.

#### Scenario: Maskable icon survives the adaptive mask
- **WHEN** the installed app icon is rendered inside a circular or squircle launcher mask
- **THEN** the Doriath glyph MUST remain fully visible within the mask safe zone
- **AND** the manifest MUST expose a `maskable` icon and an `any` purpose icon at 192×192 and 512×512

### Requirement: Mobile-First Responsive Core Flows
The core vault flows — vault list, secret detail with reveal/copy, search, TOTP display, and lock/unlock — MUST be usable on a narrow mobile viewport: single-column layout, no horizontal overflow, and a minimum 44×44 CSS-px touch target for the reveal, copy, and primary action controls (WCAG 2.5.5). Layout MUST use the NL Design System double-fallback CSS pattern with `cn-`-prefixed variables and MUST NOT hardcode colours or fixed widths that overflow small screens.

#### Scenario: Vault list and detail render without horizontal scroll
- **WHEN** a user opens the vault list and a secret detail on a narrow mobile viewport
- **THEN** the content MUST lay out in a single column with no horizontal scrolling

#### Scenario: Reveal and copy controls meet the touch-target minimum
- **WHEN** a secret detail is shown on a mobile viewport
- **THEN** the reveal (eye) and copy controls MUST each present at least a 44×44 CSS-px touch target

#### Scenario: TOTP and unlock are usable one-handed
- **WHEN** the TOTP display and the lock/unlock screen are shown on a mobile viewport
- **THEN** the one-time code, countdown, copy control, and the master-password entry MUST be legible and tappable without zooming

### Requirement: Mobile WebCrypto and Clipboard Verification
The unlock → decrypt → reveal → copy path MUST function on iOS Safari and Android Chrome. The system MUST treat a secure context (HTTPS) as a prerequisite for WebCrypto and MUST surface an honest error when `crypto.subtle` is unavailable, never a fabricated success. Copy-to-clipboard MUST run within the user's tap gesture so mobile Safari permits the write.

#### Scenario: Copy runs inside the tap gesture on mobile Safari
- **WHEN** a user taps the copy control for a revealed secret on mobile Safari
- **THEN** the clipboard write MUST occur synchronously within that gesture using the already-decrypted value
- **AND** the value MUST be copied successfully

#### Scenario: Non-secure context fails honestly
- **WHEN** the app is loaded in a non-secure context where `crypto.subtle` is unavailable
- **THEN** the unlock flow MUST surface an explicit error about the secure-context requirement
- **AND** it MUST NOT present a fabricated unlocked or success state

### Requirement: Offline and Native Boundaries
This capability MUST NOT introduce offline secret caching, offline vault access, app-shell caching, a Web Share Target, push notifications, a native app binary, or an app-store listing. Offline read caching and any app-shell service worker are the `offline-readonly-cache` capability's responsibility; this capability only documents the seam.

#### Scenario: No offline caching is introduced
- **WHEN** the mobile PWA is used without connectivity
- **THEN** it MUST behave like the live-fetch web app (no cached secrets, no offline vault)
- **AND** offline caching MUST remain the responsibility of the `offline-readonly-cache` capability
