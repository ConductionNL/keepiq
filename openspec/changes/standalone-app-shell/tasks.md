## 1. Decision record

- [x] 1.1 Write `openspec/architecture/adr-006-keepiq-is-independently-usable.md` (design D7): status accepted 2026-10-05, the exception to hydra ADR-040 and ADR-022, ADR-110's flow engine kept OpenRegister-only behind a presence gate, and the gate consequences (gate-64 n/a, gate-59 closed, gate-30 via `#[AuthorizedAdminSetting]`, gates 5/14 on real controllers). Add it to `openspec/architecture/README.md`.
  - The ADR names Ruben's in-person agreement and the product-owner decision.
  - ADR-004's sentence "the only OR touchpoint is `InitializeSettings`" is updated to point at ADR-006.

## 2. Backend shell

- [x] 2.1 Remove the AppHost wiring from `lib/AppInfo/Application.php` (the `Bootstrap` import, the `bootstrapAppHost()` call, the boot-time `reportFailure()`), delete `lib/AppInfo/OpenRegisterAutoloader.php`, and drop the AppHost "override" framing in `DomainOverrideRegistrar` and `AdminAreaRegistrar` while keeping their bindings (design D1).
  - `grep -rn 'OpenRegister\\\\AppHost' lib appinfo` returns nothing.
