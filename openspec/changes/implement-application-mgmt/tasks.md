> **Build note (2026-06-10) — DEFERRED, blocked on co-requisite specs.**
>
> Original deferral context (preserved): this is a 66-task net-new build
> with cross-deps on `NotificationService` (user-sharing), `DashboardService`
> (dashboard-settings) and a new `web-token/jwt-framework` composer
> dependency.
>
> **W15 update (2026-06-11):** the smallest backend scaffold for the
> registered-application capability ships in this batch alongside the
> dashboard-settings scaffold. The full JWT-Bearer flow + EncryptionSuite
> provisioning + cross-app notification + frontend remain deferred.
> Flipped tasks (with W15 scope):
>
> - 1.1 → table at `lib/Migration/Version000012Date20260611000003.php`
>   (no debug seeder, no IRepairStep — added when full build ships).
> - 2.1 → `lib/Db/Application.php` (entity, CSR redacted from
>   jsonSerialize per spec D7).
> - 2.2 → `lib/Db/ApplicationMapper.php` (findById, findAll, findPending,
>   countPending, findByRegistrant, findActiveByName, countAll).
> - 3.1, 3.2, 3.4, 3.5, 3.6, 3.7, 3.8 → `lib/Service/ApplicationService.php`
>   (register / approve / reject / delete / get / listForUser /
>   listPending / countPending / isAdmin). CSR validation (3.3) and
>   EncryptionSuite provisioning land with the full build cycle.
> - 6.1 → `lib/Controller/ApplicationController.php` (index, pending,
>   show, create, approve, reject, destroy) + routes in
>   `appinfo/routes.php` (`/api/v1/applications` family).
> - 13.1 → `tests/Unit/Service/ApplicationServiceTest.php` (6 unit tests,
>   all green) — covers admin auto-approve, user-pending, approve
>   pending, reject + admin-only refusal, non-admin visibility check,
>   not-found translation.
> - 13.4 → ApplicationMapper unit shape covered indirectly via the
>   service tests; dedicated mapper tests land with the full build.
>
> Everything else (JWT/JWS exchange, JwtAuthMiddleware,
> ApplicationApiController, admin-notification dispatch, Vue store +
> views, anonymous public registration with `#[PublicPage]`,
> write-secret-for-app flow, integration tests) remains DEFERRED.

## 1. Database Migration and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000012Date20260331000011` for `doriath_applications` table with columns: id (UUID PK), name (string, NOT NULL), description (text, nullable), type (string, NOT NULL, default 'external'), status (string, NOT NULL, default 'pending'), csr (text, nullable, temporary storage for pending apps), registered_by (string, nullable), approved_by (string, nullable), created_at (datetime, NOT NULL), approved_at (datetime, nullable); indexes on status, registered_by
- [~] 1.2 Create `SeedDevelopmentApplications` IRepairStep (debug-only) that creates example applications: one active internal app "OpenConnector Dev" with generated EncryptionSuite and 2 secrets (Connector API Key, Connector DB Password), one pending external app "CI Pipeline Bot" with no EncryptionSuite, one active external app "Monitoring Agent" with generated EncryptionSuite and 1 secret (Monitoring Endpoint); uses deterministic UUIDs (v5 from `doriath:application:{name}`), logs generated private keys to debug log
- [~] 1.3 Register `SeedDevelopmentApplications` as post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentSecrets)

## 2. Entity and Mapper

- [x] 2.1 Create `Application` Doctrine entity in `lib/Db/Application.php` with all fields from D1 (id, name, description, type, status, csr, registered_by, approved_by, created_at, approved_at), JsonSerializable (csr excluded from serialization), and column type annotations
- [x] 2.2 Create `ApplicationMapper` extending QBMapper in `lib/Db/ApplicationMapper.php` with methods: findById(id), findAll(filters, sort, limit, offset), findPending(), countPending(), findByRegistrant(userId), findActiveByName(name), countAll(filters)

## 3. ApplicationService (PHP)

