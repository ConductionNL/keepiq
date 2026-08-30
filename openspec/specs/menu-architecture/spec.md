# menu-architecture Specification

## Purpose

TBD - created by archiving change adopt-buildmanifest-menu-layout. Update Purpose after archive.

## Requirements

### Requirement: Shared buildManifest pipeline

Keepiq SHALL build its effective manifest (pages + menu) via `@conduction/nextcloud-vue`'s `buildManifest(base, fragments, menuLayout)` util. No app-local re-implementation of fragment merging, relocation, removal, or settings-section application logic SHALL exist in `src/main.js` or elsewhere in the app.

#### Scenario: main.js delegates manifest assembly

- **GIVEN** the Keepiq frontend bootstrap in `src/main.js`
- **WHEN** the effective manifest is assembled from `src/manifest.json` and `src/manifest.d/*.json` fragments
- **THEN** the assembly MUST go through the imported `buildManifest` function from `@conduction/nextcloud-vue`, and no local function duplicating `mergeMenuItems` / `applyMenuRelocations` / `applyMenuRemovals` / `applySettingsSection` semantics SHALL be defined in the app
- @e2e exclude build-time wiring, not a runtime user-facing scenario — verified by code review + gate

### Requirement: Setup/admin entries live in the settings foldout

Keepiq SHALL declare a `src/menu-layout.json` that places configuration, definitions, and integration/connection-plumbing menu entries — specifically the application registration/approval queue — into the settings foldout (`section: "settings"` via `settingsSection`) rather than the main scrollable navigation.

#### Scenario: Applications entry appears in the settings foldout, not main nav

- **GIVEN** a Keepiq instance with the `menu-layout.json` `settingsSection` including `"ApplicationsMenu"`
- **WHEN** a user opens the Keepiq app
- **THEN** the main scrollable navigation MUST show only daily-use entries (Dashboard, Vault) plus footer items, and the "Applications" entry MUST appear inside the `NcAppNavigationSettings` foldout (bottom-left gear)

#### Scenario: Relocated route stays reachable

- **GIVEN** the `ApplicationsMenu` menu entry has moved from main nav to the settings foldout
- **WHEN** a user navigates to the Applications route via the foldout entry or a direct deep link
- **THEN** the `ApplicationRegister` page MUST render identically to before the relocation — the route itself is unchanged, only its menu placement moved

### Requirement: App navigation renders the manifest menu

Keepiq SHALL render its left navigation rail from the effective manifest's `menu[]`: entries sorted by `order` (entries without one last), split into the main scrollable section, the footer, and the settings foldout by each entry's `section`. Route entries MUST navigate through the SPA router, external `href` entries MUST render as real anchors (opened in a new tab for scheme-prefixed URLs), the entry matching the current route MUST be marked active, and entries carrying `action: "user-settings"` MUST open the host's settings dialog instead of navigating.

#### Scenario: App navigation renders

- **GIVEN** a user with an unlocked vault
- **WHEN** they open Keepiq
- **THEN** the left navigation MUST render the manifest menu entries in their declared sections and order, mark the current route's entry as active, render external entries as real anchors, and open the user-settings dialog — without navigating — for entries whose `action` is `"user-settings"`