- [x] 2.2 Make `McpRegistrar` register the scannable-services alias unconditionally as a string alias, and remove the `$openRegisterPresent` seam (design D5).
  - Registering loads no `OCA\OpenRegister\` name and no `OCA\Keepiq\Mcp\` class.
- [x] 2.3 Rewrite `appinfo/routes.php` as one static table: the #892 fallback plus `health#index`, `metrics#index`, `preferences#getPreference`, `preferences#setPreference`. No `class_exists`, no `settings#load`, no `store#…`, catch-all last (design D2).
- [x] 2.4 Add `lib/Controller/HealthController.php` (public; `database` and `filesystem` checks; `ok`/`degraded`/`error` with 503; only short exception class names in `failed:`) and `lib/Controller/MetricsController.php` (`#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, Prometheus 0.0.4, `keepiq_info`, `keepiq_up`, `keepiq_suites_total` as one filtered COUNT) (design D3).
- [x] 2.5 Add `lib/Controller/PreferencesController.php` as a port of the AppHost preferences contract: `[^a-z0-9-]` stripped, lower-cased, 64-character cap, `pref_<key>` user value of app `keepiq`, empty PUT deletes (design D3).
- [x] 2.6 Change `lib/Sections/SettingsSection.php` to implement `OCP\Settings\IIconSection` with id `keepiq` and its current name, priority and icon (design D4).
- [x] 2.7 Delete `lib/Service/RegisterConfigurationLoader.php`, `lib/Settings/keepiq_register.json`, `lib/Repair/MigrateSchemaApplicationId.php`, replacing it in the same `info.xml` `<step>` slot with a new `RemoveLegacyRegisterRows` repair step (spec: Legacy OpenRegister Rows Are Removed When Empty; unit tests for all three scenarios), `SettingsService`/`AdminSettingsService::loadConfiguration()` and `SettingsController::load()`. Remove `isOpenRegisterAvailable()` and its skip branch from `InitializeSettings`, which now writes `config_version` = installed version after seeding. Delete the gate-59 exclude comment in `AdminSettings::provideAreaState()` (design D4).
  - The `register.d/` README is removed, or rewritten if anything else still references it.

## 3. Frontend shell and gating

- [ ] 3.1 Mount `CnAppRoot` with `:requiresApps="[]"` in `src/App.vue`, pass `:showReimport="false"` in `src/views/settings/AdminRoot.vue`, and remove the OpenRegister `objectStore.configure(...)` and the unused `useObjectStore` export from `src/store/store.js`, and bind `:aiCompanion` to Hermiq's presence (design D6).
- [ ] 3.2 In `src/manifest.json`: add `visibleIf.appInstalled: "openregister"` to `FlowsMenu`, add `requiresApp: {"id": "openregister", "name": "OpenRegister"}` to the `Flows` and `FlowDetail` pages, and remove the `observability` block. `npm run check:manifest` stays green (design D6, Mixed-spec rationale).
  - `manifest.dependencies` stays `[]`.

## 4. Tests

- [ ] 4.1 Unit tests for the three controllers and the section.
  - Health: ok, degraded, error (503), and a key-set assertion with no secret material.
  - Metrics: N active of N+M suites, the content type, and the series names.
  - Preferences: the round-trip, `walkthrough_completed_version` → `pref_walkthroughcompletedversion`, per-user isolation, 400 on an empty key, empty PUT deletes.
  - `SettingsSection`: id, name and icon.
- [ ] 4.2 Contract tests for the new public or routed endpoints (`health#index`, `metrics#index`, `preferences#*`) to satisfy gate-25. Move the health/metrics Newman scenarios from the "OR AppHost" collection into `tests/integration/keepiq.postman_collection.json`, plus a 404 assertion for `POST /api/settings/load`.
- [ ] 4.3 Replace `RoutesWithoutOpenRegisterTest` and `CanonicalRouteMethodContractTest` with a static-table test: every route resolves to a public method on an `OCA\Keepiq\Controller` class, no duplicate names, catch-all last, and an identical table with and without a stub `AppHost\Routes`. Delete `OpenRegisterAutoloaderTest`, `ManifestObservabilityTest` and the `openregister-apphost*` stubs. Keep `openregister-mcp.stub.php` only where `McpSurfaceTest` needs it.
- [ ] 4.4 Bootstrap test: run `Application::register()` against a recording `IRegistrationContext` with a recording autoloader on an autoload path without OpenRegister and integriq. Assert that no `OCA\OpenRegister\` or `OCA\Integriq\` name is requested, that every registrar ran, and that the MCP alias is registered.
- [ ] 4.5 Playwright e2e for the `app-shell` UI scenarios, each carrying its `@e2e` reference (gate-19).
  - On a no-OpenRegister instance: the shell renders the lock screen and vault, there is no Flows entry, a `/apps/keepiq/flows` deep link shows the missing-dependency screen, the admin section and version card are up to date with no re-import button, there is no Integrations entry, no AI companion and no `/apps/hermiq/` request, and the WASM CSP is still sent.
  - With OpenRegister: Flows renders.
  - Add `@spec` tags to every changed or added public/protected method (gate-16).
  - No encryption code changes: the existing JS↔PHP round-trip and "API never returns decrypted values" suites must stay green, unchanged.

## 5. CI and documentation

- [ ] 5.1 Add a CI leg in `.github/workflows/code-quality.yml` (or a new workflow) that installs Keepiq with no OpenRegister and no integriq in `apps-extra`, then runs the unit suite, `occ app:enable keepiq`, `GET /login` = 200, `GET /apps/keepiq/api/health` = 200, and the no-OpenRegister e2e subset. Update the comments in `code-quality.yml` that state OpenRegister must be present. Leave `docs-media.yml`, `mobile-e2e.yml` and `federation.yml` installing OpenRegister where their captures need Flows or MCP, otherwise drop it.
- [ ] 5.2 Update `README.md` (requirements table: OpenRegister optional, with what it enables; remove "Keepiq itself is not usable"; dev setup no longer needs a sibling `../openregister`; note that the upgrade removes the empty legacy `keepiq`/`doriath` registers, schema and data table), `openspec/config.yaml` (context: no AppHost), and the `info.xml` comments that call openregister or integriq dependencies.

## 6. Verification

- [ ] 6.1 Run `composer check:strict`, `npm run lint`, `npm run test`, and `vendor/bin/hydra-gates` (gates 5, 14, 16, 19, 25, 30, 59, 64 called out in the PR). Record the results in the PR body.
- [ ] 6.2 Live check on the dev instance with OpenRegister **disabled** (`occ app:disable openregister`):
  - `GET /login` = 200, Keepiq loads and unlocks, and a secret round-trips.
  - The Flows entry is hidden, and `/apps/keepiq/flows/1` shows the missing-dependency screen.
  - Health and metrics answer per spec.
  - The network tab shows no `/apps/openregister/` or `/apps/hermiq/` request.
- [ ] 6.3 Live check with OpenRegister **enabled**: Flows lists and opens a flow, and Hermiq lists exactly `keepiq.listEntries`, `keepiq.expiryReport` and `keepiq.rotationStatus` through OpenRegister's MCP endpoint. Re-enable OpenRegister afterwards and confirm `occ status` shows maintenance off.
