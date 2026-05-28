# Regression Report: doriath manifest-v2 + universal-widgets ([PR #40](https://github.com/ConductionNL/doriath/pull/40))

## Overall: TWO NEW BLOCKING ISSUES + WIDGET-METADATA WARNING NOISE

PR #40 layers the manifest-v2 / universal-widgets shape on top of PR #11 (encryption suites). The frontend pieces of PR #40 work as designed: menu renders, lock screen renders with the documented `type:"custom"` full-page layout, Settings dialog opens, Dashboard custom widgets mount. However, two real defects were uncovered:

1. **B-1 (blocker)** — vault setup fails with **503 "CA is not healthy (status: unknown)"**. Underlying cause is a PostgreSQL type mismatch in the encryption-suites schema (PR #11 territory but exposed end-to-end by PR #40's lock-flow wiring).
2. **B-2 (blocker, ADR-022 compliance)** — `ConfigurationService::importFromApp()` is called with the legacy 2-arg signature; the deployed OR sidecar requires 4 args.

Plus 4 stacked `[CnWidgetGrid] Unknown widgetKey "stats-block"` warnings + 11 `[CnAppRoot]` missing-metadata warnings — same pattern as docudesk PR #242. The 4 stats KPI cards on Dashboard never render because of the unknown widgetKey.

## Scope tested

- All 4 manifest menu entries: Dashboard, Documentation (footer/href external), Lock vault (footer/route), Settings (footer/action user-settings)
- The Lock page's `type:"custom"` full-page layout (the manifest's `_note` calls this out as intentionally bespoke)
- Master-password setup → suite POST flow (the universal-widgets PR's primary user-facing path)
- Two fixed-endpoint health checks: `/api/v1/suites` (GET), `/api/v1/migrations/status` (GET), `/api/settings`
- Console error/warning surface across navigation
- Network 4xx/5xx surface across navigation

Browser: browser-4 (Playwright MCP, headless Chromium 149). Logged in as `admin:admin`. App enabled via `occ app:enable doriath` (clean install — no prior data).

## Apps Tested

| Page | Renders | Console clean | Network clean | Status |
|---|---|---|---|---|
| Dashboard (/) | partial — 2 custom widgets ("Recent activity" + "Quick actions") render; **4 stats-block KPI widgets DO NOT render** | 4 NEW unknown-widgetKey warnings + 10 NEW missing-metadata warnings + 1 deprecated `customComponents` warning, 0 errors | clean | PASS w/ warnings |
| Lock (/lock) | YES — full-page "Set up your master password" form with password / confirm / strength meter / button | 11 widget warnings on this page only, errors only after vault-setup click | suite-POST returns 503 | FAIL (B-1) |
| Documentation (footer) | YES — opens https://www.conduction.nl in new tab (manifest `href`) | clean | n/a | PASS |
| Settings (footer) | YES — opens User-settings dialog with 3 sections (Session, Security, Encryption) including "Change master password" + "No encryption suite" empty state | clean | clean | PASS |
| Lock vault (footer) | YES — same Lock route as auto-redirect | same as Lock | same | PASS (renders); FAIL (action) |

## Fixed-endpoint verification

| Endpoint | Status | Notes |
|---|---|---|
| `GET /apps/doriath/api/settings` | 200 | clean |
| `GET /apps/doriath/api/v1/suites` | 200 | returns empty list — correct shape |
| `GET /apps/doriath/api/v1/migrations/status` | 200 | returns shape |
| `POST /apps/doriath/api/v1/suites` | **503** | see B-1 |

## NEW issues introduced (or surfaced) by PR #40

### B-1 (blocker, real bug) — Postgres SMALLINT vs entity-boolean mismatch breaks CA bootstrap → vault unsetable

Reproduction:
1. Fresh `occ app:enable doriath` against Postgres 16.
2. Browser to `/apps/doriath/` → redirects to `/lock?returnUrl=%2F`.
3. Enter strong master password (≥12 chars, score≥3) → click **Set up vault**.
4. POST `/apps/doriath/api/v1/suites` returns **503** `{"message":"Cannot create EncryptionSuite: CA is not healthy (status: unknown)"}`.

Root cause (verified by reading code + Postgres tables):

- `lib/Migration/Version000002Date20260331000001.php#L101-108` declares `is_active` as `Types::SMALLINT`.
- `lib/Db/CACertificate.php#L149` registers it as type `'boolean'` (and `setIsActive(bool)`).
- `lib/Service/CertificateAuthorityService.php::bootstrap()`:
  - Inserts root with `setIsActive(false)` (line 139) → succeeds (PostgreSQL accepts `false`-as-`0` on smallint via Doctrine here).
  - Calls `generateIntermediate()` (line 143) which `setIsActive(true)` (line 464) → **fails** with `SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input syntax for type smallint: "t"`.
- The intermediate cert is never inserted, so the `getActiveIntermediate()` check at `EncryptionSuiteService::createSuite` runtime sees CA status `unknown` and throws `RuntimeException`.

Evidence in `data/nextcloud.log`:
```
{"reqId":"maQ2UUh7PuMOJmNaQO8W","level":3,"app":"doriath","message":"Doriath CA bootstrap failed","data":{"exception":"...SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type smallint: \"t\""}}
```

And in DB:
```
oc_doriath_ca_certs:
 id                                  | type | is_active | cert_len | created_at
 3cc4aab9-a3e1-4bea-b37a-c5f9b9c9dd7e | root | 0         | 2065     | 2026-05-23 09:08:54
```
(only root, no intermediate — confirms partial bootstrap)

`oc_appconfig` for doriath shows no `ca_status` key at all (the `setValueString('ca_status', 'healthy')` at the end of `bootstrap()` never executes because the intermediate insert throws first).

**Why this matters now (and not before PR #40 / PR #11):** PR #11 added the encryption-suites tables but no end-to-end UI exercised them. PR #40 wires the lock-screen / master-password setup as the front door of the app, so a fresh install on Postgres is now unusable until the type mismatch is fixed.

**Fix options:**
- (preferred) change migration to `Types::BOOLEAN` to match the entity (`Doctrine\DBAL\Types\Types::BOOLEAN`).
- (alternative) change entity to integer and stop using `setIsActive(bool)`.
- (workaround for ops) the `BootstrapCertificateAuthority` repair step + `setDegraded()` exception handling would benefit from clearing partial-state (delete the lonely root row) so a retry produces a clean intermediate — currently `findRoot()` returns the root from a failed bootstrap, the early `return` on line 85 fires, and nothing ever re-tries the intermediate generation. (This is also why `occ maintenance:repair` "succeeded" on the second run yet `ca_status` stayed `unknown` — the repair shortcut said "already bootstrapped" but the missing intermediate was never noticed.)

### B-2 (blocker, ADR-022 compliance) — `ConfigurationService::importFromApp` called with 2 args, signature requires 4

`lib/Service/SettingsService.php#L146`:
```php
$result = $configurationService->importFromApp(appId: Application::APP_ID, force: $force);
```

The deployed OR sidecar signature now requires `$data` (and likely 2 more) per ADR-022. Log shows:
```
"message":"Doriath: configuration import failed",
"exception":"OCA\\OpenRegister\\Service\\ConfigurationService::importFromApp(): Argument #2 ($data) not passed"
```

Fired on `occ app:enable doriath` AND on `occ maintenance:repair`. Same fleet pattern that nextcloud-app-template, decidesk, shillinq, procest, pipelinq, scholiq have already been migrated for (see memory: "Import OR register via Repair step" + "4-arg `importFromApp`"). Doriath was greenfielded against the old signature and hasn't been updated.

**Fix:** update `SettingsService::importConfiguration()` to load the JSON from `appinfo/configuration.json` (or wherever doriath ships it) and pass it as `$data`. Reference docudesk + procest repair-step implementations.

### W-1 (warning, non-blocking — same as docudesk #242 W-1) — custom widgets missing v2 metadata

10 stacked warnings on Dashboard, 2 widgets × 5 fields each:

```
[CnAppRoot] Registry entry "doriath-recent-activity" (kind: "widget") is missing required metadata field "defaultSize" / "minSize" / "maxSize" / "allowedSlots" / "propsSchema".
[CnAppRoot] Registry entry "doriath-quick-actions" (kind: "widget") is missing required metadata field "defaultSize" / "minSize" / "maxSize" / "allowedSlots" / "propsSchema".
```

Widgets still render. Per ADR-036 universal-widget contract these fields should be present. Recommend adding defaults that mirror the manifest grid (`{ w: 6, h: 4 }` for both).

### W-2 (warning, expected per ADR-036 transition — same as docudesk #242 W-2) — `customComponents` deprecation

```
CnAppRoot: `customComponents` prop is deprecated when using v2 manifests. Use the `registry` prop instead (see ADR-036).
```

PR #40 keeps `customComponents` wired alongside the new `registry` prop for transition compatibility. Same as docudesk.

### W-3 (warning, real bug — manifest-shipped widget never registered) — `stats-block` widgetKey unknown

4 stacked warnings on Dashboard:
```
[CnWidgetGrid] Unknown widgetKey "stats-block" in slot "body". Register it in the built-in registry or pass it via the CnAppRoot registry prop.
```

The Dashboard manifest declares 4 KPI cards using `widgetKey: "stats-block"` (with `title`, `iconClass`, count props), but `stats-block` isn't in `src/registry.js` or in the nc-vue built-in widget registry shipped with the current beta. The 4 KPI cards never render — only the 2 doriath-namespaced widgets ("Recent activity", "Quick actions") show.

This is a real regression from PR #40 — the manifest references a widget shape that the lib doesn't ship yet. Either:
- (preferred) wait for nc-vue to ship a built-in `stats-block` / `kpi-card` widget and re-test, OR
- register a local `stats-block` component in `src/registry.js` until the lib catches up, OR
- swap the manifest to use whatever the built-in KPI widget is actually called in the current `@conduction/nextcloud-vue` beta.

## Pre-existing issues (NOT caused by PR #40 — flagged for context)

### P-1 — stale autoloader path noise (fleet-wide)

Every NC request logs:
```
include(): Failed opening '/tmp/worktrees/openconnector-test-harness/lib/Service/Integration/SynchronizationContractProvider.php'
```

Same `/tmp/worktrees/...` path that docudesk PR #242 report flagged as P-4. Unrelated to doriath.

### P-2 — `findAllSerialized()` cascade noise

Every docudesk page load logs `OpenRegister findAllSerialized() unavailable`. Fleet-wide OR-sidecar-lag (memory: openregister#1428). Unrelated to doriath but visible in the log.

## Cross-app integration

Not exercised — doriath has no cross-app surface yet beyond its CA + suites being available for other apps to consume.

## Recommendation

**DO NOT MERGE PR #40 as-is.**

Pre-merge blockers (both must be fixed):

1. **B-1** — change `is_active` column from `Types::SMALLINT` to `Types::BOOLEAN` in `Migration/Version000002Date20260331000001.php` (and add a follow-up migration to convert existing column for any deployed-but-broken install). Without this, the universal-widgets lock-flow that PR #40 introduces is dead on every Postgres install.
2. **B-2** — fix the `importFromApp` 4-arg call in `SettingsService.php` so the configuration import doesn't error during app enable + repair.

Pre-merge polish (recommended):

3. **W-3** — register `stats-block` widgetKey in `src/registry.js` or migrate the manifest to a widget key that actually exists in the current nc-vue beta. Right now 4 of the 6 dashboard widgets silently don't render.
4. **W-1** — add the 5 metadata fields to the 2 doriath custom widgets in `src/registry.js` to silence widget-metadata warnings.

Manifest-v2 mechanics themselves work: routing renders, the bespoke `type:"custom"` Lock page renders with the documented full-page layout, the Settings user-settings action opens the right dialog, the Documentation external link works. The PR's shape is sound; the underlying CA-bootstrap defect and the missing widget registration are what block ship.

REGRESSION_TEST_RESULT: FAIL  CRITICAL_COUNT: 2  SUMMARY: Vault setup blocked by SMALLINT-vs-boolean Postgres type mismatch in CA migration (B-1); ConfigurationService 4-arg ADR-022 signature mismatch breaks config import on app enable (B-2); 4 stats-block KPI widgets never render because widgetKey isn't registered (W-3).
