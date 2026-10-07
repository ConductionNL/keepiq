# extension-release Specification

## Purpose
What a browser extension package carries besides code: icons, Firefox's data collection declaration and third-party notices.

## Requirements

### Requirement: The extension ships its own icons
Every package MUST carry icons of 16, 32, 48 and 128 pixels, rendered from the app's own icon (`img/pwa-icon.svg`), named in the manifest for the store and for the toolbar button.

#### Scenario: A built package
@e2e exclude Build output, no browser surface. Covered by tests/extension/release.spec.js ("ships an icon of every size the manifest names, for the toolbar too (%s)").
- **GIVEN** the Chromium and Firefox packages
- **WHEN** they are built
- **THEN** each names icons of 16, 32, 48 and 128 pixels for the store and the toolbar, and each file has that size

### Requirement: Firefox is told what leaves the browser
The Firefox package MUST declare its data collection for Firefox's consent screen: `authenticationInfo` (the app password and encrypted logins) and `browsingActivity` (the site the user is on, to find its logins). It MUST NOT declare `none`: data sent to a server the user chose still counts. The privacy policy MUST say the same.

#### Scenario: The Firefox manifest
@e2e exclude Manifest content, no browser surface. Covered by tests/extension/release.spec.js ("tells Firefox that logins and the current site leave the browser, and nothing else").
- **GIVEN** the Firefox manifest
- **WHEN** it is built
- **THEN** it declares exactly authenticationInfo and browsingActivity, and the Chromium manifest has no such block

### Requirement: Bundled third-party material is credited
Every package MUST carry a notices file that credits the EFF large word list (CC BY 3.0 US) and includes the MIT licence of argon2-browser, which are bundled.

#### Scenario: The notices
@e2e exclude Build output, no browser surface. Covered by tests/extension/release.spec.js ("carries the notices for the bundled word list and Argon2 (%s)").
- **GIVEN** a built package
- **WHEN** its notices file is read
- **THEN** it credits the word list under CC BY 3.0 US and carries argon2-browser's MIT licence
