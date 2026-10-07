## Context

Keepiq keeps all vault data in its own encrypted tables (ADR-001, ADR-004), but its **shell** is borrowed from OpenRegister:

```
Keepiq                          OpenRegister
──────────────────────────      ─────────────────────────────────────────
Application::register() ──────▶ AppHost\Bootstrap::register()   (via OpenRegisterAutoloader prelude)
appinfo/routes.php ───────────▶ AppHost\Routes::standard()      (#892: class_exists fallback)
Sections\SettingsSection ─────▶ extends AppHost\Settings\GenericSettingsSection
Service\RegisterConfigLoader ─▶ Service\ConfigurationService::importFromApp()   (0 schemas)
Repair\MigrateSchemaAppId ────▶ openregister_schemas table
src/App.vue <CnAppRoot> ──────▶ requiresApps default ['openregister']  → whole UI blanked
src/store/store.js ───────────▶ /apps/openregister/api/objects|schemas  (configured, unused)
manifest Flows pages ─────────▶ OpenRegister flow engine (ADR-110)
/api/preferences, /health, /metrics, /store ─▶ AppHost generic controllers only
Mcp\KeepiqScannableServices ──▶ OpenRegister MCP scanner (already presence-gated)
Connection\ConnectionReporter ▶ integriq events (already by-name, optional)
```

Measured on `development` 8ee612ec:

- `DomainOverrideRegistrar` already binds Keepiq's own `DashboardController`, `SettingsController`, `AdminSettings` and `InitializeSettings` over the AppHost aliases. Most of the shell is therefore Keepiq's already.
- The only AppHost-only routes are preferences, health, metrics and store. Nothing in `src/` or `lib/` calls `/api/store/*`.
- `keepiq_register.json` declares zero schemas and zero registers.
- `MigrateSchemaApplicationId` only reads and updates `*PREFIX*openregister_schemas`. Its replacement, `RemoveLegacyRegisterRows`, keeps that access path.
- The only frontend caller of `POST /api/settings/load` is `CnAdminSettingsShell`'s re-import button.

Stakeholders: Robert (decision owner), Ruben (agreed in person to the ADR exception on 2026-10-05), and OpenConnector as a consumer of Keepiq's application-secret API (unaffected).

## Goals / Non-Goals

**Goals:**

- Keepiq installs, upgrades and runs fully on an instance with no OpenRegister and no integriq.
- The three optional integrations (MCP, Flows, Integrations) keep working when their app is present and disappear cleanly when it is not.
- The health, metrics and preferences contracts stay byte-compatible for existing consumers: Prometheus, uptime monitors, and the walkthrough's stored value.
- Net code shrinks: fewer files and no load-order hazard.

**Non-Goals:**

- Making OpenRegister's AppHost itself OpenRegister-independent (the 2026-09-15 idea). Out of scope: Keepiq simply stops using it.
- A Keepiq-owned flow engine. Flows stay OpenRegister's (hydra ADR-110).
- Any change to encryption, sharing, CA, JWT or application-secret behaviour. This change does not touch the E2E model (ADR-003): the server still never sees plaintext or the master password.
- Removing `@conduction/nextcloud-vue`. It is a bundled UI library, not an app dependency.

## Decisions

### D1. Drop AppHost entirely rather than vendor its generics

Keepiq registers its own classes. `Application::register()` loses the `OpenRegisterAutoloader::bootstrapAppHost()` call. `DomainOverrideRegistrar` becomes the plain registration of Keepiq's concretes: its "override" framing goes, while the class bindings stay.

*Alternatives:*
- **Copy AppHost's generic classes into Keepiq.** Rejected: it would import about 40 classes, most for features Keepiq doesn't use (store, deep links, settings plane, register resolver), and they would drift from upstream.
- **Keep the AppHost call behind the presence check.** Rejected: that is #892's state, which leaves two code paths and the load-order prelude in place.

### D2. One static route table

