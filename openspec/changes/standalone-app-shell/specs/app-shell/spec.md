## ADDED Requirements

### Requirement: Keepiq Operates Without Any Other Conduction App

Keepiq SHALL be installable, enableable, upgradable and fully usable on a Nextcloud instance where neither OpenRegister nor integriq is installed, or where either is installed but disabled. `Application::register()` and `Application::boot()` SHALL NOT reference, autoload, or resolve any `OCA\OpenRegister\…` or `OCA\Integriq\…` class, and SHALL NOT call `OCA\OpenRegister\AppHost\Bootstrap::register()`. The frontend SHALL mount `CnAppRoot` with an empty `requiresApps` list, so the app shell never replaces Keepiq's UI with a missing-app screen. The behaviour of the app SHALL be identical whether OpenRegister is enabled, disabled or absent, except for the optional integrations named in "Optional Integrations Appear Only When Their App Is Present".

#### Scenario: Keepiq is usable on an instance without OpenRegister or integriq

- **GIVEN** a Nextcloud instance on which neither OpenRegister nor integriq is installed
- **WHEN** an admin runs `occ app:enable keepiq` and a user opens `/apps/keepiq/`
- **THEN** the enable MUST succeed with every repair step completing without a warning that names OpenRegister
- **AND** the Keepiq shell MUST render the lock screen, and after unlock the vault list, with no "OpenRegister missing" or missing-dependency screen
- **AND** creating, reading, sharing and deleting a secret MUST work as on an instance with OpenRegister enabled

#### Scenario: Disabling OpenRegister changes nothing in Keepiq

- **GIVEN** an instance where Keepiq and OpenRegister are both enabled and a user has an unlocked vault
- **WHEN** an admin runs `occ app:disable openregister` and the user reloads Keepiq
- **THEN** every Nextcloud page MUST still answer HTTP 200 (no instance-wide 500)
- **AND** the user's vault MUST still load and unlock

#### Scenario: Bootstrap loads no foreign class

- **GIVEN** OpenRegister and integriq are both absent from the autoload path
- **WHEN** Keepiq's `Application::register()` and `Application::boot()` run
- **THEN** neither MUST throw, no autoload of an `OCA\OpenRegister\` or `OCA\Integriq\` name MUST be attempted, and every Keepiq registrar (domain overrides, admin areas, MCP, event registrars, platform integration) MUST have run
- @e2e exclude bootstrap wiring — no UI surface; covered by a unit test that runs register() with a recording autoloader and asserts no foreign prefix is requested

### Requirement: Keepiq Owns Its Route Table

`appinfo/routes.php` SHALL return one static route table defined by Keepiq on every instance, with no `class_exists()` branch on another app. Every route SHALL resolve to a public method on a `OCA\Keepiq\Controller\…` class. The table SHALL contain the dashboard page, every domain route, the settings routes, `health#index`, `metrics#index`, `preferences#getPreference` and `preferences#setPreference`, and the SPA catch-all as the last route. It SHALL NOT contain `settings#load` or any `store#…` route.

#### Scenario: Every route resolves to a Keepiq controller

- **GIVEN** the route table returned by `appinfo/routes.php` with no OpenRegister class on the autoload path
- **WHEN** each entry's `name` is resolved to a controller class and method
- **THEN** every entry MUST resolve to an existing public method on a class in `OCA\Keepiq\Controller`, every route name MUST appear once, and `dashboard#catchAll` MUST be the last route
- @e2e exclude route-table contract — covered by the routes unit test and hydra gate-14 (route-reachability)

#### Scenario: The table is the same with or without OpenRegister

- **GIVEN** the route table read once with OpenRegister absent and once with a stub `OCA\OpenRegister\AppHost\Routes` class defined
- **WHEN** the two tables are compared
- **THEN** they MUST be identical
- @e2e exclude route-table contract — covered by the routes unit test

#### Scenario: Removed OpenRegister-only routes answer 404

- **GIVEN** an admin session
- **WHEN** the admin calls `POST /apps/keepiq/api/settings/load` or `GET /apps/keepiq/api/store/items`
- **THEN** the response MUST be HTTP 404 and no configuration import MUST be attempted
- @e2e exclude API-only — covered by the Newman collection

### Requirement: Public Health Endpoint