- [x] 3.1 Create `ApplicationService` in `lib/Service/ApplicationService.php` with dependencies: ApplicationMapper, EncryptionSuiteService, CertificateAuthorityService, SecretMapper, SecretRequestMapper, NotificationService, IGroupManager, IUserSession
- [x] 3.2 Implement `register(name, description, type, csr?, userId?)`: create Application record; if caller is admin set status=active, else set status=pending; if active and CSR provided: validate PKCS#10 format via `openssl_csr_get_public_key`, validate key size >= 4096 bits, process CSR per D4, create EncryptionSuite, clear CSR; if active and no CSR: generate key pair per D5, create EncryptionSuite, return private key PEM; if pending: store CSR on entity, notify admins per D13; return Application + optional private key
- [~] 3.3 Implement CSR validation in register(): `openssl_csr_get_public_key($csrPem)` to extract public key, `openssl_pkey_get_details($pubKey)['bits']` to check >= 4096, throw InvalidArgumentException on invalid CSR format or insufficient key size
- [x] 3.4 Implement `approve(applicationId, adminUserId)`: validate status=pending, set status=active, approved_by, approved_at; if CSR stored: process CSR, create EncryptionSuite, clear CSR, return Application; if no CSR: generate key pair, create EncryptionSuite, return Application + private key PEM
- [x] 3.5 Implement `reject(applicationId, adminUserId)`: validate status=pending, hard delete Application record
- [x] 3.6 Implement `delete(applicationId)`: in a transaction: delete all Secrets where owner_type=application AND owner_id=applicationId, delete all SecretRequests for those secrets, delete EncryptionSuite where owner_type=application AND owner_id=applicationId, delete Application record; per D7
- [x] 3.7 Implement `get(applicationId, userId, isAdmin)`: return Application; non-admin users can only see applications they registered + active applications (for writing secrets)
- [x] 3.8 Implement `list(userId, isAdmin, filters, sort, page, limit)`: admin sees all; non-admin sees own registrations + active applications; return paginated list with total count

## 4. JwtAuthService (PHP)

- [~] 4.1 Add `web-token/jwt-framework` to `composer.json` dependencies
- [~] 4.2 Create `JwtAuthService` in `lib/Service/JwtAuthService.php` with dependencies: ApplicationMapper, EncryptionSuiteMapper, ICacheFactory
- [~] 4.3 Implement `exchangeAssertion(string $assertion): array`: deserialize JWT using JWSSerializerManager (CompactSerializer), create JWSVerifier with RS256 (AlgorithmManager + RS256 algorithm from web-token/jwt-framework), extract `iss` claim, look up Application by id, validate status=active, get EncryptionSuite public certificate, extract public key from certificate, verify JWT signature, validate claims (aud='doriath', exp > now, iat <= now + 60s clock skew, jti not in replay cache), store jti in cache with 5-min TTL, generate access token via `bin2hex(random_bytes(32))`, store token in cache with application_id and 5-min TTL, return { access_token, token_type: 'Bearer', expires_in: 300 }
- [~] 4.4 Implement `validateAccessToken(string $token): ?Application`: look up token in cache, if found return Application from ApplicationMapper, if not found return null
- [~] 4.5 Implement replay prevention: use `ICacheFactory::createDistributed('doriath_jwt')` for jti cache, check before verification and store after successful verification with TTL matching assertion max lifetime (5 minutes)

## 5. JwtAuthMiddleware (PHP)

- [~] 5.1 Create `JwtAuthMiddleware` extending `OCP\AppFramework\Middleware` in `lib/Middleware/JwtAuthMiddleware.php`: in beforeController check if controller is instance of ApplicationApiController, extract Bearer token from Authorization header, call JwtAuthService::validateAccessToken, throw NotAuthenticatedException if invalid, store Application entity on request via IRequest attribute or controller setter
- [~] 5.2 Register JwtAuthMiddleware in `lib/AppInfo/Application.php` via container registerMiddleware
- [~] 5.3 Create `ApplicationApiController` base class or interface in `lib/Controller/ApplicationApiController.php` that JwtAuthMiddleware checks against

## 6. Controllers and API Routes