`appinfo/routes.php` returns the #892 fallback table, extended with `health#index`, `metrics#index`, `preferences#getPreference` and `preferences#setPreference`. There is no `class_exists` branch. `settings#load` and the `store#…` routes are not carried over. This is the only API removal, and nothing in Keepiq calls those routes (verified with grep over `src/` and `lib/`).

*Alternative:* keep `settings#load` as a no-op returning `{success: true}`. Rejected: a no-op endpoint invites the re-import button to come back, and it would need its own contract test for nothing.

### D3. Plain controllers for health, metrics and preferences

**`HealthController`:**
- Posture: `#[PublicPage]`, `#[NoCSRFRequired]`.
- Checks: `database`, a `SELECT 1`-class query through `IDBConnection` against `keepiq_enc_suites` with `setMaxResults(1)`; and `filesystem`, `is_writable(ITempManager::getTempBaseDir())`.
- Response: `{status, app, version, checks}`. An exception shows as `failed: <ShortClassName>` only, never the message, so no path or credential can leak.

**`MetricsController`:**
- Posture: `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, `#[NoCSRFRequired]`. That is the explicit admin posture gate-30 accepts, so no waiver is needed.
- Output: a hand-written Prometheus 0.0.4 text body with `keepiq_info`, `keepiq_up` and `keepiq_suites_total`, the last from one `COUNT(*)` with `status = 'active'` through the query builder.

**`PreferencesController`:**
- Posture: `#[NoAdminRequired]`.
- Behaviour: a port of AppHost's `GenericPreferencesController` contract, with key normalisation `[^a-z0-9-]` stripped, lower-cased, 64-character cap, and storage key `pref_<key>` in `IConfig` user values for app `keepiq`.
- The storage key is kept **identical on purpose**: the walkthrough's existing `walkthrough_completed_version` value lives at `pref_walkthroughcompletedversion`, and users must not see the tour again.

OCP interfaces used: `IRequest`, `IUserSession`, `IConfig`, `IDBConnection`, `ITempManager`, `IAppManager` (version), `IConfig::getSystemValueString('version')` (Nextcloud version).

*Starting point:* Keepiq had its own `HealthController` and `MetricsController` before it adopted AppHost (the manifest `observability._note` says they were deleted then). Restore them from git history and adjust them to the contracts above, rather than writing them from scratch.

*Alternative:* port the manifest-driven observability engine. Rejected by the product decision (plain controllers). Two checks and one gauge don't justify an engine. The `observability` block leaves `src/manifest.json`.

### D4. Admin section and version card

- `SettingsSection` implements `OCP\Settings\IIconSection` (`getID`, `getName`, `getPriority`, `getIcon`) with the same id `keepiq`, so the existing `AdminSettings` areas and their `#[AuthorizedAdminSetting]` targets keep working.
- `InitializeSettings` writes `config_version` = installed app version after seeding the domain defaults. This closes the read in `AdminSettings::provideAreaState()`, so gate-59's "written by OpenRegister" exclude comment can be deleted rather than waived.
- `AdminRoot.vue` passes `:showReimport="false"` to `CnAdminSettingsShell`.
- `InitializeSettings` also loses its "OpenRegister not available, skipping" branch and the `loadConfiguration()` call.

### D5. MCP: register the alias unconditionally, load nothing

Today `McpRegistrar` asks `OpenRegisterAutoloader::register()` whether OpenRegister is present. That call is the prelude's side effect, which puts OpenRegister's PSR-4 prefix on the autoloader. With the prelude deleted, `McpRegistrar` simply calls `$context->registerServiceAlias('OCA\OpenRegister\Mcp\IMcpScannableServices::keepiq', KeepiqScannableServices::class)`:

