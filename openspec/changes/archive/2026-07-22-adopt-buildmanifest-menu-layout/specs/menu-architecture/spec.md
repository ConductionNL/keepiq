---
status: proposed
---

# Doriath Menu Architecture (buildManifest adoption)

## Purpose

Doriath builds its effective navigation via the shared `@conduction/nextcloud-vue` `buildManifest(base, fragments, menuLayout)` pipeline (hydra ADR-044), not a bespoke inline merge, and places setup/admin/plumbing entries in the settings foldout rather than the main scrollable nav.

## ADDED Requirements

### Requirement: Shared buildManifest pipeline

Doriath SHALL build its effective manifest (pages + menu) via `@conduction/nextcloud-vue`'s `buildManifest(base, fragments, menuLayout)` util. No app-local re-implementation of fragment merging, relocation, removal, or settings-section application logic SHALL exist in `src/main.js` or elsewhere in the app.

#### Scenario: main.js delegates manifest assembly

- **GIVEN** the Doriath frontend bootstrap in `src/main.js`
- **WHEN** the effective manifest is assembled from `src/manifest.json` and `src/manifest.d/*.json` fragments
- **THEN** the assembly MUST go through the imported `buildManifest` function from `@conduction/nextcloud-vue`, and no local function duplicating `mergeMenuItems` / `applyMenuRelocations` / `applyMenuRemovals` / `applySettingsSection` semantics SHALL be defined in the app
- @e2e exclude build-time wiring, not a runtime user-facing scenario — verified by code review + gate

### Requirement: Setup/admin entries live in the settings foldout

Doriath SHALL declare a `src/menu-layout.json` that places configuration, definitions, and integration/connection-plumbing menu entries — specifically the application registration/approval queue — into the settings foldout (`section: "settings"` via `settingsSection`) rather than the main scrollable navigation.

#### Scenario: Applications entry appears in the settings foldout, not main nav

- **GIVEN** a Doriath instance with the `menu-layout.json` `settingsSection` including `"ApplicationsMenu"`
- **WHEN** a user opens the Doriath app
- **THEN** the main scrollable navigation MUST show only daily-use entries (Dashboard, Vault) plus footer items, and the "Applications" entry MUST appear inside the `NcAppNavigationSettings` foldout (bottom-left gear)

#### Scenario: Relocated route stays reachable

- **GIVEN** the `ApplicationsMenu` menu entry has moved from main nav to the settings foldout
- **WHEN** a user navigates to the Applications route via the foldout entry or a direct deep link
- **THEN** the `ApplicationRegister` page MUST render identically to before the relocation — the route itself is unchanged, only its menu placement moved
