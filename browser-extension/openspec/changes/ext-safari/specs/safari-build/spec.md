## Purpose

Ships the extension to Safari 26 and later on macOS from the same source as Chrome and Firefox, inside a containing app that CI builds and loads in Safari on every change.

## ADDED Requirements

### Requirement: Safari build
The build SHALL produce a Safari extension as Manifest V3 from the same source, with `browser_specific_settings.safari.strict_min_version` `26.0`, while Chrome stays MV3 and Firefox stays MV2. The Safari manifest MUST declare `nativeMessaging` and MUST NOT declare `idle`, `offscreen` or `privacy`. Server origins stay optional host permissions requested when an account is added; the manifest declares no `host_permissions`.

#### Scenario: Three targets
- **GIVEN** a clean checkout
- **WHEN** `npm run build`, `npm run build:firefox` and `npm run build:safari` run
- **THEN** `.output/chrome-mv3/manifest.json` has `manifest_version` 3 and `offscreen`, `.output/firefox-mv2/manifest.json` has 2 and neither `offscreen` nor `nativeMessaging`, and `.output/safari-mv3/manifest.json` has 3, `nativeMessaging`, `strict_min_version` `26.0`, and none of `idle`, `offscreen`, `privacy` or `host_permissions`

### Requirement: Chromium-only features stay off Safari
On Safari the extension SHALL NOT use or offer what Safari lacks: no offscreen document, no default password manager entry, and no link to `chrome://extensions/shortcuts`. A missing extension API MUST NOT stop the background from starting.

#### Scenario: Background starts without idle
- **GIVEN** the Safari build, where `browser.idle` is undefined
- **WHEN** the background starts
- **THEN** it registers its other listeners and the popup can unlock an account

### Requirement: Containing app
The Safari extension SHALL ship inside a macOS app named Keepiq with bundle identifier `nl.conduction.keepiq.safari`, whose extension's identifier starts with `nl.conduction.keepiq.safari.`. The app is generated from the Safari build by Safari's web extension packager; the repository holds only the native message handler, its tests and the script that packages, overlays and builds it. The handler keeps no state and never logs a copied value.

#### Scenario: Packaging
- **GIVEN** `.output/safari-mv3/` from `npm run build:safari` on a Mac with Xcode 26
- **WHEN** `npm run package:safari` runs
- **THEN** `.output/keepiq-safari.zip` holds `Keepiq.app`, ad-hoc signed, whose extension runs the repository's `SafariWebExtensionHandler.swift`
- **AND** the script prints both bundle identifiers and fails when either differs from the rule above

#### Scenario: Packager output changed
- **GIVEN** a packager that generates no `SafariWebExtensionHandler.swift`, or more than one
- **WHEN** `npm run package:safari` runs
- **THEN** it fails with a message naming the missing handler, and builds nothing

### Requirement: Safari is checked in CI
Every change to the extension SHALL run a macOS job that builds the Safari target, runs the handler's tests, packages and builds the app, and loads the extension in Safari over WebDriver. The smoke test MUST pass only when the extension installs and Safari answers its id. When the extension has access to the test site in the automation session, its `MAIN` world content script MUST also have replaced `navigator.credentials.get` on a test-site page; without that access, or when the extension's own page cannot be opened to ask, the test logs that it skipped the check. The build, its artifacts and safaridriver's logs are kept for 14 days.

#### Scenario: Smoke test
- **GIVEN** the job on a `macos-26` runner with `safaridriver` enabled and the test site on port 8100
- **WHEN** the smoke test installs `.output/safari-mv3` with `POST /session/{id}/webextension`, as a path or else as a base64 zip
- **THEN** Safari answers the extension's id

#### Scenario: Smoke test with website access
- **GIVEN** the installed extension, whose page `popup.html` reports `permissions.contains` true for `http://localhost:8100/*`
- **WHEN** the smoke test opens `http://localhost:8100/passkey.html`
- **THEN** `navigator.credentials.get` is no longer the browser's native function

#### Scenario: Smoke test without website access
- **GIVEN** the installed extension without access to `http://localhost:8100/*` in the automation session, or whose page cannot be opened
- **WHEN** the smoke test runs
- **THEN** it logs that it skipped the `navigator.credentials.get` check and passes on the install alone

#### Scenario: Handler test fails
- **GIVEN** a change that makes `clipboard.clear` clear a pasteboard someone wrote since
- **WHEN** the job runs
- **THEN** the handler test step fails and the job fails