- **Both arguments are strings.** `KeepiqScannableServices::class` is a compile-time constant, so nothing autoloads.
- **The class only loads when OpenRegister asks.** It is resolved only when OpenRegister's scanner asks Keepiq's container for that alias. That can only happen while OpenRegister runs, and by then OpenRegister's prefix is registered by Nextcloud itself. Only then is the interface it implements (and the `#[McpTool]` attribute) needed.
- **Without OpenRegister nobody asks.** The alias is inert, and the request never touches an `OCA\OpenRegister\` name.

*Alternative:* gate on `IAppManager::isEnabledForUser('openregister')` at register time. Rejected: `register()` is too early to rely on the app manager's per-user state, and a gate adds a branch that buys nothing over an inert alias. The `McpRegistrar` constructor seam (`$openRegisterPresent`) is removed with it.

**Load-order hazard, restated:** Keepiq (`k`) registers before OpenRegister (`o`). Under this design no Keepiq code needs an OpenRegister class during `register()`, so the hazard the prelude existed for no longer applies to Keepiq.

### D6. Frontend gating uses the shared library's existing mechanisms

- `src/App.vue`: `<CnAppRoot :requiresApps="[]" …>`.
- `src/manifest.json`, the Flows entries:
  - `FlowsMenu` gets `"visibleIf": {"appInstalled": "openregister"}`, which hides it in navigation.
  - The `Flows` and `FlowDetail` pages get `"requiresApp": {"id": "openregister", "name": "OpenRegister"}`, so a deep link renders the library's missing-dependency screen.
  - This is the pattern `manifest.d/80-connection-registry.json` already uses for integriq.
- `src/store/store.js`: drop the `objectStore.configure(...)` with OpenRegister URLs and the unused `useObjectStore` export.
- `src/App.vue`: `:aiCompanion` becomes a computed `hermiqEnabled` (`Boolean(window.OC?.appswebroots?.hermiq)`, the same source `KeepiqAppNav` already reads). `CnAiCompanion` would otherwise probe `/apps/hermiq/api/chat/health` up to three times on every load before hiding itself.
- The Integrations page needs integriq *and* OpenRegister: its index reads integriq's `app_connection` objects through OpenRegister's objects API. Gating on integriq is sufficient because integriq requires OpenRegister, and this is stated in `app-shell`.

### D7. A Keepiq ADR records the exception

`openspec/architecture/adr-006-keepiq-is-independently-usable.md`:

- **Status:** accepted, 2026-10-05.
- **Decision:** Keepiq does not adopt hydra ADR-040 (AppHost) and does not consume OpenRegister abstractions for its shell (hydra ADR-022).
- **Why:** Keepiq must be independently usable, as agreed by the product owner and Ruben on 2026-10-05.
- **What stays OpenRegister-only, behind presence gates:** the OpenRegister flow engine (hydra ADR-110) and the MCP endpoint.
- **Gate consequences:**
  - gate-64 (apphost-autoload-prelude) no longer applies, because there is no AppHost call.
  - gate-59 closes because Keepiq now writes `config_version`.
  - gate-30 passes through `#[AuthorizedAdminSetting]` on metrics.
  - gates 5 and 14 now judge real Keepiq controllers instead of AppHost aliases.
  - `CanonicalRouteMethodContractTest` (Keepiq's own test asserting `Routes::standard(`) is rewritten to assert the static table.
- **Fleet consequence:** a fleet AppHost improvement no longer reaches Keepiq automatically. That is accepted.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `keepiq_suites_total` count (an aggregation) | Imperative: one query-builder `COUNT(*)` in `MetricsController` | The declarative path (manifest `observability` gauge / `x-openregister-aggregations`) *is* the OpenRegister dependency this change removes. Keepiq's data is not in OpenRegister (ADR-004), so there is no schema register to declare it on. |
| Health checks | Imperative, in `HealthController` | Same reason. Two fixed infrastructure checks, no derived data. |
| Flows / Integrations visibility | Declarative (manifest `visibleIf` / `requiresApp`) | The shared library already evaluates these. No code needed. |

No lifecycle, notification, relation or widget behaviour is introduced or changed.

### Mixed-spec rationale

The change is `kind: code`. Its only declarative edits are in `src/manifest.json`: two `requiresApp` keys, one `visibleIf`, and the removed `observability` block. That's under 20 lines in one config file, and it is tightly coupled to the code change: without the `CnAppRoot` and route changes, the manifest gates have nothing to gate. Splitting it would leave either a manifest that hides Flows on instances where the shell is still blanked, or a shell that shows a Flows entry leading to a broken page.

### Seed Data

None. The change introduces and modifies no OpenRegister schema (it deletes the zero-schema `keepiq_register.json`) and no Keepiq table. Keepiq stores all data in its own `lib/Db` tables (ADR-001, ADR-004). The existing `Seed*` development repair steps are unaffected. The only new stored value is the `config_version` app value written by `InitializeSettings`.

## Risks / Trade-offs

- **[Risk] Instances that already hold OpenRegister rows for Keepiq** (`openregister_schemas.application = 'doriath'|'keepiq'`, the `doriath` and `keepiq` registers, and a per-schema data table such as `oc_openregister_table_20_66`; all measured empty on the dev instance on 2026-10-05) would otherwise stay behind as orphans. → A new repair step, `RemoveLegacyRegisterRows`, removes them (user decision 2026-10-05). It uses `IDBConnection` only, after checking the tables exist, so Keepiq still references no OpenRegister class. It deletes a schema only when both `openregister_objects` and its per-schema data table hold zero rows, and drops that data table in the same case (user decision 2026-10-05), and it logs and ends rather than failing the upgrade. Trade-off: writing straight to another app's tables skips OpenRegister's caches and events. That is acceptable for rows nothing reads, and it is the access path `MigrateSchemaApplicationId` already used.
- **[Risk] Replacing the `MigrateSchemaApplicationId` repair step** while an instance still has `doriath`-keyed schemas. → With no import, the "second empty set" failure it guarded against can no longer happen. `RemoveLegacyRegisterRows` takes over its `<step>` slot in `info.xml` and deletes those schemas instead of renaming them.
- **[Risk] An external monitor or Prometheus config breaks** if the health or metrics output shape drifts. → Contract tests pin the exact keys, content type and series names from the old spec, and the Newman collection moves to Keepiq.
- **[Risk] The walkthrough re-appears for every user** if the preference key normalisation differs. → D3 keeps it identical, and a unit test pins `walkthrough_completed_version` → `pref_walkthroughcompletedversion`.
- **[Risk] A dev or CI instance with OpenRegister masks a hidden dependency.** → A new CI leg runs the unit suite, the route and bootstrap tests, and a smoke e2e with *no* OpenRegister and *no* integriq in `apps-extra`.
- **[Trade-off] Keepiq no longer receives fleet AppHost fixes.** → Accepted in ADR-006. The surfaces involved are small: three controllers and a static table.
- **[Risk] `CnAppRoot`'s dependency phase** (`manifest.dependencies`) could still blank the UI. → `dependencies` is `[]` in Keepiq's manifest, and it must stay so. The bootstrap e2e asserts that the shell renders.

## Migration Plan

1. The ADR and specs land with the code in one PR.
2. **Deploy:** a normal `occ upgrade`. The repair-step list swaps `MigrateSchemaApplicationId` for `RemoveLegacyRegisterRows`, `InitializeSettings` writes `config_version`, and there are no schema migrations.
3. **Instances with OpenRegister:** no visible change. Flows and MCP still appear.
4. **Instances without OpenRegister:** Keepiq becomes usable.
5. **Rollback:** revert the PR. The previous release re-imports its empty register on the next repair run, and no data migration needs undoing.

## Open Questions

None open. Resolved during review (2026-10-05):

- `CnIndexPage` in `ApplicationRegisterView` renders local `rows` from Keepiq's store, and the three `object-table` dashboard widgets read `endpointSource.url` values under `/apps/keepiq/api/`. Neither calls OpenRegister.
- `CnAppRoot`'s `aiCompanion` was the one remaining foreign request (Hermiq's chat health probe). It is now gated in D6.
- The live check (task 6.2) still watches the network tab for any `/apps/openregister/` or `/apps/hermiq/` request as a backstop.
