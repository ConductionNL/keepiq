---
kind: code
---

# Proposal: Doriath Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Doriath carries its own drifted copies of the fleet observability and plumbing boilerplate, and the drift has produced a real ADR-006 contract violation:

- **Health endpoint requires authentication.** `HealthController::index()` returns 401 unless a user session exists, but ADR-006 mandates a **public** health endpoint so container orchestration and uptime probes can reach it without credentials. The checks themselves (database `SELECT 1`, temp-file write) are the standard fleet pair.
- **Metrics endpoint is under-restricted.** `MetricsController::index()` allows any authenticated user; ADR-006 mandates **admin-only** metrics. It exposes `doriath_info` plus `doriath_suites_total`, which today counts active encryption suites by fetching every row (`EncryptionSuiteMapper::findAllActive()` does `SELECT * WHERE status='active'` and the service `count()`s the array in PHP) — a fetch-all where a `COUNT(*)` belongs.
- ~9 more files are byte-level near-duplicates of the petstore skeleton (Settings index/create/load, register-config resolution, `InitializeSettings` repair step, `AdminSettings`/`SettingsSection`, `DeepLinkRegistrationListener`), so every fleet-wide fix needs a doriath-specific PR.

### Security note: making health public on a secrets app

Doriath is a secrets-management app, so the auth gate on `/api/health` may have been a deliberate hardening choice rather than pure drift. We verified the standard AppHost health response leaks **no secret material**: it carries exactly `status` (`ok|degraded|error`), `app`, `version`, and `checks` (per-check `"ok"` / `"failed: <infrastructure error>"` strings about the database connection and temp filesystem). No secret values, suite identifiers, key material, share tokens, user IDs, or row counts appear in it. The only new disclosure to an anonymous caller is "doriath is installed, at version X, and its DB/filesystem are up" — the same posture every other fleet app already exposes per ADR-006. This change is nonetheless **explicitly flagged for security sign-off** in tasks.md (task 0.2) before implementation proceeds.

## Proposed Change

Adopt the OpenRegister AppHost (chained on `apphost-observability-engine` + `apphost-boilerplate-controllers`): declare observability in `src/manifest.json`, route `/api/health` and `/api/metrics` to the AppHost generic controllers (normalising auth posture to public health / admin metrics), wire the boilerplate plumbing through `AppHost\Bootstrap::register()` + `AppHost\Routes::standard()`, and delete the local copies. Probe/scrape URLs do not change.

### Observability descriptors

| Today | Descriptor | Delta |
|---|---|---|
| `HealthController` database check (`SELECT 1`) | `{"id": "database", "type": "database"}` | parity; endpoint becomes public (ADR-006 fix) |
| `HealthController` filesystem check (temp-file write, degrades not errors) | `{"id": "filesystem", "type": "filesystem", "severity": "degraded"}` | parity |
| `doriath_info{version,php_version}` | implicit `{app}_info` | parity (engine adds `nextcloud_version` label) |
| — | implicit `{app}_up` | new, fleet-standard |
| `doriath_suites_total` (PHP `count()` over `findAllActive()` fetch-all) | `{"name": "suites_total", "type": "gauge", "source": {"kind": "tableCount", "table": "doriath_enc_suites", "filter": {"status": {"eq": "active"}}}}` | same value, now a SQL `COUNT(*)` (efficiency fix); endpoint tightens from any-authenticated-user to admin (ADR-006 fix) |

Note the physical table is **`doriath_enc_suites`** (the mapper's actual table name), not `doriath_encryption_suites`.

### Deletions (boilerplate, replaced by AppHost)

| File | Disposition |
|---|---|
| `lib/Controller/HealthController.php` | **delete** — route aliased to `GenericHealthController` |
| `lib/Controller/MetricsController.php` | **delete** — route aliased to `GenericMetricsController`; `EncryptionSuiteService::countActiveSuites()` loses its only metrics caller (sweep: delete if no other caller remains) |
| `lib/Repair/InitializeSettings.php` | **delete** — `GenericInitializeSettings` reads `doriath_register.json` by appId; info.xml `<repair-steps>` entry repointed |
| `lib/Settings/AdminSettings.php` | **delete** — `GenericAdminSettings` (IDelegatedSettings, #299 pattern) via Bootstrap |
| `lib/Sections/SettingsSection.php` | **delete** — `GenericSettingsSection` via Bootstrap |
| `lib/Listener/DeepLinkRegistrationListener.php` | **delete** — `GenericDeepLinkRegistrationListener` via Bootstrap |
| `lib/Controller/SettingsController.php` | **shrink to subclass** — boilerplate `index`/`create`/`load` delegate to `GenericSettingsController`; the domain admin/user settings split (`getAdminSettings`, `updateAdminSettings`, `getUserSettings`, `updateUserSettings` — password policy, session timeout, CA auto-renew) stays local |
| `lib/Service/SettingsService.php` | **shrink to subclass** — register-config resolution + `getSettings`/`updateSettings`/`loadConfiguration` move to `AppHostSettingsService`; `ADMIN_CONFIG_KEYS` handling and user-preference methods stay local |
| `lib/AppInfo/Application.php` | **shrink** — `AppHost\Bootstrap::register()` takes over alias/admin-settings/deep-link wiring; the 7 domain event listeners, `SecretSearchProvider`, `DoriathNotifier`, and `JwtAuthMiddleware` registrations stay |
| `appinfo/routes.php` | **shrink** — `Routes::standard($extra)` provides dashboard page + catch-all, settings, health, metrics; the ~70 domain routes (secrets, suites, CA, shares, public link-share/secret-request fill, JWT token exchange) move to `$extra` |

### Explicitly retained (domain — out of scope)

Doriath is a secrets app: **anything touching crypto or secrets is domain** and is not migrated.

- `lib/Controller/DashboardController.php` — looks boilerplate but is not: `page()` sets a domain CSP (`allowEvalWasm` for the argon2-browser WASM used by link-share client-side key derivation — crypto path) and provides the `faviconServiceUrl` initial state, and `summary()` is a domain aggregation endpoint. Retained; at most subclass `GenericDashboardController` if it exposes a protected CSP hook, otherwise keep as-is.
- `lib/Service/DashboardService.php` — **domain, not boilerplate**: per-user dashboard preferences plus the secrets/folders/shares/pending-apps summary aggregator. Retained.
- `lib/Controller/DashboardSettingsController.php`, all other controllers/services (Secret*, Share*, Delegation, EncryptionSuite, CACertificate, KeyGenerator, Migration, Application*, LinkShare*), `JwtAuthMiddleware`, all crypto listeners/events, `BootstrapCertificateAuthority` + all `Seed*` repair steps — domain, untouched.

## Impact

- **Deleted**: 6 files (~600 lines); **shrunk**: 4 files; **modified**: `src/manifest.json`, `appinfo/info.xml` (repair-step repoint).
- **Behavioural deltas (intentional, ADR-006)**: health 401→public; metrics any-user→admin-only. Both called out for security review.
- **Verification**: baseline capture before any change, byte-compare metrics names/types/labels after, OR's AppHost Newman contract collection + doriath's existing Newman collection and e2e suite.
- **Risk**: monitoring dashboards or probes authenticating to scrape `/api/metrics` as a non-admin user will break — release note required.

## Dependencies

Chained: `apphost-observability-engine`, `apphost-boilerplate-controllers` (both in openregister). ADR-040 (declarative observability), ADR-006 (endpoint contract), ADR-022 (apps consume OR abstractions).
