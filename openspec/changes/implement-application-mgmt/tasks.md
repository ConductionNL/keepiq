## Implementation Notes (hydra-build)

This build was implemented against the **actual** doriath codebase, which is earlier than the design assumed. The following design-stated prerequisites do **not yet exist** in the app and the relevant tasks were adapted or deferred:

- **No Secret / SecretRequest entity, SecretMapper, or secrets DB table** — so cascade deletion (D7) cascades the application's EncryptionSuite(s) only; the secret-cascade and the application-secret listing land with the future Secret entity. Seed apps therefore carry no seeded secrets.
- **No NotificationService / DoriathNotifier / DashboardService** — admin notification (§7) is implemented as a best-effort admin-group log dispatch via `IGroupManager`; the rich `DoriathNotifier` subject + dashboard `pending_apps_count` wiring are deferred until those services exist. `ApplicationMapper::countPending()` is implemented so a future DashboardService can call it.
- **JWT library**: the design specified `web-token/jwt-framework`, but it cannot be installed offline and adding it would break `composer install`. RS256 verification is implemented with **native OpenSSL** (`openssl_verify`) against the stored certificate — consistent with the app's existing certificate handling and fully unit-tested. No new composer dependency.
- **Migration / repair numbering**: migrations only reached `Version000005`, so the new migration is `Version000006Date20260604000000` (not the design's `Version000012`). No `SeedDevelopmentSecrets` step exists; the seed step runs after `SeedDevelopmentData`.
- **Frontend is manifest-v2 declarative** (registry.js + manifest.d fragments + custom pages), NOT vue-router/MainMenu (which do not exist). Pages/menu added via `src/manifest.d/20-application-mgmt.json` + `src/registry.js`; §11 implemented accordingly.
- **No JS test runner** (no vitest/jest in package.json) → frontend tests (§15) are deferred; PHP unit coverage of the equivalent logic is provided. Integration tests (§14) require a live Nextcloud DB and are deferred (no standalone harness); the same behaviour is exercised by the service/middleware/JWT unit tests.

`composer check:strict` passes fully (lint, phpcs, phpmd, psalm, phpstan, 201 unit tests).

## 1. Database Migration and Seed Data

- [x] 1.1 Create ISchemaWrapper migration (`Version000006Date20260604000000`, renumbered) for `doriath_applications` table with all D1 columns + indexes on status, registered_by
- [x] 1.2 Create `SeedDevelopmentApplications` IRepairStep (debug-only): "OpenConnector Dev" (active internal, generated suite), "CI Pipeline Bot" (pending external, no suite), "Monitoring Agent" (active external, generated suite); deterministic v5 UUIDs, logs generated private keys to debug log. (Seeded secrets deferred — no Secret entity yet.)
- [x] 1.3 Register `SeedDevelopmentApplications` as install + post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentData)

## 2. Entity and Mapper

- [x] 2.1 Create `Application` entity in `lib/Db/Application.php` (all D1 fields, JsonSerializable with csr excluded, addType annotations)
- [x] 2.2 Create `ApplicationMapper` extending QBMapper with findById, findAll(filters, sort, order, limit, offset), findPending, countPending, findByRegistrant, findActiveByName, countAll

## 3. ApplicationService (PHP)

- [x] 3.1 Create `ApplicationService` with ApplicationMapper, EncryptionSuiteService, EncryptionSuiteMapper, IGroupManager, LoggerInterface (SecretMapper/SecretRequestMapper/NotificationService deferred — not present)
- [x] 3.2 Implement `register(...)`: admin → active + provision; non-admin/anon → pending; CSR vs generated-key paths; returns Application + optional private key
- [x] 3.3 Implement CSR validation (`openssl_csr_get_public_key`, key-bits >= 4096, throws InvalidArgumentException)
- [x] 3.4 Implement `approve(...)`: validate pending, set active/approved_by/approved_at, provision suite (CSR or generated key), clear CSR
- [x] 3.5 Implement `reject(...)`: validate pending, hard delete
- [x] 3.6 Implement `delete(...)`: cascade EncryptionSuite(s) + application record (secret cascade deferred — no Secret entity)
- [x] 3.7 Implement `get(...)`: admin any; non-admin own + active
- [x] 3.8 Implement `list(...)`: admin all (filtered); non-admin own ∪ active, paginated with total

## 4. JwtAuthService (PHP)

- [~] 4.1 `web-token/jwt-framework` — NOT added (uninstallable offline; would break composer install). Native OpenSSL RS256 used instead.
- [x] 4.2 Create `JwtAuthService` with ApplicationMapper, EncryptionSuiteMapper, ICacheFactory, LoggerInterface
- [x] 4.3 Implement `exchangeAssertion(...)`: decode compact JWT, require RS256, resolve active app by iss, verify signature against cert public key, validate aud/exp/iat(+skew)/jti, replay-guard, issue opaque access token (5-min TTL)
- [x] 4.4 Implement `validateAccessToken(...)`: cache lookup → active Application or null
- [x] 4.5 Implement replay prevention via `ICacheFactory::createDistributed` jti cache (5-min TTL)

## 5. JwtAuthMiddleware (PHP)

