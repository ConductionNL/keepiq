# Design — mobile-pwa

## Context

Doriath is a Vue 2.7 SPA served through a single Nextcloud page (`templates/index.php`) that mounts `#content` and loads the shared chunks + `doriath-main`. All decryption happens in the browser via WebCrypto against the in-memory `CryptoKey` (ADR-003, encryption-suites Session Mechanism). There is no web app manifest and no service worker in the repo today (verified: `grep -rln "webmanifest|serviceWorker|standalone|maskable" src templates appinfo` is empty; the `offline-readonly-cache` change independently confirms `serviceWorker|caches.|indexedDB` is absent from `src/`). Nextcloud itself ships an instance-level `manifest.webmanifest` and a service worker (the theming/core PWA), so the platform already satisfies the browser's "installable" service-worker criterion.

This change makes **Doriath** present and behave as a first-class installable mobile vault on top of that platform, without a native app, without an app-store listing, and without introducing offline caching (the sibling `offline-readonly-cache` capability owns that).

## Decisions

### D1 — Ship a Doriath web app manifest, distinct from the internal page manifest
Doriath already has `src/manifest.json` — that is the **internal page/router manifest** consumed by `main.js` to build the vue-router; it is not a PWA artifact. This change adds a separate **web app manifest** (`manifest.webmanifest`, W3C Web App Manifest) declaring `name`, `short_name`, `display: standalone`, `theme_color`/`background_color` (NL Design System tokens, no hardcoded hex), `start_url` and `scope` pointing at the Doriath vault route, an `icons` array (maskable + any-purpose), and a `shortcuts` entry into the vault. The two artifacts are named and documented distinctly to avoid confusion. Rejected: overloading `src/manifest.json` — different consumer, different schema, different lifecycle.