Keepiq SHALL serve `GET /apps/keepiq/api/health` from its own `HealthController`, publicly accessible (`#[PublicPage]`, `#[NoCSRFRequired]`) per hydra ADR-006. The controller SHALL run two checks, `database` (critical: a trivial query against a Keepiq table) and `filesystem` (degraded: the temp directory is writable). The response SHALL contain ONLY the keys `status`, `app`, `version` and `checks`. `status` SHALL be `ok` when all checks pass, `degraded` when only a degraded check fails, and `error` with HTTP 503 when a critical check fails. Each `checks` value SHALL be `"ok"` or `"failed: <infrastructure error class>"`, and no value SHALL carry secret material.

#### Scenario: Anonymous health check succeeds

- **GIVEN** a healthy instance with Keepiq enabled and no OpenRegister installed
- **WHEN** `GET /apps/keepiq/api/health` is called with no authentication
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `app = "keepiq"`, the installed app version, `checks.database = "ok"` and `checks.filesystem = "ok"`
- @e2e exclude API-only endpoint — covered by the Keepiq Newman collection and a contract test

#### Scenario: Health response carries no secret material

- **GIVEN** an instance containing secrets, encryption suites, shares and link-share tokens
- **WHEN** `GET /apps/keepiq/api/health` is called with no authentication
- **THEN** the response body MUST contain ONLY the keys `status`, `app`, `version` and `checks`, and the `checks` values MUST be limited to `"ok"` or `"failed: <infrastructure error>"` strings, with no secret values, suite identifiers, key material, share tokens, user identifiers or row counts
- @e2e exclude API-only endpoint — covered by the Keepiq Newman collection and a contract test

#### Scenario: Degraded filesystem does not mask database health

- **GIVEN** an instance where the temp filesystem is not writable but the database is reachable
- **WHEN** `GET /apps/keepiq/api/health` is called
- **THEN** the response MUST be HTTP 200 with `status = "degraded"`, `checks.filesystem` beginning `failed:` and `checks.database = "ok"`
- @e2e exclude API-only endpoint — covered by a unit test with a non-writable temp path

#### Scenario: Unreachable database reports an error

- **GIVEN** a database query that throws
- **WHEN** `GET /apps/keepiq/api/health` is called
- **THEN** the response MUST be HTTP 503 with `status = "error"`, and `checks.database` MUST begin `failed:` followed by the exception's class name only, never its message
- @e2e exclude API-only endpoint — covered by a unit test with a throwing query builder

### Requirement: Admin-Only Metrics Endpoint

Keepiq SHALL serve `GET /apps/keepiq/api/metrics` from its own `MetricsController` in Prometheus text exposition format 0.0.4 (`Content-Type: text/plain; version=0.0.4`). Access SHALL be restricted to admins and to users delegated Keepiq's General admin area: the method SHALL declare `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, the explicit admin posture that hydra gate-30 (public-monitoring) accepts for an admin-only scrape endpoint, and SHALL NOT carry `#[NoAdminRequired]` or `#[PublicPage]`. The output SHALL contain `keepiq_info{version,php_version,nextcloud_version} 1`, `keepiq_up 1` and `keepiq_suites_total`, computed as one SQL `COUNT(*)` over `keepiq_enc_suites` with `status = 'active'`. It SHALL contain no identifier, key material or owner.

#### Scenario: Metrics report the active suite count

- **GIVEN** a seeded instance with N active and M revoked encryption suites
- **WHEN** `GET /apps/keepiq/api/metrics` is called by an admin
- **THEN** the output MUST contain `keepiq_info{…} 1`, `keepiq_up 1` and `keepiq_suites_total N` (active suites only, matching a direct filtered table count), with `Content-Type: text/plain; version=0.0.4`
- @e2e exclude API-only endpoint — covered by the Keepiq Newman collection and a contract test

#### Scenario: Non-admin user cannot scrape metrics

- **GIVEN** an authenticated non-admin user
- **WHEN** that user calls `GET /apps/keepiq/api/metrics`
- **THEN** the request MUST be rejected with HTTP 403
- @e2e exclude API-only endpoint — covered by the Keepiq Newman collection

### Requirement: Per-User Preferences Endpoint

