## ADDED Requirements

### Requirement: One build per browser

The build MUST produce a loadable extension package for Chromium browsers (Chrome, Edge), for Firefox and for Safari, each with the manifest keys that browser requires. The Firefox package MUST start its background script, and the Chromium package MUST keep its service worker.

#### Scenario: Firefox starts the background

- **GIVEN** the Firefox package installed as a temporary add-on
- **WHEN** the browser loads it
- **THEN** the background script runs and the popup can reach it

#### Scenario: Chromium is unchanged

- **GIVEN** the Chromium package loaded unpacked
- **WHEN** the browser loads it
- **THEN** the service worker registers as before

#### Scenario: Safari package is produced

- **GIVEN** a macOS build runner
- **WHEN** the Safari conversion step runs
- **THEN** an Xcode project or app extension bundle is produced and its build log is kept
