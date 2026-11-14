## REMOVED Requirements

### Requirement: Public Declarative Health Endpoint

**Reason**: Keepiq no longer adopts the OpenRegister AppHost engine (Keepiq ADR-006). The health endpoint is no longer served by `GenericHealthController` from manifest descriptors.

**Migration**: Replaced by `app-shell` "Public Health Endpoint": same URL, same public posture, same response keys and check names, served by `OCA\Keepiq\Controller\HealthController`. The `observability` block in `src/manifest.json` is removed.

### Requirement: Admin-Only Declarative Metrics Endpoint

**Reason**: Keepiq no longer adopts the OpenRegister AppHost engine (Keepiq ADR-006). Metrics are no longer computed by the AppHost metrics engine.

**Migration**: Replaced by `app-shell` "Admin-Only Metrics Endpoint": same URL, same admin-only posture, same `keepiq_info`, `keepiq_up` and `keepiq_suites_total` series, served by `OCA\Keepiq\Controller\MetricsController`.

### Requirement: Boilerplate Plumbing Served by AppHost Generics

**Reason**: Keepiq must be usable without OpenRegister. The generic dashboard, settings, preferences, store and deep-link plumbing came from OpenRegister and disappeared with it. The register-configuration import it kept alive imported a register that declares no schemas.

**Migration**: Keepiq's own `DashboardController`, `SettingsController`, `AdminSettings` and `SettingsSection` (now `IIconSection`) serve the shell, and a new `PreferencesController` serves preferences (`app-shell` "Keepiq Owns Its Route Table", "Per-User Preferences Endpoint", "Native Admin Section And Version Card"). `RegisterConfigurationLoader`, `keepiq_register.json`, `POST /api/settings/load` and the `/api/store/*` routes are deleted. `InitializeSettings` keeps seeding the domain default-config keys and now writes `config_version` itself.

### Requirement: AppHost Prelude Registers OpenRegister With Public API Only

**Reason**: With no AppHost call there is no reason to put OpenRegister's PSR-4 prefix on the autoloader during `Application::register()`, and doing so would keep a load-order hazard alive for no gain.

**Migration**: `OpenRegisterAutoloader` and its tests are deleted. The one remaining OpenRegister touchpoint, the MCP scannable-services alias, is a string-only registration that loads no OpenRegister class at register time (`mcp-metadata-surface` "Surface Is Exposed Only Through The Scannable-Services Opt-In").

### Requirement: Domain Surfaces Excluded From Adoption

**Reason**: There is no adoption left to exclude domain surfaces from.

**Migration**: Replaced by `app-shell` "Domain Surfaces Unchanged", which keeps the same guarantee (including the dashboard's WASM CSP scenario) for this change.