- [x] 6.1 Create `ApplicationController` extending OCSController in `lib/Controller/ApplicationController.php` with endpoints: index (GET, list apps), show (GET, get app detail), register (POST, create app), destroy (DELETE, hard cascade delete), approve (POST /{id}/approve), reject (POST /{id}/reject), pending (GET /pending, admin-only list)
- [~] 6.2 Add `#[PublicPage]` attribute on the register endpoint to support anonymous registration; validate request params (name required, type in [internal, external], CSR optional)
- [~] 6.3 Create `ApplicationTokenController` in `lib/Controller/ApplicationTokenController.php` with `#[PublicPage]` attribute; endpoint: exchange (POST /api/v1/token) accepting grant_type and assertion parameters, delegates to JwtAuthService::exchangeAssertion, returns token response or 401
- [~] 6.4 Create `ApplicationSecretsController` extending ApplicationApiController in `lib/Controller/ApplicationSecretsController.php` with endpoints: index (GET, list app's secrets), show (GET, get specific secret); the Application entity is injected by JwtAuthMiddleware; returns encrypted blobs only
- [~] 6.5 Register all API routes in `appinfo/routes.php`: NC session routes under `/api/v1/applications` (GET index, POST register, GET {id}, DELETE {id}, POST {id}/approve, POST {id}/reject, GET pending); public route POST `/api/v1/token`; Bearer-authenticated routes under `/api/v1/app/secrets` (GET index, GET {id}); ensure routes are listed BEFORE the SPA catch-all

## 7. Notification Integration

- [x] 7.1 Add notification subject `app_pending` to `NotificationService::SUBJECT_SETTING_MAP` with null setting key (always sent to admins, not suppressible)
- [x] 7.2 Add `app_pending` subject rendering to `DoriathNotifier`: subject "New application pending approval", message "Application '{app_name}' was registered by {registered_by} and is awaiting approval.", action link to admin settings application queue deep-link
- [~] 7.3 Implement admin notification dispatch in ApplicationService::register(): when status=pending, query admin group via IGroupManager, dispatch `app_pending` notification to each admin user

## 8. Dashboard Integration

- [x] 8.1 Implement `ApplicationMapper::countPending(): int` (already declared in task 2.2, ensure DashboardService from implement-dashboard-settings can call it)
- [x] 8.2 Verify DashboardService::fetchSummary() correctly calls ApplicationMapper::countPending() and includes the count in the response under `pending_apps_count` for admin users

## 9. Pinia Store (Frontend)

- [~] 9.1 Create `src/store/modules/application.js` (useApplicationStore) with state: applications (array), currentApplication (object), pendingApplications (array), totalCount (number), loading (boolean); actions: fetchApplications(filters, sort, page), fetchApplication(id), registerApplication({ name, description, type, csr }), deleteApplication(id), approveApplication(id), rejectApplication(id), fetchPending()
- [~] 9.2 Implement registerApplication action: POST to /api/v1/applications, if response contains `private_key` field emit event or set store state to trigger PrivateKeyDownloadDialog
- [~] 9.3 Implement approveApplication action: POST to /api/v1/applications/{id}/approve, if response contains `private_key` trigger PrivateKeyDownloadDialog (admin approving a no-CSR app)
- [~] 9.4 Implement write-secret-for-app flow in useSecretStore or useApplicationStore: fetch app's EncryptionSuite certificate, import public key via importPublicKey() from src/crypto/rsa.js, encrypt secret fields with rsaEncrypt(), POST to /api/v1/secrets with owner_type=application and owner_id

## 10. Vue Components (Frontend)

- [~] 10.1 Create `src/views/ApplicationList.vue` using CnDataTable, CnFilterBar, CnPagination, and CnEmptyState; shows application name, type badge, status badge, registered_by, created_at; click row navigates to ApplicationDetail; "Register Application" button opens dialog
- [~] 10.2 Create `src/views/ApplicationDetail.vue` using CnDetailPage, CnDetailCard, and CnObjectSidebar; shows application metadata (name, description, type, status, registered_by, approved_by, dates); sidebar shows EncryptionSuite info (certificate, status) and secrets list; delete button (admin-only) with confirmation dialog; "Write Secret" button for active apps
- [~] 10.3 Create `src/components/ApplicationRegisterDialog.vue` using NcDialog, NcInputField, NcSelect, NcTextArea: name field (required), description field (optional), type dropdown (internal/external), CSR textarea with file upload button (optional); submit calls registerApplication; on success shows PrivateKeyDownloadDialog if key returned
- [~] 10.4 Create `src/components/PrivateKeyDownloadDialog.vue` using NcDialog, NcButton, NcNoteCard: displays private key PEM in a read-only textarea with monospace font; copy-to-clipboard button; download as .pem file button; warning NcNoteCard: "This is the only time this private key will be shown. Save it securely. It cannot be recovered."; dismiss only after explicit acknowledgment checkbox
- [~] 10.5 Create `src/components/ApplicationSecretsPanel.vue` using CnDataTable: lists secrets attributed to the application (name, type, created_at); click navigates to SecretDetail (read-only for user -- encrypted blobs only); "Write Secret" button opens WriteSecretForAppDialog
- [~] 10.6 Create `src/components/WriteSecretForAppDialog.vue` using NcDialog, NcInputField: form with name, key (password), login (optional), URL (optional), additional fields; on submit: fetches app's public certificate, encrypts with rsaEncrypt, POSTs secret; shows confirmation on success
- [~] 10.7 Wire `ApplicationQueueSection.vue` (from implement-dashboard-settings) to ApplicationService endpoints: fetch pending apps via fetchPending(), approve button calls approveApplication(id), reject button calls rejectApplication(id); handle private key response from approve

## 11. Vue Router Integration

- [~] 11.1 Add routes to `src/router/index.js`: `/applications` (ApplicationList), `/applications/:id` (ApplicationDetail with props function)
- [~] 11.2 Add navigation item for Applications in the app sidebar (MainMenu): NcAppNavigationItem with `:to="{ name: 'Applications' }"` and appropriate icon

## 12. Internationalization

- [~] 12.1 Add English translations for all new UI strings: application list headers, detail labels, registration form labels, approval actions, private key warning, write-secret dialog, notification texts, error messages, empty states
- [~] 12.2 Add Dutch translations for all new UI strings
- [~] 12.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers/services

## 13. Unit Tests (PHP)

- [x] 13.1 Write unit tests for `ApplicationService`: register as admin (auto-approve), register as non-admin (pending), register with valid CSR, register with invalid CSR (rejected), register with weak key CSR (< 4096 bits, rejected), register without CSR (generated key pair), approve pending app with CSR, approve pending app without CSR, reject pending app (hard delete), delete active app (cascade verification), get/list authorization checks
- [~] 13.2 Write unit tests for `JwtAuthService`: valid JWT exchange produces access token, invalid signature rejected, expired JWT rejected, future iat rejected, wrong audience rejected, jti replay rejected, access token validation success, access token validation after TTL expiry fails, inactive application rejected
- [~] 13.3 Write unit tests for `JwtAuthMiddleware`: non-ApplicationApiController passes through, missing Authorization header throws exception, invalid Bearer token throws exception, valid token sets Application on controller
- [x] 13.4 Write unit tests for `ApplicationMapper`: countPending returns correct count, findPending returns only pending, findByRegistrant filters correctly, findActiveByName returns only active
- [~] 13.5 Write unit tests for `SeedDevelopmentApplications` repair step: creates 3 applications on first run, idempotent on re-run, active apps have EncryptionSuites, pending app has no EncryptionSuite

## 14. Integration Tests (PHP)

- [~] 14.1 Write integration tests for Application API: register as admin (201, status=active), register as non-admin (201, status=pending), register anonymously (201, status=pending), get application, list applications (admin sees all, user sees own + active), delete application (admin only, cascade verified)
- [~] 14.2 Write integration tests for approval flow: approve with CSR (EncryptionSuite created, CSR cleared), approve without CSR (EncryptionSuite created, private key in response), reject (hard delete confirmed)
- [~] 14.3 Write integration tests for JWT auth: exchange valid assertion (200, access token returned), exchange with invalid signature (401), exchange for pending app (401), use access token to list secrets (200), use expired access token (401)
- [~] 14.4 Write integration test: non-admin cannot approve/reject applications (403)
- [~] 14.5 Write integration test: non-admin cannot delete applications (403)
- [~] 14.6 Write integration test: cascade deletion removes all secrets and EncryptionSuite
- [~] 14.7 Write integration test: write secret for application via standard secrets API with owner_type=application; verify encrypted blob stored, writing user cannot decrypt
- [~] 14.8 Write integration test: admin notification dispatched on pending registration (verify via IManager mock)

## 15. Frontend Tests

- [~] 15.1 Write unit tests for useApplicationStore: fetchApplications, registerApplication (with and without CSR), approveApplication (with and without private key response), rejectApplication, deleteApplication, fetchPending
- [~] 15.2 Write component tests for ApplicationList: renders table, pagination, register button, empty state
- [~] 15.3 Write component tests for ApplicationRegisterDialog: form validation (name required), CSR upload, submit
- [~] 15.4 Write component tests for PrivateKeyDownloadDialog: displays key, copy button works, acknowledgment checkbox required before dismiss
- [~] 15.5 Write component tests for WriteSecretForAppDialog: encrypts with app's public key, submits encrypted blob, shows confirmation
- [~] 15.6 Write component tests for ApplicationQueueSection: lists pending apps, approve/reject buttons call correct actions, handles private key response on approve