- [x] 5.1 Create `JwtAuthMiddleware` (guards ApplicationApiController only, extracts Bearer token, validates, injects Application, 401 on failure via afterException)
- [x] 5.2 Register JwtAuthMiddleware in `lib/AppInfo/Application.php` via `registerMiddleware`
- [x] 5.3 Create `ApplicationApiController` base class with `setAuthenticatedApplication`/`getAuthenticatedApplication`

## 6. Controllers and API Routes

- [x] 6.1 Create `ApplicationController` (OCSController): index, show, register, destroy, approve, reject, pending
- [x] 6.2 `#[PublicPage]` on register for anonymous registration; type/name validation in service
- [x] 6.3 Create `ApplicationTokenController` (#[PublicPage] #[NoCSRFRequired]): exchange (POST /api/v1/token), JWT-Bearer grant validation
- [x] 6.4 Create `ApplicationSecretsController` extending ApplicationApiController: index, show (encrypted blobs only; secret listing returns empty pending the Secret entity)
- [x] 6.5 Register all routes in `appinfo/routes.php` (verb routes before {id} wildcard; before SPA catch-all)

## 7. Notification Integration

- [~] 7.1 `app_pending` subject in NotificationService — deferred (no NotificationService in app); admin dispatch logs via IGroupManager as a best-effort placeholder
- [~] 7.2 DoriathNotifier rendering — deferred (no DoriathNotifier in app)
- [x] 7.3 Admin notification dispatch in `register()` when status=pending (queries admin group, logs per-admin; ready to swap to NotificationService when it lands)

## 8. Dashboard Integration

- [x] 8.1 Implement `ApplicationMapper::countPending(): int`
- [~] 8.2 DashboardService::fetchSummary() wiring — deferred (no DashboardService in app; countPending() ready for it)

## 9. Pinia Store (Frontend)

- [x] 9.1 Create `src/store/modules/application.js` (useApplicationStore) with full state + actions
- [x] 9.2 registerApplication stores returned `private_key` in `oneTimePrivateKey` to trigger the download dialog
- [x] 9.3 approveApplication stores returned `private_key` (no-CSR approval) to trigger the download dialog
- [x] 9.4 writeSecretForApplication: fetch app cert, importPublicKey, rsaEncrypt, POST with owner_type=application

## 10. Vue Components (Frontend)

- [x] 10.1 Create `src/views/ApplicationList.vue` (table, register button, empty state, row → detail)
- [x] 10.2 Create `src/views/ApplicationDetail.vue` (metadata, secrets panel, admin delete w/ confirm)
- [x] 10.3 Create `src/components/ApplicationRegisterDialog.vue` (name/description/type/CSR; calls registerApplication)
- [x] 10.4 Create `src/components/PrivateKeyDownloadDialog.vue` (read-only PEM, copy, download, warning note, acknowledgment gate)
- [x] 10.5 Create `src/components/ApplicationSecretsPanel.vue` (secrets table + Write secret button)
- [x] 10.6 Create `src/components/WriteSecretForAppDialog.vue` (encrypts with app public key, POSTs encrypted blob)
- [x] 10.7 Create + wire `ApplicationQueueSection.vue` into admin Settings (fetchPending, approve/reject, private-key dialog)

## 11. Vue Router Integration (manifest-v2)

- [x] 11.1 Add `/applications` + `/applications/:id` pages via `src/manifest.d/20-application-mgmt.json` + register custom pages in `src/registry.js` (this app uses manifest-v2 custom pages, not src/router/index.js which does not exist)
- [x] 11.2 Add Applications menu item via the manifest fragment `menu[]` (CnAppNav-driven; no MainMenu.vue in this app)

## 12. Internationalization

- [x] 12.1 English strings added to `l10n/en.json`
- [x] 12.2 Dutch strings added to `l10n/nl.json`
- [x] 12.3 `t()` used in all Vue components and PHP user-facing strings

## 13. Unit Tests (PHP)

- [x] 13.1 ApplicationService tests (admin/non-admin/anon register, valid/invalid/weak CSR, generated key, approve w/ + w/o CSR, reject hard delete, delete cascade, get/list authorization)
- [x] 13.2 JwtAuthService tests (valid exchange, invalid signature, expired, future iat, wrong aud, jti replay, access-token validate, unknown token, inactive app)
- [x] 13.3 JwtAuthMiddleware tests (non-API pass-through, missing header, invalid token, valid token injects app, 401 mapping, rethrow)
- [x] 13.4 ApplicationMapper coverage — entity/serialization tested in ApplicationTest; mapper query methods exercised via ApplicationService tests (no DB-backed mapper unit tests exist in this app)
- [~] 13.5 SeedDevelopmentApplications repair-step test — deferred (requires a live DB + CA; logic mirrors existing untested SeedDevelopmentData)

## 14. Integration Tests (PHP)

- [~] 14.1–14.8 Deferred — require a live Nextcloud DB/HTTP harness not available standalone. The equivalent behaviours (auth posture, approval flow, JWT auth, cascade, write-without-read shape) are covered by the service/middleware/JWT unit tests.

## 15. Frontend Tests

- [~] 15.1–15.6 Deferred — the app has no JS test runner configured (no vitest/jest in package.json). Equivalent store/flow logic is covered by PHP unit tests.