### D2 — Link the manifest from the app page; add iOS meta; register no service worker
The manifest and mobile meta are injected from `templates/index.php` via `OCP\Util::addHeader` (`<link rel="manifest">`, `<meta name="theme-color">`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`, `apple-mobile-web-app-title`, and an `apple-touch-icon` link). **This change registers no service worker.** The browser's installability contract (secure context + manifest + a fetch-handling service worker) is satisfied for the SW part by Nextcloud's existing instance service worker; Doriath does not register a competing SW (a second SW at an overlapping scope would conflict with NC's). This keeps the change minimal and leaves any app-shell SW to `offline-readonly-cache`. Rejected: registering a Doriath service worker now — it collides with NC's SW scope and pulls offline-caching scope into this change.

### D3 — Maskable + themed icons with correct safe zone
Provide PWA icons derived from the existing `img/app.svg` / `img/app-dark.svg`: a maskable icon with ≥20% safe-zone padding (so Android's adaptive-icon mask never clips the glyph), an any-purpose icon, and an iOS `apple-touch-icon` (no transparency, correct corner treatment applied by iOS). Sizes cover the common install targets (at least 192×192 and 512×512, plus the iOS touch icon). Rejected: reusing the un-padded app icon as maskable — it clips under circular/squircle masks.

### D4 — Mobile-first viewport/touch audit of the core flows, with breakpoints
Audit and fix the vault-critical flows for narrow viewports and touch:

- **Vault list** (`src/views/SecretList.vue`, `src/components/SecretListItem.vue`) — single-column rows, tappable row targets, no horizontal overflow.
- **Secret detail + reveal/copy** (`src/views/SecretDetail.vue`, `src/components/PasswordField.vue:5-12`, `src/components/CopyButton.vue`) — reveal (eye) and copy controls sized to a minimum 44×44 CSS-px touch target (WCAG 2.5.5, Apple HIG), stacked field layout.
- **Search** — full-width input, reachable without a hover affordance.
- **TOTP display** (`src/components/TotpDisplay.vue`) — code, countdown ring, and copy legible and tappable at mobile widths.
- **Lock / unlock** (`src/views/LockScreen.vue`) — the master-password entry is comfortable one-handed; this is the first screen on every mobile session.

Define a small set of responsive breakpoints (e.g. a single narrow-viewport breakpoint driving single-column layout) using the NL Design System double-fallback CSS pattern with `cn-` prefixed variables — no hardcoded colours, no fixed pixel widths that overflow small screens. The `<meta name="viewport">` tag is provided by Nextcloud's page chrome; the audit ensures the app content honours it and never forces horizontal scroll.

### D5 — Verify WebCrypto + clipboard on mobile browsers; document constraints honestly
Exercise the real path — unlock → decrypt a secret → reveal → copy — on iOS Safari and Android Chrome, and record the outcome truthfully:

- **WebCrypto `subtle`** requires a **secure context (HTTPS)**; over plain HTTP on a LAN IP `crypto.subtle` is undefined and unlock fails. Document this as a deployment prerequisite (it already holds for any real NC instance behind TLS) rather than papering over it.
- **Clipboard write** on mobile Safari requires a **direct user gesture**; copy-to-clipboard must run synchronously inside the tap handler (verify `CopyButton.vue` does, and fix if it defers behind an async decrypt — decrypt first on reveal, then copy the already-available value on tap).
- Any further observed constraint (e.g. viewport resize on the software keyboard, `visualViewport` quirks) is documented, not hidden. A failure MUST surface as an honest error, never a fabricated success — mirroring `totp`'s "never show a fabricated code" honesty.

### Declarative-vs-imperative decision
Imperative, per ADR-001: Doriath owns its own tables and does not use OpenRegister. This change touches only the app page template, static assets (manifest + icons), and frontend CSS/layout — there is no OR register, schema, or seed data involved, and no data storage at all.

## Decisions made under uncertainty

- **Manifest precedence vs. Nextcloud's instance manifest.** A page-level `<link rel="manifest">` may be superseded by NC's instance manifest depending on the browser and how NC serves the page. Decision: ship the Doriath manifest + iOS meta (which iOS honours per-page) and rely on NC's platform SW for the SW criterion; where the browser installs the NC-scoped PWA instead, Doriath's icon/shortcut and the responsive fixes still deliver the mobile vault experience. The manifest is additive and harmless where overridden. A deployment note captures the observed behaviour per platform.
- **Service worker or not.** Chosen: none in this change. If installability on a target browser strictly requires an app-owned SW, the minimal-SW seam is documented for `offline-readonly-cache` to own — this change will not register one, to avoid scope collision with NC's SW and with offline caching.
- **Which breakpoints.** Chosen: a minimal single narrow-viewport breakpoint driving single-column layout and 44px targets, rather than a full multi-tier grid system — enough to make the core flows usable one-handed without over-engineering a responsive framework. Refinable later against real device testing.
- **HTTPS requirement framing.** Chosen: document the secure-context requirement as a prerequisite (true of every production NC) rather than attempt a non-secure-context fallback — there is none for WebCrypto, and pretending otherwise would be dishonest.
- **iOS clipboard gesture.** If `CopyButton.vue` currently copies after an async decrypt, the fix is to ensure the plaintext is resolved on reveal so the tap-time copy is synchronous. Confirmed as a verification task; the exact code touch depends on the current handler shape.

## Risks / Trade-offs

- **Not a native app.** Some native-only capabilities (biometric unlock via platform APIs, background autofill) are out of reach for a PWA v1. Accepted: this is explicitly the pragmatic alternative, and the vault is fully usable installed to the home screen.
- **Manifest override by NC's instance PWA.** → Additive and harmless; the responsive fixes and icons carry the experience regardless (uncertainty note above).
- **No offline access.** → By design; deferred to `offline-readonly-cache`. Users on a flaky mobile connection get the same live-fetch behaviour as the desktop web app until that change lands.
- **Secure-context dependency.** → Documented prerequisite; holds for any TLS-terminated NC. Only bites on plain-HTTP dev/LAN setups.

## Migration / Rollout

- No data migration. Additive assets (manifest + icons) and CSS/layout fixes only. Existing desktop web usage is unaffected; mobile users gain an installable, touch-usable vault. The app-shell/offline seam is left for `offline-readonly-cache` to build without rework here.
