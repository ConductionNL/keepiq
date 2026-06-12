---
status: proposed
---

# Doriath Adoption of OpenRegister AppHost

## Purpose

Doriath's `/api/health` and `/api/metrics` run on the AppHost declarative engine with ADR-006-compliant auth posture (public health, admin metrics), and the petstore-skeleton plumbing (settings boilerplate, repair step, admin-settings section, deep-link listener) is served by the AppHost generics. Domain surfaces — everything touching secrets, suites, shares, CA, and the WASM-CSP dashboard shell — are explicitly out of scope.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## Requirements

### Requirement: Public Declarative Health Endpoint

Doriath SHALL serve `GET /apps/doriath/api/health` through the AppHost `GenericHealthController` from manifest descriptors (`database` critical, `filesystem` degraded), publicly accessible per ADR-006, and the response SHALL contain no secret material.

#### Scenario: Anonymous health check succeeds

- **GIVEN** a healthy instance with doriath enabled
- **WHEN** `GET /apps/doriath/api/health` is called with no authentication
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `checks.database = "ok"`, and `checks.filesystem = "ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Health response carries no secret material

- **GIVEN** an instance containing secrets, encryption suites, shares, and link-share tokens
- **WHEN** `GET /apps/doriath/api/health` is called with no authentication
- **THEN** the response body MUST contain ONLY the keys `status`, `app`, `version`, and `checks`, and the `checks` values MUST be limited to `"ok"` or `"failed: <infrastructure error>"` strings — no secret values, suite identifiers, key material, share tokens, user identifiers, or row counts
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Degraded filesystem does not mask database health

- **GIVEN** an instance where the temp filesystem is not writable but the database is reachable
- **WHEN** `GET /apps/doriath/api/health` is called
- **THEN** the response MUST report `status = "degraded"` with `checks.filesystem` beginning `failed:` and `checks.database = "ok"`, per the `severity: degraded` descriptor
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Admin-Only Declarative Metrics Endpoint

Doriath SHALL serve `GET /apps/doriath/api/metrics` through the AppHost `GenericMetricsController` in Prometheus text exposition format 0.0.4, restricted to admin users per ADR-006, with `doriath_suites_total` computed as a SQL `COUNT(*)` over `doriath_enc_suites` filtered to `status = 'active'`.

#### Scenario: Metrics parity after adoption

- **GIVEN** a seeded instance with N active and M revoked encryption suites
- **WHEN** `GET /apps/doriath/api/metrics` is called by an admin
- **THEN** the output MUST contain `doriath_info{version,php_version,nextcloud_version}`, `doriath_up 1`, and `doriath_suites_total N` (active suites only, matching a direct filtered table count), as `Content-Type: text/plain; version=0.0.4`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin user cannot scrape metrics

- **GIVEN** an authenticated non-admin user
- **WHEN** that user calls `GET /apps/doriath/api/metrics`
- **THEN** the request MUST be rejected (HTTP 403), tightening the pre-adoption any-authenticated-user posture to ADR-006 admin-only
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Plumbing Served by AppHost Generics

Doriath SHALL delete its local `HealthController`, `MetricsController`, `InitializeSettings`, `AdminSettings`, `SettingsSection`, and `DeepLinkRegistrationListener`, wiring their responsibilities through `AppHost\Bootstrap::register()` and `AppHost\Routes::standard()`, with all route names, URLs, and public-route postures unchanged for domain endpoints.

#### Scenario: Domain routes unchanged after Routes::standard adoption

- **GIVEN** the adopted `appinfo/routes.php` built from `Routes::standard($extra)`
- **WHEN** the route table is compared with the pre-adoption baseline
- **THEN** every domain route (secrets, suites, CA, shares, delegations, public link-share access, public secret-request fill, JWT token exchange, applications, dashboard settings) MUST resolve to the same controller method at the same URL and verb, and the SPA catch-all MUST remain the last route
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Register configuration imported by the generic repair step

- **GIVEN** a fresh install with `lib/Repair/InitializeSettings.php` deleted and info.xml pointing at `GenericInitializeSettings`
- **WHEN** `occ app:enable doriath` runs and repair steps execute
- **THEN** the register configuration from `doriath_register.json` MUST be imported and the app's register/schema config keys resolved, identical to the pre-adoption repair step
- @e2e exclude install-time repair step — verified via occ in CI, no UI surface

#### Scenario: Admin settings page still renders through the generic section

- **GIVEN** the local `AdminSettings` and `SettingsSection` are deleted and Bootstrap registers the AppHost generics
- **WHEN** an admin opens the Doriath section in Nextcloud admin settings
- **THEN** the settings form MUST render and the domain admin settings (password policy, session timeout, CA auto-renew) MUST remain readable and writable via the retained `SettingsController` subclass methods

### Requirement: Domain Surfaces Excluded From Adoption

Doriath SHALL retain its `DashboardController` (argon2-WASM CSP and `summary()` aggregation), `DashboardService`, and every controller, service, middleware, listener, and repair step that touches secrets, encryption suites, shares, certificates, or JWT authentication, unchanged by this adoption.

#### Scenario: Dashboard WASM CSP survives adoption

- **GIVEN** the adopted app with AppHost wiring in place
- **WHEN** a user opens the Doriath SPA and accesses a link share whose AES key derives via the argon2-browser WASM module
- **THEN** the page response MUST still carry the `wasm-unsafe-eval` CSP opt-in and the client-side key derivation MUST succeed
