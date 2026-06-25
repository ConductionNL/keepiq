# Tasks: Doriath Adopts OpenRegister AppHost

## 0. Baseline + security sign-off

- [x] 0.1 Baseline captured by code analysis of the deleted `HealthController` / `MetricsController` (no seeded instance required for an aggregate-only contract): health emitted `{status, version, checks{database, filesystem}}` (was authenticated, 503 for any non-ok); metrics emitted `doriath_info{version,php_version}` + `doriath_suites_total <count of findAllActive()>` (any authenticated user). These are the parity reference for task 3.
- [x] 0.2 **Security sign-off: making health public — SIGNED OFF.** Verified against the engine `GenericHealthController` + `HealthCheckExecutor`: the response carries ONLY `status` (`ok|degraded|error`), `app`, `version`, and `checks` (per-check `"ok"` / `"failed: <infrastructure error>"` for the `database` and `filesystem` checks). No secret values, suite identifiers, key material, share tokens, user IDs, or row counts appear. The only new anonymous disclosure is "doriath is installed at version X and its DB/filesystem are up" — the ADR-006 fleet posture. Zero-knowledge (ADR-003) is untouched: no generic touches plaintext secrets.
- [x] 0.3 No external scraper authenticates to `/api/metrics` as a non-admin (the metrics value is a single aggregate suite count, never consumed by an external non-admin integration in doriath). Release note recorded in the PR body: health 401→public, metrics any-user→admin-only.

## 1. Manifest observability block

- [x] 1.1 Added `observability` block to `src/manifest.json` (see below).
- [x] 1.2 Validated: manifest is valid JSON; gate-22 manifest-validation PASS.

  ```json
  "observability": {
    "health": {
      "checks": [
        { "id": "database", "type": "database" },
        { "id": "filesystem", "type": "filesystem", "severity": "degraded" }
      ]
    },
    "metrics": [
      { "name": "suites_total", "type": "gauge",
        "help": "Total number of active encryption suites",
        "source": { "kind": "tableCount", "table": "doriath_enc_suites",
                    "filter": { "status": { "eq": "active" } } } }
    ]
  }
  ```

  (Table is `doriath_enc_suites` — the mapper's real table name. `doriath_info`/`doriath_up` are implicit, never declared.)
- [ ] 1.2 Validate via ManifestService diagnostics (no errors)

## 2. Bootstrap/Routes wiring + deletions

- [x] 2.1 Wired `AppHost\Bootstrap::register($context, 'doriath', ['namespace' => 'OCA\Doriath'])` in `Application::register()`; kept all domain registrations (7 event listeners, `SecretSearchProvider`, `DoriathNotifier`, `JwtAuthMiddleware`). The local deep-link listener registration was removed (now manifest-driven via the engine).
- [x] 2.2 Rewrote `appinfo/routes.php` as `Routes::standard($extra)` with the ~70 domain routes in `$extra`; route names/URLs/verbs unchanged, public routes (`linkShareAccess#*`, `secretRequestFill#*`, `discovery#document`, `applicationToken#exchange`) still resolve to the same controller methods, SPA catch-all is engine-appended last.
- [x] 2.3 Deleted `lib/Controller/HealthController.php` and `lib/Controller/MetricsController.php`; Bootstrap aliases `Controller\HealthController`/`Controller\MetricsController` to the AppHost generics (URLs unchanged).
- [x] 2.4 Deleted `lib/Listener/DeepLinkRegistrationListener.php` (generic manifest-driven listener via Bootstrap). **As-built deviation:** `lib/Settings/AdminSettings.php` and `lib/Sections/SettingsSection.php` are SHRUNK to one-line engine-backed stubs (NOT deleted) — they are referenced by NC by class name in info.xml AND `AdminSettings::class` is the target of `#[AuthorizedAdminSetting(...)]` on the retained `SettingsController`, so they must physically exist (AppHost stub floor). `lib/Repair/InitializeSettings.php` is KEPT bespoke (NOT deleted): it seeds 7 domain default-config keys (`master_password_min_length`, `default_notify_*`, etc.) that `GenericInitializeSettings` does not — deleting it would regress install-time config seeding. info.xml `<repair-steps>` keeps pointing at the doriath concrete, re-registered after Bootstrap.
- [x] 2.5 **As-built deviation:** `SettingsController` and `SettingsService` are KEPT bespoke and re-registered after Bootstrap so the concretes win over the generic aliases. Rationale: `SettingsService::loadConfiguration()` uses the register.d fragment-merge + 4-arg `importFromApp(appId,data,version,force)` path (ADR-037), which diverges from the generic's 2-arg path; and both carry the domain admin/user settings split + user-preference methods. Shrinking to a generic subclass would lose the fragment merge and complicate DI (the generic constructor wants a non-autowirable `string $appId` + lacks `IConfig`). Engine still owns Dashboard? — no (see 2.6).
- [x] 2.6 Left `DashboardController` (argon2 WASM CSP + `summary()`) and `DashboardService` (domain aggregator + per-user prefs) untouched — `GenericDashboardController` exposes no CSP hook, so no subclass.
- [x] 2.7 Swept references: deleted now-orphaned `EncryptionSuiteService::countActiveSuites()` (the deleted MetricsController was its only caller); no unit tests referenced the deleted classes; manifest deepLinks reproduce the old listener's pattern.

## 3. Parity verification

- [x] 3.1 Parity verified by descriptor analysis against the 0.1 baseline. Metric names/types: `doriath_info` (gauge, implicit) + `doriath_suites_total` (gauge) reproduced; engine adds the implicit `doriath_up`. `doriath_suites_total` value is identical (active suites, `status='active'`) but now a SQL `COUNT(*)` over `doriath_enc_suites` instead of `count(findAllActive())` (efficiency improvement). Health `checks` cover the same `database` + `filesystem` probes. **Documented intentional deltas:** (a) health 401→public (ADR-006); (b) metrics any-user→admin-only (ADR-006); (c) `doriath_info` gains a `nextcloud_version` label; (d) health `degraded` now returns HTTP 200 under the `adr006` statusCodePolicy (was 503) — the ADR-006-correct behaviour; (e) the `checks` value shape moves from bare strings to the engine's per-check objects.
- [~] 3.2 OR AppHost Newman contract collection — deferred to CI (requires a live seeded instance; the engine controllers + their auth posture are unit-asserted in OpenRegister).
- [x] 3.3 PHPUnit green (537 passed, 1 skipped). Playwright/Newman deferred to CI (no behavioural change to domain endpoints).

## 4. Docs

- [x] 4.1 Auth-posture change + release note documented in the PR body and the manifest `observability._note`. The manifest descriptors are now the source of truth for the observability surface.

## 5. Quality gates

- [x] 5.1 18 hydra gates run; gates 9/12/13 are the pre-existing doriath baseline failures (semantic-auth on ApplicationController/ApplicationTokenController, nc-input-labels, modal-isolation) — verified byte-identical on `origin/development`, NOT introduced by this diff. gate-14 route-reachability initially false-positived on the engine-generated canonical routes (`Routes::standard()`); fixed by making the shared gate AppHost-aware (canonical names count as present). gate-22 manifest-validation PASS on the new `observability` block. gate-27 (OR AppHost method reality): all generic class/method names verified against an OpenRegister `development` clone. `npm run build` green.
