---
kind: code
---

# Keepiq runs without any other app

## Why

Keepiq is a secrets vault: it keeps every secret in its own encrypted tables (ADR-001, ADR-004) and stores nothing in OpenRegister. Even so, it cannot be used without OpenRegister installed and enabled. The app shell (startup wiring, route table, admin section, health, metrics and preferences) runs on OpenRegister's AppHost engine, and the frontend's `CnAppRoot` is mounted with its default `requiresApps: ['openregister']`. Without OpenRegister the whole Keepiq UI is replaced by an "OpenRegister missing" screen.

#892 stopped a missing OpenRegister from taking all of Nextcloud down (#857, #867), but only by degrading Keepiq. Its README now says Keepiq "is not usable" without OpenRegister. The product decision (2026-10-05, agreed with Ruben in person) is that **Keepiq must be independently usable**. The integrations that genuinely need another app stay, but only appear when that app is present.

## What Changes

- **Own app shell.** `Application::register()` no longer calls `OCA\OpenRegister\AppHost\Bootstrap::register()`. `OpenRegisterAutoloader` and its prelude are deleted.
- **Own route table.** `appinfo/routes.php` returns Keepiq's own table on every instance. The #892 fallback becomes the only path, plus the three routes below. **BREAKING (API):** `POST /api/settings/load` (re-import the OpenRegister configuration) and the AppHost-only `/api/store/*` routes are removed. Nothing in Keepiq calls either of them.
- **Plain controllers** in Keepiq, with the same URLs and auth postures as today:
  - `GET /api/health` (public).
  - `GET /api/metrics` (admin-only, Prometheus 0.0.4).
  - `GET` and `PUT /api/preferences/{key}` (per-user, logged in).
- **Native admin section.** `SettingsSection` implements `OCP\Settings\IIconSection` instead of extending `GenericSettingsSection`. The admin version card no longer depends on an OpenRegister import: the Keepiq repair step records `config_version` itself, and the re-import button is hidden.
- **Dead OpenRegister plumbing removed:**
  - `RegisterConfigurationLoader` and its callers.
  - `lib/Settings/keepiq_register.json`, which declares 0 schemas and 0 registers.
  - The `MigrateSchemaApplicationId` repair step, which only re-keys rows in `openregister_schemas`.
  - The unused OpenRegister object-store configuration in `src/store/store.js`.
- **Frontend independent of OpenRegister.** `CnAppRoot` is mounted with `:requires-apps="[]"`.
- **Integrations gated on presence:**
  - **MCP tools** (`listEntries`, `expiryReport`, `rotationStatus`): offered only when OpenRegister is present. The scannable-services alias stays inert otherwise.
  - **Flows and FlowDetail pages:** the menu entry is shown only when OpenRegister is installed (`visibleIf.appInstalled`). A deep link without OpenRegister lands on the missing-dependency screen (`requiresApp`).
  - **Integrations page:** already gated on integriq. Unchanged.
  - **AI companion:** mounted only when Hermiq, its chat backend, is enabled.
- **ADR-006 (Keepiq).** A local exception ADR records that Keepiq does not adopt the AppHost engine (hydra ADR-040) and does not consume OpenRegister abstractions for its shell (hydra ADR-022). OpenRegister's flow engine (hydra ADR-110) stays the only flow engine and is reached only when present.
- **Documentation and CI** updated: the README requirements table, `openspec/config.yaml` context, `info.xml` comments, and the workflows that install OpenRegister. One CI leg runs Keepiq's tests and a smoke check with **no** OpenRegister and **no** integriq installed.

## Capabilities

### New Capabilities

- `app-shell`: Keepiq's own shell. It covers standalone operation without any other Conduction app, the health/metrics/preferences endpoint contracts, the admin section, and the rule that each optional integration is reachable only when the app it needs is present.

### Modified Capabilities

- `apphost-adoption`: every requirement is removed. Health and metrics move to `app-shell` with the same contracts. The AppHost plumbing, the OpenRegister autoload prelude and the register-import scenario go away.
- `mcp-metadata-surface`: the opt-in requirement now states that the surface exists only when OpenRegister is installed and enabled, and that without it Keepiq loads no OpenRegister class and boots normally.

## Impact

- **Backend:**
  - Modified: `lib/AppInfo/Application.php`, `lib/AppInfo/McpRegistrar.php`, `lib/AppInfo/DomainOverrideRegistrar.php`, `lib/Sections/SettingsSection.php`, `lib/Settings/AdminSettings.php`, `lib/Service/SettingsService.php`, `lib/Service/AdminSettingsService.php`, `lib/Repair/InitializeSettings.php`, `lib/Controller/SettingsController.php`, `appinfo/routes.php`, `appinfo/info.xml`.
  - New: `HealthController`, `MetricsController`, `PreferencesController`.
  - Deleted: `lib/AppInfo/OpenRegisterAutoloader.php`, `lib/Service/RegisterConfigurationLoader.php`, `lib/Repair/MigrateSchemaApplicationId.php`, `lib/Settings/keepiq_register.json`.
- **Frontend:** `src/App.vue`, `src/views/settings/AdminRoot.vue`, `src/store/store.js`, `src/manifest.json` (Flows gating, removed `observability` block).
- **Tests:**
  - Rewritten or deleted: `CanonicalRouteMethodContractTest`, `RoutesWithoutOpenRegisterTest`, `OpenRegisterAutoloaderTest`, `ManifestObservabilityTest`, and the AppHost stubs.
  - New: unit and contract tests for the three controllers.
  - Newman collection: health/metrics scenarios move from the "OR AppHost" collection to Keepiq's own.
- **API:** the URLs and verbs of the health, metrics, preferences, settings and domain routes are unchanged. Removed: `POST /api/settings/load` and `/api/store/*`.
- **Security:**
  - Health stays public and returns only `status`, `app`, `version` and `checks`, never secret material. Metrics stays admin-only and returns aggregate counts only.
  - Preferences are keyed per user and never shared between users.
  - No encryption code changes: encryption, sharing, the CA and JWT surfaces are untouched (see `app-shell` "Domain surfaces unchanged").
- **OpenConnector:** unaffected. OpenConnector consumes Keepiq's application-secret API and token exchange, which are Keepiq domain routes and keep their URLs and postures. Keepiq's own secret-provider seam has no OpenRegister in its path.
- **Dependencies:** none added. OpenRegister and integriq become optional at runtime. `@conduction/nextcloud-vue` stays as a bundled UI library.
- **Feature tier:** platform/infrastructure. It does not map to a `docs/FEATURES.md` row, but it removes a prerequisite from every MVP feature.
