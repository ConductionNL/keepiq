# Tasks: Doriath Adopts OpenRegister AppHost

## 0. Baseline + security sign-off

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl` (authenticated) `/apps/doriath/api/health` JSON + `/apps/doriath/api/metrics` Prometheus text; store as fixtures for the parity diff
- [ ] 0.2 **Security sign-off: making health public.** Doriath is a secrets app and the existing auth gate may have been deliberate. Review and sign off that the standard AppHost health response carries no secret material — it contains only `status`/`app`/`version`/`checks` with per-check `ok`/`failed:` infrastructure strings (database, filesystem); no secret values, suite IDs, key material, tokens, user IDs, or counts. Record the sign-off in this change before tasks 1+ proceed
- [ ] 0.3 Confirm no external scraper authenticates to `/api/metrics` as a non-admin user (the posture tightens to admin); add a release note for the auth-posture changes

## 1. Manifest observability block

- [ ] 1.1 Add to `src/manifest.json`:

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

- [ ] 2.1 Wire `AppHost\Bootstrap::register($context, 'doriath')` in `Application::register()`; keep all domain registrations (7 event listeners, `SecretSearchProvider`, `DoriathNotifier`, `JwtAuthMiddleware`)
- [ ] 2.2 Rewrite `appinfo/routes.php` as `Routes::standard($extra)` with the ~70 domain routes (secrets, suites, CA, shares, public link-share + secret-request fill, JWT exchange, dashboard-settings, applications) in `$extra`; verify route names/URLs unchanged, public routes still public, and the SPA catch-all stays last
- [ ] 2.3 Delete `lib/Controller/HealthController.php` and `lib/Controller/MetricsController.php`; routes alias to the AppHost generics
- [ ] 2.4 Delete `lib/Repair/InitializeSettings.php` (repoint info.xml `<repair-steps>` to `GenericInitializeSettings`), `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php`, `lib/Listener/DeepLinkRegistrationListener.php`
- [ ] 2.5 Shrink `SettingsController`/`SettingsService` to subclasses: boilerplate `index`/`create`/`load` + register-config resolution delegate to the AppHost generics; keep the domain admin/user settings split (`ADMIN_CONFIG_KEYS`, user preferences) local
- [ ] 2.6 Leave `DashboardController` (argon2 WASM CSP + `summary()`) and `DashboardService` (domain aggregator + per-user prefs) untouched; subclass `GenericDashboardController` only if it exposes a protected CSP hook
- [ ] 2.7 Sweep references: `EncryptionSuiteService::countActiveSuites()` (delete if the metrics controller was its only caller), unit tests of deleted classes, `@spec` tags, docs

## 3. Parity verification

- [ ] 3.1 Diff new endpoint output vs 0.1 baseline: identical metric names/types/label sets for `doriath_info` and `doriath_suites_total` (plus new implicit `doriath_up`); health `checks` shape identical. Intentional deltas documented: health now public, metrics now admin-only, `doriath_info` gains `nextcloud_version`
- [ ] 3.2 OR AppHost Newman contract collection green against doriath (`/api/health` anonymous 200, `/api/metrics` non-admin 403 + admin Prometheus 0.0.4)
- [ ] 3.3 Doriath's existing Newman collection (`tests/integration/doriath.postman_collection.json`) and Playwright e2e suite green; PHPUnit green

## 4. Docs

- [ ] 4.1 Update doriath observability/monitoring docs: descriptors in manifest.json are the source of truth; document the auth-posture change (public health, admin metrics) and the release note from 0.3

## 5. Quality gates

- [ ] 5.1 `composer check:strict` green; 18 hydra gates green; gate-22 manifest validation green on the new `observability` block; `@spec` tags updated