Keepiq SHALL serve `GET /apps/keepiq/api/preferences/{key}` and `PUT /apps/keepiq/api/preferences/{key}` from its own `PreferencesController`, for any logged-in user (`#[NoAdminRequired]`). The key SHALL be normalised by lower-casing and stripping every character outside `[a-z0-9-]`, then cut to 64 characters. A key that is empty after this SHALL be refused with HTTP 400. The value SHALL be stored through `OCP\IConfig` user values under app `keepiq` and key `pref_<normalised key>`, which is the same storage key the AppHost controller used, so existing values survive. `GET` SHALL answer `{"value": <string|null>}`. `PUT` SHALL answer `{"value": <stored value>}`, and with an empty value SHALL delete the preference and answer `{"value": null}`. A user SHALL only ever read or write their own preferences. Values are UI state (for example the walkthrough's completed version), are stored in plain text, and SHALL NOT be used for secret material.

#### Scenario: The walkthrough preference round-trips

- **GIVEN** a logged-in user with no stored preference
- **WHEN** the user calls `PUT /apps/keepiq/api/preferences/walkthrough_completed_version` with value `0.3.7`, then `GET` on the same URL
- **THEN** the `PUT` MUST answer `{"value": "0.3.7"}`, the `GET` MUST answer `{"value": "0.3.7"}`, and the value MUST be stored as user value `pref_walkthroughcompletedversion` of app `keepiq`
- @e2e exclude API-only endpoint — the walkthrough's use of it is covered by the existing walkthrough e2e flow; the contract is covered by a contract test

#### Scenario: Preferences are per user

- **GIVEN** user A has stored a preference under key `walkthrough_completed_version`
- **WHEN** user B calls `GET /apps/keepiq/api/preferences/walkthrough_completed_version`
- **THEN** the response MUST be `{"value": null}`
- @e2e exclude API-only endpoint — covered by a contract test

#### Scenario: An invalid key is refused

- **GIVEN** a logged-in user
- **WHEN** the user calls `GET /apps/keepiq/api/preferences/%20%21` (a key with no `[a-z0-9-]` character)
- **THEN** the response MUST be HTTP 400 and no user value MUST be read or written
- @e2e exclude API-only endpoint — covered by a unit test

### Requirement: Native Admin Section And Version Card

Keepiq's admin section (`OCA\Keepiq\Sections\SettingsSection`) SHALL implement `OCP\Settings\IIconSection` directly. The General admin area SHALL render the version card through `CnAdminSettingsShell` with the re-import action hidden (`showReimport: false`), because Keepiq imports no configuration. The `config_version` app value that the card compares against SHALL be written by Keepiq's own `InitializeSettings` repair step, set to the installed app version once its domain default-config seeding has completed. A version read with no matching write SHALL therefore not exist (hydra gate-59).

#### Scenario: The admin section renders without OpenRegister

- **GIVEN** an instance without OpenRegister
- **WHEN** an admin opens Administration settings → Keepiq
- **THEN** the Keepiq section MUST be listed with its icon, the General area MUST render, and the domain settings (password policy, session timeout, CA auto-renew) MUST be readable and writable

#### Scenario: The version card is up to date after install

- **GIVEN** a fresh install where `occ app:enable keepiq` has run its repair steps
- **WHEN** an admin opens the General admin area
- **THEN** the version card MUST show the installed version as up to date, and no "Re-import configuration" button MUST be shown

### Requirement: Legacy OpenRegister Rows Are Removed When Empty

A Keepiq repair step (`OCA\Keepiq\Repair\RemoveLegacyRegisterRows`, replacing `MigrateSchemaApplicationId`) SHALL remove what earlier Keepiq versions created in OpenRegister:

- every `openregister_schemas` row whose `application` is `keepiq` or `doriath`;
- for each such schema, OpenRegister's per-schema data table `openregister_table_<registerId>_<schemaId>`, dropped only when it holds zero rows;
- every `openregister_registers` row whose `slug` is `keepiq` or `doriath`, once its `schemas` list no longer names a schema outside that set.

A schema SHALL count as empty only when it has zero rows in `openregister_objects` AND zero rows in its per-schema data table (or that table does not exist). The step SHALL select rows by `application` and register slug, never by schema slug alone, because schema slugs such as `example` are shared between apps. It SHALL reach OpenRegister's tables through `IDBConnection` only, after checking that they exist, and SHALL reference no OpenRegister class. It SHALL be idempotent, and SHALL NOT fail the install or upgrade: any error is logged and the step ends.

#### Scenario: Empty leftovers are removed when OpenRegister is installed

- **GIVEN** an instance with a `doriath` register listing schema `example` (`application = keepiq`), that schema's empty data table, and an empty `keepiq` register
- **WHEN** `occ upgrade` runs Keepiq's repair steps
- **THEN** both registers, the schema row and the empty data table MUST be gone, and every other OpenRegister register, schema and table MUST be untouched, including another app's schema with the slug `example`

#### Scenario: Rows that still hold objects are kept

- **GIVEN** a Keepiq-keyed schema with at least one row in `openregister_objects` or in its per-schema data table
- **WHEN** the repair step runs
- **THEN** that schema, its data table and its register MUST be kept, and a warning naming the schema slug and row count MUST be logged

#### Scenario: Nothing happens without OpenRegister's tables

- **GIVEN** an instance where OpenRegister was never installed
- **WHEN** the repair step runs
- **THEN** it MUST end without error and without issuing any query against an `openregister_*` table

#### Scenario: A second run is a no-op

- **GIVEN** an instance where the repair step already removed the leftovers
- **WHEN** the repair step runs again
- **THEN** it MUST end without error, delete nothing and drop nothing

### Requirement: Optional Integrations Appear Only When Their App Is Present

Each Keepiq integration with another app SHALL be reachable only when that app is installed and enabled, and its absence SHALL NOT affect any other part of Keepiq:

- **MCP tools** (`mcp-metadata-surface`) require OpenRegister.
- **The Flows and FlowDetail pages** require OpenRegister, whose flow engine they render (hydra ADR-110). The `FlowsMenu` entry SHALL carry `visibleIf.appInstalled: "openregister"`, and both pages SHALL carry `requiresApp: {"id": "openregister", "name": "OpenRegister"}`.
- **The Integrations page and connection reporting** (`admin-integrations`) require integriq, as already declared in `src/manifest.d/80-connection-registry.json` and by `ConnectionReporter`'s by-name event lookup.
- **The AI companion** (`CnAiCompanion`, mounted by `CnAppRoot`'s `aiCompanion` prop) requires Hermiq, its chat backend. `aiCompanion` SHALL be `true` only when `hermiq` is enabled for the user (`OC.appswebroots`), so that without Hermiq the shell issues no request to `/apps/hermiq/`.

#### Scenario: Flows are hidden without OpenRegister

- **GIVEN** an instance without OpenRegister and a logged-in user with an unlocked vault
- **WHEN** the user looks at Keepiq's navigation
- **THEN** no Flows entry MUST be shown

#### Scenario: A deep link to Flows without OpenRegister explains the missing app

- **GIVEN** an instance without OpenRegister
- **WHEN** a user opens `/apps/keepiq/flows` or `/apps/keepiq/flows/1` directly (the router uses HTML5 history mode)
- **THEN** the page MUST render the missing-dependency screen naming OpenRegister instead of the flow list or canvas, and the rest of the shell (navigation, vault) MUST stay usable

#### Scenario: Flows work when OpenRegister is present

- **GIVEN** an instance with OpenRegister enabled
- **WHEN** a user opens the Flows entry and then one flow
- **THEN** the flow list and the flow canvas MUST render as before this change

#### Scenario: The AI companion is absent without Hermiq

- **GIVEN** an instance without Hermiq
- **WHEN** a user loads Keepiq
- **THEN** no AI companion MUST be rendered, and no request to `/apps/hermiq/` MUST be issued

#### Scenario: Integrations are hidden without integriq

- **GIVEN** an instance without integriq and an admin user
- **WHEN** the admin looks at Keepiq's settings navigation
- **THEN** no Integrations entry MUST be shown, and saving a connection-bearing setting MUST NOT throw

### Requirement: Domain Surfaces Unchanged

This change SHALL NOT alter any controller, service, middleware, listener, migration or repair step that touches secrets, encryption suites, shares, certificates, application secrets or JWT authentication, other than its registration path. Their routes SHALL keep their names, URLs, verbs and auth postures. `DashboardController` SHALL keep its argon2-WASM CSP opt-in.

#### Scenario: Dashboard WASM CSP survives the change

- **GIVEN** the app with its own shell and no OpenRegister
- **WHEN** a user opens the Keepiq SPA and accesses a link share whose AES key is derived by the argon2-browser WASM module
- **THEN** the page response MUST carry the `wasm-unsafe-eval` CSP opt-in and the client-side key derivation MUST succeed

#### Scenario: Domain routes keep their contract

- **GIVEN** the route table before and after this change
- **WHEN** the domain routes (secrets, suites, CA, shares, delegations, public link-share access, public secret-request fill, JWT token exchange, applications, dashboard settings) are compared
- **THEN** each MUST resolve to the same controller method at the same URL and verb, with the same auth attributes
- @e2e exclude route-table contract — covered by the routes unit test and `PublicRouteSurfaceContractTest`
